<?php
require __DIR__ . '/wp-stubs.php';

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = $got === $want;
	$ok ? $pass++ : $fail++;
	printf( "%s %s\n", $ok ? 'ok  ' : 'FAIL', $label );
	if ( ! $ok ) {
		echo "     got:  " . var_export( $got, true ) . "\n";
		echo "     want: " . var_export( $want, true ) . "\n";
	}
}
function call_private( $obj, $method, array $args ) {
	$r = new ReflectionMethod( $obj, $method );
	return $r->invokeArgs( $obj, $args );
}

// ---------- Settings ----------
check( 'default region resolves to CZ base URL', Raynet_Lead_Settings::api_base_url(), 'https://app.raynet.cz/api/v2/' );
check( 'not configured when empty', Raynet_Lead_Settings::is_configured(), false );

$clean = Raynet_Lead_Settings::sanitize( array(
	'region' => 'sk', 'username' => ' user@example.cz ', 'api_key' => 'SECRET1',
	'instance_name' => 'mujraynet', 'priority' => 'critical', 'timeout' => 900,
	'category' => '-4', 'notify_emails' => "a@b.cz, bad-address\nc@d.cz,a@b.cz",
	'min_fill_seconds' => 999, 'fallback_email' => 'not-an-email', 'lead_person' => '1',
) );
update_option( Raynet_Lead_Settings::OPTION, $clean );

check( 'region sk applied',       Raynet_Lead_Settings::api_base_url(), 'https://app.raynetcrm.sk/api/v2/' );
check( 'priority uppercased',     $clean['priority'], 'CRITICAL' );
check( 'timeout clamped to 60',   $clean['timeout'], 60 );
check( 'negative id clamped to 0',$clean['category'], 0 );
check( 'emails filtered + dedup', $clean['notify_emails'], 'a@b.cz,c@d.cz' );
check( 'min_fill clamped to 120', $clean['min_fill_seconds'], 120 );
check( 'invalid fallback dropped',$clean['fallback_email'], '' );
check( 'configured now',          Raynet_Lead_Settings::is_configured(), true );

$kept = Raynet_Lead_Settings::sanitize( array( 'region' => 'sk', 'username' => 'user@example.cz', 'api_key' => '', 'instance_name' => 'mujraynet' ) );
check( 'empty api key keeps stored secret', $kept['api_key'], 'SECRET1' );

$replaced = Raynet_Lead_Settings::sanitize( array( 'region' => 'sk', 'api_key' => 'SECRET2' ) );
check( 'new api key replaces stored one', $replaced['api_key'], 'SECRET2' );

check( 'unknown region falls back to cz', Raynet_Lead_Settings::sanitize( array( 'region' => 'mars' ) )['region'], 'cz' );

// ---------- Migration from 1.x ----------
$GLOBALS['wp_options'] = array(
	'raynet_username'      => 'legacy@example.cz',
	'raynet_api_key'       => 'LEGACYKEY',
	'raynet_instance_name' => 'legacyinstance',
	'raynet_custom_note'   => 'Přidáno z webu',
	'raynet_api_url'       => 'https://app.raynet.cz/api/v2/lead/',
);
Raynet_Lead_Settings::maybe_migrate();
check( 'legacy username migrated',  Raynet_Lead_Settings::get( 'username' ), 'legacy@example.cz' );
check( 'legacy key migrated',       Raynet_Lead_Settings::get( 'api_key' ), 'LEGACYKEY' );
check( 'legacy note migrated',      Raynet_Lead_Settings::get( 'notice_prefix' ), 'Přidáno z webu' );
check( 'legacy url mapped to cz',   Raynet_Lead_Settings::get( 'region' ), 'cz' );

$GLOBALS['wp_options'] = array( 'raynet_api_url' => 'https://crm.vlastni.cz/api/v2/lead/', 'raynet_username' => 'x@y.cz' );
Raynet_Lead_Settings::maybe_migrate();
check( 'unknown host becomes custom', Raynet_Lead_Settings::get( 'region' ), 'custom' );
check( 'custom url stripped to base', Raynet_Lead_Settings::get( 'custom_api_url' ), 'https://crm.vlastni.cz/api/v2/' );

// ---------- API client ----------
$client = new Raynet_Lead_Api_Client( array(
	'base_url' => 'https://app.raynet.cz/api/v2/', 'username' => 'u@e.cz',
	'api_key' => 'KEY', 'instance_name' => 'inst', 'timeout' => 15,
) );

$GLOBALS['wp_requests'] = array();
$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":42}}' );
$res = $client->create_lead( array( 'topic' => 'Test', 'priority' => 'DEFAULT' ) );
$req = $GLOBALS['wp_requests'][0];

check( 'create_lead uses PUT',            $req['args']['method'], 'PUT' );
check( 'create_lead hits /lead/',         $req['url'], 'https://app.raynet.cz/api/v2/lead/' );
check( 'basic auth header',               $req['args']['headers']['Authorization'], 'Basic ' . base64_encode( 'u@e.cz:KEY' ) );
check( 'instance name header',            $req['args']['headers']['X-Instance-Name'], 'inst' );
check( 'no instance id header',           isset( $req['args']['headers']['X-Instance-Id'] ), false );
check( '201 treated as success',          $res['data']['id'], 42 );

