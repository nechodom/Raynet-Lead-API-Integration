<?php
// Minimal WordPress stubs so the plugin classes can be exercised outside WordPress.
// Run the suite with: php tests/run.php

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
define( 'RAYNET_LEAD_VERSION', '2.0.0' );
define( 'RAYNET_LEAD_FILE', __DIR__ . '/plugin.php' );
define( 'RAYNET_LEAD_PATH', __DIR__ . '/' );
define( 'RAYNET_LEAD_URL', 'https://example.test/wp-content/plugins/raynet/' );

$GLOBALS['wp_options']    = array();
$GLOBALS['wp_transients'] = array();
$GLOBALS['wp_mails']      = array();
$GLOBALS['wp_requests']   = array();
$GLOBALS['wp_next_response'] = null;

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
	public function add_data( $data ) { $this->data = $data; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wp_options'] ) ? $GLOBALS['wp_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) { $GLOBALS['wp_options'][ $name ] = $value; return true; }
function delete_option( $name ) { unset( $GLOBALS['wp_options'][ $name ] ); return true; }

function get_transient( $key ) { return isset( $GLOBALS['wp_transients'][ $key ] ) ? $GLOBALS['wp_transients'][ $key ] : false; }
function set_transient( $key, $value, $ttl = 0 ) { $GLOBALS['wp_transients'][ $key ] = $value; return true; }

function sanitize_text_field( $str ) { return trim( preg_replace( '/[\r\n\t]+|<[^>]*>/', '', (string) $str ) ); }
function sanitize_textarea_field( $str ) { return trim( preg_replace( '/<[^>]*>/', '', (string) $str ) ); }
function sanitize_email( $email ) { return trim( (string) $email ); }
function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $key ) ); }
function sanitize_title( $t ) { return trim( strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', trim( (string) $t ) ) ), '-' ); }
function absint( $v ) { return abs( (int) $v ); }
function esc_url_raw( $url ) { return filter_var( (string) $url, FILTER_VALIDATE_URL ) ? (string) $url : ''; }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_textarea( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function wp_kses_post( $t ) { return (string) $t; }
function is_email( $email ) { return (bool) filter_var( (string) $email, FILTER_VALIDATE_EMAIL ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/\\' ) . '/'; }
function wp_specialchars_decode( $s, $q = null ) { return html_entity_decode( (string) $s ); }
function wp_unslash( $v ) { return $v; }

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_attr__( $text, $domain = null ) { return $text; }
function esc_html_e( $text, $domain = null ) { echo htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr_e( $text, $domain = null ) { echo htmlspecialchars( (string) $text, ENT_QUOTES ); }
function _e( $text, $domain = null ) { echo $text; }

function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function add_shortcode( ...$a ) {}
function apply_filters( $tag, $value, ...$rest ) { return $value; }
function do_action( ...$a ) {}
function register_rest_route( ...$a ) {}

function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags | JSON_UNESCAPED_UNICODE ); }
function wp_hash( $data, $scheme = 'auth' ) { return hash_hmac( 'md5', (string) $data, 'test-salt' ); }
function current_time( $format ) { return date( $format ); }
function get_bloginfo( $what ) { return 'Testovací web'; }
function home_url( $path = '/' ) { return 'https://example.test' . $path; }
function is_singular() { return true; }
function get_permalink() { return 'https://example.test/kontakt/'; }
function wp_create_nonce( $action ) { return 'nonce-' . md5( $action ); }
function wp_verify_nonce( $nonce, $action ) { return $nonce === 'nonce-' . md5( $action ) ? 1 : false; }
function wp_mail( $to, $subject, $body ) { $GLOBALS['wp_mails'][] = compact( 'to', 'subject', 'body' ); return true; }
function wp_unique_id( $prefix = '' ) { static $i = 0; return $prefix . ( ++$i ); }
function wp_nonce_field( ...$a ) { echo '<input type="hidden" name="raynet_nonce" value="x" />'; }

function add_query_arg( $key, $value, $url ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
}

function wp_remote_request( $url, $args ) {
	$GLOBALS['wp_requests'][] = array( 'url' => $url, 'args' => $args );
	$next = $GLOBALS['wp_next_response'];
	$GLOBALS['wp_next_response'] = null;
	return null === $next ? array( 'code' => 200, 'body' => '{}' ) : $next;
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? $r['code'] : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? $r['body'] : ''; }

$raynet_includes = dirname( __DIR__ ) . '/raynet-lead-api-integration/includes/';

require_once $raynet_includes . 'class-raynet-settings.php';
require_once $raynet_includes . 'class-raynet-api-client.php';
require_once $raynet_includes . 'class-raynet-lead-form.php';
