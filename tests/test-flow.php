<?php
require __DIR__ . '/wp-stubs.php';

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = $got === $want; $ok ? $pass++ : $fail++;
	printf( "%s %s\n", $ok ? 'ok  ' : 'FAIL', $label );
	if ( ! $ok ) { echo "     got:  " . var_export( $got, true ) . "\n     want: " . var_export( $want, true ) . "\n"; }
}
function process( Raynet_Lead_Form $form, array $input ) {
	$m = new ReflectionMethod( $form, 'process' );
	return $m->invoke( $form, $input );
}
function code( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'OK'; }

function configure( array $overrides = array() ) {
	$GLOBALS['wp_options']    = array();
	$GLOBALS['wp_transients'] = array();
	$GLOBALS['wp_mails']      = array();
	$GLOBALS['wp_requests']   = array();
	update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array_merge( array(
		'region' => 'cz', 'username' => 'u@e.cz', 'api_key' => 'K', 'instance_name' => 'inst',
		'consent_enabled' => '1', 'honeypot_enabled' => '1', 'min_fill_seconds' => '3',
		'throttle_seconds' => '20',
	), $overrides ) ) );
}

function valid_input( array $overrides = array() ) {
	$stamp = time() - 10;
	return array_merge( array(
		'raynet_nonce'   => wp_create_nonce( Raynet_Lead_Form::NONCE_ACTION ),
		'raynet_ts'      => (string) $stamp,
		'raynet_ts_hash' => wp_hash( 'raynet_lead_ts|' . $stamp ),
		'consent'        => '1',
		'email'          => 'jan@example.cz',
		'message'        => 'Dobrý den, mám zájem.',
		'topic'          => 'Poptávka',
	), $overrides );
}

$form = new Raynet_Lead_Form();

// Happy path.
configure();
$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":77}}' );
$r = process( $form, valid_input() );
check( 'valid submission succeeds', code( $r ), 'OK' );
check( 'one API call made', count( $GLOBALS['wp_requests'] ), 1 );
$sent = json_decode( $GLOBALS['wp_requests'][0]['args']['body'], true );
check( 'payload carries email', $sent['contactInfo']['email'], 'jan@example.cz' );
check( 'success message returned', str_contains( $r['message'], 'úspěšně' ), true );

// Nonce.
configure();
check( 'bad nonce rejected', code( process( $form, valid_input( array( 'raynet_nonce' => 'forged' ) ) ) ), 'raynet_nonce_expired' );
check( 'missing nonce rejected', code( process( $form, array() ) ), 'raynet_nonce_expired' );
check( 'no API call on bad nonce', count( $GLOBALS['wp_requests'] ), 0 );

// Honeypot.
configure();
check( 'honeypot trips', code( process( $form, valid_input( array( 'website' => 'http://spam' ) ) ) ), 'raynet_spam' );
check( 'honeypot blocks API call', count( $GLOBALS['wp_requests'] ), 0 );

// Time trap.
configure();
$now = time();
check( 'too fast rejected', code( process( $form, valid_input( array(
	'raynet_ts' => (string) $now, 'raynet_ts_hash' => wp_hash( 'raynet_lead_ts|' . $now ),
) ) ) ), 'raynet_too_fast' );

configure();
check( 'forged timestamp rejected', code( process( $form, valid_input( array(
	'raynet_ts' => (string) ( time() - 600 ), 'raynet_ts_hash' => 'bogus',
) ) ) ), 'raynet_invalid_form' );

configure();
$old = time() - ( DAY_IN_SECONDS + 60 );
check( 'day-old form rejected', code( process( $form, valid_input( array(
	'raynet_ts' => (string) $old, 'raynet_ts_hash' => wp_hash( 'raynet_lead_ts|' . $old ),
) ) ) ), 'raynet_too_fast' );

// Consent now follows the form definition, not a global switch.
configure();
$consent_form = Raynet_Lead_Form_Post_Type::create( 'Se souhlasem', Raynet_Lead_Form_Definition::default_fields(), array() );
check( 'missing consent rejected',
	code( process( $form, valid_input( array( 'raynet_form_id' => (string) $consent_form, 'consent' => '' ) ) ) ),
	'raynet_consent_required' );

