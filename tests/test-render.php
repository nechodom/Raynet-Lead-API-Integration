<?php
/**
 * Shortcode rendering: it must resolve a form, honour its definition,
 * and never leak credentials into the page.
 */

require __DIR__ . '/wp-stubs.php';

function wp_register_style( ...$a ) {}
function wp_register_script( ...$a ) {}
function wp_enqueue_style( ...$a ) {}
function wp_enqueue_script( ...$a ) {}
function wp_localize_script( ...$a ) {}
function admin_url( $p ) { return 'https://example.test/wp-admin/' . $p; }
function esc_url( $u ) { return htmlspecialchars( (string) $u, ENT_QUOTES ); }
function current_user_can( $cap ) { return $GLOBALS['wp_is_admin'] ?? true; }
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$out = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, (array) $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = $got === $want; $ok ? $pass++ : $fail++;
	printf( "%s %s\n", $ok ? 'ok  ' : 'FAIL', $label );
	if ( ! $ok ) { echo "     got: " . var_export( $got, true ) . "\n"; }
}

$GLOBALS['wp_is_admin'] = true;

// A form has to exist before the shortcode renders anything.
$fid = Raynet_Lead_Form_Post_Type::create( 'Kontakt', Raynet_Lead_Form_Definition::default_fields(), array() );
Raynet_Lead_Form_Post_Type::set_default( $fid );

$form = new Raynet_Lead_Form();
$html = $form->render_shortcode( array() );

$dom = new DOMDocument();
libxml_use_internal_errors( true );
$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
$errors = array_filter( libxml_get_errors(), fn( $e ) => $e->level >= LIBXML_ERR_ERROR );
libxml_clear_errors();

check( 'renders parseable HTML', count( $errors ), 0 );

$xp    = new DOMXPath( $dom );
$names = array();
foreach ( $xp->query( '//input | //textarea | //button | //select' ) as $el ) {
	$names[] = $el->getAttribute( 'name' ) ?: ( '<' . $el->tagName . '>' );
}

check( 'default form fields present',
	array_values( array_filter( $names, fn( $n ) => in_array( $n, array( 'firstName', 'lastName', 'email', 'phone', 'message' ), true ) ) ),
	array( 'firstName', 'lastName', 'email', 'phone', 'message' ) );
check( 'nonce field present',    in_array( 'raynet_nonce', $names, true ), true );
check( 'form id carried',        str_contains( $html, 'name="raynet_form_id" value="' . $fid . '"' ), true );
check( 'timestamp pair present', array( in_array( 'raynet_ts', $names, true ), in_array( 'raynet_ts_hash', $names, true ) ), array( true, true ) );
check( 'honeypot present',       in_array( 'website', $names, true ), true );
check( 'consent present',        in_array( 'consent', $names, true ), true );
check( 'no credentials leaked',  preg_match( '/api_?key|Basic |X-Instance|raynetCredentials/i', $html ), 0 );

// Lookup by slug and by numeric id.
check( 'resolved by slug',   str_contains( $form->render_shortcode( array( 'id' => 'kontakt' ) ), 'name="raynet_form_id"' ), true );
check( 'resolved by number', str_contains( $form->render_shortcode( array( 'id' => (string) $fid ) ), 'name="raynet_form_id"' ), true );

// Legacy attributes still filter the resolved form.
$narrowed = $form->render_shortcode( array( 'fields' => 'email,message' ) );
check( 'fields= drops others',    preg_match_all( '/name="(firstName|lastName|phone)"/', $narrowed ), 0 );
check( 'fields= keeps email',     str_contains( $narrowed, 'name="email"' ), true );
check( 'fields= keeps consent',   str_contains( $narrowed, 'name="consent"' ), true );

$req = $form->render_shortcode( array( 'required' => 'phone' ) );
check( 'required= promotes phone', (bool) preg_match( '/name="phone"[^>]*required/', $req ), true );
check( 'required= demotes email',  (bool) preg_match( '/name="email"[^>]*required/', $req ), false );

$bad = $form->render_shortcode( array( 'fields' => 'nonsense,<script>' ) );
check( 'unknown fields ignore the filter', str_contains( $bad, 'name="email"' ), true );
check( 'no script injected',               str_contains( $bad, '<script>' ), false );

// Shortcode options.
$custom = $form->render_shortcode( array( 'topic' => 'Ceník', 'button' => 'Poslat', 'redirect' => 'https://example.test/diky/' ) );
check( 'fixed topic hidden field', str_contains( $custom, 'name="raynet_fixed_topic" value="Ceník"' ), true );
check( 'button label applied',     str_contains( $custom, '>Poslat<' ), true );
check( 'redirect attr rendered',   str_contains( $custom, 'data-redirect="https://example.test/diky/"' ), true );

// Missing form: nothing for visitors, a pointer for admins.
$GLOBALS['wp_is_admin'] = false;
check( 'unknown id renders nothing for a visitor', trim( $form->render_shortcode( array( 'id' => 'neexistuje' ) ) ), '' );
$GLOBALS['wp_is_admin'] = true;
$notice = $form->render_shortcode( array( 'id' => 'neexistuje' ) );
check( 'admin is told which id failed', str_contains( $notice, 'neexistuje' ), true );
check( 'admin notice is not a form',    str_contains( $notice, '<form' ), false );

// Unique ids across two renders on one page.
$ids = array();
foreach ( $xp->query( '//*[@id]' ) as $el ) { $ids[] = $el->getAttribute( 'id' ); }
check( 'ids are unique', count( $ids ) === count( array_unique( $ids ) ), true );

$two = $form->render_shortcode( array() ) . $form->render_shortcode( array() );
preg_match_all( '/id="(raynet-lead-form-\d+)"/', $two, $m );
check( 'two forms get distinct ids', count( array_unique( $m[1] ) ), 2 );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
