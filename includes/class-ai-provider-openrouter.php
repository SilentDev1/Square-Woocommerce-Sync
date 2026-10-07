<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * OpenRouter (https://openrouter.ai/docs/api/reference/overview): one API, models from many
 * providers. OpenAI-compatible chat completions at https://openrouter.ai/api/v1.
 */
class SWS_Ai_Provider_Openrouter extends SWS_Ai_Provider {

    const API          = 'https://openrouter.ai/api/v1';
    const MODELS_CACHE = 'sws_openrouter_models';

    /**
     * Suggestions for Square Sync's short JSON tasks (matching, attribute labels, descriptions),
     * checked against OpenRouter's catalog on 2026-10-07. The settings page only shows the ones
     * OpenRouter still lists, with live prices.
     */
    const RECOMMENDED = [
        'affordable' => [ 'openai/gpt-4.1-mini', 'openai/gpt-4o-mini', 'google/gemini-3.1-flash-lite' ],
        'balanced'   => [ 'anthropic/claude-haiku-4.5' ],
        'quality'    => [ 'anthropic/claude-sonnet-5', 'openai/gpt-5.4' ],
    ];

    public static function id(): string { return 'openrouter'; }
    public static function label(): string { return 'OpenRouter'; }
    public static function key_url(): string { return 'https://openrouter.ai/settings/keys'; }
    public static function default_model(): string { return 'openai/gpt-4.1-mini'; }

    private function headers(): array {
        return [
            'Authorization'      => 'Bearer ' . $this->api_key,
            'Content-Type'       => 'application/json',
            // Optional app attribution (documented headers); identifies the plugin, nothing about the store's data.
            'HTTP-Referer'       => home_url( '/' ),
            'X-OpenRouter-Title' => 'Square WooCommerce Sync',
        ];
    }

    protected function completion_request( string $prompt, int $max_tokens ): array {
        return [ self::API . '/chat/completions', $this->headers(), [
            'model'      => $this->model,
            'max_tokens' => $max_tokens,
            'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
        ] ];
    }

    protected function completion_text( $data ) {
        $text = $data['choices'][0]['message']['content'] ?? null;
        if ( ! is_string( $text ) ) {
            return $this->error( self::MALFORMED_RESPONSE );
        }
        if ( ( $data['choices'][0]['finish_reason'] ?? '' ) === 'error' ) {
            return $this->error( self::MODEL_UNAVAILABLE );
        }
        return $text;
    }

    /**
     * Free checks: GET /key (the key works; its credit limit and today's usage) and the public model
     * list (the selected model exists). No completion is sent.
     */
    public function test_connection(): array {
        if ( $this->api_key === '' ) {
            return [ 'success' => false, 'code' => self::NOT_CONFIGURED, 'message' => $this->error( self::NOT_CONFIGURED )->get_error_message() ];
        }
        $res = $this->request( 'GET', self::API . '/key', [ 'Authorization' => 'Bearer ' . $this->api_key ], null, 15, 1 );
        if ( is_wp_error( $res ) ) {
            return [ 'success' => false, 'code' => $res->get_error_code(), 'message' => $res->get_error_message() ];
        }
        $k = is_array( $res['data']['data'] ?? null ) ? $res['data']['data'] : [];
        if ( ! empty( $k['is_management_key'] ) || ! empty( $k['is_provisioning_key'] ) ) {
            return [ 'success' => false, 'code' => self::PERMISSION_DENIED, 'message' => 'This is an OpenRouter management key. Use a regular API key (it can\'t manage your other keys).' ];
        }
        $usage = '';
        if ( isset( $k['usage_daily'] ) && is_numeric( $k['usage_daily'] ) ) {
            $usage = sprintf( ' Key usage today: $%.2f.', (float) $k['usage_daily'] );
        }
        if ( isset( $k['limit'] ) && is_numeric( $k['limit'] ) ) {
            $left = isset( $k['limit_remaining'] ) && is_numeric( $k['limit_remaining'] ) ? (float) $k['limit_remaining'] : null;
            $usage .= $left !== null ? sprintf( ' Credit limit $%.2f, $%.2f left.', (float) $k['limit'], $left ) : sprintf( ' Credit limit $%.2f.', (float) $k['limit'] );
            if ( $left !== null && $left <= 0 ) {
                return [ 'success' => false, 'code' => self::INSUFFICIENT_CREDITS, 'message' => 'Connected, but this key has reached its credit limit.' . $usage ];
            }
        } else {
            $usage .= ' No credit limit set on this key (recommended: set one at openrouter.ai/settings/keys).';
        }

        $models = $this->list_models();
        if ( is_wp_error( $models ) ) {
            return [ 'success' => true, 'code' => 'OK', 'message' => 'Connected to OpenRouter. Couldn\'t check the model right now.' . $usage ];
        }
        $found = false;
        foreach ( $models as $m ) {
            if ( $m['id'] === $this->model || $m['id'] === preg_replace( '~:(nitro|floor|exacto|online)$~', '', $this->model ) ) {
                $found = true;
                break;
            }
        }
        if ( ! $found ) {
            return [ 'success' => false, 'code' => self::MODEL_NOT_FOUND, 'message' => 'Connected, but ' . $this->error( self::MODEL_NOT_FOUND )->get_error_message() . $usage ];
        }
        return [ 'success' => true, 'code' => 'OK', 'message' => 'Connected to OpenRouter. Model ' . esc_html( $this->model ) . ' is available.' . $usage ];
    }

    /**
     * Text models from OpenRouter's public catalog (GET /models needs no key), trimmed to what the
     * settings page shows, cached for 12 hours.
     *
     * @return array<int, array{id: string, name: string, context: int|null, in: float|null, out: float|null, json: bool}>|WP_Error
     */
    public function list_models() {
        $cached = get_transient( self::MODELS_CACHE );
        if ( is_array( $cached ) && $cached ) {
            return $cached;
        }
        $res = $this->request( 'GET', self::API . '/models', [ 'Accept' => 'application/json' ], null, 20, 2 );
        if ( is_wp_error( $res ) ) {
            return $res;
        }
        $out = [];
        foreach ( (array) ( $res['data']['data'] ?? [] ) as $m ) {
            if ( ! is_array( $m ) || ! isset( $m['id'] ) || self::sanitize_model( $m['id'] ) === '' ) {
                continue;
            }
            $outputs = (array) ( $m['architecture']['output_modalities'] ?? [ 'text' ] );
            if ( ! in_array( 'text', $outputs, true ) ) {
                continue;
            }
            $price = static function ( $v ) {
                return is_numeric( $v ) ? round( (float) $v * 1e6, 4 ) : null; // USD per million tokens
            };
            $out[] = [
                'id'      => $m['id'],
                'name'    => mb_substr( wp_strip_all_tags( (string) ( $m['name'] ?? $m['id'] ) ), 0, 80 ),
                'context' => isset( $m['context_length'] ) ? (int) $m['context_length'] : null,
                'in'      => $price( $m['pricing']['prompt'] ?? null ),
                'out'     => $price( $m['pricing']['completion'] ?? null ),
                'json'    => in_array( 'response_format', (array) ( $m['supported_parameters'] ?? [] ), true ),
            ];
        }
        if ( ! $out ) {
            return $this->error( self::MALFORMED_RESPONSE );
        }
        usort( $out, static function ( $a, $b ) { return strcmp( $a['id'], $b['id'] ); } );
        set_transient( self::MODELS_CACHE, $out, 12 * HOUR_IN_SECONDS );
        return $out;
    }

    /** RECOMMENDED, limited to models OpenRouter currently lists, with their live details. */
    public static function recommendations( array $models ): array {
        $by_id = [];
        foreach ( $models as $m ) {
            $by_id[ $m['id'] ] = $m;
        }
        $out = [];
        foreach ( self::RECOMMENDED as $tier => $ids ) {
            foreach ( $ids as $id ) {
                if ( isset( $by_id[ $id ] ) ) {
                    $out[ $tier ][] = $by_id[ $id ];
                }
            }
        }
        return $out;
    }
}