configure();
$plain_form = Raynet_Lead_Form_Post_Type::create( 'Bez souhlasu', Raynet_Lead_Form_Definition::sanitize_fields( array(
	array( 'source' => 'email', 'required' => true ),
	array( 'source' => 'message', 'required' => true ),
) ), array() );
$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":1}}' );
check( 'consent optional when the form has no consent field',
	code( process( $form, valid_input( array( 'raynet_form_id' => (string) $plain_form, 'consent' => '' ) ) ) ),
	'OK' );

// Contact validation.
configure();
check( 'no email nor phone rejected', code( process( $form, valid_input( array( 'email' => '', 'message' => 'x' ) ) ) ), 'raynet_contact_required' );
configure();
check( 'malformed email rejected', code( process( $form, valid_input( array( 'email' => 'jan[at]example' ) ) ) ), 'raynet_invalid_email' );
configure();
$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":1}}' );
check( 'phone alone is enough', code( process( $form, valid_input( array( 'email' => '', 'phone' => '777123456' ) ) ) ), 'OK' );

// A typo must not start the cool down.
configure();
process( $form, valid_input( array( 'email' => 'nope' ) ) );
$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":2}}' );
check( 'retry after typo is allowed', code( process( $form, valid_input() ) ), 'OK' );

// Throttle after a real send.
configure();
$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":3}}' );
process( $form, valid_input() );
check( 'second send throttled', code( process( $form, valid_input() ) ), 'raynet_throttled' );
configure( array( 'throttle_seconds' => '0' ) );
$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":4}}' );
process( $form, valid_input() );
$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":5}}' );
check( 'throttle off allows repeat', code( process( $form, valid_input() ) ), 'OK' );

// Honeypot hit does start the cool down.
configure();
process( $form, valid_input( array( 'website' => 'spam' ) ) );
check( 'bot gets throttled', code( process( $form, valid_input() ) ), 'raynet_throttled' );

// API failure path.
configure( array( 'fallback_email' => 'zaloha@example.cz', 'error_message' => 'Nepovedlo se.' ) );
$GLOBALS['wp_next_response'] = array( 'code' => 401, 'body' => '{"message":"bad creds"}' );
$r = process( $form, valid_input() );
check( 'api failure surfaces generic error', code( $r ), 'raynet_api_error' );
check( 'visitor sees custom message', $r->get_error_message(), 'Nepovedlo se.' );
check( 'visitor never sees 401 detail', str_contains( $r->get_error_message(), '401' ), false );
check( 'fallback email sent', count( $GLOBALS['wp_mails'] ), 1 );
check( 'fallback carries the lead', str_contains( $GLOBALS['wp_mails'][0]['body'], 'jan@example.cz' ), true );
check( 'last error stored for admin', str_contains( get_option( 'raynet_lead_last_error' )['message'], '401' ), true );

// Unconfigured plugin.
$GLOBALS['wp_options'] = array(); $GLOBALS['wp_transients'] = array(); $GLOBALS['wp_mails'] = array(); $GLOBALS['wp_requests'] = array();
update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array( 'consent_enabled' => '', 'min_fill_seconds' => '3' ) ) );
check( 'unconfigured plugin refuses', code( process( $form, valid_input( array( 'consent' => '' ) ) ) ), 'raynet_not_configured' );
check( 'unconfigured makes no API call', count( $GLOBALS['wp_requests'] ), 0 );

// Oversized input is truncated, not rejected.
configure( array( 'throttle_seconds' => '0' ) );
$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":9}}' );
process( $form, valid_input( array( 'message' => str_repeat( 'á', 9000 ), 'topic' => str_repeat( 'b', 900 ) ) ) );
$sent = json_decode( $GLOBALS['wp_requests'][0]['args']['body'], true );
check( 'topic capped at 255', mb_strlen( $sent['topic'] ), 255 );
check( 'message capped at 5000', mb_strlen( $sent['notice'] ) <= 10000, true );

