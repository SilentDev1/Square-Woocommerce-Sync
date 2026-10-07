<?php
/**
 * Live check of the OpenRouter adapter against the real API (not WordPress, not the store).
 * Costs well under $0.001: one tiny completion and one product match on a cheap model.
 *
 *   read -s OPENROUTER_API_KEY && export OPENROUTER_API_KEY && php tests/ai/live.php; unset OPENROUTER_API_KEY
 *
 * Never prints the key.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'SWS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
$KEY   = (string) getenv( 'OPENROUTER_API_KEY' );
$MODEL = getenv( 'OPENROUTER_MODEL' ) ?: 'openai/gpt-4.1-mini';
if ( ! preg_match( '~^sk-or-~', $KEY ) ) {
	fwrite( STDERR, "Set OPENROUTER_API_KEY (sk-or-…) first.\n" );
	exit( 2 );
}

// WordPress stand-ins with a real HTTP client.
class WP_Error {
	private $c, $m, $d;
	public function __construct( $c = '', $m = '', $d = null ) { $this->c = $c; $this->m = $m; $this->d = $d; }
	public function get_error_code() { return $this->c; }
	public function get_error_message() { return $this->m; }
	public function get_error_data() { return $this->d; }
}
$GLOBALS['t'] = [];
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function get_option( $k, $d = false ) { return $d; }
function get_transient( $k ) { return $GLOBALS['t'][ $k ] ?? false; }
function set_transient( $k, $v, $x = 0 ) { $GLOBALS['t'][ $k ] = $v; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function home_url( $p = '' ) { return 'https://localhost.test' . $p; }
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_kses_post( $s ) { return (string) $s; }
function wp_get_post_terms( $id, $tax, $a = [] ) { return [ 'Salt Nicotine E-Juice' ]; }
function wc_get_product( $id ) { return null; }
function wp_remote_request( $url, $args ) {
	$ch = curl_init( $url );
	$h  = [];
	foreach ( $args['headers'] as $k => $v ) { $h[] = "$k: $v"; }
	$resp_headers = [];
	curl_setopt_array( $ch, [ CURLOPT_CUSTOMREQUEST => $args['method'], CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $args['timeout'], CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_HEADERFUNCTION => function ( $c, $line ) use ( &$resp_headers ) { $p = explode( ':', $line, 2 ); if ( count( $p ) === 2 ) { $resp_headers[ strtolower( trim( $p[0] ) ) ] = trim( $p[1] ); } return strlen( $line ); } ] );
	if ( isset( $args['body'] ) ) { curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['body'] ); }
	$body = curl_exec( $ch );
	if ( $body === false ) { $e = curl_error( $ch ); return new WP_Error( 'http_request_failed', $e ); }
	return [ 'status' => curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ), 'body' => $body, 'headers' => $resp_headers ];
}
function wp_remote_retrieve_response_code( $r ) { return $r['status']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function wp_remote_retrieve_header( $r, $h ) { return $r['headers'][ strtolower( $h ) ] ?? ''; }
function sws_decrypt_key( $s ) { return ''; }
foreach ( [ 'class-ai-provider.php', 'class-ai-provider-anthropic.php', 'class-ai-provider-openai.php', 'class-ai-provider-openrouter.php', 'class-ai-matcher.php' ] as $f ) {
	require SWS_PLUGIN_DIR . 'includes/' . $f;
}

$out = function ( $label, $ok, $detail ) use ( $KEY ) {
	$detail = str_replace( $KEY, '[key]', (string) $detail );
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . ' — ' . $detail . "\n";
};

$or = new SWS_Ai_Provider_Openrouter( $KEY, $MODEL );
$r  = $or->test_connection();
$out( 'test connection (GET /key + model list, no completion)', $r['success'], $r['code'] . ': ' . $r['message'] );

$models = $or->list_models();
$rec    = is_wp_error( $models ) ? [] : SWS_Ai_Provider_Openrouter::recommendations( $models );
$out( 'model discovery', ! is_wp_error( $models ) && count( $models ) > 50, is_wp_error( $models ) ? $models->get_error_code() : count( $models ) . ' text models; recommended available: ' . implode( ', ', array_merge( ...array_map( fn( $t ) => array_column( $t, 'id' ), array_values( $rec ) ) ) ) );

$t0 = microtime( true );
$text = $or->complete( 'Reply with exactly: OK', 5 );
$out( "one tiny completion ($MODEL)", is_string( $text ) && stripos( $text, 'OK' ) !== false, ( is_wp_error( $text ) ? $text->get_error_code() : json_encode( $text ) ) . sprintf( ' in %.1fs', microtime( true ) - $t0 ) );

class LiveWcProduct {
	public function get_id() { return 26154; }
	public function get_name() { return 'Pod Juice Salt Loops'; }
	public function get_sku() { return 'PJ-LOOPS'; }
	public function get_regular_price() { return '19.99'; }
	public function get_price() { return '19.99'; }
	public function get_type() { return 'simple'; }
	public function is_type( $t ) { return $t === 'simple'; }
	public function get_children() { return []; }
}
$match = ( new SWS_Ai_Matcher( $or ) )->find_best_match( [ 'name' => 'Pod Juice Loops Salt 30ml', 'categories' => [ 'Salt Juice' ], 'variations' => [ [ 'name' => '35mg', 'sku' => 'PJ-LOOPS-35', 'price' => '19.99' ] ] ], [ new LiveWcProduct() ] );
$out( 'product match normalised (same JSON shape as OpenAI/Anthropic)', $match['match_id'] === 26154 && $match['confidence'] > 0.5, json_encode( $match ) );

$bad_model = new SWS_Ai_Provider_Openrouter( $KEY, 'acme/no-such-model-2026' );
$e = $bad_model->complete( 'Reply OK', 5 );
$out( 'invalid model → MODEL_NOT_FOUND (no retry, no raw provider text)', is_wp_error( $e ) && $e->get_error_code() === 'MODEL_NOT_FOUND', is_wp_error( $e ) ? $e->get_error_code() . ': ' . $e->get_error_message() : 'no error' );

$bad_key = new SWS_Ai_Provider_Openrouter( 'sk-or-v1-' . str_repeat( '0', 64 ), $MODEL );
$r = $bad_key->test_connection();
$out( 'invalid key → AUTHENTICATION_FAILED', ! $r['success'] && $r['code'] === 'AUTHENTICATION_FAILED', $r['code'] . ': ' . $r['message'] );
