<?php
/**
 * Square Sync AI provider tests (no network: every HTTP call is answered by a scripted fake).
 *
 *   php tests/ai/run.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'SWS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );

// ── Minimal WordPress stand-ins ─────────────────────────────────────────
$GLOBALS['opts'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['http'] = [];      // scripted responses (FIFO)
$GLOBALS['requests'] = [];  // what was sent
class WP_Error {
	private $code, $message, $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function get_option( $k, $d = false ) { return array_key_exists( $k, $GLOBALS['opts'] ) ? $GLOBALS['opts'][ $k ] : $d; }
function update_option( $k, $v, $autoload = null ) { $GLOBALS['opts'][ $k ] = $v; return true; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); return true; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['transients'][ $k ] ); }
function wp_salt( $s ) { return "test-salt-$s-0123456789abcdef"; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function home_url( $p = '' ) { return 'https://shop.example' . $p; }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_text_field( $s ) { return trim( preg_replace( '/[\r\n\t ]+/', ' ', strip_tags( (string) $s ) ) ); }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_kses_post( $s ) { return (string) $s; }
function wp_unslash( $v ) { return $v; }
function get_current_user_id() { return 1; }
function wp_get_post_terms( $id, $tax, $args = [] ) { return [ 'E-Liquid' ]; }
function wc_get_product( $id ) { return null; }
function wp_remote_request( $url, $args ) {
	$GLOBALS['requests'][] = [ 'url' => $url, 'args' => $args ];
	$r = array_shift( $GLOBALS['http'] );
	if ( $r === null ) { return new WP_Error( 'http_request_failed', 'No scripted response' ); }
	if ( $r instanceof WP_Error ) { return $r; }
	return $r;
}
function wp_remote_retrieve_response_code( $r ) { return $r['status']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_header( $r, $h ) { return $r['headers'][ strtolower( $h ) ] ?? ''; }
function resp( int $status, $body, array $headers = [] ) { return [ 'status' => $status, 'body' => is_string( $body ) ? $body : json_encode( $body ), 'headers' => $headers ]; }

// Encryption helpers from the plugin file (only the credential functions, extracted verbatim).
$main = file_get_contents( SWS_PLUGIN_DIR . 'square-woo-sync.php' );
$start = strpos( $main, 'function sws_cipher_key() {' );
$end   = strpos( $main, '// Autoload classes' );
eval( substr( $main, $start, $end - $start ) );
foreach ( [ 'class-ai-provider.php', 'class-ai-provider-anthropic.php', 'class-ai-provider-openai.php', 'class-ai-provider-openrouter.php', 'class-ai-matcher.php' ] as $f ) {
	require SWS_PLUGIN_DIR . 'includes/' . $f;
}
$slept = [];
SWS_Ai_Provider::$sleeper = function ( $s ) use ( &$slept ) { $slept[] = $s; };

$pass = 0; $fail = 0;
function check( string $name, bool $ok, string $detail = '' ) {
	global $pass, $fail;
	$ok ? $pass++ : $fail++;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( ! $ok && $detail !== '' ? "\n     $detail" : '' ) . "\n";
}
function reset_state() { $GLOBALS['opts'] = []; $GLOBALS['transients'] = []; $GLOBALS['http'] = []; $GLOBALS['requests'] = []; }

$OPENAI_KEY = 'sk-proj-TESTopenaiKEY000000000000001234';
$ANTH_KEY   = 'sk-ant-api03-TESTanthropicKEY0000000005678';
$OR_KEY     = 'sk-or-v1-TESTopenrouterKEY000000000000009abc';
$ALL_KEYS   = [ $OPENAI_KEY, $ANTH_KEY, $OR_KEY ];

// ── 1. Credential encryption ────────────────────────────────────────────
$enc = sws_encrypt_key( $OR_KEY );
check( 'encrypt: v2 format, not plaintext', strpos( $enc, 'v2:' ) === 0 && strpos( $enc, $OR_KEY ) === false && strpos( base64_decode( substr( $enc, 3 ) ), $OR_KEY ) === false );
check( 'encrypt: round trip', sws_decrypt_key( $enc ) === $OR_KEY );
check( 'encrypt: random IV (two encryptions differ)', sws_encrypt_key( $OR_KEY ) !== $enc );
$raw = base64_decode( substr( $enc, 3 ) ); $raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
check( 'encrypt: tampered value is rejected (returns empty, not ciphertext)', sws_decrypt_key( 'v2:' . base64_encode( $raw ) ) === '' );
$iv = random_bytes( 16 ); $legacy = base64_encode( $iv . openssl_encrypt( $OPENAI_KEY, 'AES-256-CBC', sws_cipher_key(), 0, $iv ) );
check( 'decrypt: 1.x (AES-256-CBC) value still readable', sws_decrypt_key( $legacy ) === $OPENAI_KEY );
check( 'decrypt: garbage returns empty', sws_decrypt_key( 'not-a-key' ) === '' && sws_decrypt_key( '' ) === '' );

// ── 2. Upgrade keeps existing configurations ────────────────────────────
reset_state();
update_option( 'sws_ai_provider', 'openai' ); update_option( 'sws_ai_api_key', $legacy ); update_option( 'sws_ai_model', 'gpt-4o-mini' );
SWS_Ai_Provider::migrate();
check( 'upgrade: OpenAI stays selected', SWS_Ai_Provider::selected_id() === 'openai' );
check( 'upgrade: existing OpenAI key moves to the OpenAI slot (re-encrypted v2)', strpos( get_option( 'sws_ai_key_openai' ), 'v2:' ) === 0 && SWS_Ai_Provider::saved_key( 'openai' ) === $OPENAI_KEY );
check( 'upgrade: existing OpenAI model kept', SWS_Ai_Provider::saved_model( 'openai' ) === 'gpt-4o-mini' );
check( 'upgrade: OpenRouter is NOT selected and has no key', SWS_Ai_Provider::selected_id() !== 'openrouter' && SWS_Ai_Provider::saved_key( 'openrouter' ) === '' );
check( 'upgrade: old options left untouched (rollback to 1.14 works)', get_option( 'sws_ai_api_key' ) === $legacy && get_option( 'sws_ai_model' ) === 'gpt-4o-mini' );
check( 'upgrade: runs once', ( function () { update_option( 'sws_ai_key_openai', 'changed' ); SWS_Ai_Provider::migrate(); return get_option( 'sws_ai_key_openai' ) === 'changed'; } )() );
reset_state();
update_option( 'sws_ai_provider', 'anthropic' ); update_option( 'sws_ai_api_key', sws_encrypt_key( $ANTH_KEY ) ); update_option( 'sws_ai_model', 'claude-3-haiku-20240307' );
SWS_Ai_Provider::migrate();
check( 'upgrade: existing Anthropic configuration survives', SWS_Ai_Provider::selected_id() === 'anthropic' && SWS_Ai_Provider::saved_key( 'anthropic' ) === $ANTH_KEY && SWS_Ai_Provider::saved_model( 'anthropic' ) === 'claude-3-haiku-20240307' );
reset_state();
update_option( 'sws_ai_provider', 'openai' ); update_option( 'sws_ai_api_key', $OPENAI_KEY ); update_option( 'sws_ai_model', 'gpt-4o-mini' );
SWS_Ai_Provider::migrate();
check( 'upgrade: a PLAINTEXT 1.x key (as found on Evolve) is moved and encrypted', SWS_Ai_Provider::saved_key( 'openai' ) === $OPENAI_KEY && strpos( get_option( 'sws_ai_key_openai' ), 'v2:' ) === 0 );
check( 'upgrade: no plaintext copy left; 1.x rollback copy is 1.x-encrypted and decrypts', get_option( 'sws_ai_api_key' ) !== $OPENAI_KEY && strpos( json_encode( $GLOBALS['opts'] ), $OPENAI_KEY ) === false && sws_decrypt_key( get_option( 'sws_ai_api_key' ) ) === $OPENAI_KEY && strpos( get_option( 'sws_ai_api_key' ), 'v2:' ) !== 0 );
reset_state();
update_option( 'sws_ai_provider', 'openai' ); update_option( 'sws_ai_api_key', 'garbage value' );
SWS_Ai_Provider::migrate();
check( 'upgrade: an unreadable value is not invented into a key', SWS_Ai_Provider::saved_key( 'openai' ) === '' && get_option( 'sws_ai_api_key' ) === 'garbage value' );
reset_state();
SWS_Ai_Provider::migrate();
check( 'fresh install: default stays Anthropic (OpenRouter never auto-selected)', SWS_Ai_Provider::selected_id() === 'anthropic' );

// ── 3. Existing providers send exactly what 1.14 sent ───────────────────
reset_state();
$GLOBALS['http'][] = resp( 200, [ 'choices' => [ [ 'message' => [ 'content' => 'hello' ] ] ] ] );
$p = SWS_Ai_Provider::make( 'openai', $OPENAI_KEY, 'gpt-4o-mini' );
$t = $p->complete( 'Prompt X', 300 );
$r = $GLOBALS['requests'][0];
check( 'OpenAI: same endpoint, auth header, body as before', $t === 'hello' && $r['url'] === 'https://api.openai.com/v1/chat/completions' && $r['args']['headers']['Authorization'] === "Bearer $OPENAI_KEY" && json_decode( $r['args']['body'], true ) === [ 'model' => 'gpt-4o-mini', 'max_tokens' => 300, 'messages' => [ [ 'role' => 'user', 'content' => 'Prompt X' ] ] ] && $r['args']['timeout'] === 60 );
reset_state();
$GLOBALS['http'][] = resp( 200, [ 'content' => [ [ 'type' => 'text', 'text' => 'hi' ] ] ] );
$t = SWS_Ai_Provider::make( 'anthropic', $ANTH_KEY, 'claude-3-haiku-20240307' )->complete( 'Prompt Y', 150 );
$r = $GLOBALS['requests'][0];
check( 'Anthropic: same endpoint, headers, body as before', $t === 'hi' && $r['url'] === 'https://api.anthropic.com/v1/messages' && $r['args']['headers']['x-api-key'] === $ANTH_KEY && $r['args']['headers']['anthropic-version'] === '2023-06-01' && json_decode( $r['args']['body'], true ) === [ 'model' => 'claude-3-haiku-20240307', 'max_tokens' => 150, 'messages' => [ [ 'role' => 'user', 'content' => 'Prompt Y' ] ] ] );

// ── 4. OpenRouter requests ──────────────────────────────────────────────
reset_state();
$GLOBALS['http'][] = resp( 200, [ 'id' => 'gen-1', 'model' => 'openai/gpt-4.1-mini', 'choices' => [ [ 'finish_reason' => 'stop', 'message' => [ 'role' => 'assistant', 'content' => '{"match_woo_id": 12, "confidence": 0.9, "reasoning": "same"}' ] ] ], 'usage' => [ 'prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15, 'cost' => 0.00001 ] ] );
$or = SWS_Ai_Provider::make( 'openrouter', $OR_KEY, 'openai/gpt-4.1-mini' );
$t = $or->complete( 'Prompt Z', 300 );
$r = $GLOBALS['requests'][0];
$b = json_decode( $r['args']['body'], true );
check( 'OpenRouter: documented endpoint and Bearer auth', $r['url'] === 'https://openrouter.ai/api/v1/chat/completions' && $r['args']['headers']['Authorization'] === "Bearer $OR_KEY" );
check( 'OpenRouter: same request shape as the other providers', $b === [ 'model' => 'openai/gpt-4.1-mini', 'max_tokens' => 300, 'messages' => [ [ 'role' => 'user', 'content' => 'Prompt Z' ] ] ] );
check( 'OpenRouter: response normalised to text', is_string( $t ) && strpos( $t, '"match_woo_id": 12' ) !== false );
check( 'OpenRouter: no redirects followed', $r['args']['redirection'] === 0 );

// ── 5. Errors, retries ──────────────────────────────────────────────────
function run_or( array $responses ) {
	global $OR_KEY, $slept;
	reset_state(); $slept = [];
	$GLOBALS['http'] = $responses;
	return SWS_Ai_Provider::make( 'openrouter', $OR_KEY, 'openai/gpt-4.1-mini' )->complete( 'p', 50 );
}
$err401 = resp( 401, [ 'error' => [ 'code' => 401, 'message' => "Invalid key $OR_KEY" ] ] );
$e = run_or( [ $err401 ] );
check( 'invalid credential → AUTHENTICATION_FAILED', is_wp_error( $e ) && $e->get_error_code() === 'AUTHENTICATION_FAILED' );
check( 'no retry on authentication failure', count( $GLOBALS['requests'] ) === 1 && ! $slept );
check( 'provider error text (which echoed the key) is not passed on', strpos( $e->get_error_message(), 'sk-or' ) === false );
$e = run_or( [ new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 60001 milliseconds' ), new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) ] );
check( 'timeout → TIMEOUT after bounded retries (3 attempts)', is_wp_error( $e ) && $e->get_error_code() === 'TIMEOUT' && count( $GLOBALS['requests'] ) === 3 && count( $slept ) === 2 );
check( 'backoff grows (1 s then 2 s, + jitter)', $slept && $slept[0] >= 1 && $slept[0] < 1.3 && $slept[1] >= 2 && $slept[1] < 2.3, json_encode( $slept ) );
$ok = resp( 200, [ 'choices' => [ [ 'message' => [ 'content' => 'fine' ] ] ] ] );
$t = run_or( [ resp( 429, [ 'error' => [ 'code' => 429, 'message' => 'rate' ] ], [ 'retry-after' => '2' ] ), $ok ] );
check( '429 retried after Retry-After, then succeeds', $t === 'fine' && $slept === [ 2.0 ] );
$e = run_or( [ resp( 429, [ 'error' => [ 'code' => 429 ] ], [ 'retry-after' => '120' ] ) ] );
check( '429 with a long Retry-After is not waited on → RATE_LIMITED', is_wp_error( $e ) && $e->get_error_code() === 'RATE_LIMITED' && count( $GLOBALS['requests'] ) === 1 );
$e = run_or( [ resp( 429, '{}' ), resp( 429, '{}' ), resp( 429, '{}' ), $ok ] );
check( 'retry limit: never more than 3 attempts', is_wp_error( $e ) && count( $GLOBALS['requests'] ) === 3 );
$t = run_or( [ resp( 503, [ 'error' => [ 'code' => 503, 'message' => 'No endpoints found' ] ] ), $ok ] );
check( '5xx retried, then succeeds', $t === 'fine' && count( $GLOBALS['requests'] ) === 2 );
$e = run_or( [ resp( 502, '{}' ), resp( 502, '{}' ), resp( 502, '{}' ) ] );
check( 'model provider down → MODEL_UNAVAILABLE', is_wp_error( $e ) && $e->get_error_code() === 'MODEL_UNAVAILABLE' );
$e = run_or( [ resp( 400, [ 'error' => [ 'code' => 400, 'message' => 'openai/gpt-x is not a valid model ID' ] ] ) ] );
check( 'invalid model → MODEL_NOT_FOUND, not retried', is_wp_error( $e ) && $e->get_error_code() === 'MODEL_NOT_FOUND' && count( $GLOBALS['requests'] ) === 1 );
$e = run_or( [ resp( 400, [ 'error' => [ 'code' => 400, 'message' => 'messages: required' ] ] ) ] );
check( 'bad request → REQUEST_FAILED, not retried', is_wp_error( $e ) && $e->get_error_code() === 'REQUEST_FAILED' && count( $GLOBALS['requests'] ) === 1 );
$e = run_or( [ resp( 402, [ 'error' => [ 'code' => 402 ] ] ) ] );
check( 'no credits → INSUFFICIENT_CREDITS, not retried', is_wp_error( $e ) && $e->get_error_code() === 'INSUFFICIENT_CREDITS' && count( $GLOBALS['requests'] ) === 1 );
$e = run_or( [ resp( 403, [ 'error' => [ 'code' => 403, 'metadata' => [ 'flagged_input' => 'customer Jane' ] ] ] ) ] );
check( 'moderation/permission → PERMISSION_DENIED; flagged input not echoed', is_wp_error( $e ) && $e->get_error_code() === 'PERMISSION_DENIED' && strpos( $e->get_error_message(), 'Jane' ) === false );
$e = run_or( [ resp( 200, 'not json' ) ] );
check( 'malformed response → MALFORMED_RESPONSE', is_wp_error( $e ) && $e->get_error_code() === 'MALFORMED_RESPONSE' );
$e = run_or( [ resp( 200, [ 'choices' => [ [ 'message' => [] ] ] ] ) ] );
check( 'response without text → MALFORMED_RESPONSE', is_wp_error( $e ) && $e->get_error_code() === 'MALFORMED_RESPONSE' );
$t = run_or( [ resp( 200, [ 'error' => [ 'code' => 502, 'message' => 'Provider returned error' ] ] ), $ok ] );
check( 'HTTP 200 with an error body is treated as a failure and retried', $t === 'fine' && count( $GLOBALS['requests'] ) === 2 );
$e = run_or( [ resp( 200, [ 'choices' => [ [ 'finish_reason' => 'error', 'message' => [ 'content' => '' ] ] ] ] ) ] );
check( 'finish_reason "error" → MODEL_UNAVAILABLE', is_wp_error( $e ) && $e->get_error_code() === 'MODEL_UNAVAILABLE' );

// ── 6. Test connection (free endpoints, no completion) ──────────────────
reset_state();
$catalog = [ 'data' => [ [ 'id' => 'openai/gpt-4.1-mini', 'name' => 'OpenAI: GPT-4.1 Mini', 'context_length' => 1047576, 'pricing' => [ 'prompt' => '0.0000004', 'completion' => '0.0000016' ], 'supported_parameters' => [ 'response_format' ], 'architecture' => [ 'output_modalities' => [ 'text' ] ] ], [ 'id' => 'img/only', 'architecture' => [ 'output_modalities' => [ 'image' ] ] ] ] ];
$GLOBALS['http'] = [ resp( 200, [ 'data' => [ 'label' => 'sk-or-v1-abc...xyz', 'limit' => 5, 'limit_remaining' => 4.5, 'usage_daily' => 0.12 ] ] ), resp( 200, $catalog ) ];
$r = SWS_Ai_Provider::make( 'openrouter', $OR_KEY, 'openai/gpt-4.1-mini' )->test_connection();
check( 'test connection: success with usage and limit', $r['success'] && strpos( $r['message'], 'Connected to OpenRouter' ) === 0 && strpos( $r['message'], '$0.12' ) !== false && strpos( $r['message'], '$4.50 left' ) !== false, $r['message'] );
check( 'test connection: only GET /key and GET /models (no paid completion)', array_map( fn( $q ) => $q['args']['method'] . ' ' . $q['url'], $GLOBALS['requests'] ) === [ 'GET https://openrouter.ai/api/v1/key', 'GET https://openrouter.ai/api/v1/models' ] );
check( 'test connection: masked key label never shown', strpos( $r['message'], 'sk-or' ) === false );
reset_state();
$GLOBALS['http'] = [ resp( 401, [ 'error' => [ 'code' => 401 ] ] ) ];
$r = SWS_Ai_Provider::make( 'openrouter', $OR_KEY, 'openai/gpt-4.1-mini' )->test_connection();
check( 'test connection: invalid key → Authentication failed message', ! $r['success'] && $r['code'] === 'AUTHENTICATION_FAILED' );
reset_state();
$GLOBALS['http'] = [ resp( 200, [ 'data' => [ 'limit' => null ] ] ), resp( 200, $catalog ) ];
$r = SWS_Ai_Provider::make( 'openrouter', $OR_KEY, 'acme/does-not-exist' )->test_connection();
check( 'test connection: configured model unavailable', ! $r['success'] && $r['code'] === 'MODEL_NOT_FOUND' );
reset_state();
$GLOBALS['http'] = [ resp( 200, [ 'data' => [ 'is_management_key' => true ] ] ) ];
$r = SWS_Ai_Provider::make( 'openrouter', $OR_KEY, 'openai/gpt-4.1-mini' )->test_connection();
check( 'test connection: management key refused', ! $r['success'] );
reset_state();
$GLOBALS['http'] = [ new WP_Error( 'http_request_failed', 'Could not resolve host' ) ];
$r = SWS_Ai_Provider::make( 'openrouter', $OR_KEY, 'openai/gpt-4.1-mini' )->test_connection();
check( 'test connection: OpenRouter unavailable', ! $r['success'] && $r['code'] === 'PROVIDER_UNAVAILABLE' );
reset_state();
$GLOBALS['http'] = [ resp( 200, [ 'id' => 'gpt-4o-mini', 'object' => 'model' ] ) ];
$r = SWS_Ai_Provider::make( 'openai', $OPENAI_KEY, 'gpt-4o-mini' )->test_connection();
check( 'OpenAI test: free GET /v1/models/{model}', $r['success'] && $GLOBALS['requests'][0]['url'] === 'https://api.openai.com/v1/models/gpt-4o-mini' && $GLOBALS['requests'][0]['args']['method'] === 'GET' );
reset_state();
$GLOBALS['http'] = [ resp( 404, [ 'error' => [ 'type' => 'not_found_error', 'message' => 'model: claude-x' ] ] ) ];
$r = SWS_Ai_Provider::make( 'anthropic', $ANTH_KEY, 'claude-x' )->test_connection();
check( 'Anthropic test: unknown model → MODEL_NOT_FOUND', ! $r['success'] && $r['code'] === 'MODEL_NOT_FOUND' );
reset_state();
$r = SWS_Ai_Provider::make( 'openrouter', '', 'openai/gpt-4.1-mini' )->test_connection();
check( 'test connection without a key: no request sent', ! $r['success'] && ! $GLOBALS['requests'] );

// ── 7. Model list ───────────────────────────────────────────────────────
reset_state();
$GLOBALS['http'] = [ resp( 200, $catalog ) ];
$m = ( new SWS_Ai_Provider_Openrouter( '', '' ) )->list_models();
check( 'models: text models only, with context/price/json', count( $m ) === 1 && $m[0]['id'] === 'openai/gpt-4.1-mini' && $m[0]['in'] == 0.4 && $m[0]['out'] == 1.6 && $m[0]['json'] === true );
check( 'models: public list fetched without a key', ! isset( $GLOBALS['requests'][0]['args']['headers']['Authorization'] ) );
$GLOBALS['http'] = [];
check( 'models: cached (no second request)', ( new SWS_Ai_Provider_Openrouter( '', '' ) )->list_models() === $m );
$rec = SWS_Ai_Provider_Openrouter::recommendations( $m );
check( 'recommendations: only models OpenRouter still lists', isset( $rec['affordable'] ) && count( $rec['affordable'] ) === 1 && ! isset( $rec['quality'] ) );

// ── 8. Provider switching uses each provider's own key ──────────────────
reset_state();
update_option( 'sws_ai_key_openai', sws_encrypt_key( $OPENAI_KEY ) );
update_option( 'sws_ai_provider', 'openrouter' );
$GLOBALS['http'][] = resp( 200, [ 'choices' => [ [ 'message' => [ 'content' => 'x' ] ] ] ] );
$e = SWS_Ai_Provider::current()->complete( 'p' );
check( 'switching to OpenRouter without its key: nothing sent, the OpenAI key never reaches OpenRouter', is_wp_error( $e ) && $e->get_error_code() === 'NOT_CONFIGURED' && ! $GLOBALS['requests'] );
update_option( 'sws_ai_key_openrouter', sws_encrypt_key( $OR_KEY ) );
SWS_Ai_Provider::current()->complete( 'p' );
check( 'with its own key: OpenRouter gets the OpenRouter key', ( $GLOBALS['requests'][0]['args']['headers']['Authorization'] ?? '' ) === "Bearer $OR_KEY" );

// ── 9. Settings save (admin handler) ────────────────────────────────────
require SWS_PLUGIN_DIR . 'admin/class-admin-page.php';
$admin = ( new ReflectionClass( 'SWS_Admin_Page' ) )->newInstanceWithoutConstructor();
$save  = new ReflectionMethod( 'SWS_Admin_Page', 'save_ai_settings' );
reset_state();
update_option( 'sws_ai_provider', 'openai' ); update_option( 'sws_ai_key_openai', sws_encrypt_key( $OPENAI_KEY ) ); update_option( 'sws_ai_model_openai', 'gpt-4o-mini' );
$_POST = [ 'sws_ai_provider' => 'openai', 'sws_ai_key' => [ 'openai' => '', 'openrouter' => $OR_KEY ], 'sws_ai_model' => [ 'openrouter' => 'openai/gpt-4.1-mini', 'openai' => 'gpt-4o-mini' ], 'sws_ai_key_action' => [] ];
$save->invoke( $admin );
check( 'save: OpenRouter key stored encrypted (v2), never plaintext', strpos( get_option( 'sws_ai_key_openrouter' ), 'v2:' ) === 0 && strpos( json_encode( $GLOBALS['opts'] ), $OR_KEY ) === false );
check( 'save: blank field keeps the existing OpenAI key; provider unchanged', SWS_Ai_Provider::saved_key( 'openai' ) === $OPENAI_KEY && get_option( 'sws_ai_provider' ) === 'openai' );
check( 'save: OpenRouter model saved', get_option( 'sws_ai_model_openrouter' ) === 'openai/gpt-4.1-mini' );
$_POST = [ 'sws_ai_provider' => 'evil', 'sws_ai_key_action' => [ 'openrouter' => 'remove' ], 'sws_ai_model' => [ 'openai' => 'bad model; drop' ] ];
$save->invoke( $admin );
check( 'save: unknown provider ignored', get_option( 'sws_ai_provider' ) === 'openai' );
check( 'save: Remove Key deletes only that provider\'s key', get_option( 'sws_ai_key_openrouter', null ) === null && SWS_Ai_Provider::saved_key( 'openai' ) === $OPENAI_KEY );
check( 'save: invalid model id rejected', get_option( 'sws_ai_model_openai' ) === 'gpt-4o-mini' );
$_POST = [];
$save->invoke( $admin );
check( 'save: form without the AI section changes nothing', get_option( 'sws_ai_provider' ) === 'openai' && SWS_Ai_Provider::saved_key( 'openai' ) === $OPENAI_KEY );

// ── 10. Matching and descriptions through every provider ────────────────
class FakeWcProduct {
	public function __construct( private int $id, private string $name, private string $sku ) {}
	public function get_id() { return $this->id; }
	public function get_name() { return $this->name; }
	public function get_sku() { return $this->sku; }
	public function get_regular_price() { return '19.99'; }
	public function get_price() { return '19.99'; }
	public function get_type() { return 'simple'; }
	public function is_type( $t ) { return $t === 'simple'; }
	public function get_children() { return []; }
	// Data a product object can reach; it must never be in a prompt.
	public function get_customer_email() { return 'jane.customer@example.com'; }
}
$sq = [ 'name' => 'Pod Juice Loops', 'categories' => [ 'Salt Juice' ], 'description' => 'Fruity cereal', 'variations' => [ [ 'name' => '35mg', 'sku' => 'PJ-L-35', 'price' => '19.99' ] ] ];
$responses = [
	'openai'     => resp( 200, [ 'choices' => [ [ 'message' => [ 'content' => '{"match_woo_id": 26154, "confidence": 0.92, "reasoning": "Same product"}' ] ] ] ] ),
	'anthropic'  => resp( 200, [ 'content' => [ [ 'text' => 'Sure: {"match_woo_id": 26154, "confidence": 0.92, "reasoning": "Same product"}' ] ] ] ),
	'openrouter' => resp( 200, [ 'choices' => [ [ 'finish_reason' => 'stop', 'message' => [ 'content' => "```json\n{\"match_woo_id\": 26154, \"confidence\": 0.92, \"reasoning\": \"Same product\"}\n```" ] ] ] ] ),
];
$prompts = [];
foreach ( $responses as $id => $resp ) {
	reset_state(); $GLOBALS['http'] = [ $resp ];
	$m = new SWS_Ai_Matcher( SWS_Ai_Provider::make( $id, 'k-' . $id, '' ) );
	$res = $m->find_best_match( $sq, [ new FakeWcProduct( 26154, 'Pod Juice Loops', 'PJ-L' ) ] );
	check( "matching via $id: normalised result", $res['match_id'] === 26154 && abs( $res['confidence'] - 0.92 ) < 1e-9, json_encode( $res ) );
	$body = json_decode( $GLOBALS['requests'][0]['args']['body'], true );
	$prompts[ $id ] = $body['messages'][0]['content'];
	check( "matching via $id: no customer/order/payment data in the request", strpos( $GLOBALS['requests'][0]['args']['body'], 'jane.customer' ) === false );
}
check( 'all three providers receive the identical matching prompt', count( array_unique( $prompts ) ) === 1 );
foreach ( [ 'openrouter' => resp( 200, [ 'choices' => [ [ 'message' => [ 'content' => '{"short_description": "<p>Short</p>", "description": "<p>Long</p>"}' ] ] ] ] ) ] as $id => $resp ) {
	reset_state(); $GLOBALS['http'] = [ $resp ];
	$d = ( new SWS_Ai_Matcher( SWS_Ai_Provider::make( $id, 'k', 'openai/gpt-4.1-mini' ) ) )->generate_product_description( $sq );
	check( "description via $id: normalised", $d['description'] === '<p>Long</p>' && $d['short_description'] === '<p>Short</p>' );
	check( "description via $id: only product fields in the prompt", strpos( $GLOBALS['requests'][0]['args']['body'], 'Pod Juice Loops' ) !== false && strpos( $GLOBALS['requests'][0]['args']['body'], 'jane' ) === false );
}
reset_state(); $GLOBALS['http'] = [ $err401 ];
$res = ( new SWS_Ai_Matcher( SWS_Ai_Provider::make( 'openrouter', $OR_KEY, 'x/y' ) ) )->find_best_match( $sq, [ new FakeWcProduct( 1, 'A', 'B' ) ] );
check( 'matching error: readable reason, no key, no raw provider text', $res['match_id'] === null && strpos( $res['reasoning'], 'rejected the API key' ) !== false && strpos( $res['reasoning'], 'sk-or' ) === false && strpos( $res['reasoning'], 'Invalid key' ) === false );

// ── 11. Nothing secret leaves through errors or AJAX payloads ───────────
$leak = false;
foreach ( [ 'AUTHENTICATION_FAILED', 'INSUFFICIENT_CREDITS', 'PERMISSION_DENIED', 'MODEL_NOT_FOUND', 'MODEL_UNAVAILABLE', 'RATE_LIMITED', 'PROVIDER_UNAVAILABLE', 'TIMEOUT', 'REQUEST_FAILED', 'MALFORMED_RESPONSE', 'NOT_CONFIGURED' ] as $code ) {
	$e = SWS_Ai_Provider::make( 'openrouter', $OR_KEY, 'openai/gpt-4.1-mini' )->error( $code );
	foreach ( $ALL_KEYS as $k ) { if ( strpos( $e->get_error_message() . json_encode( $e->get_error_data() ), substr( $k, 0, 12 ) ) !== false ) { $leak = true; } }
}
check( 'no error message or error data contains a key', ! $leak );
check( 'errors carry no request headers (Authorization never logged)', SWS_Ai_Provider::make( 'openrouter', $OR_KEY, 'm' )->error( 'TIMEOUT' )->get_error_data() === [ 'provider' => 'openrouter' ] );
$src = implode( "\n", array_map( 'file_get_contents', glob( SWS_PLUGIN_DIR . 'includes/class-ai-provider*.php' ) ) );
check( 'provider code never logs (no error_log/logger calls)', ! preg_match( '~error_log\s*\(|SWS_Sync_Logger|logger->~', $src ) );
$ui = file_get_contents( SWS_PLUGIN_DIR . 'admin/class-admin-page.php' );
check( 'settings page renders only the last 4 characters of a saved key', strpos( $ui, "substr( \$sws_key, -4 )" ) !== false && ! preg_match( '~value="<\?php echo[^"]*\$sws_key~', $ui ) );
check( 'no REST route exposes AI settings', ! preg_match( '~register_rest_route~', $src . $ui ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
