<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * One AI provider (Anthropic, OpenAI, OpenRouter): the only place that knows a provider's HTTP API.
 *
 * Business logic (SWS_Ai_Matcher) sends a prompt and gets text back; the provider builds the
 * request, retries temporary failures with bounded backoff, and turns every failure into one of
 * the error codes below with a message written for store staff. Provider error bodies are used
 * only to classify the failure, never shown, logged or stored: they can echo request text, model
 * names, or part of the key.
 *
 * Each provider has its own saved key (`sws_ai_key_<id>`) and model (`sws_ai_model_<id>`), so a
 * key is never sent to another provider when the selection changes.
 */
abstract class SWS_Ai_Provider {

    const AUTHENTICATION_FAILED = 'AUTHENTICATION_FAILED';
    const INSUFFICIENT_CREDITS  = 'INSUFFICIENT_CREDITS';
    const PERMISSION_DENIED     = 'PERMISSION_DENIED';
    const MODEL_NOT_FOUND       = 'MODEL_NOT_FOUND';
    const MODEL_UNAVAILABLE     = 'MODEL_UNAVAILABLE';
    const RATE_LIMITED          = 'RATE_LIMITED';
    const PROVIDER_UNAVAILABLE  = 'PROVIDER_UNAVAILABLE';
    const TIMEOUT               = 'TIMEOUT';
    const REQUEST_FAILED        = 'REQUEST_FAILED';
    const MALFORMED_RESPONSE    = 'MALFORMED_RESPONSE';
    const NOT_CONFIGURED        = 'NOT_CONFIGURED';

    /** Attempts for one request, including the first. */
    const MAX_ATTEMPTS = 3;
    /** Longest Retry-After we'll wait for inside a request (seconds); longer means "rate limited". */
    const MAX_RETRY_AFTER = 10;

    /** @var callable(float): void  Replaced in tests so retries don't sleep. */
    public static $sleeper = null;

    protected $api_key;
    protected $model;

    public function __construct( string $api_key, string $model ) {
        $this->api_key = $api_key;
        $this->model   = $model !== '' ? $model : static::default_model();
    }

    // ── Provider description ─────────────────────────────────────────────

    abstract public static function id(): string;
    abstract public static function label(): string;
    abstract public static function key_url(): string;
    abstract public static function default_model(): string;

    // ── Operations ───────────────────────────────────────────────────────

    /** Free check that the key works (and, where the API can tell, that the model exists). */
    abstract public function test_connection(): array;

    /** Models the user can pick from, or [] when the provider's list isn't offered in the UI. */
    public function list_models() {
        return [];
    }

    /** @return string|WP_Error The model's text. */
    public function complete( string $prompt, int $max_tokens = 500 ) {
        if ( $this->api_key === '' ) {
            return $this->error( self::NOT_CONFIGURED );
        }
        [ $url, $headers, $body ] = $this->completion_request( $prompt, $max_tokens );
        $res = $this->request( 'POST', $url, $headers, $body, 60 );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        return $this->completion_text( $res['data'] );
    }

    public function has_key(): bool {
        return $this->api_key !== '';
    }

    public function model(): string {
        return $this->model;
    }

    /** @return array{0: string, 1: array, 2: array} URL, headers, JSON body. */
    abstract protected function completion_request( string $prompt, int $max_tokens ): array;

    /** @return string|WP_Error */
    abstract protected function completion_text( $data );

    // ── HTTP with bounded retries ────────────────────────────────────────

