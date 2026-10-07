<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** OpenAI Chat Completions API (https://platform.openai.com/docs/api-reference/chat). */
class SWS_Ai_Provider_Openai extends SWS_Ai_Provider {

    const API = 'https://api.openai.com/v1';

    public static function id(): string { return 'openai'; }
    public static function label(): string { return 'OpenAI'; }
    public static function key_url(): string { return 'https://platform.openai.com/api-keys'; }
    public static function default_model(): string { return 'gpt-4o-mini'; }

    private function headers(): array {
        return [ 'Authorization' => 'Bearer ' . $this->api_key, 'Content-Type' => 'application/json' ];
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
        return is_string( $text ) ? $text : $this->error( self::MALFORMED_RESPONSE );
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
        return [ 'success' => true, 'code' => 'OK', 'message' => 'Connected to OpenAI. Model ' . esc_html( $this->model ) . ' is available.' ];
    }
}
