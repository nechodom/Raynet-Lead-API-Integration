<?php
require __DIR__ . '/wp-stubs.php';

function wp_register_style( ...$a ) {}
function wp_register_script( ...$a ) {}
function wp_enqueue_style( ...$a ) {}
function wp_enqueue_script( ...$a ) {}
function wp_localize_script( ...$a ) {}
function admin_url( $p ) { return 'https://example.test/wp-admin/' . $p; }
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$out = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }

// No option row at all: a fresh install must still get the safe defaults.
$form = new Raynet_Lead_Form();

$html = $form->render_shortcode( array() );
$dom  = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
$errors = array_filter( libxml_get_errors(), fn( $e ) => $e->level >= LIBXML_ERR_ERROR );
libxml_clear_errors();

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = $got === $want; $ok ? $pass++ : $fail++;
	printf( "%s %s\n", $ok ? 'ok  ' : 'FAIL', $label );
	if ( ! $ok ) { echo "     got: " . var_export( $got, true ) . "\n"; }
}

check( 'renders parseable HTML', count( $errors ), 0 );

$xp = new DOMXPath( $dom );
$names = array();
foreach ( $xp->query( '//input | //textarea | //button' ) as $el ) {
	$names[] = $el->getAttribute( 'name' ) ?: ( '<' . $el->tagName . '>' );
}
check( 'default fields present', array_values( array_filter( $names, fn( $n ) => in_array( $n, array( 'firstName', 'lastName', 'email', 'phone', 'topic', 'message' ), true ) ) ),
	array( 'firstName', 'lastName', 'email', 'phone', 'topic', 'message' ) );
check( 'nonce field present',    in_array( 'raynet_nonce', $names, true ), true );
check( 'timestamp pair present', array( in_array( 'raynet_ts', $names, true ), in_array( 'raynet_ts_hash', $names, true ) ), array( true, true ) );
check( 'honeypot on by default', in_array( 'website', $names, true ), true );
check( 'consent on by default',  in_array( 'consent', $names, true ), true );
check( 'no credentials leaked',  preg_match( '/api_?key|Basic |X-Instance|raynetCredentials/i', $html ), 0 );

$custom = $form->render_shortcode( array( 'fields' => 'email,message', 'topic' => 'Ceník', 'button' => 'Poslat', 'redirect' => 'https://example.test/diky/' ) );
check( 'fields attr narrows the form', preg_match_all( '/name="(firstName|phone|topic)"/', $custom ), 0 );
check( 'fixed topic hidden field',     str_contains( $custom, 'name="raynet_fixed_topic" value="Ceník"' ), true );
check( 'button label applied',         str_contains( $custom, '>Poslat<' ), true );
check( 'redirect attr rendered',       str_contains( $custom, 'data-redirect="https://example.test/diky/"' ), true );

$bad = $form->render_shortcode( array( 'fields' => 'nonsense,<script>' ) );
check( 'unknown fields fall back safely', array( str_contains( $bad, 'name="email"' ), str_contains( $bad, '<script>' ) ), array( true, false ) );

// Both can be switched off, and then must disappear from the markup.
update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array(
	'region' => 'cz', 'username' => 'u@e.cz', 'api_key' => 'K', 'instance_name' => 'inst',
) ) );
$off = $form->render_shortcode( array() );
check( 'honeypot removable', str_contains( $off, 'name="website"' ), false );
check( 'consent removable',  str_contains( $off, 'name="consent"' ), false );

$ids = array();
foreach ( $xp->query( '//*[@id]' ) as $el ) { $ids[] = $el->getAttribute( 'id' ); }
check( 'ids are unique', count( $ids ) === count( array_unique( $ids ) ), true );

$two = $form->render_shortcode( array() ) . $form->render_shortcode( array() );
preg_match_all( '/id="(raynet-lead-form-\d+)"/', $two, $m );
check( 'two forms get distinct ids', count( array_unique( $m[1] ) ), 2 );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