    /**
     * @return array{status: int, data: mixed, headers: array}|WP_Error
     */
    protected function request( string $method, string $url, array $headers, ?array $body = null, int $timeout = 30, int $attempts = self::MAX_ATTEMPTS ) {
        $last = null;
        for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
            $args = [ 'method' => $method, 'timeout' => $timeout, 'headers' => $headers, 'redirection' => 0 ];
            if ( $body !== null ) {
                $args['body'] = wp_json_encode( $body );
            }
            $response = wp_remote_request( $url, $args );

            if ( is_wp_error( $response ) ) {
                $msg  = strtolower( $response->get_error_message() );
                $code = ( strpos( $msg, 'timed out' ) !== false || strpos( $msg, 'timeout' ) !== false ) ? self::TIMEOUT : self::PROVIDER_UNAVAILABLE;
                $last = $this->error( $code );
                if ( $attempt < $attempts ) {
                    $this->wait( $this->backoff( $attempt ) );
                    continue;
                }
                return $last;
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            $data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
            $retry_after = $this->retry_after( wp_remote_retrieve_header( $response, 'retry-after' ) );

            // Some providers (OpenRouter) report a failed generation with HTTP 200 and an error body.
            $error_status = $status;
            if ( $status >= 200 && $status < 300 && is_array( $data ) && isset( $data['error'] ) && empty( $data['choices'] ) && empty( $data['content'] ) ) {
                $error_status = (int) ( $data['error']['code'] ?? 502 );
                if ( $error_status < 400 ) {
                    $error_status = 502;
                }
            }

            if ( $error_status >= 200 && $error_status < 300 ) {
                if ( ! is_array( $data ) ) {
                    return $this->error( self::MALFORMED_RESPONSE );
                }
                return [ 'status' => $status, 'data' => $data, 'headers' => [] ];
            }

            $code = $this->classify( $error_status, is_array( $data ) ? $data : [] );
            $last = $this->error( $code );
            // Out of credits/quota is permanent until someone pays, even when sent as 429 (OpenAI).
            $retryable = $code !== self::INSUFFICIENT_CREDITS && in_array( $error_status, [ 408, 429, 500, 502, 503, 504 ], true )
                || ( $error_status === 402 && $retry_after !== null ); // OpenRouter: in-flight budget
            if ( ! $retryable || $attempt >= $attempts ) {
                return $last;
            }
            if ( $retry_after !== null && $retry_after > self::MAX_RETRY_AFTER ) {
                return $last; // Don't hold a sync request for minutes.
            }
            $this->wait( $retry_after ?? $this->backoff( $attempt ) );
        }
        return $last ?: $this->error( self::REQUEST_FAILED );
    }

    /** 1 s, 2 s, 4 s … (capped at 8 s) plus up to 250 ms jitter. */
    protected function backoff( int $attempt ): float {
        return min( 8, 2 ** ( $attempt - 1 ) ) + mt_rand( 0, 250 ) / 1000;
    }

    private function retry_after( $value ): ?float {
        if ( is_array( $value ) ) {
            $value = reset( $value );
        }
        if ( $value === '' || $value === null || $value === false ) {
            return null;
        }
        if ( is_numeric( $value ) ) {
            return max( 0, (float) $value );
        }
        $ts = strtotime( (string) $value );
        return $ts ? max( 0, $ts - time() ) : null;
    }

    private function wait( float $seconds ): void {
        if ( self::$sleeper ) {
            ( self::$sleeper )( $seconds );
            return;
        }
        usleep( (int) ( $seconds * 1e6 ) );
    }

    /** Map an HTTP failure to an error code. The body is read only to tell model errors apart. */
    protected function classify( int $status, array $data ): string {
        $text = strtolower( (string) ( $data['error']['message'] ?? '' ) . ' ' . (string) ( $data['error']['type'] ?? '' ) . ' ' . (string) ( $data['error']['code'] ?? '' ) . ' ' . (string) ( $data['error']['metadata']['error_type'] ?? '' ) );
        $mentions_model = strpos( $text, 'model' ) !== false;
        switch ( true ) {
            case $status === 401:
                return self::AUTHENTICATION_FAILED;
            case $status === 402:
            case strpos( $text, 'insufficient_quota' ) !== false:
            case strpos( $text, 'credit_balance' ) !== false:
            case strpos( $text, 'billing' ) !== false && $status === 400:
                return self::INSUFFICIENT_CREDITS;
            case $status === 403:
                return self::PERMISSION_DENIED;
            case $status === 404 && $mentions_model:
            case $status === 400 && $mentions_model && ( strpos( $text, 'not' ) !== false || strpos( $text, 'invalid' ) !== false ):
                return self::MODEL_NOT_FOUND;
            case $status === 404:
                return self::REQUEST_FAILED;
            case $status === 408:
                return self::TIMEOUT;
            case $status === 429:
                return self::RATE_LIMITED;
            case $status === 502 || $status === 503:
                return $mentions_model || static::id() === 'openrouter' ? self::MODEL_UNAVAILABLE : self::PROVIDER_UNAVAILABLE;
            case $status >= 500:
                return self::PROVIDER_UNAVAILABLE;
            default:
                return self::REQUEST_FAILED;
        }
    }

