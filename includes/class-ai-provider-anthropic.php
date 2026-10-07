<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Anthropic Messages API (https://docs.anthropic.com/en/api/messages). */
class SWS_Ai_Provider_Anthropic extends SWS_Ai_Provider {

    const API     = 'https://api.anthropic.com/v1';
    const VERSION = '2023-06-01';

    public static function id(): string { return 'anthropic'; }
    public static function label(): string { return 'Anthropic'; }
    public static function key_url(): string { return 'https://console.anthropic.com/settings/keys'; }
    public static function default_model(): string { return 'claude-3-haiku-20240307'; }

    private function headers(): array {
        return [ 'x-api-key' => $this->api_key, 'anthropic-version' => self::VERSION, 'content-type' => 'application/json' ];
    }

    protected function completion_request( string $prompt, int $max_tokens ): array {
        return [ self::API . '/messages', $this->headers(), [
            'model'      => $this->model,
            'max_tokens' => $max_tokens,
            'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
        ] ];
    }

    protected function completion_text( $data ) {
        if ( ! isset( $data['content'][0]['text'] ) || ! is_string( $data['content'][0]['text'] ) ) {
            return $this->error( self::MALFORMED_RESPONSE );
        }
        return $data['content'][0]['text'];
    }

    /** GET /v1/models/{model}: free; proves the key and that the model exists. */
    public function test_connection(): array {
        if ( $this->api_key === '' ) {
            return [ 'success' => false, 'code' => self::NOT_CONFIGURED, 'message' => $this->error( self::NOT_CONFIGURED )->get_error_message() ];
        }
        $res = $this->request( 'GET', self::API . '/models/' . rawurlencode( $this->model ), $this->headers(), null, 15, 1 );
        if ( is_wp_error( $res ) ) {
            return [ 'success' => false, 'code' => $res->get_error_code(), 'message' => $res->get_error_message() ];
        }
        return [ 'success' => true, 'code' => 'OK', 'message' => 'Connected to Anthropic. Model ' . esc_html( $this->model ) . ' is available.' ];
    }
}