$client_id = new Raynet_Lead_Api_Client( array(
	'base_url' => 'https://app.raynet.cz/api/v2/', 'username' => 'u@e.cz',
	'api_key' => 'KEY', 'instance_name' => 'inst', 'instance_id' => 'abc123',
) );
$GLOBALS['wp_requests'] = array();
$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":1}}' );
$client_id->create_lead( array() );
$h = $GLOBALS['wp_requests'][0]['args']['headers'];
check( 'instance id wins over name', array( isset( $h['X-Instance-Id'] ), isset( $h['X-Instance-Name'] ) ), array( true, false ) );

$GLOBALS['wp_next_response'] = array( 'code' => 401, 'body' => '{"message":"nope"}' );
$err = $client->create_lead( array() );
check( '401 becomes WP_Error',   is_wp_error( $err ), true );
check( '401 error code',         $err->get_error_code(), 'raynet_http_401' );
check( '401 message is actionable', str_contains( $err->get_error_message(), '401' ), true );

$GLOBALS['wp_next_response'] = array( 'code' => 429, 'body' => '{"type":"RequestLimitReached"}' );
check( '429 becomes WP_Error',   is_wp_error( $client->create_lead( array() ) ), true );

$GLOBALS['wp_next_response'] = array( 'code' => 200, 'body' => '{"success":"true","data":[{"id":3,"code01":"Web"},{"id":7,"code01":"Telefon"}]}' );
check( 'code list reduced to id=>label', $client->get_code_list( 'leadCategory/' ), array( 3 => 'Web', 7 => 'Telefon' ) );

$unconfigured = new Raynet_Lead_Api_Client( array( 'base_url' => 'https://x/api/v2/', 'username' => 'a', 'api_key' => '' ) );
check( 'missing key short-circuits', $unconfigured->create_lead( array() )->get_error_code(), 'raynet_not_configured' );

// ---------- Payload ----------
$GLOBALS['wp_options'] = array();
update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array(
	'region' => 'cz', 'username' => 'u@e.cz', 'api_key' => 'K', 'instance_name' => 'inst',
	'priority' => 'DEFAULT', 'lead_person' => '1', 'notice_prefix' => 'Z webu example.cz',
	'category' => '5', 'contact_source' => '9', 'tags' => 'web,poptavka',
	'notify_emails' => 'sales@example.cz', 'consent_enabled' => '1',
) ) );

$form     = new Raynet_Lead_Form();
$settings = Raynet_Lead_Settings::all();

$values = call_private( $form, 'collect_values', array( array(
	'firstName' => ' Jan ', 'lastName' => 'Novák', 'email' => ' jan@example.cz ',
	'phone' => '+420 777 123 456', 'message' => "Mám zájem o cenovou nabídku.",
	'topic' => 'Poptávka', 'companyName' => '', 'street' => '', 'city' => '', 'zipCode' => '',
) ) );
check( 'first name trimmed', $values['firstName'], 'Jan' );
check( 'email trimmed',      $values['email'], 'jan@example.cz' );

$payload = call_private( $form, 'build_payload', array( $values, $settings, array(
	'raynet_source_url'  => 'https://example.test/kontakt/',
	'raynet_has_consent' => true,
	'raynet_extras'      => array( 'Odkud jste se o nás dozvěděli?' => 'Google' ),
) ) );

check( 'topic from form',        $payload['topic'], 'Poptávka' );
check( 'priority present',       $payload['priority'], 'DEFAULT' );
check( 'email mapped',           $payload['contactInfo']['email'], 'jan@example.cz' );
check( 'phone mapped to tel1',   $payload['contactInfo']['tel1'], '+420 777 123 456' );
check( 'empty address dropped',  isset( $payload['address'] ), false );
check( 'category from settings', $payload['category'], 5 );
check( 'contactSource mapped',   $payload['contactSource'], 9 );
check( 'owner not sent when 0',  isset( $payload['owner'] ), false );
check( 'tags sent',              $payload['tags'], 'web,poptavka' );
check( 'notify emails sent',     $payload['notificationEmailAddresses'], array( 'sales@example.cz' ) );
check( 'leadPerson true',        $payload['leadPerson'], true );
check( 'message inside notice',  str_contains( $payload['notice'], 'cenovou nabídku' ), true );
check( 'prefix inside notice',   str_contains( $payload['notice'], 'Z webu example.cz' ), true );
check( 'source url in notice',   str_contains( $payload['notice'], 'example.test/kontakt' ), true );
check( 'consent noted',          str_contains( $payload['notice'], 'Souhlas' ), true );
check( 'custom field noted',     str_contains( $payload['notice'], 'Odkud jste se o nás dozvěděli?: Google' ), true );

$no_consent = call_private( $form, 'build_payload', array( $values, $settings, array() ) );
check( 'no consent claimed without a consent field', str_contains( $no_consent['notice'], 'Souhlas' ), false );
check( 'leadDate is today',      $payload['leadDate'], date( 'Y-m-d' ) );

$company_values = $values;
$company_values['companyName'] = 'ACME s.r.o.';
$company_payload = call_private( $form, 'build_payload', array( $company_values, $settings, array() ) );
check( 'company flips leadPerson to false', $company_payload['leadPerson'], false );
check( 'companyName mapped',                $company_payload['companyName'], 'ACME s.r.o.' );

$empty_topic = call_private( $form, 'build_payload', array(
	array_merge( $values, array( 'topic' => '' ) ), $settings, array( 'raynet_fixed_topic' => 'Ceník' )
) );
check( 'fixed topic used when field empty', $empty_topic['topic'], 'Ceník' );

$no_topic = call_private( $form, 'build_payload', array( array_merge( $values, array( 'topic' => '' ) ), $settings, array() ) );
check( 'topic never empty', str_contains( $no_topic['topic'], 'Testovací web' ), true );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