    /** A WP_Error with a message written for store staff (no provider text, no key). */
    public function error( string $code ): WP_Error {
        $p = static::label();
        $m = esc_html( $this->model );
        $messages = [
            self::AUTHENTICATION_FAILED => "{$p} rejected the API key. Check or replace it in Square Sync → Settings → AI.",
            self::INSUFFICIENT_CREDITS  => "Your {$p} account has no credits left. Add credits with {$p}.",
            self::PERMISSION_DENIED     => "{$p} refused this request (the key isn't allowed to use it, or the content was blocked).",
            self::MODEL_NOT_FOUND       => "The model \"{$m}\" isn't available on {$p}. Choose another model in Settings → AI.",
            self::MODEL_UNAVAILABLE     => "The model \"{$m}\" is temporarily unavailable on {$p}. Try again later or choose another model.",
            self::RATE_LIMITED          => "{$p} is rate limiting requests. Try again in a minute.",
            self::PROVIDER_UNAVAILABLE  => "{$p} is unavailable right now. Try again later.",
            self::TIMEOUT               => "{$p} didn't answer in time. Try again later.",
            self::REQUEST_FAILED        => "{$p} couldn't process the request.",
            self::MALFORMED_RESPONSE    => "{$p} returned a response Square Sync couldn't read.",
            self::NOT_CONFIGURED        => "No {$p} API key is saved. Add one in Square Sync → Settings → AI.",
        ];
        return new WP_Error( $code, $messages[ $code ] ?? $messages[ self::REQUEST_FAILED ], [ 'provider' => static::id() ] );
    }

    // ── Registry and settings ────────────────────────────────────────────

    /** @return array<string, class-string<SWS_Ai_Provider>> */
    public static function registry(): array {
        return [
            'anthropic'  => 'SWS_Ai_Provider_Anthropic',
            'openai'     => 'SWS_Ai_Provider_Openai',
            'openrouter' => 'SWS_Ai_Provider_Openrouter',
        ];
    }

    public static function selected_id(): string {
        $id = (string) get_option( 'sws_ai_provider', 'anthropic' );
        return isset( self::registry()[ $id ] ) ? $id : 'anthropic';
    }

    public static function saved_key( string $id ): string {
        $stored = get_option( 'sws_ai_key_' . $id, '' );
        return $stored ? sws_decrypt_key( $stored ) : '';
    }

    public static function saved_model( string $id ): string {
        $class = self::registry()[ $id ] ?? self::registry()['anthropic'];
        $model = (string) get_option( 'sws_ai_model_' . $id, '' );
        return $model !== '' ? $model : $class::default_model();
    }

    public static function make( string $id, ?string $key = null, ?string $model = null ): SWS_Ai_Provider {
        $registry = self::registry();
        $id       = isset( $registry[ $id ] ) ? $id : 'anthropic';
        $class    = $registry[ $id ];
        return new $class( $key ?? self::saved_key( $id ), $model ?? self::saved_model( $id ) );
    }

    /** The provider the owner selected, with its own key and model. */
    public static function current(): SWS_Ai_Provider {
        return self::make( self::selected_id() );
    }

    /** Model ids are short identifiers like "gpt-4o-mini" or "anthropic/claude-haiku-4.5". */
    public static function sanitize_model( $model ): string {
        $model = trim( (string) $model );
        return preg_match( '~^[A-Za-z0-9._:/@-]{1,150}$~', $model ) ? $model : '';
    }

    /**
     * Upgrade (1.15.0): the single shared key/model become the selected provider's own key/model.
     * The old options are left untouched so a rollback to 1.14.x keeps working.
     */
    public static function migrate(): void {
        if ( (int) get_option( 'sws_ai_store_version', 1 ) >= 2 ) {
            return;
        }
        $id     = self::selected_id();
        $legacy = (string) get_option( 'sws_ai_api_key', '' );
        if ( $legacy !== '' && ! get_option( 'sws_ai_key_' . $id ) ) {
            $plain = sws_decrypt_key( $legacy );
            // 1.x could leave the key unencrypted (saved by other tools, or without OpenSSL): a value
            // that doesn't decrypt but looks like a raw API key is that plaintext key.
            if ( $plain === '' && preg_match( '~^(sk-|sk_|sk-ant-|sk-or-)[A-Za-z0-9_.-]{16,300}$~', $legacy ) ) {
                $plain = $legacy;
            }
            if ( $plain !== '' ) {
                $enc = sws_encrypt_key( $plain );
                if ( $enc !== '' ) {
                    update_option( 'sws_ai_key_' . $id, $enc, false );
                    // Keep the 1.x option readable by 1.x (rollback), but never as plaintext.
                    if ( $plain === $legacy ) {
                        update_option( 'sws_ai_api_key', sws_encrypt_key_v1( $plain ) );
                    }
                }
            }
        }
        $legacy_model = self::sanitize_model( get_option( 'sws_ai_model', '' ) );
        if ( $legacy_model !== '' && ! get_option( 'sws_ai_model_' . $id ) ) {
            update_option( 'sws_ai_model_' . $id, $legacy_model, false );
        }
        update_option( 'sws_ai_store_version', 2, false );
    }
}