// Custom fields land in the lead note under their label.
configure();
$custom_fields = Raynet_Lead_Form_Definition::sanitize_fields( array(
	array( 'source' => 'email', 'required' => true ),
	array( 'source' => 'message', 'required' => true ),
	array( 'source' => 'custom', 'type' => 'text', 'label' => 'Odkud jste se o nás dozvěděli?' ),
	array( 'source' => 'custom', 'type' => 'checkbox', 'label' => 'Chci newsletter' ),
	array( 'source' => 'consent' ),
) );
$custom_form = Raynet_Lead_Form_Post_Type::create( 'S vlastními poli', $custom_fields, array() );

$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":9}}' );
$r = process( $form, valid_input( array(
	'raynet_form_id' => (string) $custom_form,
	'raynet_custom'  => array( $custom_fields[2]['id'] => 'Google' ),
	'consent'        => '1',
) ) );
check( 'odesláno s vlastními poli', code( $r ), 'OK' );
$sent = json_decode( $GLOBALS['wp_requests'][0]['args']['body'], true );
check( 'vlastní pole v poznámce',   str_contains( $sent['notice'], 'Odkud jste se o nás dozvěděli?: Google' ), true );
check( 'nezaškrtnuté zaškrtávátko je odpověď', str_contains( $sent['notice'], 'Chci newsletter: ne' ), true );
check( 'souhlas v poznámce',        str_contains( $sent['notice'], 'Souhlas' ), true );

// A field the form does not declare cannot be smuggled in.
configure();
$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":10}}' );
process( $form, valid_input( array(
	'raynet_form_id' => (string) $custom_form,
	'consent'        => '1',
	'firstName'      => 'Podvrh',
	'companyName'    => 'Podvržená s.r.o.',
	'raynet_custom'  => array( 'f_zzzzzz' => 'cizí pole' ),
) ) );
$spoof = json_decode( $GLOBALS['wp_requests'][0]['args']['body'], true );
check( 'nedeklarované jméno se zahodí',  isset( $spoof['firstName'] ), false );
check( 'nedeklarovaná firma se zahodí',  isset( $spoof['companyName'] ), false );
check( 'cizí vlastní pole se zahodí',    str_contains( $spoof['notice'], 'cizí pole' ), false );

// Per-form lead settings win over the global ones.
configure( array( 'category' => '1', 'priority' => 'DEFAULT', 'tags' => 'globalni' ) );
$tuned = Raynet_Lead_Form_Post_Type::create( 'Ceník', Raynet_Lead_Form_Definition::sanitize_fields( array(
	array( 'source' => 'email', 'required' => true ),
) ), array( 'category' => '5', 'owner' => '7', 'priority' => 'CRITICAL', 'tags' => 'cenik' ) );

$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":11}}' );
process( $form, valid_input( array( 'raynet_form_id' => (string) $tuned, 'consent' => '' ) ) );
$tuned_payload = json_decode( $GLOBALS['wp_requests'][0]['args']['body'], true );
check( 'kategorie z formuláře', $tuned_payload['category'], 5 );
check( 'vlastník z formuláře',  $tuned_payload['owner'], 7 );
check( 'priorita z formuláře',  $tuned_payload['priority'], 'CRITICAL' );
check( 'štítky z formuláře',    $tuned_payload['tags'], 'cenik' );

// A form that inherits keeps the global values.
configure( array( 'category' => '1', 'tags' => 'globalni' ) );
$inherit = Raynet_Lead_Form_Post_Type::create( 'Dědí', Raynet_Lead_Form_Definition::sanitize_fields( array(
	array( 'source' => 'email', 'required' => true ),
) ), array() );
$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":12}}' );
process( $form, valid_input( array( 'raynet_form_id' => (string) $inherit, 'consent' => '' ) ) );
$inherited = json_decode( $GLOBALS['wp_requests'][0]['args']['body'], true );
check( 'kategorie zděděna', $inherited['category'], 1 );
check( 'štítky zděděny',    $inherited['tags'], 'globalni' );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
