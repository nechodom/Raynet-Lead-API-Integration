<?php
/**
 * Integration suite: the plugin against a real WordPress.
 *
 * Run through wp-cli so WordPress is loaded, then drive the site over HTTP the
 * way a browser would. This covers what the stubbed suite cannot reach — hook
 * order, capability mapping, the admin menu, asset enqueueing and real requests.
 *
 * Usage: bin/wp-test.sh
 *
 * @package RaynetLeadApiIntegration
 */

// wp-cli's eval-file runs this inside a function, so everything the helpers
// reach for through `global` has to be declared global here too.
global $base, $jar, $pass, $fail;

$base = getenv( 'RAYNET_TEST_URL' ) ? getenv( 'RAYNET_TEST_URL' ) : 'http://localhost:8765';
$jar  = sys_get_temp_dir() . '/raynet-test-cookies.txt';
$pass = 0;
$fail = 0;

/**
 * Records one assertion.
 *
 * @param string $label Description.
 * @param mixed  $got   Actual value.
 * @param mixed  $want  Expected value.
 * @return void
 */
function check( $label, $got, $want ) {
	global $pass, $fail;

	$ok = $got === $want;
	$ok ? $pass++ : $fail++;

	printf( "%s %s\n", $ok ? 'ok  ' : 'FAIL', $label );

	if ( ! $ok ) {
		echo '     got:  ' . var_export( $got, true ) . "\n";
		echo '     want: ' . var_export( $want, true ) . "\n";
	}
}

/**
 * Performs an HTTP request against the test site.
 *
 * @param string     $path    Path, or a full URL.
 * @param array|null $post    POST fields, or null for GET.
 * @param bool       $as_user Send the admin's cookies.
 * @return array{status:int,body:string} Response.
 */
function req( $path, $post = null, $as_user = false ) {
	global $base, $jar;

	$ch = curl_init( 0 === strpos( $path, 'http' ) ? $path : $base . $path );

	curl_setopt_array(
		$ch,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT        => 30,
		)
	);

	if ( $as_user ) {
		curl_setopt( $ch, CURLOPT_COOKIEFILE, $jar );
		curl_setopt( $ch, CURLOPT_COOKIEJAR, $jar );
	}

	if ( null !== $post ) {
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, http_build_query( $post ) );
	}

	$body   = (string) curl_exec( $ch );
	$status = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );

	return array( 'status' => $status, 'body' => $body );
}

/**
 * Logs the admin in and stores the cookies.
 *
 * @return bool True when the login produced an auth cookie.
 */
function login() {
	global $base, $jar;

	@unlink( $jar ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Absent on a first run.

	req( '/wp-login.php', null, true );

	$response = req(
		'/wp-login.php',
		array(
			'log'         => 'admin',
			'pwd'         => 'admin',
			'wp-submit'   => 'Log In',
			'redirect_to' => $base . '/wp-admin/',
			'testcookie'  => '1',
		),
		true
	);

	return 302 === $response['status'];
}

/**
 * Pulls one input's value out of rendered markup.
 *
 * @param string $html Markup.
 * @param string $name Input name.
 * @return string Value, or an empty string.
 */
function field_value( $html, $name ) {
	$found = preg_match( '/name="' . preg_quote( $name, '/' ) . '"[^>]*value="([^"]*)"/', $html, $m )
		|| preg_match( '/value="([^"]*)"[^>]*name="' . preg_quote( $name, '/' ) . '"/', $html, $m );

	return $found ? html_entity_decode( $m[1], ENT_QUOTES ) : '';
}

echo "=== Zapojení a oprávnění ===\n";

check( 'plugin je aktivní', is_plugin_active( 'raynet-lead-api-integration/raynet-lead-api-integration.php' ), true );
check( 'typ obsahu registrován', post_type_exists( 'raynet_form' ), true );

// The 2.2.2 regression: naming manage_options for the meta capabilities turned
// it into a meta capability site wide, so every check of it answered false.
wp_set_current_user( 1 );
check( 'manage_options funguje', current_user_can( 'manage_options' ), true );
check( 'jiná oprávnění netknuta', current_user_can( 'edit_posts' ), true );

$meta_caps = isset( $GLOBALS['post_type_meta_caps'] ) ? $GLOBALS['post_type_meta_caps'] : array();
check( 'manage_options není meta capabilita', isset( $meta_caps['manage_options'] ), false );

$default = (int) get_option( 'raynet_lead_default_form' );
check( 'výchozí formulář existuje', $default > 0, true );
check( 'výchozí formulář má pole', count( Raynet_Lead_Form_Post_Type::get_fields( $default ) ), 7 );

echo "\n=== Administrace ===\n";

check( 'přihlášení proběhlo', login(), true );

$dashboard = req( '/wp-admin/', null, true );
check( 'nástěnka se načte', $dashboard['status'], 200 );
check( 'menu obsahuje RAYNET CRM', false !== strpos( $dashboard['body'], 'RAYNET CRM' ), true );
check( 'menu obsahuje Formuláře', false !== strpos( $dashboard['body'], '>Formuláře<' ), true );
check( 'menu obsahuje Nastavení', false !== strpos( $dashboard['body'], '>Nastavení<' ), true );

$settings = req( '/wp-admin/admin.php?page=raynet-lead-integration', null, true );
check( 'nastavení se načte', $settings['status'], 200 );
check( 'nastavení není odepřeno', false !== stripos( $settings['body'], 'not allowed' ), false );
check( 'nastavení má nadpis', false !== strpos( $settings['body'], 'RAYNET Lead API Integration' ), true );
check( 'tlačítko testu spojení', false !== strpos( $settings['body'], 'id="raynet-test-connection"' ), true );
check( 'tlačítko kontroly aktualizací', false !== strpos( $settings['body'], 'id="raynet-check-update"' ), true );
check( 'skript administrace načten', false !== strpos( $settings['body'], 'raynet-lead-admin.js' ), true );

$elm_screen = req( '/wp-admin/admin.php?page=raynet-elementor-forms', null, true );
check( 'obrazovka Elementor formulářů se načte', $elm_screen['status'], 200 );
check( 'obrazovka má styl', false !== strpos( $elm_screen['body'], 'raynet-lead-admin.css' ), true );
check( 'obrazovka má tabulku', false !== strpos( $elm_screen['body'], 'raynet-elm__forms' ), true );
check( 'obrazovka nabízí šablonu', false !== strpos( $elm_screen['body'], 'template_name' ), true );

$list = req( '/wp-admin/edit.php?post_type=raynet_form', null, true );
check( 'seznam formulářů se načte', $list['status'], 200 );
check( 'seznam ukazuje zkratku', false !== strpos( $list['body'], 'raynet_lead_form id=' ), true );

$edit = req( '/wp-admin/post.php?post=' . $default . '&action=edit', null, true );
check( 'builder se načte', $edit['status'], 200 );
check( 'builder má seznam polí', false !== strpos( $edit['body'], 'id="raynet-builder-list"' ), true );
check( 'builder má náhled', false !== strpos( $edit['body'], 'id="raynet-builder-preview"' ), true );
check( 'builder má skryté JSON', false !== strpos( $edit['body'], 'id="raynet-form-fields-json"' ), true );
check( 'builder načítá svůj skript', false !== strpos( $edit['body'], 'raynet-form-builder.js' ), true );
check( 'builder načítá svůj styl', false !== strpos( $edit['body'], 'raynet-form-builder.css' ), true );
check( 'builder je lokalizovaný', false !== strpos( $edit['body'], 'raynetFormBuilder' ), true );

// The consent label promises a link to the privacy policy, so a real save must
// keep the <a> while stripping script and event-handler markup; every other
// label stays plain text.
$consent_probe = array(
	array( 'source' => 'firstName', 'label' => 'Jméno <a href="/x">odkaz</a>' ),
	array( 'source' => 'consent', 'label' => 'Souhlasím se <a href="/gdpr" target="_blank" rel="noopener" onclick="steal()">zásadami</a>.<script>evil()</script>' ),
);
$fields_backup = get_post_meta( $default, Raynet_Lead_Form_Post_Type::META_FIELDS, true );
Raynet_Lead_Form_Post_Type::save_fields( $default, $consent_probe );
$saved_fields = Raynet_Lead_Form_Post_Type::get_fields( $default );
update_post_meta( $default, Raynet_Lead_Form_Post_Type::META_FIELDS, $fields_backup );

$saved_by_source = array_column( $saved_fields, 'label', 'source' );
$saved_consent   = isset( $saved_by_source['consent'] ) ? $saved_by_source['consent'] : '';

check( 'uložený souhlas nese odkaz',  false !== strpos( $saved_consent, '<a href="/gdpr"' ), true );
check( 'uložený souhlas bez onclick', false !== strpos( $saved_consent, 'onclick' ), false );
check( 'uložený souhlas bez script',  false !== strpos( $saved_consent, '<script' ), false );
check( 'jiné uložené pole bez HTML',  isset( $saved_by_source['firstName'] ) ? $saved_by_source['firstName'] : '', 'Jméno odkaz' );

// The preview is rendered into the post edit form, so its controls must not
// take part in it: a required one blocks Update, a named one is saved with the
// post.
preg_match( '/"previewNonce":"([^"]+)"/', $edit['body'], $nonce_match );
check( 'nonce náhledu je na stránce', isset( $nonce_match[1] ), true );

$preview_call = req(
	'/wp-admin/admin-ajax.php',
	array(
		'action' => 'raynet_form_preview',
		'nonce'  => isset( $nonce_match[1] ) ? $nonce_match[1] : '',
		'fields' => wp_json_encode( Raynet_Lead_Form_Post_Type::get_fields( $default ) ),
	),
	true
);

$preview_json = json_decode( $preview_call['body'], true );
$preview_html = isset( $preview_json['data']['html'] ) ? $preview_json['data']['html'] : '';

check( 'náhled se vykreslí', '' !== $preview_html, true );
check( 'náhled nese popisky', false !== strpos( $preview_html, 'raynet-lead-form__row' ), true );
check( 'náhled nemá required', (bool) preg_match( '/\srequired[\s>\/]/', $preview_html ), false );
check( 'náhled nemá name', false !== strpos( $preview_html, 'name="' ), false );
check( 'náhled je disabled', false !== strpos( $preview_html, 'disabled' ), true );

echo "\n=== Veřejný formulář ===\n";

$page_id = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Integrační test',
		'post_status'  => 'publish',
		'post_content' => '[raynet_lead_form]',
	)
);

$front = req( '/?page_id=' . $page_id );
check( 'stránka se načte', $front['status'], 200 );
check( 'formulář vykreslen', false !== strpos( $front['body'], 'raynet-lead-form' ), true );
check( 'nese ID formuláře', false !== strpos( $front['body'], 'name="raynet_form_id"' ), true );
check( 'nese nonce', false !== strpos( $front['body'], 'name="raynet_nonce"' ), true );
check( 'nese honeypot', false !== strpos( $front['body'], 'name="website"' ), true );

foreach ( array( 'firstName', 'lastName', 'email', 'phone', 'topic', 'message', 'consent' ) as $field ) {
	check( 'pole ' . $field . ' vykresleno', false !== strpos( $front['body'], 'name="' . $field . '"' ), true );
}

check( 'žádné přihlašovací údaje v HTML', (bool) preg_match( '/api_?key|Basic [A-Za-z0-9+\/=]{8}|X-Instance/i', $front['body'] ), false );

$missing_page = (int) wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_title'   => 'Neexistující formulář',
		'post_status'  => 'publish',
		'post_content' => '[raynet_lead_form id="tenhle-neexistuje"]',
	)
);

$missing = req( '/?page_id=' . $missing_page );
check( 'neznámý formulář nevykreslí formulář', false !== strpos( $missing['body'], '<form' ), false );
check( 'návštěvník nevidí hlášku', false !== strpos( $missing['body'], 'raynet-lead-form__admin-notice' ), false );

echo "\n=== Odeslání ===\n";

update_option( 'raynet_test_http_calls', array() );

$settings_row = Raynet_Lead_Settings::all();
$settings_row['username']      = 'test@example.cz';
$settings_row['api_key']       = 'TESTKEY';
$settings_row['instance_name'] = 'testinstance';
$settings_row['throttle_seconds'] = 0;
// Set explicitly rather than relying on the default: a previous run leaves its
// own value behind in this database.
$settings_row['min_fill_seconds'] = 3;
update_option( Raynet_Lead_Settings::OPTION, $settings_row );

$form_html = req( '/?page_id=' . $page_id )['body'];

$submission = array(
	'action'         => 'raynet_lead_submit',
	'raynet_nonce'   => field_value( $form_html, 'raynet_nonce' ),
	'raynet_form_id' => field_value( $form_html, 'raynet_form_id' ),
	'raynet_ts'      => field_value( $form_html, 'raynet_ts' ),
	'raynet_ts_hash' => field_value( $form_html, 'raynet_ts_hash' ),
	'email'          => 'jan@example.cz',
	'message'        => 'Integrační test.',
	'consent'        => '1',
);

check( 'nonce vytažen z formuláře', '' !== $submission['raynet_nonce'], true );

// The time trap should refuse a submission that arrives instantly.
$fast = json_decode( req( '/wp-admin/admin-ajax.php', $submission )['body'], true );
check( 'časová past odmítne okamžité odeslání', isset( $fast['data']['code'] ) ? $fast['data']['code'] : '', 'raynet_too_fast' );

$settings_row['min_fill_seconds'] = 0;
update_option( Raynet_Lead_Settings::OPTION, $settings_row );

$ok = json_decode( req( '/wp-admin/admin-ajax.php', $submission )['body'], true );
check( 'odeslání přijato', isset( $ok['success'] ) ? $ok['success'] : null, true );

// The submissions happened in another process, so this one's object cache still
// holds the empty array written above.
wp_cache_flush();

$calls = get_option( 'raynet_test_http_calls', array() );
$lead  = null;

foreach ( $calls as $call ) {
	if ( false !== strpos( $call['url'], '/lead/' ) ) {
		$lead = $call;
	}
}

check( 'RAYNET byl zavolán', null !== $lead, true );

if ( $lead ) {
	check( 'metoda je PUT', $lead['method'], 'PUT' );

	$payload = json_decode( $lead['body'], true );

	check( 'payload nese e-mail', isset( $payload['contactInfo']['email'] ) ? $payload['contactInfo']['email'] : '', 'jan@example.cz' );
	check( 'payload nese předmět', ! empty( $payload['topic'] ), true );
	check( 'payload nese prioritu', ! empty( $payload['priority'] ), true );
	check( 'zpráva v poznámce', false !== strpos( (string) $payload['notice'], 'Integrační test.' ), true );
	check( 'souhlas v poznámce', false !== strpos( (string) $payload['notice'], 'Souhlas' ), true );
}

// Without the consent box ticked the submission must not reach RAYNET.
update_option( 'raynet_test_http_calls', array() );
$no_consent = $submission;
unset( $no_consent['consent'] );

$refused = json_decode( req( '/wp-admin/admin-ajax.php', $no_consent )['body'], true );
check( 'bez souhlasu odmítnuto', isset( $refused['data']['code'] ) ? $refused['data']['code'] : '', 'raynet_consent_required' );

wp_cache_flush();
check( 'bez souhlasu se nevolá RAYNET', count( get_option( 'raynet_test_http_calls', array() ) ), 0 );

// The REST route, as a headless front end calls it: anonymous, no cookie.
$rest_submission = $submission;
unset( $rest_submission['action'] );

$rest_ok   = req( '/?rest_route=/raynet-lead/v1/submit', $rest_submission );
$rest_json = json_decode( $rest_ok['body'], true );
check( 'REST přijme odeslání', $rest_ok['status'], 200 );
check( 'REST vrátí lead', ! empty( $rest_json['data']['lead_id'] ), true );

// RAYNET refuses the lead. The visitor gets the configured wording and
// nothing else: the reason is for administrators (log, last error, e-mail).
update_option( 'raynet_test_refuse_leads', 1 );
$rest_failed = req( '/?rest_route=/raynet-lead/v1/submit', $rest_submission );
$rest_json   = json_decode( $rest_failed['body'], true );
wp_cache_flush();
$last_error  = get_option( 'raynet_lead_last_error' );
$diagnostic  = is_array( $last_error ) && isset( $last_error['message'] ) ? (string) $last_error['message'] : '';
check( 'REST odmítnutí je 400', $rest_failed['status'], 400 );
check( 'REST odmítnutí má kód', isset( $rest_json['code'] ) ? $rest_json['code'] : '', 'raynet_api_error' );
check( 'důvod odmítnutí je zapsaný pro správce', '' !== $diagnostic, true );
// Compared decoded: WordPress escapes the diacritics in JSON.
check( 'REST neprozradí důvod návštěvníkovi', '' !== $diagnostic && false !== strpos( (string) wp_json_encode( $rest_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $diagnostic ), false );
check( 'REST nemá další data chyby', isset( $rest_json['additional_data'] ) || isset( $rest_json['additional_errors'][0]['data']['diagnostic'] ), false );
check( 'REST data jen se stavem', isset( $rest_json['data'] ) ? $rest_json['data'] : null, array( 'status' => 400 ) );

$ajax_failed = json_decode( req( '/wp-admin/admin-ajax.php', $submission )['body'], true );
check( 'AJAX odmítnutí má kód', isset( $ajax_failed['data']['code'] ) ? $ajax_failed['data']['code'] : '', 'raynet_api_error' );
check( 'AJAX neprozradí důvod návštěvníkovi', '' !== $diagnostic && false !== strpos( (string) wp_json_encode( $ajax_failed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $diagnostic ), false );
delete_option( 'raynet_test_refuse_leads' );
delete_option( 'raynet_lead_last_error' );

echo "\n=== Aktualizace ===\n";

delete_site_transient( 'raynet_lead_latest_release' );

$updater   = new Raynet_Lead_Updater();
$transient = new stdClass();
$transient->response  = array();
$transient->no_update = array();

$result   = $updater->inject_update( $transient );
$basename = 'raynet-lead-api-integration/raynet-lead-api-integration.php';

check( 'aktualizace nabídnuta', isset( $result->response[ $basename ] ), true );

if ( isset( $result->response[ $basename ] ) ) {
	check( 'nabídnutá verze', $result->response[ $basename ]->new_version, '99.0.0' );
	check( 'balíček z GitHubu', false !== strpos( $result->response[ $basename ]->package, 'github.com' ), true );
}

echo "\n=== Elementor Pro Forms ===\n";

if ( ! class_exists( '\\ElementorPro\\Plugin' ) || ! class_exists( '\\ElementorPro\\Modules\\Forms\\Classes\\Action_Base' ) ) {
	echo "     přeskočeno, Elementor Pro není nainstalované\n";
} else {
	$forms  = \ElementorPro\Plugin::instance()->modules_manager->get_modules( 'forms' );
	$action = $forms->actions_registrar->get( 'raynet_crm' );

	check( 'akce je zaregistrovaná', null !== $action && is_object( $action ), true );

	if ( is_object( $action ) ) {
		check( 'akce je Action_Base', $action instanceof \ElementorPro\Modules\Forms\Classes\Action_Base, true );
		check( 'název akce', $action->get_name(), 'raynet_crm' );
		check( 'popisek akce', $action->get_label(), 'RAYNET CRM' );

		// The mapping control must declare remote_label and remote_type on its
		// repeater. Elementor drops any key of a static default that has no
		// matching control, and its editor view then reads remote_type as
		// undefined and filters every form field out of the dropdown, leaving
		// rows titled "Item #1" over an empty list.
		$form_widget = \Elementor\Plugin::instance()->widgets_manager->get_widget_types( 'form' );
		check( 'widget formuláře existuje', null !== $form_widget, true );

		if ( $form_widget ) {
			$map_control = $form_widget->get_controls( 'raynet_crm_fields_map' );

			check( 'mapovací control zaregistrován', null !== $map_control, true );

			if ( $map_control ) {
				check( 'typ controlu', $map_control['type'], 'fields_map' );

				$repeater_keys = isset( $map_control['fields'] ) ? array_keys( $map_control['fields'] ) : array();

				foreach ( array( 'remote_id', 'remote_label', 'remote_type', 'local_id' ) as $needed ) {
					check( 'repeater zná ' . $needed, in_array( $needed, $repeater_keys, true ), true );
				}

				// The whole-name row has to be offered by hand as well as by the
				// bulk screen, or a single name field cannot be split there.
				check( 'nabízí i celé jméno', in_array( 'fullName', array_column( $map_control['default'], 'remote_id' ), true ), true );
				check( 'nabízí všechny atributy', array_column( $map_control['default'], 'remote_id' ), array_column( Raynet_Lead_Fields::mapping_rows(), 'id' ) );
				check( 'nabízí i rozšířené atributy', in_array( 'regNumber', array_column( $map_control['default'], 'remote_id' ), true ), true );
				check( 'atributy nesou popisek', ! empty( $map_control['default'][0]['remote_label'] ), true );

				// A row declaring anything but text would make Elementor's editor
				// offer only fields of that exact type, so an address typed into a
				// plain Text field would be unmappable.
				$types = array_unique( array_column( $map_control['default'], 'remote_type' ) );
				check( 'všechny řádky jsou text', $types, array( 'text' ) );
			}
		}

		$settings_row['category'] = 1;
		$settings_row['tags']     = 'globalni';
		update_option( Raynet_Lead_Settings::OPTION, $settings_row );
		update_option( 'raynet_test_http_calls', array() );

		$form_settings = array(
			'form_post_id'          => $page_id,
			'raynet_crm_fields_map' => array(
				array( 'remote_id' => 'email', 'local_id' => 'f_mail' ),
				array( 'remote_id' => 'firstName', 'local_id' => 'f_name' ),
				array( 'remote_id' => 'message', 'local_id' => 'f_msg' ),
				// Neither survives: an unknown attribute, and a field the form
				// does not have.
				array( 'remote_id' => 'nesmysl', 'local_id' => 'f_name' ),
				array( 'remote_id' => 'phone', 'local_id' => 'f_chybi' ),
			),
			'raynet_crm_topic'        => 'Poptávka z Elementoru',
			'raynet_crm_priority'     => 'CRITICAL',
			'raynet_crm_category'     => '5',
			'raynet_crm_tags'         => 'elementor',
			'raynet_crm_consent_note' => 'yes',
			'raynet_crm_source_url'   => 'yes',
		);

		$record = new class( $form_settings, array(
			'f_mail' => array( 'value' => 'jan@example.cz' ),
			'f_name' => array( 'value' => 'Jan' ),
			'f_msg'  => array( 'value' => 'Mám zájem.' ),
		) ) {
			private $fs;
			private $f;
			public function __construct( $fs, $f ) {
				$this->fs = $fs;
				$this->f  = $f;
			}
			public function get( $k ) {
				return 'form_settings' === $k ? $this->fs : ( 'fields' === $k ? $this->f : null );
			}
		};

		$handler = new class {
			public $data = array();
			public function add_response_data( $k, $v ) {
				$this->data[ $k ] = $v;
			}
		};

		$threw = '';

		try {
			$action->run( $record, $handler );
		} catch ( \Exception $e ) {
			$threw = $e->getMessage();
		}

		check( 'run() nevyhodí výjimku', $threw, '' );
		check( 'ID leadu předáno Elementoru', isset( $handler->data['raynet_lead_id'] ), true );

		wp_cache_flush();
		$elm_lead = null;

		foreach ( get_option( 'raynet_test_http_calls', array() ) as $call ) {
			if ( false !== strpos( $call['url'], '/lead/' ) ) {
				$elm_lead = json_decode( $call['body'], true );
			}
		}

		check( 'RAYNET zavolán', null !== $elm_lead, true );

		if ( $elm_lead ) {
			check( 'namapovaný e-mail', $elm_lead['contactInfo']['email'], 'jan@example.cz' );
			check( 'namapované jméno', $elm_lead['firstName'], 'Jan' );
			check( 'neznámý atribut zahozen', isset( $elm_lead['companyName'] ), false );
			check( 'chybějící pole nezaložilo telefon', isset( $elm_lead['contactInfo']['tel1'] ), false );
			check( 'předmět z formuláře', $elm_lead['topic'], 'Poptávka z Elementoru' );
			check( 'priorita z formuláře', $elm_lead['priority'], 'CRITICAL' );
			check( 'kategorie z formuláře přebíjí globální', $elm_lead['category'], 5 );
			check( 'štítky z formuláře přebíjí globální', $elm_lead['tags'], 'elementor' );
			check( 'zpráva v poznámce', false !== strpos( $elm_lead['notice'], 'Mám zájem.' ), true );
			check( 'souhlas v poznámce', false !== strpos( $elm_lead['notice'], 'Souhlas' ), true );
		}

		// A form mapping neither e-mail nor phone is refused before any request.
		update_option( 'raynet_test_http_calls', array() );
		$empty = new class( array( 'raynet_crm_fields_map' => array() ), array() ) {
			private $fs;
			private $f;
			public function __construct( $fs, $f ) {
				$this->fs = $fs;
				$this->f  = $f;
			}
			public function get( $k ) {
				return 'form_settings' === $k ? $this->fs : ( 'fields' === $k ? $this->f : null );
			}
		};

		$refused_msg = '';

		try {
			$action->run( $empty, $handler );
		} catch ( \Exception $e ) {
			$refused_msg = $e->getMessage();
		}

		check( 'bez kontaktu vyhodí výjimku', '' !== $refused_msg, true );
		wp_cache_flush();
		check( 'bez kontaktu se nevolá RAYNET', count( get_option( 'raynet_test_http_calls', array() ) ), 0 );

		// The exception carries the diagnostic, which Elementor shows to admins
		// only; the visitor sees the form's own error message.
		$fail_http = function () {
			return array(
				'headers'  => array(),
				'cookies'  => array(),
				'filename' => null,
				'body'     => wp_json_encode( array( 'message' => 'bad creds' ) ),
				'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
			);
		};

		add_filter( 'pre_http_request', $fail_http, 5 );

		$failed_msg = '';

		try {
			$action->run( $record, $handler );
		} catch ( \Exception $e ) {
			$failed_msg = $e->getMessage();
		}

		check( 'selhání API vyhodí výjimku', '' !== $failed_msg, true );
		check( 'výjimka nese diagnostiku', false !== strpos( $failed_msg, '401' ), true );

		// Everything after this point expects RAYNET to accept leads again.
		remove_filter( 'pre_http_request', $fail_http, 5 );

		// --- Scanning, applying a template and rolling back -----------------
		$scan_page = (int) wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_title'  => 'Skenovaná stránka',
				'post_status' => 'publish',
			)
		);

		$widget_id = 'scanform';
		$layout    = array(
			array(
				'id'       => 'scancont',
				'elType'   => 'container',
				'settings' => array(),
				'elements' => array(
					array(
						'id'         => $widget_id,
						'elType'     => 'widget',
						'widgetType' => 'form',
						'settings'   => array(
							'form_name'   => 'Skenovaný',
							'form_fields' => array(
								array( '_id' => 's1', 'custom_id' => 's1', 'field_type' => 'text', 'field_label' => 'Jméno a příjmení' ),
								array( '_id' => 's2', 'custom_id' => 's2', 'field_type' => 'email', 'field_label' => 'E-mail' ),
								array( '_id' => 's3', 'custom_id' => 's3', 'field_type' => 'textarea', 'field_label' => 'Zpráva' ),
							),
						),
						'elements'   => array(),
					),
				),
			),
		);

		update_post_meta( $scan_page, '_elementor_data', wp_slash( wp_json_encode( $layout ) ) );
		update_post_meta( $scan_page, '_elementor_edit_mode', 'builder' );

		$found = Raynet_Elementor_Forms::forms_in_post( $scan_page );
		check( 'sken najde formulář', count( $found ), 1 );

		if ( $found ) {
			check( 'sken zná název', $found[0]['form_name'], 'Skenovaný' );
			check( 'sken zná pole', count( $found[0]['fields'] ), 3 );
			check( 'sken vidí vypnuto', $found[0]['enabled'], false );
		}

		check( 'sken celého webu najde i tenhle', count( Raynet_Elementor_Forms::scan() ) >= 1, true );

		$applied = Raynet_Elementor_Forms::apply(
			$scan_page,
			$widget_id,
			array( 'priority' => 'CRITICAL', 'category' => '9', 'tags' => 'sablona' ),
			true
		);

		check( 'nasazení proběhlo', is_wp_error( $applied ), false );

		$after = Raynet_Elementor_Forms::forms_in_post( $scan_page );
		check( 'po nasazení zapnuto', $after[0]['enabled'], true );
		check( 'po nasazení namapováno', $after[0]['mapped'] >= 3, true );
		check( 'záloha existuje', Raynet_Elementor_Forms::has_backup( $scan_page ), true );

		// The whole-name box has to reach RAYNET as two attributes.
		$saved   = json_decode( get_post_meta( $scan_page, '_elementor_data', true ), true );
		$applied_settings = $saved[0]['elements'][0]['settings'];
		$applied_map      = array();

		foreach ( $applied_settings['raynet_crm_fields_map'] as $row ) {
			if ( ! empty( $row['local_id'] ) ) {
				$applied_map[ $row['remote_id'] ] = $row['local_id'];
			}
		}

		check( 'celé jméno namapováno', isset( $applied_map['fullName'] ) ? $applied_map['fullName'] : '', 's1' );
		check( 'e-mail namapován', isset( $applied_map['email'] ) ? $applied_map['email'] : '', 's2' );
		check( 'priorita v datech', $applied_settings['raynet_crm_priority'], 'CRITICAL' );
		check( 'kategorie v datech', $applied_settings['raynet_crm_category'], '9' );

		// A submission through that configuration splits the name.
		update_option( 'raynet_test_http_calls', array() );

		$scan_record = new class( $applied_settings, array(
			's1' => array( 'value' => 'Jan Novák' ),
			's2' => array( 'value' => 'jan@example.cz' ),
			's3' => array( 'value' => 'Text.' ),
		) ) {
			private $fs;
			private $f;
			public function __construct( $fs, $f ) {
				$this->fs = $fs;
				$this->f  = $f;
			}
			public function get( $k ) {
				return 'form_settings' === $k ? $this->fs : ( 'fields' === $k ? $this->f : null );
			}
		};

		$split_error = '';

		try {
			$action->run( $scan_record, $handler );
		} catch ( \Exception $e ) {
			$split_error = $e->getMessage();
		}

		check( 'odeslání přes nasazenou šablonu', $split_error, '' );

		wp_cache_flush();
		$split_lead = null;

		foreach ( get_option( 'raynet_test_http_calls', array() ) as $call ) {
			if ( false !== strpos( $call['url'], '/lead/' ) ) {
				$split_lead = json_decode( $call['body'], true );
			}
		}

		check( 'jedno pole dalo jméno', isset( $split_lead['firstName'] ) ? $split_lead['firstName'] : '', 'Jan' );
		check( 'jedno pole dalo příjmení', isset( $split_lead['lastName'] ) ? $split_lead['lastName'] : '', 'Novák' );
		check( 'priorita ze šablony', isset( $split_lead['priority'] ) ? $split_lead['priority'] : '', 'CRITICAL' );

		// A page holding two forms is applied to twice in one submit. The first
		// write is the only rollback point; the second must not replace it with
		// the half-applied copy.
		$pristine = get_post_meta( $scan_page, Raynet_Elementor_Forms::BACKUP_META, true );
		Raynet_Elementor_Forms::apply( $scan_page, $widget_id, array( 'priority' => 'MINOR' ), true );
		check( 'druhé nasazení nepřepíše zálohu', get_post_meta( $scan_page, Raynet_Elementor_Forms::BACKUP_META, true ), $pristine );

		// A form that does not name a widget must be refused, not treated as
		// "every form on the page".
		check( 'prázdné ID widgetu odmítnuto',
			is_wp_error( Raynet_Elementor_Forms::apply( $scan_page, '', array(), false ) ), true );

		// Editing the page in Elementor afterwards must block the rollback.
		$current = json_decode( get_post_meta( $scan_page, '_elementor_data', true ), true );
		$current[0]['settings']['padding'] = '40px';
		update_post_meta( $scan_page, '_elementor_data', wp_slash( wp_json_encode( $current ) ) );

		check( 'úprava se pozná', Raynet_Elementor_Forms::edited_since( $scan_page ), true );

		$stale = Raynet_Elementor_Forms::restore( $scan_page );
		check( 'zastaralá záloha se nevrátí', is_wp_error( $stale ), true );
		check( 'a řekne proč', $stale->get_error_code(), 'raynet_stale_backup' );
		check( 'úprava přežila', isset( json_decode( get_post_meta( $scan_page, '_elementor_data', true ), true )[0]['settings']['padding'] ), true );

		check( 'vynucené vrácení projde', is_wp_error( Raynet_Elementor_Forms::restore( $scan_page, true ) ), false );

		// Put the page back the way the rest of this block expects it.
		Raynet_Elementor_Forms::apply( $scan_page, $widget_id, array( 'priority' => 'CRITICAL', 'category' => '9', 'tags' => 'sablona' ), true );

		$restored = Raynet_Elementor_Forms::restore( $scan_page );
		check( 'návrat proběhl', is_wp_error( $restored ), false );

		$back = Raynet_Elementor_Forms::forms_in_post( $scan_page );
		check( 'po návratu zase vypnuto', $back[0]['enabled'], false );
		check( 'záloha spotřebována', Raynet_Elementor_Forms::has_backup( $scan_page ), false );
		check( 'druhý návrat selže', is_wp_error( Raynet_Elementor_Forms::restore( $scan_page ) ), true );

		// The rollback control is a button in a form of its own, and the confirm
		// dialog is what raises force. A typo in that inline handler throws before
		// it can, so the screen is fetched and the attribute parsed.
		Raynet_Elementor_Forms::apply( $scan_page, $widget_id, array( 'priority' => 'MINOR' ), true );
		$edited = json_decode( get_post_meta( $scan_page, '_elementor_data', true ), true );
		$edited[0]['settings']['padding'] = '60px';
		update_post_meta( $scan_page, '_elementor_data', wp_slash( wp_json_encode( $edited ) ) );

		$stale_screen = req( '/wp-admin/admin.php?page=raynet-elementor-forms', null, true );
		check( 'zastaralý řádek varuje', false !== strpos( $stale_screen['body'], 'raynet-elm__stale' ), true );
		check( 'vrácení je tlačítko', false !== strpos( $stale_screen['body'], 'form="raynet-restore-' . $scan_page . '"' ), true );
		check( 'vrácení má vlastní formulář', false !== strpos( $stale_screen['body'], 'id="raynet-restore-' . $scan_page . '"' ), true );
		check( 'force začíná na nule', false !== strpos( $stale_screen['body'], 'id="raynet-force-' . $scan_page . '" name="force" value="0"' ), true );

		preg_match( '/onclick="([^"]*raynet-force-' . $scan_page . '[^"]*)"/', $stale_screen['body'], $onclick );
		$handler = isset( $onclick[1] ) ? html_entity_decode( $onclick[1], ENT_QUOTES, 'UTF-8' ) : '';
		check( 'potvrzení je na tlačítku', false !== strpos( $handler, 'confirm(' ), true );
		check( 'potvrzení zvedá force', (bool) preg_match( '/raynet-force-' . $scan_page . "[\"']\\s*\\)\\.value = '1'/", $handler ), true );

		$depth  = 0;
		$broken = false;
		foreach ( str_split( preg_replace( array( '/"(?:[^"\\\\]|\\\\.)*"/', "/'[^']*'/" ), array( '""', "''" ), $handler ) ) as $char ) {
			if ( '(' === $char ) {
				$depth++;
			} elseif ( ')' === $char ) {
				$depth--;
			}
			if ( $depth < 0 ) {
				$broken = true;
			}
		}
		check( 'obsluha má vyvážené závorky', 0 === $depth && ! $broken, true );

		Raynet_Elementor_Forms::restore( $scan_page, true );

		// --- Templates ------------------------------------------------------
		delete_option( Raynet_Elementor_Forms::TEMPLATES_OPTION );
		$slug = Raynet_Elementor_Forms::save_template( 'Poptávky z webu', array( 'priority' => 'MINOR', 'owner' => '3' ) );

		check( 'šablona uložena pod slugem', $slug, 'poptavky-z-webu' );
		$stored = Raynet_Elementor_Forms::templates();
		check( 'šablona má název', $stored[ $slug ]['name'], 'Poptávky z webu' );
		check( 'šablona má nastavení', $stored[ $slug ]['lead']['owner'], 3 );

		Raynet_Elementor_Forms::delete_template( $slug );
		check( 'šablona smazána', count( Raynet_Elementor_Forms::templates() ), 0 );

		// --- Where forms live ----------------------------------------------
		// A form can sit in a popup, a header, a footer or a global widget: all
		// of those are template-library posts, which "any" post type skips.
		$form_layout = function ( $id, $name, array $settings = array() ) {
			return array(
				array(
					'id'         => $id,
					'elType'     => 'widget',
					'widgetType' => 'form',
					'settings'   => array_merge(
						array(
							'form_name'   => $name,
							'form_fields' => array(
								array( '_id' => $id . 'e', 'custom_id' => $id . 'e', 'field_type' => 'email', 'field_label' => 'E-mail' ),
								array( '_id' => $id . 'z', 'custom_id' => $id . 'z', 'field_type' => 'number', 'field_label' => 'Počet zaměstnanců' ),
							),
						),
						$settings
					),
					'elements'   => array(),
				),
			);
		};

		$library_post = function ( $title, $type, array $data ) {
			$id = (int) wp_insert_post( array( 'post_type' => 'elementor_library', 'post_title' => $title, 'post_status' => 'publish' ) );
			update_post_meta( $id, '_elementor_template_type', $type );
			update_post_meta( $id, '_elementor_edit_mode', 'builder' );
			update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );

			return $id;
		};

		$popup  = $library_post( 'Popup poptávka', 'popup', $form_layout( 'popform', 'Z popupu' ) );
		$footer = $library_post( 'Patička webu', 'footer', $form_layout( 'footform', 'Z patičky' ) );
		$global = $library_post( 'Globální poptávka', 'widget', $form_layout( 'globform', 'Globální' ) );

		// The page only carries a reference; the form lives in the template.
		$host = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Stránka s globálním widgetem', 'post_status' => 'publish' ) );
		update_post_meta( $host, '_elementor_edit_mode', 'builder' );
		update_post_meta(
			$host,
			'_elementor_data',
			wp_slash( wp_json_encode( array( array( 'id' => 'glref', 'elType' => 'widget', 'widgetType' => 'global', 'templateID' => $global, 'settings' => array(), 'elements' => array() ) ) ) )
		);

		// An autosave carries a copy of the layout and must not list the form twice.
		$autosave = (int) wp_insert_post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $popup,
				'post_name'   => $popup . '-autosave-v1',
				'post_title'  => 'Popup poptávka',
				'post_author' => get_current_user_id(),
			)
		);
		// update_post_meta() would write to the parent; a revision needs update_metadata().
		update_metadata( 'post', $autosave, '_elementor_data', wp_slash( wp_json_encode( $form_layout( 'popform', 'Rozpracovaný koncept' ) ) ) );

		// A page switched back to the WordPress editor keeps its old layout.
		$classic = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Stará stránka', 'post_status' => 'publish' ) );
		update_post_meta( $classic, '_elementor_data', wp_slash( wp_json_encode( $form_layout( 'oldform', 'Starý' ) ) ) );

		$by_widget = array();

		foreach ( Raynet_Elementor_Forms::scan() as $row ) {
			$by_widget[ $row['widget_id'] ][] = $row;
		}

		check( 'sken najde formulář v popupu', isset( $by_widget['popform'] ), true );
		check( 'popup jen jednou, bez autosave', isset( $by_widget['popform'] ) ? count( $by_widget['popform'] ) : 0, 1 );
		check( 'popup má umístění', isset( $by_widget['popform'] ) ? $by_widget['popform'][0]['location'] : '', 'Popup' );
		check( 'sken najde formulář v patičce', isset( $by_widget['footform'] ) ? $by_widget['footform'][0]['location'] : '', 'Patička' );
		check( 'sken najde globální widget', isset( $by_widget['globform'] ) ? $by_widget['globform'][0]['post_id'] : 0, $global );
		check( 'globální widget má umístění', isset( $by_widget['globform'] ) ? $by_widget['globform'][0]['location'] : '', 'Globální widget' );
		check( 'odkaz na stránku není formulář', count( Raynet_Elementor_Forms::forms_in_post( $host ) ), 0 );
		check( 'stará stránka se ukáže', isset( $by_widget['oldform'] ), true );
		check( 'stará stránka se nevykresluje', isset( $by_widget['oldform'] ) ? $by_widget['oldform'][0]['rendered'] : null, false );
		check( 'stránka se ukáže pod názvem typu', isset( $by_widget[ $widget_id ] ) ? $by_widget[ $widget_id ][0]['location'] : '', get_post_type_object( 'page' )->labels->singular_name );

		$refused = Raynet_Elementor_Forms::apply( $classic, 'oldform', array(), true );
		check( 'nevykreslovanou stránku nenastaví', is_wp_error( $refused ) ? $refused->get_error_code() : '', 'raynet_not_rendered' );

		// The template is what Elementor reads on submit, so that is where the
		// setting has to land.
		$popup_applied = Raynet_Elementor_Forms::apply( $popup, 'popform', array( 'priority' => 'CRITICAL' ), true );
		check( 'popup nastaven', $popup_applied, array( 'popform' ) );
		$popup_form = Raynet_Elementor_Forms::forms_in_post( $popup );
		check( 'popup zapnutý', $popup_form[0]['enabled'], true );
		check( 'popup má kontakt', $popup_form[0]['has_contact'], true );

		$global_applied = Raynet_Elementor_Forms::apply( $global, 'globform', array(), true );
		check( 'globální widget nastaven v šabloně', $global_applied, array( 'globform' ) );

		$document = \Elementor\Plugin::instance()->documents->get( $global );
		$elements = $document ? $document->get_elements_data() : array();
		check( 'Elementor čte akci ze šablony', isset( $elements[0]['settings']['submit_actions'] ) && in_array( 'raynet_crm', $elements[0]['settings']['submit_actions'], true ), true );

		// A form whose actions were never touched runs the default e-mail
		// action; Elementor does not even store it. Turning RAYNET on must not
		// turn that off.
		$footer_applied = Raynet_Elementor_Forms::apply( $footer, 'footform', array(), true );
		$footer_data    = json_decode( get_post_meta( $footer, '_elementor_data', true ), true );
		// Elementor Pro adds its own defaults through this filter (Submissions
		// puts "save-to-database" there), so the expectation comes from it too.
		$default_actions = (array) apply_filters( 'elementor_pro/forms/default_submit_actions', array( 'email' ) );
		check( 'výchozí e-mailová akce zůstala', $footer_data[0]['settings']['submit_actions'], array_merge( $default_actions, array( 'raynet_crm' ) ) );
		check( 'mezi výchozími je e-mail', in_array( 'email', $footer_data[0]['settings']['submit_actions'], true ), true );

		// Only the forms that were found count as configured.
		$partial = Raynet_Elementor_Forms::apply( $footer, array( 'footform', 'duch' ), array(), true );
		check( 'vrátí jen nalezené formuláře', $partial, array( 'footform' ) );

		// Edits made after an apply become the new rollback point, so undoing a
		// later apply does not also undo them.
		$footer_now            = json_decode( get_post_meta( $footer, '_elementor_data', true ), true );
		$footer_now[0]['settings']['form_name'] = 'Přejmenovaná v Elementoru';
		update_post_meta( $footer, '_elementor_data', wp_slash( wp_json_encode( $footer_now ) ) );

		Raynet_Elementor_Forms::apply( $footer, 'footform', array( 'priority' => 'CRITICAL' ), true );
		check( 'po nasazení je stránka zase „naše“', Raynet_Elementor_Forms::edited_since( $footer ), false );
		check( 'vrácení projde bez vynucení', is_wp_error( Raynet_Elementor_Forms::restore( $footer ) ), false );
		$footer_back = Raynet_Elementor_Forms::forms_in_post( $footer );
		check( 'vrácení nechá mezitímní úpravu', $footer_back[0]['form_name'], 'Přejmenovaná v Elementoru' );

		// Elementor 4's atomic form is listed, but cannot be configured.
		$atomic_page = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Atomová stránka', 'post_status' => 'publish' ) );
		update_post_meta( $atomic_page, '_elementor_edit_mode', 'builder' );
		update_post_meta(
			$atomic_page,
			'_elementor_data',
			wp_slash(
				wp_json_encode(
					array(
						array(
							'id'       => 'atomform',
							'elType'   => 'e-form',
							'settings' => array( 'form-name' => array( '$$type' => 'string', 'value' => 'Nový formulář' ) ),
							'elements' => array(),
						),
					)
				)
			)
		);

		$atomic_rows = array_values( array_filter( Raynet_Elementor_Forms::scan(), function ( $row ) {
			return 'atomform' === $row['widget_id'];
		} ) );

		check( 'atomový formulář se ukáže', count( $atomic_rows ), 1 );
		check( 'atomový formulář je označený', isset( $atomic_rows[0] ) ? $atomic_rows[0]['atomic'] : null, true );
		check( 'atomový formulář má název', isset( $atomic_rows[0] ) ? $atomic_rows[0]['form_name'] : '', 'Nový formulář' );
		check( 'atomový formulář bez polí je vypnutý', isset( $atomic_rows[0] ) ? $atomic_rows[0]['enabled'] : null, false );
		check( 'registr akcí atomového formuláře je k dispozici', Raynet_Elementor_Atomic_Forms::supported(), true );
		check( 'atomový řádek nese podporu', isset( $atomic_rows[0]['supported'] ) ? $atomic_rows[0]['supported'] : null, true );

		// --- Elementor 4 atomic form: reading and configuring --------------------
		global $wpdb;

		$typed = function ( $type, $value ) {
			return array( '$$type' => $type, 'value' => $value );
		};
		$str   = function ( $value ) use ( $typed ) {
			return $typed( 'string', $value );
		};
		$aw    = function ( $id, $type, array $settings = array() ) {
			return array( 'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => array(), 'isInner' => false );
		};
		$alabel = function ( $id, $html, $input ) use ( $aw, $typed, $str ) {
			return $aw( $id, 'e-form-label', array( 'text' => $typed( 'html-v3', array( 'content' => $str( $html ), 'children' => array() ) ), 'input-id' => $str( $input ) ) );
		};
		$atomic_form = array(
			'id'       => 'aform1',
			'elType'   => 'e-form',
			'settings' => array(
				'form-name' => $str( 'Atomová poptávka' ),
				'classes'   => $typed( 'classes', array( 'e-aform1-1' ) ),
				// Elementor Pro runs e-mail by default only for a form that stores
				// e-mail settings; the editor writes them with a new form.
				'email'     => $typed( 'email', array( 'to' => $str( 'obchod@example.cz' ) ) ),
			),
			'elements' => array(
				$alabel( 'al1', 'E-mail', 'mail' ),
				$aw( 'af1', 'e-form-input', array( 'type' => $str( 'email' ), '_cssid' => $str( 'mail' ) ) ),
				$alabel( 'al2', 'Zpráva', 'msg' ),
				$aw( 'af2', 'e-form-textarea', array( '_cssid' => $str( 'msg' ) ) ),
				array(
					'id'       => 'arow',
					'elType'   => 'e-flexbox',
					'settings' => array(),
					'elements' => array(
						$aw( 'af3', 'e-form-checkbox', array( '_cssid' => $str( 'gdpr' ) ) ),
						$alabel( 'al3', 'Souhlasím se <strong>zpracováním</strong> osobních údajů', 'gdpr' ),
					),
				),
				$alabel( 'al4', 'Služba', 'svc' ),
				$aw( 'af4', 'e-form-select', array( '_cssid' => $str( 'svc' ) ) ),
				$alabel( 'al5', 'Příloha', 'file' ),
				$aw( 'af5', 'e-form-file-upload', array( '_cssid' => $str( 'file' ) ) ),
				$aw( 'af6', 'e-form-input', array( 'placeholder' => $str( 'Název firmy' ) ) ),
				$aw( 'af7', 'e-form-input' ),
				$aw( 'asb', 'e-form-submit-button' ),
			),
		);

		$mixed_page = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Atomový i klasický', 'post_status' => 'publish' ) );
		update_post_meta( $mixed_page, '_elementor_edit_mode', 'builder' );
		update_post_meta( $mixed_page, '_elementor_data', wp_slash( wp_json_encode( array_merge( array( $atomic_form ), $form_layout( 'mixclassic', 'Klasický', array( 'submit_actions' => array( 'email', 'raynet_crm' ), 'raynet_crm_topic' => 'Klasické téma' ) ) ) ) ) );

		$mixed_rows = array();

		foreach ( Raynet_Elementor_Forms::scan() as $row ) {
			if ( $mixed_page === $row['post_id'] ) {
				$mixed_rows[ $row['widget_id'] ] = $row;
			}
		}

		check( 'sken najde atomový formulář s poli', isset( $mixed_rows['aform1'] ) ? count( $mixed_rows['aform1']['fields'] ) : 0, 7 );
		check( 'atomový formulář je atomový', isset( $mixed_rows['aform1'] ) ? $mixed_rows['aform1']['atomic'] : null, true );
		check( 'atomový formulář zatím vypnutý', isset( $mixed_rows['aform1'] ) ? $mixed_rows['aform1']['enabled'] : null, false );
		check( 'atomová pole mají typy ze stromu', isset( $mixed_rows['aform1'] ) ? array_column( $mixed_rows['aform1']['fields'], 'type' ) : array(), array( 'email', 'textarea', 'acceptance', 'select', 'upload', 'text', 'text' ) );
		check( 'klasický řádek má podporu', isset( $mixed_rows['mixclassic'] ) ? $mixed_rows['mixclassic']['supported'] : null, true );

		$mixed_before = json_decode( get_post_meta( $mixed_page, '_elementor_data', true ), true );
		$mixed_done   = Raynet_Elementor_Forms::apply( $mixed_page, 'aform1', array( 'priority' => 'CRITICAL', 'topic' => 'Atomová' ), true );
		check( 'atomový formulář nastaven', $mixed_done, array( 'aform1' ) );

		$mixed_after = json_decode( get_post_meta( $mixed_page, '_elementor_data', true ), true );
		check(
			'strom má e-mail i RAYNET',
			isset( $mixed_after[0]['settings']['actions-after-submit'] ) ? $mixed_after[0]['settings']['actions-after-submit'] : null,
			array( '$$type' => 'string-array', 'value' => array( $str( 'email' ), $str( 'raynet_crm' ) ) )
		);
		$after_rest = $mixed_after[0];
		unset( $after_rest['settings']['actions-after-submit'] );
		check( 'jinak se atomový prvek nezměnil', wp_json_encode( $after_rest ), wp_json_encode( $mixed_before[0] ) );
		check( 'klasický widget na stejné stránce beze změny', wp_json_encode( $mixed_after[1] ), wp_json_encode( $mixed_before[1] ) );

		$atomic_meta = get_post_meta( $mixed_page, Raynet_Elementor_Atomic_Forms::META, true );
		check( 'meta drží nastavení leadu', isset( $atomic_meta['aform1']['raynet_crm_priority'] ) ? $atomic_meta['aform1']['raynet_crm_priority'] : '', 'CRITICAL' );
		check( 'meta drží téma', isset( $atomic_meta['aform1']['raynet_crm_topic'] ) ? $atomic_meta['aform1']['raynet_crm_topic'] : '', 'Atomová' );
		check( 'meta nedrží nic cizího', isset( $atomic_meta['aform1'] ) ? array_values( array_filter( array_keys( $atomic_meta['aform1'] ), function ( $key ) {
			return 0 !== strpos( $key, 'raynet_crm_' );
		} ) ) : array( 'chybí' ), array() );

		$atomic_settings = Raynet_Elementor_Forms::form_settings( $mixed_page, 'aform1' );
		$atomic_map = null !== $atomic_settings ? Raynet_Elementor_Forms::current_map( $atomic_settings ) : array();
		ksort( $atomic_map );
		check( 'e-mail a další namapované samy', $atomic_map, array( 'companyName' => 'af6', 'email' => 'af1', 'gdprConsent' => 'af3', 'message' => 'af2' ) );
		check( 'form_settings() má název', null !== $atomic_settings ? $atomic_settings['form_name'] : '', 'Atomová poptávka' );

		$mixed_now = Raynet_Elementor_Forms::forms_in_post( $mixed_page );
		check( 'po nasazení zapnutý', $mixed_now[0]['enabled'], true );
		check( 'po nasazení má kontakt', $mixed_now[0]['has_contact'], true );

		// Mapping screen data: changes go to meta and come back the same way.
		$saved = Raynet_Elementor_Forms::save_targets( $mixed_page, 'aform1', array( 'af6' => '-', 'af4' => Raynet_Elementor_Forms::TARGET_NOTICE, 'af7' => 'www' ), true );
		check( 'mapování atomového formuláře uloženo', $saved, true );
		$round = Raynet_Elementor_Forms::field_targets( Raynet_Elementor_Forms::form_settings( $mixed_page, 'aform1' ) );
		check( 'firma vynechaná', isset( $round['af6'] ) ? $round['af6'] : '', '-' );
		check( 'služba do poznámky', isset( $round['af4'] ) ? $round['af4'] : '', Raynet_Elementor_Forms::TARGET_NOTICE );
		check( 'poslední pole na web', isset( $round['af7'] ) ? $round['af7'] : '', 'www' );
		check( 'e-mail zůstal', isset( $round['af1'] ) ? $round['af1'] : '', 'email' );
		check( 'akce po uložení mapování stejné', json_decode( get_post_meta( $mixed_page, '_elementor_data', true ), true )[0]['settings']['actions-after-submit'], $mixed_after[0]['settings']['actions-after-submit'] );

		// A pending draft gets the slug in its copy of the element; the settings
		// stay on the page, not on the revision.
		$atomic_draft = (int) wp_insert_post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $mixed_page,
				'post_name'   => $mixed_page . '-autosave-v1',
				'post_title'  => 'Atomový i klasický',
				'post_author' => get_current_user_id(),
			)
		);
		$draft_form = $atomic_form;
		$draft_form['settings']['form-name'] = $str( 'Rozpracovaná' );
		// Its own actions, set in the editor and not yet published.
		$draft_form['settings']['actions-after-submit'] = $typed( 'string-array', array( $str( 'email' ), $str( 'raynet_crm' ), $str( 'collect-submissions' ) ) );
		update_metadata( 'post', $atomic_draft, '_elementor_data', wp_slash( wp_json_encode( array( $draft_form ) ) ) );
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2020-01-01 00:00:00' ), array( 'ID' => $mixed_page ) );
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2021-01-01 00:00:00' ), array( 'ID' => $atomic_draft ) );
		clean_post_cache( $mixed_page );
		clean_post_cache( $atomic_draft );

		$off = Raynet_Elementor_Forms::update_forms(
			$mixed_page,
			'aform1',
			function ( array $settings ) {
				$settings['submit_actions'] = array_values( array_diff( $settings['submit_actions'], array( 'raynet_crm' ) ) );
				$settings['raynet_crm_priority'] = 'MINOR';
				return $settings;
			}
		);
		check( 'atomový formulář vypnut', $off, array( 'aform1' ) );
		$draft_after = json_decode( get_metadata( 'post', $atomic_draft, '_elementor_data', true ), true );
		check( 'koncept nese změnu akcí', isset( $draft_after[0]['settings']['actions-after-submit'] ) ? $draft_after[0]['settings']['actions-after-submit'] : null, array( '$$type' => 'string-array', 'value' => array( $str( 'email' ), $str( 'collect-submissions' ) ) ) );
		check( 'stránka má jen e-mail', json_decode( get_post_meta( $mixed_page, '_elementor_data', true ), true )[0]['settings']['actions-after-submit'], array( '$$type' => 'string-array', 'value' => array( $str( 'email' ) ) ) );
		check( 'koncept si nechal svůj název', $draft_after[0]['settings']['form-name'], $str( 'Rozpracovaná' ) );
		check( 'revize nemá vlastní meta', metadata_exists( 'post', $atomic_draft, Raynet_Elementor_Atomic_Forms::META ), false );
		check( 'meta stránky změněné jednou', Raynet_Elementor_Atomic_Forms::stored( $mixed_page, 'aform1' )['raynet_crm_priority'], 'MINOR' );
		check( 'mapování po vypnutí zůstalo', Raynet_Elementor_Forms::field_targets( Raynet_Elementor_Forms::form_settings( $mixed_page, 'aform1' ) )['af7'], 'www' );

		// A stale entry for a form no longer on the page does no harm.
		$stale_meta            = get_post_meta( $mixed_page, Raynet_Elementor_Atomic_Forms::META, true );
		$stale_meta['zmizely'] = array( 'raynet_crm_topic' => 'Smazaný formulář' );
		$stale_meta['rozbity'] = 'není pole';
		update_post_meta( $mixed_page, Raynet_Elementor_Atomic_Forms::META, $stale_meta );
		check( 'zastaralá položka meta nevadí skenu', count( Raynet_Elementor_Forms::forms_in_post( $mixed_page ) ), 2 );
		check( 'zastaralý formulář se nenajde', Raynet_Elementor_Forms::form_settings( $mixed_page, 'zmizely' ), null );
		check( 'zastaralý formulář nejde nastavit', is_wp_error( Raynet_Elementor_Forms::apply( $mixed_page, 'zmizely', array(), true ) ), true );

		// A value with a backslash and a quote survives the meta round trip.
		Raynet_Elementor_Atomic_Forms::store( $mixed_page, 'aform1', array( 'raynet_crm_topic' => 'C:\\cesta "v uvozovkách"' ) );
		check( 'zpětné lomítko a uvozovky přežijí uložení', Raynet_Elementor_Atomic_Forms::stored( $mixed_page, 'aform1' ), array( 'raynet_crm_topic' => 'C:\\cesta "v uvozovkách"' ) );

		// Rollback puts the RAYNET settings back with the tree: first on a page
		// that had none, then on one that had some.
		$atomic_page_with = function ( $title, $meta ) use ( $atomic_form ) {
			$id = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => $title, 'post_status' => 'publish' ) );
			update_post_meta( $id, '_elementor_edit_mode', 'builder' );
			update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( array( $atomic_form ) ) ) );

			if ( null !== $meta ) {
				update_post_meta( $id, Raynet_Elementor_Atomic_Forms::META, $meta );
			}

			return $id;
		};

		$undo_fresh = $atomic_page_with( 'Vrácení bez nastavení', null );
		check( 'nasazení na čistý atomový formulář', Raynet_Elementor_Forms::apply( $undo_fresh, 'aform1', array( 'topic' => 'Ze šablony' ), true ), array( 'aform1' ) );
		check( 'před vrácením zapnutý', Raynet_Elementor_Forms::forms_in_post( $undo_fresh )[0]['enabled'], true );
		check( 'vrácení čistého projde', Raynet_Elementor_Forms::restore( $undo_fresh ), true );
		check( 'po vrácení vypnutý', Raynet_Elementor_Forms::forms_in_post( $undo_fresh )[0]['enabled'], false );
		check( 'po vrácení bez nastavení RAYNETu', Raynet_Elementor_Atomic_Forms::stored( $undo_fresh, 'aform1' ), array() );
		check( 'po vrácení meta zmizelo', metadata_exists( 'post', $undo_fresh, Raynet_Elementor_Atomic_Forms::META ), false );
		check( 'po vrácení zmizela i záloha meta', metadata_exists( 'post', $undo_fresh, Raynet_Elementor_Forms::ATOMIC_BACKUP_META ), false );

		$undo_kept = $atomic_page_with( 'Vrácení s nastavením', array( 'aform1' => array( 'raynet_crm_topic' => 'Původní', 'raynet_crm_notice_fields' => array( 'af4' ) ) ) );
		Raynet_Elementor_Forms::apply( $undo_kept, 'aform1', array( 'topic' => 'Ze šablony' ), true );
		check( 'šablona přepsala téma', Raynet_Elementor_Atomic_Forms::stored( $undo_kept, 'aform1' )['raynet_crm_topic'], 'Ze šablony' );
		check( 'vrácení s nastavením projde', Raynet_Elementor_Forms::restore( $undo_kept ), true );
		check( 'po vrácení původní nastavení', Raynet_Elementor_Atomic_Forms::stored( $undo_kept, 'aform1' ), array( 'raynet_crm_topic' => 'Původní', 'raynet_crm_notice_fields' => array( 'af4' ) ) );
		check( 'po vrácení s nastavením vypnutý', Raynet_Elementor_Forms::forms_in_post( $undo_kept )[0]['enabled'], false );

		// One apply over a page's classic and atomic form configures both, and
		// the classic one exactly as it would be on its own.
		$both_page = $atomic_page_with( 'Obě v jednom volání', null );
		update_post_meta( $both_page, '_elementor_data', wp_slash( wp_json_encode( array_merge( array( $atomic_form ), $form_layout( 'bothclassic', 'Klasický' ) ) ) ) );
		$solo_page = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Jen klasický', 'post_status' => 'publish' ) );
		update_post_meta( $solo_page, '_elementor_edit_mode', 'builder' );
		update_post_meta( $solo_page, '_elementor_data', wp_slash( wp_json_encode( $form_layout( 'bothclassic', 'Klasický' ) ) ) );

		$both_lead = array( 'topic' => 'Společná', 'priority' => 'CRITICAL' );
		check( 'jedno volání nastaví oba formuláře', Raynet_Elementor_Forms::apply( $both_page, array( 'aform1', 'bothclassic' ), $both_lead, true ), array( 'aform1', 'bothclassic' ) );
		Raynet_Elementor_Forms::apply( $solo_page, 'bothclassic', $both_lead, true );
		check(
			'klasický widget nastaven stejně jako bez atomového',
			wp_json_encode( Raynet_Elementor_Forms::form_settings( $both_page, 'bothclassic' ) ),
			wp_json_encode( Raynet_Elementor_Forms::form_settings( $solo_page, 'bothclassic' ) )
		);
		check( 'atomový v témže volání zapnutý', Raynet_Elementor_Forms::forms_in_post( $both_page )[0]['enabled'], true );

		foreach ( array( $undo_fresh, $undo_kept, $both_page, $solo_page ) as $atomic_cleanup ) {
			wp_delete_post( $atomic_cleanup, true );
		}

		wp_delete_post( $atomic_draft, true );
		wp_delete_post( $mixed_page, true );

		// An autosave older than the post is ignored by the editor. Writing meta
		// alone would leave an earlier autosave "newer" for good.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_modified' => '2020-01-01 00:00:00', 'post_modified_gmt' => '2020-01-01 00:00:00' ), array( 'ID' => $popup ) );
		$wpdb->update( $wpdb->posts, array( 'post_modified' => '2021-01-01 00:00:00', 'post_modified_gmt' => '2021-01-01 00:00:00' ), array( 'ID' => $autosave ) );
		clean_post_cache( $popup );
		clean_post_cache( $autosave );

		check( 'autosave je před nasazením novější', false !== \Elementor\Utils::get_post_autosave( $popup ), true );
		Raynet_Elementor_Forms::apply( $popup, 'popform', array( 'priority' => 'MINOR' ), true );

		// The draft must survive — it is someone's unpublished work — and carry
		// the change, so that its next Update does not drop it.
		wp_cache_flush();
		check( 'koncept zůstává novější', false !== \Elementor\Utils::get_post_autosave( $popup ), true );
		$draft_data = json_decode( get_metadata( 'post', $autosave, '_elementor_data', true ), true );
		check( 'koncept si nechal svou práci', isset( $draft_data[0]['settings']['form_name'] ) ? $draft_data[0]['settings']['form_name'] : '', 'Rozpracovaný koncept' );
		check( 'koncept nese RAYNET', isset( $draft_data[0]['settings']['submit_actions'] ) && in_array( 'raynet_crm', $draft_data[0]['settings']['submit_actions'], true ), true );
		check( 'koncept nese nastavení šablony', isset( $draft_data[0]['settings']['raynet_crm_priority'] ) ? $draft_data[0]['settings']['raynet_crm_priority'] : '', 'MINOR' );
		check( 'stránka se nepřepsala konceptem', Raynet_Elementor_Forms::forms_in_post( $popup )[0]['form_name'], 'Z popupu' );

		$draft_restore = Raynet_Elementor_Forms::restore( $popup, true );
		check( 'vrácení s čekajícím konceptem odmítne', is_wp_error( $draft_restore ) ? $draft_restore->get_error_code() : '', 'raynet_pending_draft' );
		check( 'koncept po odmítnutí nedotčen', json_decode( get_metadata( 'post', $autosave, '_elementor_data', true ), true )[0]['settings']['form_name'], 'Rozpracovaný koncept' );

		// Once the draft is published (the page is newer again), nothing blocks it.
		$wpdb->update( $wpdb->posts, array( 'post_modified' => '2022-01-01 00:00:00', 'post_modified_gmt' => '2022-01-01 00:00:00' ), array( 'ID' => $popup ) );
		clean_post_cache( $popup );
		check( 'po publikování konceptu už nic nečeká', Raynet_Elementor_Forms::pending_autosaves( $popup ), array() );

		// --- Custom fields from RAYNET ---------------------------------------
		delete_option( Raynet_Lead_Fields::OPTION );
		$fetched = Raynet_Lead_Fields::refresh();
		check( 'vlastní pole načtena', $fetched, 4 );
		check( 'pole firem ne, soubor ne', array_keys( Raynet_Lead_Fields::custom() ), array( 'Pocet_zam_a1b2c', 'Velikost_d3e4f', 'Termin_g5h6', 'VIP_b91d1' ) );

		$remote_ids = array_column( Raynet_Elementor_Form_Action::remote_fields(), 'remote_id' );
		check( 'mapování v Elementoru nabízí vlastní pole', in_array( 'cf:Velikost_d3e4f', $remote_ids, true ), true );

		// Bulk apply now picks the custom field by its label.
		Raynet_Elementor_Forms::apply( $popup, 'popform', array(), true );
		$popup_data = json_decode( get_post_meta( $popup, '_elementor_data', true ), true );
		$popup_map  = array();

		foreach ( $popup_data[0]['settings']['raynet_crm_fields_map'] as $row ) {
			if ( '' !== $row['local_id'] ) {
				$popup_map[ $row['remote_id'] ] = $row['local_id'];
			}
		}

		check( 'odhad našel vlastní pole podle popisku', isset( $popup_map['cf:Pocet_zam_a1b2c'] ) ? $popup_map['cf:Pocet_zam_a1b2c'] : '', 'popformz' );

		// A submission carries typed custom values; a value the field does not
		// take goes to the note instead of costing the lead.
		update_option( 'raynet_test_http_calls', array() );

		$custom_settings = array(
			'form_post_id'          => $popup,
			'raynet_crm_source_url' => 'yes',
			'raynet_crm_fields_map' => array(
				array( 'remote_id' => 'email', 'local_id' => 'm' ),
				array( 'remote_id' => 'regNumber', 'local_id' => 'ico' ),
				array( 'remote_id' => 'taxNumber', 'local_id' => 'prazdne' ),
				array( 'remote_id' => 'email2', 'local_id' => 'm2' ),
				array( 'remote_id' => 'cf:Pocet_zam_a1b2c', 'local_id' => 'zam' ),
				array( 'remote_id' => 'cf:Velikost_d3e4f', 'local_id' => 'vel' ),
				array( 'remote_id' => 'cf:Termin_g5h6', 'local_id' => 'ter' ),
				array( 'remote_id' => 'cf:VIP_b91d1', 'local_id' => 'vip' ),
			),
		);

		$custom_record = new class( $custom_settings, array(
			'm'   => array( 'value' => 'firma@example.cz' ),
			// Number fields: Elementor intval()s the value; what was typed is in raw_value.
			'ico'     => array( 'type' => 'number', 'value' => 2795281, 'raw_value' => '02795281' ),
			'prazdne' => array( 'type' => 'number', 'value' => 0, 'raw_value' => '' ),
			'm2'  => array( 'value' => 'fakturace@example.cz' ),
			'zam' => array( 'type' => 'number', 'value' => 1250, 'raw_value' => '1 250' ),
			'vel' => array( 'value' => 'Obrovská' ),
			'ter' => array( 'value' => '1. 12. 2026' ),
			'vip' => array( 'value' => 'on' ),
		) ) {
			private $fs;
			private $f;
			public function __construct( $fs, $f ) {
				$this->fs = $fs;
				$this->f  = $f;
			}
			public function get( $k ) {
				return 'form_settings' === $k ? $this->fs : ( 'fields' === $k ? $this->f : null );
			}
		};

		// The popup posts itself as the form's post; the page it was shown on
		// comes as queried_id, and that is the URL the note should carry.
		$_POST['queried_id'] = (string) $host;

		$custom_error   = '';
		$custom_handler = new class {
			public $data = array();
			public function add_response_data( $k, $v ) {
				$this->data[ $k ] = $v;
			}
		};

		try {
			$action->run( $custom_record, $custom_handler );
		} catch ( \Exception $e ) {
			$custom_error = $e->getMessage();
		}

		unset( $_POST['queried_id'] );
		check( 'odeslání s vlastními poli projde', $custom_error, '' );

		wp_cache_flush();
		$custom_lead = null;

		foreach ( get_option( 'raynet_test_http_calls', array() ) as $call ) {
			if ( false !== strpos( $call['url'], '/lead/' ) ) {
				$custom_lead = json_decode( $call['body'], true );
			}
		}

		check( 'lead odeslán', null !== $custom_lead, true );
		check( 'vlastní pole s typy', isset( $custom_lead['customFields'] ) ? $custom_lead['customFields'] : null, array( 'Pocet_zam_a1b2c' => 1250, 'Termin_g5h6' => '2026-12-01', 'VIP_b91d1' => true ) );
		check( 'IČO z číselného pole s úvodní nulou', isset( $custom_lead['regNumber'] ) ? $custom_lead['regNumber'] : '', '02795281' );
		check( 'prázdné číselné pole se neposlalo jako 0', isset( $custom_lead['taxNumber'] ), false );
		check( 'poznámka nese stránku, ne šablonu popupu', isset( $custom_lead['notice'] ) && false !== strpos( $custom_lead['notice'], get_permalink( $host ) ), true );
		check( 'poznámka nenese adresu šablony', isset( $custom_lead['notice'] ) && false !== strpos( $custom_lead['notice'], 'elementor_library' ), false );
		check( 'druhý e-mail v leadu', isset( $custom_lead['contactInfo']['email2'] ) ? $custom_lead['contactInfo']['email2'] : '', 'fakturace@example.cz' );
		check( 'hodnota mimo číselník v poznámce', isset( $custom_lead['notice'] ) && false !== strpos( $custom_lead['notice'], 'Velikost zakázky: Obrovská' ), true );

		// The editor script gets the same rows, custom fields included.
		raynet_lead_elementor_editor_scripts();
		$editor_data = wp_scripts()->get_data( 'raynet-elementor-editor', 'data' );
		check( 'editor skript zařazen', wp_script_is( 'raynet-elementor-editor', 'enqueued' ), true );
		check( 'editor dostane vlastní pole', false !== strpos( (string) $editor_data, 'cf:Velikost_d3e4f' ), true );

		// A page with two forms gets one rollback form, not one per row.
		$twin = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Dva formuláře', 'post_status' => 'publish' ) );
		update_post_meta( $twin, '_elementor_edit_mode', 'builder' );
		update_post_meta( $twin, '_elementor_data', wp_slash( wp_json_encode( array_merge( $form_layout( 'twin1', 'První' ), $form_layout( 'twin2', 'Druhý' ) ) ) ) );
		check( 'oba formuláře stránky nastaveny', Raynet_Elementor_Forms::apply( $twin, array( 'twin1', 'twin2' ), array(), true ), array( 'twin1', 'twin2' ) );

		// The screen over HTTP: the new places and the fields panel.
		$screen = req( '/wp-admin/admin.php?page=raynet-elementor-forms', null, true );
		check( 'obrazovka ukáže popup', false !== strpos( $screen['body'], 'Popup poptávka' ), true );
		check( 'obrazovka ukáže globální widget', false !== strpos( $screen['body'], 'Globální widget' ), true );
		check( 'obrazovka ukáže panel polí', false !== strpos( $screen['body'], 'Pole z RAYNETu' ), true );
		check( 'obrazovka vypíše vlastní pole', false !== strpos( $screen['body'], 'Pocet_zam_a1b2c' ), true );
		check( 'nevykreslovaná stránka nejde vybrat', (bool) preg_match( '/value="' . $classic . ':oldform"[^>]*disabled/', $screen['body'] ), true );
		check( 'atomový formulář nejde vybrat', (bool) preg_match( '/value="' . $atomic_page . ':atomform"[^>]*disabled/', $screen['body'] ), true );
		check( 'atomový formulář je vysvětlený', false !== strpos( $screen['body'], 'atomový formulář Elementoru 4' ), true );
		check( 'stránka se dvěma formuláři má jediný formulář zálohy', substr_count( $screen['body'], 'id="raynet-restore-' . $twin . '"' ), 1 );
		check( 'a obě tlačítka na něj míří', substr_count( $screen['body'], 'form="raynet-restore-' . $twin . '"' ), 2 );

		preg_match( '/name="action" value="raynet_elm_refresh_fields".*?name="_wpnonce" value="([^"]+)"/s', $screen['body'], $refresh_nonce );
		delete_option( Raynet_Lead_Fields::OPTION );
		$refreshed = req(
			'/wp-admin/admin-post.php',
			array(
				'action'   => 'raynet_elm_refresh_fields',
				'_wpnonce' => isset( $refresh_nonce[1] ) ? $refresh_nonce[1] : '',
			),
			true
		);
		wp_cache_flush();
		check( 'tlačítko načtení přesměruje', $refreshed['status'], 302 );
		check( 'tlačítko pole znovu načte', count( Raynet_Lead_Fields::custom() ), 4 );

		// --- Mapping a form field by field ------------------------------------
		$map_page = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Mapovaná stránka', 'post_status' => 'publish' ) );
		update_post_meta( $map_page, '_elementor_edit_mode', 'builder' );
		update_post_meta(
			$map_page,
			'_elementor_data',
			wp_slash(
				wp_json_encode(
					array(
						array(
							'id'         => 'mapform',
							'elType'     => 'widget',
							'widgetType' => 'form',
							'settings'   => array(
								'form_name'   => 'Mapovaný',
								'form_fields' => array(
									array( '_id' => 'mj', 'custom_id' => 'mj', 'field_type' => 'text', 'field_label' => 'Jméno a příjmení' ),
									array( '_id' => 'me', 'custom_id' => 'me', 'field_type' => 'email', 'field_label' => 'E-mail' ),
									array( '_id' => 'mb', 'custom_id' => 'mb', 'field_type' => 'text', 'field_label' => 'Barva auta' ),
									array( '_id' => 'mn', 'custom_id' => 'mn', 'field_type' => 'number', 'field_label' => 'Počet zaměstnanců' ),
									array( '_id' => 'mg', 'custom_id' => 'mg', 'field_type' => 'acceptance', 'field_label' => 'Souhlasím' ),
									array( '_id' => 'mc', 'custom_id' => 'mc', 'field_type' => 'acceptance', 'field_label' => 'gdpr' ),
								),
							),
							'elements'   => array(),
						),
					)
				)
			)
		);

		$map_key  = $map_page . ':mapform';
		$map_path = '/wp-admin/admin.php?page=raynet-elementor-forms&mapovat=' . rawurlencode( $map_key );
		$map_view = req( $map_path, null, true );

		check( 'obrazovka mapování se načte', $map_view['status'], 200 );
		check( 'vypíše pole formuláře', false !== strpos( $map_view['body'], 'name="target[mb]"' ), true );
		check( 'nevypíše, co nejde poslat', false !== strpos( $map_view['body'], 'name="target[cap]"' ), false );
		check( 'nabízí vlastní pole ve skupině', false !== strpos( $map_view['body'], 'Vlastní pole z RAYNETu' ), true );
		check( 'návrhy jsou označené', substr_count( $map_view['body'], 'raynet-elm__proposed' ) >= 1, true );
		check( 'pole bez protějšku navrženo do poznámky', (bool) preg_match( '/name="target\[mb\]".*?value="__notice"\s+selected/s', $map_view['body'] ), true );
		check( 'celé jméno navrženo podle popisku', (bool) preg_match( '/name="target\[mj\]".*?value="fullName"\s+selected/s', $map_view['body'] ), true );
		check( 'zaškrtávátko gdpr navrženo jako souhlas', (bool) preg_match( '/name="target\[mc\]".*?value="gdprConsent"\s+selected/s', $map_view['body'] ), true );

		$scan_before = array_values( array_filter( Raynet_Elementor_Forms::scan(), function ( $row ) {
			return 'mapform' === $row['widget_id'];
		} ) );
		check( 'přehled hlásí pole bez určení', $scan_before[0]['undecided'], 6 );

		preg_match( '/name="action" value="raynet_elm_save_mapping".*?name="_wpnonce" value="([^"]+)"/s', $map_view['body'], $map_nonce );
		$map_nonce = isset( $map_nonce[1] ) ? $map_nonce[1] : '';

		// Two fields on one attribute: refused, and the screen says why.
		$refused = req(
			'/wp-admin/admin-post.php',
			array(
				'action'   => 'raynet_elm_save_mapping',
				'_wpnonce' => $map_nonce,
				'form'     => $map_key,
				'target'   => array( 'mj' => 'email', 'me' => 'email' ),
				'enable'   => '1',
			),
			true
		);
		check( 'duplicita přesměruje zpět', $refused['status'], 302 );
		$refused_view = req( $map_path . '&raynet_error=mapping', null, true );
		check( 'duplicita je vysvětlená', false !== strpos( $refused_view['body'], 'je vybraný u dvou polí' ), true );
		check( 'výběr zůstal zachovaný', (bool) preg_match( '/name="target\[mj\]".*?value="email"\s+selected/s', $refused_view['body'] ), true );
		wp_cache_flush();
		check( 'nic se nezapsalo', Raynet_Elementor_Forms::has_backup( $map_page ), false );

		$saved = req(
			'/wp-admin/admin-post.php',
			array(
				'action'   => 'raynet_elm_save_mapping',
				'_wpnonce' => $map_nonce,
				'form'     => $map_key,
				'target'   => array( 'mj' => 'fullName', 'me' => 'email', 'mb' => '__notice', 'mn' => 'cf:Pocet_zam_a1b2c', 'mg' => '-', 'mc' => 'gdprConsent' ),
				'enable'   => '1',
			),
			true
		);
		check( 'uložení přesměruje', $saved['status'], 302 );
		wp_cache_flush();

		$map_settings = Raynet_Elementor_Forms::form_settings( $map_page, 'mapform' );
		check( 'akce zapnutá', in_array( 'raynet_crm', Raynet_Elementor_Forms::submit_actions( $map_settings ), true ), true );
		check( 'výchozí akce zachované', in_array( 'email', Raynet_Elementor_Forms::submit_actions( $map_settings ), true ), true );
		$saved_map = Raynet_Elementor_Forms::current_map( $map_settings );
		ksort( $saved_map );
		check( 'mapování uložené', $saved_map, array( 'cf:Pocet_zam_a1b2c' => 'mn', 'email' => 'me', 'fullName' => 'mj', 'gdprConsent' => 'mc' ) );
		check( 'poznámka uložená', $map_settings['raynet_crm_notice_fields'], array( 'mb' ) );
		check( 'souhlas vynechaný', $map_settings['raynet_crm_ignored_fields'], array( 'mg' ) );
		check( 'záloha vznikla', Raynet_Elementor_Forms::has_backup( $map_page ), true );

		$scan_after = array_values( array_filter( Raynet_Elementor_Forms::scan(), function ( $row ) {
			return 'mapform' === $row['widget_id'];
		} ) );
		check( 'po uložení nic bez určení', $scan_after[0]['undecided'], 0 );
		check( 'přehled ukáže počet v poznámce', $scan_after[0]['noted'], 1 );

		$saved_view = req( $map_path . '&raynet_done=mapped', null, true );
		check( 'potvrzení uložení', false !== strpos( $saved_view['body'], 'Mapování uloženo' ), true );
		check( 'uložené se už nenavrhuje', substr_count( $saved_view['body'], 'raynet-elm__proposed' ), 0 );
		check( 'u zapnutého formuláře není matoucí zaškrtávátko', false !== strpos( $saved_view['body'], 'name="enable"' ), false );

		// A draft with a field the page does not have yet: saving the screen
		// changes the fields changed here and leaves the draft's own alone.
		$draft_tree = json_decode( get_post_meta( $map_page, '_elementor_data', true ), true );
		$draft_tree[0]['settings']['form_fields'][] = array( '_id' => 'mf', 'custom_id' => 'mf', 'field_type' => 'text', 'field_label' => 'Firma' );
		foreach ( $draft_tree[0]['settings']['raynet_crm_fields_map'] as &$draft_row ) {
			if ( 'companyName' === $draft_row['remote_id'] ) {
				$draft_row['local_id'] = 'mf';
			}
		}
		unset( $draft_row );

		$map_draft = (int) wp_insert_post( array( 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $map_page, 'post_name' => $map_page . '-autosave-v1', 'post_author' => get_current_user_id() ) );
		update_metadata( 'post', $map_draft, '_elementor_data', wp_slash( wp_json_encode( $draft_tree ) ) );
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2020-01-01 00:00:00', 'post_modified' => '2020-01-01 00:00:00' ), array( 'ID' => $map_page ) );
		$wpdb->update( $wpdb->posts, array( 'post_modified_gmt' => '2021-01-01 00:00:00', 'post_modified' => '2021-01-01 00:00:00' ), array( 'ID' => $map_draft ) );
		clean_post_cache( $map_page );
		clean_post_cache( $map_draft );

		$draft_view = req( $map_path, null, true );
		check( 'obrazovka upozorní na koncept', false !== strpos( $draft_view['body'], 'neuložený koncept' ), true );

		req(
			'/wp-admin/admin-post.php',
			array(
				'action'   => 'raynet_elm_save_mapping',
				'_wpnonce' => $map_nonce,
				'form'     => $map_key,
				'target'   => array( 'mj' => 'fullName', 'me' => 'email', 'mb' => '-', 'mn' => 'cf:Pocet_zam_a1b2c', 'mg' => '-', 'mc' => 'gdprConsent' ),
			),
			true
		);
		wp_cache_flush();

		$draft_after = json_decode( get_metadata( 'post', $map_draft, '_elementor_data', true ), true );
		$draft_map   = Raynet_Elementor_Forms::current_map( $draft_after[0]['settings'] );
		check( 'koncept si nechal mapování svého pole', isset( $draft_map['companyName'] ) ? $draft_map['companyName'] : '', 'mf' );
		check( 'koncept dostal změnu z obrazovky', in_array( 'mb', Raynet_Elementor_Forms::id_list( $draft_after[0]['settings'], 'raynet_crm_ignored_fields' ), true ), true );
		check( 'koncept si nechal ostatní mapování', isset( $draft_map['email'] ) ? $draft_map['email'] : '', 'me' );
		check( 'stránka dostala změnu taky', Raynet_Elementor_Forms::field_targets( Raynet_Elementor_Forms::form_settings( $map_page, 'mapform' ) )['mb'], '-' );
		wp_delete_post( $map_draft, true );

		// Back to the note for the submission test below.
		Raynet_Elementor_Forms::save_targets( $map_page, 'mapform', array( 'mj' => 'fullName', 'me' => 'email', 'mb' => '__notice', 'mn' => 'cf:Pocet_zam_a1b2c', 'mg' => '-', 'mc' => 'gdprConsent' ), false );
		$map_settings = Raynet_Elementor_Forms::form_settings( $map_page, 'mapform' );

		// A submission writes the noted field under its label.
		update_option( 'raynet_test_http_calls', array() );
		$map_record_settings = array_merge( $map_settings, array( 'form_post_id' => $map_page ) );
		$map_record_settings['raynet_crm_notice_fields'][] = 'me';
		$map_record_settings['raynet_crm_fields_map'][]    = array( 'remote_id' => 'otherContact', 'local_id' => 'pw' );
		$map_record = new class( $map_record_settings, array(
			'pw' => array( 'type' => 'password', 'title' => 'Heslo', 'value' => 'tajne123' ),
			'mj' => array( 'type' => 'text', 'title' => 'Jméno a příjmení', 'value' => 'Eva Malá' ),
			'me' => array( 'type' => 'email', 'title' => 'E-mail', 'value' => 'eva@example.cz' ),
			'mb' => array( 'type' => 'text', 'title' => 'Barva auta', 'value' => 'červená' ),
			'mn' => array( 'type' => 'number', 'title' => 'Počet zaměstnanců', 'value' => 12, 'raw_value' => '12' ),
			'mg' => array( 'type' => 'acceptance', 'title' => 'Souhlasím', 'value' => 'on' ),
		) ) {
			private $fs;
			private $f;
			public function __construct( $fs, $f ) {
				$this->fs = $fs;
				$this->f  = $f;
			}
			public function get( $k ) {
				return 'form_settings' === $k ? $this->fs : ( 'fields' === $k ? $this->f : null );
			}
		};

		$map_error = '';

		try {
			$action->run( $map_record, $custom_handler );
		} catch ( \Exception $e ) {
			$map_error = $e->getMessage();
		}

		check( 'odeslání projde', $map_error, '' );
		wp_cache_flush();
		$map_lead = null;

		foreach ( get_option( 'raynet_test_http_calls', array() ) as $call ) {
			if ( false !== strpos( $call['url'], '/lead/' ) ) {
				$map_lead = json_decode( $call['body'], true );
			}
		}

		check( 'pole v poznámce pod popiskem', isset( $map_lead['notice'] ) && false !== strpos( $map_lead['notice'], 'Barva auta: červená' ), true );
		check( 'namapované pole se v poznámce neopakuje', isset( $map_lead['notice'] ) && false !== strpos( $map_lead['notice'], 'E-mail: eva@example.cz' ), false );
		check( 'vynechané pole v poznámce není', isset( $map_lead['notice'] ) && false !== strpos( $map_lead['notice'], 'Souhlasím' ), false );
		check( 'namapované jméno rozdělené', isset( $map_lead['lastName'] ) ? $map_lead['lastName'] : '', 'Malá' );
		check( 'vlastní pole odešlo', isset( $map_lead['customFields']['Pocet_zam_a1b2c'] ) ? $map_lead['customFields']['Pocet_zam_a1b2c'] : null, 12 );
		check( 'heslo neodešlo ani namapované', false !== strpos( (string) wp_json_encode( $map_lead ), 'tajne123' ), false );

		// The same choice in Elementor's editor.
		$notice_control = $form_widget ? $form_widget->get_controls( 'raynet_crm_notice_fields' ) : null;
		check( 'výběr do poznámky je v editoru', $notice_control ? $notice_control['type'] : '', 'select2' );
		check( 'výběr do poznámky je vícenásobný', $notice_control ? ! empty( $notice_control['multiple'] ) : false, true );

		$exported_notice = $action->on_export( array( 'settings' => array( 'raynet_crm_notice_fields' => array( 'mb' ), 'raynet_crm_ignored_fields' => array( 'mg' ) ) ) );
		check( 'export odstraní výběr do poznámky', isset( $exported_notice['settings']['raynet_crm_notice_fields'] ), false );

		// Bulk apply can send the rest to the note as well.
		Raynet_Elementor_Forms::apply( $map_page, 'mapform', array(), true, true );
		$after_bulk = Raynet_Elementor_Forms::field_targets( Raynet_Elementor_Forms::form_settings( $map_page, 'mapform' ) );
		check( 'hromadné nasazení nezmění rozhodnuté', array( $after_bulk['mb'], $after_bulk['mg'], $after_bulk['mj'] ), array( '__notice', '-', 'fullName' ) );

		wp_delete_post( $map_page, true );

		// --- GDPR consent from a ticked box -----------------------------------
		$gdpr_before = get_option( Raynet_Lead_Settings::OPTION );
		update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array_merge( (array) $gdpr_before, array( 'gdpr_template' => '5', 'gdpr_form_agreement' => '2' ) ) ) );

		$gdpr_settings = array(
			'form_post_id'          => $page_id,
			'raynet_crm_fields_map' => array(
				array( 'remote_id' => 'email', 'local_id' => 'e' ),
				array( 'remote_id' => 'gdprConsent', 'local_id' => 'gdpr' ),
			),
		);
		$gdpr_record = function ( $ticked ) use ( $gdpr_settings ) {
			return new class( $gdpr_settings, array(
				'e'    => array( 'type' => 'email', 'title' => 'E-mail', 'value' => 'souhlas@example.cz' ),
				'gdpr' => array( 'type' => 'acceptance', 'title' => 'Souhlasím se zpracováním osobních údajů', 'value' => $ticked ? 'on' : '' ),
			) ) {
				private $fs;
				private $f;
				public function __construct( $fs, $f ) {
					$this->fs = $fs;
					$this->f  = $f;
				}
				public function get( $k ) {
					return 'form_settings' === $k ? $this->fs : ( 'fields' === $k ? $this->f : null );
				}
			};
		};
		$gdpr_calls = function () {
			wp_cache_flush();
			$out = array( 'lead' => null, 'gdpr' => null );
			foreach ( get_option( 'raynet_test_http_calls', array() ) as $call ) {
				if ( false !== strpos( $call['url'], '/lead/' ) ) {
					$out['lead'] = json_decode( $call['body'], true );
				} elseif ( false !== strpos( $call['url'], '/gdpr/' ) ) {
					$out['gdpr'] = json_decode( $call['body'], true );
				}
			}
			return $out;
		};

		update_option( 'raynet_test_http_calls', array() );
		$action->run( $gdpr_record( true ), $custom_handler );
		$ticked_calls = $gdpr_calls();
		check( 'zaškrtnutý souhlas: lead', null !== $ticked_calls['lead'], true );
		check( 'zaškrtnutý souhlas: poznámka s datem a zněním', isset( $ticked_calls['lead']['notice'] ) && false !== strpos( $ticked_calls['lead']['notice'], 'Souhlas se zpracováním údajů udělen' ) && false !== strpos( $ticked_calls['lead']['notice'], 'Souhlasím se zpracováním osobních údajů' ), true );
		check( 'zaškrtnutý souhlas: GDPR záznam', null !== $ticked_calls['gdpr'], true );
		check( 'GDPR záznam u nového leadu', isset( $ticked_calls['gdpr']['lead'] ) ? $ticked_calls['gdpr']['lead'] : 0, 4242 );
		check( 'GDPR záznam se šablonou z nastavení', isset( $ticked_calls['gdpr']['gdprTemplate'] ) ? $ticked_calls['gdpr']['gdprTemplate'] : 0, 5 );
		check( 'GDPR záznam s formou souhlasu', isset( $ticked_calls['gdpr']['gdprFormAgreement'] ) ? $ticked_calls['gdpr']['gdprFormAgreement'] : 0, 2 );
		check( 'souhlas neodešel jako atribut leadu', isset( $ticked_calls['lead']['gdprConsent'] ), false );

		update_option( 'raynet_test_http_calls', array() );
		$action->run( $gdpr_record( false ), $custom_handler );
		$unticked_calls = $gdpr_calls();
		check( 'nezaškrtnuto: lead vznikne', null !== $unticked_calls['lead'], true );
		check( 'nezaškrtnuto: žádný GDPR záznam', $unticked_calls['gdpr'], null );
		check( 'nezaškrtnuto: poznámka souhlas netvrdí', isset( $unticked_calls['lead']['notice'] ) && false !== strpos( $unticked_calls['lead']['notice'], 'Souhlas se zpracováním' ), false );

		// A mapped consent field decides even when the old switch is on.
		$gdpr_switch = $gdpr_settings;
		$gdpr_switch['raynet_crm_consent_note'] = 'yes';
		update_option( 'raynet_test_http_calls', array() );
		$switch_record = new class( $gdpr_switch, array(
			'e'    => array( 'type' => 'email', 'title' => 'E-mail', 'value' => 'souhlas@example.cz' ),
			'gdpr' => array( 'type' => 'acceptance', 'title' => 'Souhlasím', 'value' => '' ),
		) ) {
			private $fs;
			private $f;
			public function __construct( $fs, $f ) {
				$this->fs = $fs;
				$this->f  = $f;
			}
			public function get( $k ) {
				return 'form_settings' === $k ? $this->fs : ( 'fields' === $k ? $this->f : null );
			}
		};
		$action->run( $switch_record, $custom_handler );
		check( 'namapované pole přebije přepínač', $gdpr_calls()['gdpr'], null );

		// The older switch alone: consent only if the box was ticked, and only
		// in the note — a formal GDPR record needs a mapped, ticked box.
		$switch_only = array(
			'form_post_id'            => $page_id,
			'raynet_crm_consent_note' => 'yes',
			'raynet_crm_fields_map'   => array( array( 'remote_id' => 'email', 'local_id' => 'e' ) ),
		);
		foreach ( array( 'on' => true, '' => false ) as $box => $given ) {
			update_option( 'raynet_test_http_calls', array() );
			$action->run( new class( $switch_only, array(
				'e' => array( 'type' => 'email', 'title' => 'E-mail', 'value' => 'prepinac@example.cz' ),
				'g' => array( 'type' => 'acceptance', 'title' => 'Souhlas', 'value' => $box ),
			) ) {
				private $fs;
				private $f;
				public function __construct( $fs, $f ) {
					$this->fs = $fs;
					$this->f  = $f;
				}
				public function get( $k ) {
					return 'form_settings' === $k ? $this->fs : ( 'fields' === $k ? $this->f : null );
				}
			}, $custom_handler );
			$switch_calls = $gdpr_calls();
			check( 'přepínač, pole ' . ( $given ? 'zaškrtnuté' : 'nezaškrtnuté' ) . ': souhlas v poznámce', isset( $switch_calls['lead']['notice'] ) && false !== strpos( $switch_calls['lead']['notice'], 'Souhlas se zpracováním údajů udělen' ), $given );
			check( 'přepínač, pole ' . ( $given ? 'zaškrtnuté' : 'nezaškrtnuté' ) . ': žádný GDPR záznam', $switch_calls['gdpr'], null );
		}

		// The wording in the note is what the visitor read, not the label.
		$worded = $gdpr_settings;
		$worded['form_fields'] = array(
			array( 'custom_id' => 'gdpr', 'field_type' => 'acceptance', 'field_label' => 'gdpr', 'acceptance_text' => 'Souhlasím se <a href="/osobni-udaje">zpracováním osobních údajů</a> pro vyřízení poptávky' ),
		);
		update_option( 'raynet_test_http_calls', array() );
		$action->run( new class( $worded, array(
			'e'    => array( 'type' => 'email', 'title' => 'E-mail', 'value' => 'zneni@example.cz' ),
			'gdpr' => array( 'type' => 'acceptance', 'title' => 'gdpr', 'value' => 'on' ),
		) ) {
			private $fs;
			private $f;
			public function __construct( $fs, $f ) {
				$this->fs = $fs;
				$this->f  = $f;
			}
			public function get( $k ) {
				return 'form_settings' === $k ? $this->fs : ( 'fields' === $k ? $this->f : null );
			}
		}, $custom_handler );
		$worded_calls = $gdpr_calls();
		check( 'znění souhlasu z textu zaškrtávátka', isset( $worded_calls['lead']['notice'] ) && false !== strpos( $worded_calls['lead']['notice'], 'Souhlasím se zpracováním osobních údajů pro vyřízení poptávky' ), true );
		check( 'znění bez HTML', isset( $worded_calls['lead']['notice'] ) && false !== strpos( $worded_calls['lead']['notice'], '<a' ), false );

		// The lead exists before the consent is recorded; a refused GDPR record
		// must not turn the visitor's submission into an error.
		$refuse_gdpr = function ( $preempt, $args, $url ) {
			if ( false !== strpos( $url, '/gdpr/' ) ) {
				return array( 'headers' => array(), 'cookies' => array(), 'filename' => null, 'body' => wp_json_encode( array( 'message' => 'Neplatná šablona' ) ), 'response' => array( 'code' => 400, 'message' => 'Bad Request' ) );
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $refuse_gdpr, 5, 3 );
		delete_option( 'raynet_lead_last_error' );
		$gdpr_error = '';
		try {
			$action->run( $gdpr_record( true ), $custom_handler );
		} catch ( \Exception $e ) {
			$gdpr_error = $e->getMessage();
		}
		remove_filter( 'pre_http_request', $refuse_gdpr, 5 );
		check( 'odmítnutý GDPR záznam neshodí odeslání', $gdpr_error, '' );
		wp_cache_flush();
		$last_error = get_option( 'raynet_lead_last_error', array() );
		check( 'a chyba se zapamatuje pro správce', is_array( $last_error ) && false !== strpos( (string) $last_error['message'], 'GDPR' ), true );

		update_option( Raynet_Lead_Settings::OPTION, $gdpr_before );

		// The settings page offers the choice.
		$settings_view = req( '/wp-admin/admin.php?page=raynet-lead-integration', null, true );
		check( 'nastavení má sekci GDPR', false !== strpos( $settings_view['body'], 'GDPR souhlas v RAYNETu' ), true );
		check( 'nastavení má šablonu právního titulu', false !== strpos( $settings_view['body'], '[gdpr_template]' ), true );

		// --- Owner chosen from the RAYNET users -------------------------------
		delete_option( Raynet_Lead_Users::OPTION );
		check( 'uživatelé načteni', Raynet_Lead_Users::refresh(), 2 );
		check( 'vlastník je kontaktní osoba', array_keys( Raynet_Lead_Users::all() ), array( 11, 9 ) );

		$owner_before = get_option( Raynet_Lead_Settings::OPTION );
		update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array_merge( (array) $owner_before, array( 'owner' => '9' ) ) ) );

		$owner_view = req( '/wp-admin/admin.php?page=raynet-lead-integration', null, true );
		check( 'nastavení nabízí vlastníka výběrem', false !== strpos( $owner_view['body'], 'name="raynet_lead_settings[owner]"' ) && false !== strpos( $owner_view['body'], '<select id="raynet-owner"' ), true );
		check( 'nastavení ukazuje jména', false !== strpos( $owner_view['body'], 'Jana Nováková (jana@firma.cz)' ), true );
		check( 'uložený vlastník je vybraný', (bool) preg_match( '/value="9" selected/', $owner_view['body'] ), true );
		check( 'řádek označený INVALID se nenabízí', false !== strpos( $owner_view['body'], 'Bývalý Kolega' ), false );

		preg_match( '/href="([^"]*action=raynet_lead_refresh_users[^"]*)"/', $owner_view['body'], $refresh_link );
		check( 'odkaz na nové načtení', isset( $refresh_link[1] ), true );
		$refreshed_users = req( html_entity_decode( isset( $refresh_link[1] ) ? $refresh_link[1] : '/wp-admin/admin-post.php?action=raynet_lead_refresh_users' ), null, true );
		check( 'nové načtení přesměruje', $refreshed_users['status'], 302 );
		check( 'výsledek není v adrese', false !== strpos( (string) $refreshed_users['body'] . implode( '', (array) $refreshed_users ), 'raynet_users=' ), false );
		$after_refresh = req( '/wp-admin/admin.php?page=raynet-lead-integration', null, true );
		check( 'po načtení jedna hláška', false !== strpos( $after_refresh['body'], 'Uživatelé z RAYNETu načteni.' ), true );
		$after_save = req( '/wp-admin/admin.php?page=raynet-lead-integration', null, true );
		check( 'hláška se po dalším načtení stránky neopakuje', false !== strpos( $after_save['body'], 'Uživatelé z RAYNETu načteni.' ), false );
		$forged_refresh = req( '/wp-admin/admin-post.php?action=raynet_lead_refresh_users', null, true );
		check( 'načtení bez nonce odmítnuto', 302 === $forged_refresh['status'], false );

		$tpl_view = req( '/wp-admin/admin.php?page=raynet-elementor-forms', null, true );
		check( 'šablona nabízí vlastníka výběrem', false !== strpos( $tpl_view['body'], '<select id="raynet-tpl-owner" name="lead[owner]"' ), true );

		$builder_view = req( '/wp-admin/post.php?post=' . $default . '&action=edit', null, true );
		check( 'builder nabízí vlastníka výběrem', false !== strpos( $builder_view['body'], 'name="raynet_form_lead[owner]"' ) && false !== strpos( $builder_view['body'], '<select id="raynet-lead-owner"' ), true );

		// The Elementor control, registered against a recording stand-in.
		$recorder = new class {
			public $controls = array();
			public function start_controls_section( $id, $args ) {}
			public function end_controls_section() {}
			public function add_control( $id, $args ) {
				$this->controls[ $id ] = $args;
			}
		};
		if ( class_exists( 'Raynet_Elementor_Form_Action' ) ) {
			( new Raynet_Elementor_Form_Action() )->register_settings_section( $recorder );
			$owner_control = isset( $recorder->controls['raynet_crm_owner'] ) ? $recorder->controls['raynet_crm_owner'] : array();
			check( 'Elementor nabízí vlastníka výběrem', isset( $owner_control['type'] ) ? $owner_control['type'] : '', 'select' );
			check( 'Elementor má jména a ID osob', isset( $owner_control['options']['11'] ) ? $owner_control['options']['11'] : '', 'Jana Nováková' );
			check( 'Elementor nevidí e-maily kolegů', false !== strpos( wp_json_encode( $owner_control['options'] ), '@firma.cz' ), false );
		}

		// A template's owner reaches the form and the lead as the person id.
		$owner_slug = Raynet_Elementor_Forms::save_template( 'Pro Janu', array( 'owner' => '11' ) );
		check( 'šablona uloží vlastníka', Raynet_Elementor_Forms::templates()[ $owner_slug ]['lead']['owner'], 11 );
		check( 'nastavení formuláře nese ID osoby', Raynet_Elementor_Forms::configure( array(), Raynet_Elementor_Forms::templates()[ $owner_slug ]['lead'], false )['raynet_crm_owner'], '11' );
		Raynet_Elementor_Forms::delete_template( $owner_slug );

		// The key may not be allowed to list users: say so, and keep the id box.
		update_option( 'raynet_test_refuse_users', 1 );
		delete_option( Raynet_Lead_Users::OPTION );
		check( 'odmítnuté načtení je chyba', is_wp_error( Raynet_Lead_Users::refresh() ), true );
		$refused_view = req( '/wp-admin/admin.php?page=raynet-lead-integration', null, true );
		check( 'bez seznamu číselné pole s hodnotou', (bool) preg_match( '/<input type="number" min="0" id="raynet-owner" name="raynet_lead_settings\[owner\]" value="9"/', $refused_view['body'] ), true );
		check( 'chyba načtení je u vlastníka vidět', false !== strpos( $refused_view['body'], 'Seznam uživatelů z RAYNETu se nepodařilo načíst' ), true );
		check( 'u vlastníka je skutečný důvod (403)', false !== strpos( $refused_view['body'], 'nemá v RAYNETu oprávnění vypisovat uživatele (403)' ), true );
		check( 'odkaz pro načtení je i bez seznamu', false !== strpos( $refused_view['body'], 'Načíst uživatele z RAYNETu' ), true );

		preg_match( '/raynetLeadAdmin = \{[^;]*?"nonce":"([^"]+)"/', $refused_view['body'], $test_nonce );
		$connection_test = req(
			'/wp-admin/admin-ajax.php',
			array(
				'action' => 'raynet_lead_test_connection',
				'nonce'  => isset( $test_nonce[1] ) ? $test_nonce[1] : '',
			),
			true
		);
		$connection_json = json_decode( $connection_test['body'], true );
		check( 'test spojení varuje, že uživatelé chybí', isset( $connection_json['data']['warning'] ) && false !== strpos( $connection_json['data']['warning'], 'Uživatele z RAYNETu se nepodařilo načíst' ), true );
		delete_option( 'raynet_test_refuse_users' );
		Raynet_Lead_Users::refresh();

		// A refresh that fails over a list fetched before keeps that list,
		// and the warning says so rather than "only by number".
		$connection_test = req( '/wp-admin/admin-ajax.php', array( 'action' => 'raynet_lead_test_connection', 'nonce' => isset( $test_nonce[1] ) ? $test_nonce[1] : '' ), true );
		$connection_json = json_decode( $connection_test['body'], true );
		$owner_list      = isset( $connection_json['data']['codeLists']['Uživatelé — vlastník leadu (owner)'] ) ? $connection_json['data']['codeLists']['Uživatelé — vlastník leadu (owner)'] : array();
		check( 'test spojení vypíše uživatele podle jména', array_column( $owner_list, 'label' ), array( 'Jana Nováková (jana@firma.cz)', 'Petr Svoboda (petr@firma.cz)' ) );
		check( 'test spojení nese ID osob', array_column( $owner_list, 'id' ), array( '11', '9' ) );
		check( 'úspěšný test bez varování', isset( $connection_json['data']['warning'] ) ? $connection_json['data']['warning'] : null, '' );

		update_option( 'raynet_test_refuse_users', 1 );
		$connection_test = req( '/wp-admin/admin-ajax.php', array( 'action' => 'raynet_lead_test_connection', 'nonce' => isset( $test_nonce[1] ) ? $test_nonce[1] : '' ), true );
		$connection_json = json_decode( $connection_test['body'], true );
		$kept_warning    = isset( $connection_json['data']['warning'] ) ? $connection_json['data']['warning'] : '';
		check( 'neobnovený seznam: varování o starém seznamu', false !== strpos( $kept_warning, 'výběr vlastníka nabízí ten načtený dřív' ), true );
		check( 'neobnovený seznam: netvrdí, že jen číslem', false !== strpos( $kept_warning, 'jen číslem' ), false );
		check( 'neobnovený seznam: uživatelé dál vypsaní', isset( $connection_json['data']['codeLists']['Uživatelé — vlastník leadu (owner)'] ), true );
		wp_cache_flush();
		check( 'neobnovený seznam zůstal', array_keys( Raynet_Lead_Users::all() ), array( 11, 9 ) );
		delete_option( 'raynet_test_refuse_users' );
		Raynet_Lead_Users::refresh();

		// After a change of connection the admin lands on the settings page;
		// the custom fields are fetched again there, not only on the forms screen.
		delete_option( Raynet_Lead_Fields::OPTION );
		req( '/wp-admin/admin.php?page=raynet-lead-integration', null, true );
		wp_cache_flush();
		check( 'nastavení znovu načte vlastní pole', count( Raynet_Lead_Fields::custom() ) > 0, true );

		update_option( Raynet_Lead_Settings::OPTION, $owner_before );

		// --- Attachments and the message label, through Elementor's own handler
		$files_page = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Poptávka s přílohou', 'post_status' => 'publish' ) );
		update_post_meta( $files_page, '_elementor_edit_mode', 'builder' );
		$files_form = array(
			'id'         => 'filesform',
			'elType'     => 'widget',
			'widgetType' => 'form',
			'settings'   => array(
				'form_name'             => 'S přílohou',
				'submit_actions'        => array( 'email', 'raynet_crm' ),
				'form_fields'           => array(
					array( '_id' => 'fn', 'custom_id' => 'fn', 'field_type' => 'text', 'field_label' => 'Jméno a příjmení' ),
					array( '_id' => 'fe', 'custom_id' => 'fe', 'field_type' => 'email', 'field_label' => 'E-mail' ),
					array( '_id' => 'fz', 'custom_id' => 'fz', 'field_type' => 'textarea', 'field_label' => 'Popis poptávky' ),
					array( '_id' => 'fp', 'custom_id' => 'fp', 'field_type' => 'upload', 'field_label' => 'Fotografie střechy', 'attachment_type' => 'attach', 'allow_multiple_upload' => 'yes', 'file_types' => 'pdf,jpg,txt' ),
				),
				'raynet_crm_fields_map' => array(
					array( 'remote_id' => 'fullName', 'local_id' => 'fn' ),
					array( 'remote_id' => 'email', 'local_id' => 'fe' ),
					array( 'remote_id' => 'message', 'local_id' => 'fz' ),
				),
			),
			'elements'   => array(),
		);
		update_post_meta( $files_page, '_elementor_data', wp_slash( wp_json_encode( array( $files_form ) ) ) );

		$upload_dir = wp_upload_dir();
		$sample     = trailingslashit( get_temp_dir() ) . 'nabidka-strechy.txt';
		file_put_contents( $sample, "Obsah přílohy pro RAYNET\n" );

		$send_files = function ( array $fields, array $files ) use ( $base, $files_page ) {
			$post = array(
				'action'     => 'elementor_pro_forms_send_form',
				'post_id'    => (string) $files_page,
				'form_id'    => 'filesform',
				'queried_id' => (string) $files_page,
			);
			foreach ( $fields as $key => $value ) {
				$post[ 'form_fields[' . $key . ']' ] = $value;
			}
			foreach ( $files as $key => $path ) {
				$post[ 'form_fields[' . $key . '][]' ] = new CURLFile( $path, 'text/plain', basename( $path ) );
			}
			$ch = curl_init( $base . '/wp-admin/admin-ajax.php' );
			curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_TIMEOUT => 60 ) );
			$body = (string) curl_exec( $ch );
			return json_decode( $body, true );
		};

		update_option( 'raynet_test_http_calls', array() );
		$files_response = $send_files(
			array( 'fn' => 'Karel Střecha', 'fe' => 'karel@example.cz', 'fz' => 'Prosím o nabídku zateplení.' ),
			array( 'fp' => $sample )
		);
		wp_cache_flush();

		$files_calls = get_option( 'raynet_test_http_calls', array() );
		$upload_call = null;
		$attach_call = null;
		$files_lead  = null;
		foreach ( $files_calls as $call ) {
			if ( false !== strpos( $call['url'], '/fileUpload' ) ) {
				$upload_call = $call;
			} elseif ( false !== strpos( $call['url'], '/attachment/' ) ) {
				$attach_call = $call;
			} elseif ( false !== strpos( $call['url'], '/lead/' ) ) {
				$files_lead = json_decode( $call['body'], true );
			}
		}

		check( 'odeslání s přílohou založilo lead', isset( $files_response['data']['data']['raynet_lead_id'] ) ? $files_response['data']['data']['raynet_lead_id'] : 0, 4242 );
		check( 'soubor se nahrál do RAYNETu', null !== $upload_call, true );
		check( 'nahrání je multipart s polem file', null !== $upload_call && false !== strpos( $upload_call['body'], 'name="file"; filename="nabidka-strechy.txt"' ), true );
		check( 'nahrál se obsah souboru', null !== $upload_call && false !== strpos( $upload_call['body'], 'Obsah přílohy pro RAYNET' ), true );
		check( 'soubor se připojil k leadu', null !== $attach_call && false !== strpos( $attach_call['url'], '/attachment/lead/4242/' ), true );
		check( 'připojení nese UUID', null !== $attach_call && false !== strpos( $attach_call['body'], 'test-uuid-' ), true );
		check( 'připojení až po založení leadu', null !== $files_lead && array_search( $attach_call, $files_calls, true ) > array_search( $upload_call, $files_calls, true ), true );
		check( 'poznámka jmenuje přílohu pod polem', isset( $files_lead['notice'] ) && false !== strpos( $files_lead['notice'], 'Fotografie střechy: nabidka-strechy.txt' ), true );
		check( 'zpráva v poznámce pod názvem pole', isset( $files_lead['notice'] ) && false !== strpos( $files_lead['notice'], "Popis poptávky:\nProsím o nabídku zateplení." ), true );

		$leftover = glob( trailingslashit( get_temp_dir() ) . 'raynet-att-*', GLOB_ONLYDIR );
		check( 'dočasné kopie po odeslání uklizené', is_array( $leftover ) ? count( $leftover ) : 0, 0 );

		// RAYNET is down: the fallback e-mail carries the file under its own name.
		$fallback_before = get_option( Raynet_Lead_Settings::OPTION );
		update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array_merge( (array) $fallback_before, array( 'fallback_email' => 'zaloha@example.cz' ) ) ) );
		update_option( 'raynet_test_refuse_leads', 1 );
		update_option( 'raynet_test_mails', array() );
		update_option( 'raynet_test_http_calls', array() );
		$send_files( array( 'fn' => 'Karel Střecha', 'fe' => 'karel@example.cz', 'fz' => 'RAYNET neběží.' ), array( 'fp' => $sample ) );
		delete_option( 'raynet_test_refuse_leads' );
		update_option( Raynet_Lead_Settings::OPTION, $fallback_before );
		wp_cache_flush();

		$fallback_mail = null;
		foreach ( get_option( 'raynet_test_mails', array() ) as $mail ) {
			if ( 'zaloha@example.cz' === $mail['to'] ) {
				$fallback_mail = $mail;
			}
		}
		$fallback_names = null !== $fallback_mail ? array_column( $fallback_mail['attachments'], 'name' ) : array();
		check( 'záložní e-mail odešel', null !== $fallback_mail, true );
		check( 'záložní e-mail nese přílohu pod původním jménem', $fallback_names, array( 'nabidka-strechy.txt' ) );
		check( 'příloha záložního e-mailu má obsah', null !== $fallback_mail && isset( $fallback_mail['attachments'][0] ) && false !== strpos( $fallback_mail['attachments'][0]['content'], 'Obsah přílohy' ), true );
		$fallback_uploads = 0;
		foreach ( get_option( 'raynet_test_http_calls', array() ) as $call ) {
			if ( false !== strpos( $call['url'], '/fileUpload' ) ) {
				$fallback_uploads++;
			}
		}
		check( 'bez leadu se do RAYNETu nic nenahrává', $fallback_uploads, 0 );

		// A visitor posting a server path as the upload field's value, with no
		// file, must not get that file sent anywhere.
		update_option( 'raynet_test_http_calls', array() );
		$send_files( array( 'fn' => 'Útočník', 'fe' => 'zly@example.cz', 'fz' => 'x', 'fp' => ABSPATH . 'wp-config.php' ), array() );
		wp_cache_flush();
		$spoof_uploads = 0;
		foreach ( get_option( 'raynet_test_http_calls', array() ) as $call ) {
			if ( false !== strpos( $call['url'], '/fileUpload' ) ) {
				$spoof_uploads++;
			}
		}
		check( 'podstrčená cesta se nenahraje', $spoof_uploads, 0 );

		// Left out on the mapping screen: not attached.
		Raynet_Elementor_Forms::save_targets( $files_page, 'filesform', array( 'fp' => '-' ), false );
		update_option( 'raynet_test_http_calls', array() );
		$send_files( array( 'fn' => 'Karel Střecha', 'fe' => 'karel@example.cz', 'fz' => 'Bez přílohy.' ), array( 'fp' => $sample ) );
		wp_cache_flush();
		$skipped_uploads = 0;
		foreach ( get_option( 'raynet_test_http_calls', array() ) as $call ) {
			if ( false !== strpos( $call['url'], '/fileUpload' ) ) {
				$skipped_uploads++;
			}
		}
		check( 'vynechané pole se nepřikládá', $skipped_uploads, 0 );

		$files_view = req( '/wp-admin/admin.php?page=raynet-elementor-forms&mapovat=' . rawurlencode( $files_page . ':filesform' ), null, true );
		check( 'obrazovka nabízí přiložení', false !== strpos( $files_view['body'], 'Přiložit k leadu jako přílohu' ), true );

		unlink( $sample );
		wp_delete_post( $files_page, true );

		// --- A cap that dropped the oldest pages -------------------------------
		// Two hundred newer posts saved with Elementor used to push an old
		// contact page out of the list.
		$old_page = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Kontakt z roku 2015', 'post_status' => 'publish', 'post_date' => '2015-01-01 10:00:00' ) );
		update_post_meta( $old_page, '_elementor_edit_mode', 'builder' );
		update_post_meta( $old_page, '_elementor_data', wp_slash( wp_json_encode( $form_layout( 'oldcontact', 'Kontakt' ) ) ) );

		$filler = array();

		for ( $i = 0; $i < 205; $i++ ) {
			$filler[] = $fid = (int) wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'Výplň ' . $i, 'post_status' => 'publish' ) );
			update_post_meta( $fid, '_elementor_data', '[]' );
		}

		check( 'stará kontaktní stránka nevypadne', in_array( 'oldcontact', array_column( Raynet_Elementor_Forms::scan(), 'widget_id' ), true ), true );

		foreach ( $filler as $fid ) {
			wp_delete_post( $fid, true );
		}

		foreach ( array( $popup, $footer, $global, $host, $classic, $old_page, $atomic_page, $twin, $scan_page ) as $cleanup ) {
			wp_delete_post( $cleanup, true );
		}

		$exported = $action->on_export( array( 'settings' => array( 'raynet_crm_owner' => 7, 'jine' => 'zustane' ) ) );
		check( 'on_export maže pod settings', isset( $exported['settings']['raynet_crm_owner'] ), false );
		check( 'on_export nechá cizí klíče', $exported['settings']['jine'], 'zustane' );

		echo "\n=== Import uložených odeslání ===\n";

		global $wpdb;

		$import_query = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
		$import_page  = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Import poptávek', 'post_status' => 'publish' ) );
		$import_map   = array(
			array( 'remote_id' => 'firstName', 'local_id' => 'name' ),
			array( 'remote_id' => 'email', 'local_id' => 'email' ),
			array( 'remote_id' => 'message', 'local_id' => 'msg' ),
		);
		$import_form  = function ( array $map ) {
			return array(
				array(
					'id'         => 'impform',
					'elType'     => 'widget',
					'widgetType' => 'form',
					'settings'   => array(
						'form_name'                => 'Import',
						'submit_actions'           => array( 'save-to-database' ),
						'form_fields'              => array(
							array( '_id' => 'name', 'custom_id' => 'name', 'field_type' => 'text', 'field_label' => 'Jméno' ),
							array( '_id' => 'email', 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'E-mail' ),
							array( '_id' => 'msg', 'custom_id' => 'msg', 'field_type' => 'textarea', 'field_label' => 'Zpráva' ),
						),
						'raynet_crm_fields_map'    => $map,
						'raynet_crm_notify_emails' => 'obchod@example.cz',
						'raynet_crm_priority'      => 'CRITICAL',
						'raynet_crm_owner'         => '11',
					),
					'elements'   => array(),
				),
			);
		};
		update_post_meta( $import_page, '_elementor_edit_mode', 'builder' );
		update_post_meta( $import_page, '_elementor_data', wp_slash( wp_json_encode( $import_form( $import_map ) ) ) );

		$add_stored = function ( $post_id, $name, $email, $message, $ip, array $extra = array() ) use ( $import_query, $import_page ) {
			return (int) $import_query->add_submission(
				array_merge(
					array(
						'main_meta_id'            => 0,
						'post_id'                 => $post_id,
						'referer'                 => 'http://localhost/import-poptavek/',
						'referer_title'           => 'Import poptávek',
						'element_id'              => 'impform',
						'form_name'               => 'Import',
						'campaign_id'             => 0,
						'user_id'                 => 0,
						'user_ip'                 => $ip,
						'user_agent'              => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 Safari/605.1.15',
						'actions_count'           => 1,
						'actions_succeeded_count' => 0,
						// Elementor saves the page id as the request carried it: a string.
						'meta'                    => wp_json_encode( array( 'edit_post_id' => (string) $post_id ) ),
					),
					$extra
				),
				array(
					array( 'id' => 'name', 'type' => 'text', 'value' => $name ),
					array( 'id' => 'email', 'type' => 'email', 'value' => $email ),
					array( 'id' => 'msg', 'type' => 'textarea', 'value' => $message ),
				)
			);
		};

		$sub_a = $add_stored( $import_page, 'Jana', 'jana.importova@example.cz', 'Dobrý den, poptávám nabídku na rekonstrukci koupelny v rodinném domě.', '192.0.2.1', array( 'created_at' => '2025-03-12 14:10:00', 'created_at_gmt' => '2025-03-12 13:10:00' ) );
		$sub_b = $add_stored( $import_page, 'Ivan', 'ivan@example.ru', 'Здравствуйте, предлагаем продвижение вашего сайта в поисковых системах.', '192.0.2.2' );
		$sub_c = $add_stored( $import_page, 'Petr', 'petr.importovany@example.cz', 'Prosím o cenovou nabídku na zateplení fasády bytového domu v Brně.', '192.0.2.3' );
		$sub_d = $add_stored( $import_page, 'Eva', 'eva.importova@example.cz', 'Měli bychom zájem o pravidelný servis klimatizace ve třech kancelářích.', '192.0.2.4' );
		$sub_e = $add_stored( $import_page, 'Karel', 'karel.kos@example.cz', 'Tohle odeslání je v koši a nesmí se nabídnout ani importovat.', '192.0.2.5' );
		$sub_f = $add_stored( $import_page + 100000, 'Cizí', 'cizi@example.cz', 'Stejné ID widgetu na jiné stránce, odeslání sem nepatří vůbec.', '192.0.2.6' );

		$import_query->add_action_log( $sub_c, $action, 'success', 'Lead 77' );
		$import_query->add_action_log( $sub_d, $action, 'failed', 'Connection timed out' );
		$import_query->move_to_trash_submission( $sub_e );

		$import_key = $import_page . ':impform';

		// Every cached index row of one form, whatever it is split into. The
		// pattern is left unescaped: SQLite's LIKE has no default escape
		// character, and an underscore matching itself is all it needs here.
		$drop_index = function ( $form_key ) use ( $wpdb ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient%' . Raynet_Elementor_Submissions::CACHE_PREFIX . md5( $form_key ) . '%' ) ); // phpcs:ignore WordPress.DB
			wp_cache_flush();
		};
		// The plugin's record of an import attempt, read past every cache.
		$attempt_row = function ( $submission_id ) use ( $wpdb ) {
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'raynet_import_attempt_' . (int) $submission_id ) ); // phpcs:ignore WordPress.DB
			return null === $value ? null : maybe_unserialize( $value );
		};

		check( 'odeslání vložena', 0 < min( $sub_a, $sub_b, $sub_c, $sub_d, $sub_e, $sub_f ), true );
		check( 'import je dostupný', Raynet_Elementor_Submissions::available(), true );

		$pending = Raynet_Elementor_Submissions::form_counts();
		check( 'k importu čekají A, B a D', isset( $pending[ $import_key ] ) ? $pending[ $import_key ]['pending'] : null, 3 );

		$index = Raynet_Elementor_Submissions::index( $import_page, 'impform' );
		check( 'index: A k posouzení', isset( $index[ $sub_a ] ) ? $index[ $sub_a ]['tab'] : null, Raynet_Elementor_Submissions::TAB_REVIEW );
		check( 'index: A předvybrané', isset( $index[ $sub_a ] ) ? $index[ $sub_a ]['preselect'] : null, true );
		check( 'index: B spam', isset( $index[ $sub_b ] ) ? $index[ $sub_b ]['tab'] : null, Raynet_Elementor_Submissions::TAB_SPAM );
		check( 'index: C už v RAYNETu', isset( $index[ $sub_c ] ) ? $index[ $sub_c ]['tab'] : null, Raynet_Elementor_Submissions::TAB_SENT );
		check( 'index: D k posouzení', isset( $index[ $sub_d ] ) ? $index[ $sub_d ]['tab'] : null, Raynet_Elementor_Submissions::TAB_REVIEW );
		check( 'index: D nepředvybrané', isset( $index[ $sub_d ] ) ? $index[ $sub_d ]['preselect'] : null, false );
		check( 'index: D ve stavu selhalo', isset( $index[ $sub_d ] ) ? $index[ $sub_d ]['state'] : null, Raynet_Elementor_Submissions::STATE_FAILED );
		check( 'index: koš a cizí stránka chybí', isset( $index[ $sub_e ] ) || isset( $index[ $sub_f ] ), false );
		check( 'index: nejnovější první', array_keys( $index ), array( $sub_d, $sub_c, $sub_b, $sub_a ) );

		$loaded_d = Raynet_Elementor_Submissions::load( $import_page, 'impform', array( $sub_d, $sub_e, $sub_f ) );
		check( 'load: jen D', array_keys( $loaded_d ), array( $sub_d ) );
		check( 'load: D nese původní chybu', isset( $loaded_d[ $sub_d ] ) ? $loaded_d[ $sub_d ]['log'] : null, 'Connection timed out' );
		check( 'load: D nese hodnoty', isset( $loaded_d[ $sub_d ] ) ? $loaded_d[ $sub_d ]['values']['email'] : null, 'eva.importova@example.cz' );

		// --- The import screen, over HTTP ------------------------------------
		// G carries markup in every value it can; K is a clean row the AJAX
		// checks send, so A stays untouched for the checks of import() below.
		$sub_xss = $add_stored( $import_page, '<script>alert(1)</script>', 'gustav.xss@example.cz', '"><img src=x onerror=alert(1)>', '192.0.2.10' );
		$sub_k   = $add_stored( $import_page, 'Klára', 'klara.ajaxova@example.cz', 'Dobrý den, prosím o nezávaznou nabídku na výměnu oken v rodinném domě.', '192.0.2.11' );

		// The row of one submission in a page of the import table.
		$import_row = function ( $html, $submission_id ) {
			return preg_match( '~<tr[^>]*\bdata-submission="' . (int) $submission_id . '"[^>]*>.*?</tr>~s', $html, $m ) ? $m[0] : '';
		};
		$import_url = '/wp-admin/admin.php?page=raynet-elementor-forms&import=' . rawurlencode( $import_key );

		$empty_page = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Formulář bez odeslání', 'post_status' => 'publish' ) );
		update_post_meta( $empty_page, '_elementor_edit_mode', 'builder' );
		update_post_meta( $empty_page, '_elementor_data', wp_slash( wp_json_encode( $form_layout( 'emptyform', 'Bez odeslání' ) ) ) );

		// A form whose only submission is already in RAYNET.
		$sent_page = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Vše odesláno', 'post_status' => 'publish' ) );
		update_post_meta( $sent_page, '_elementor_edit_mode', 'builder' );
		update_post_meta( $sent_page, '_elementor_data', wp_slash( wp_json_encode( $form_layout( 'sentform', 'Vše odesláno' ) ) ) );
		$sub_sent = $add_stored( $sent_page, 'Olga', 'olga@example.cz', 'Tahle poptávka už v RAYNETu je, odkaz má ukázat nulu.', '192.0.2.12', array( 'element_id' => 'sentform' ) );
		$import_query->add_action_log( $sub_sent, $action, 'success', 'Lead 78' );

		$forms_view = req( '/wp-admin/admin.php?page=raynet-elementor-forms', null, true )['body'];
		check( 'přehled: odkaz na import formuláře', (bool) preg_match( '~href="[^"]*import=' . preg_quote( $import_key, '~' ) . '"[^>]*>\s*Importovat starší odeslání \(~', $forms_view ), true );
		check( 'přehled: počet čekajících v odkazu', false !== strpos( $forms_view, 'Importovat starší odeslání (5)' ), true );
		check( 'přehled: formulář bez odeslání odkaz nemá', false !== strpos( $forms_view, 'import=' . $empty_page . ':emptyform' ), false );
		check( 'přehled: vše odeslané má odkaz s nulou', (bool) preg_match( '~href="[^"]*import=' . $sent_page . ':sentform"[^>]*>\s*Importovat starší odeslání \(0\)~', $forms_view ), true );
		$all_counts = Raynet_Elementor_Submissions::form_counts();
		check( 'form_counts: vše odeslané', isset( $all_counts[ $sent_page . ':sentform' ] ) ? $all_counts[ $sent_page . ':sentform' ] : null, array( 'pending' => 0, 'total' => 1 ) );
		check( 'form_counts: import', isset( $all_counts[ $import_key ] ) ? $all_counts[ $import_key ] : null, array( 'pending' => 5, 'total' => 6 ) );

		$review = req( $import_url, null, true );
		$row_a  = $import_row( $review['body'], $sub_a );
		$row_d  = $import_row( $review['body'], $sub_d );
		check( 'import: obrazovka se načte', $review['status'], 200 );
		check( 'import: tabulka', false !== strpos( $review['body'], 'class="wp-list-table widefat raynet-import"' ), true );
		check( 'import: A zaškrtnuté', '' !== $row_a && false !== strpos( $row_a, 'name="submissions[]"' ) && false !== strpos( $row_a, 'checked' ), true );
		check( 'import: D bez zaškrtnutí', '' !== $row_d && false === strpos( $row_d, 'checked' ), true );
		check( 'import: D má zaškrtávátko', false !== strpos( $row_d, 'name="submissions[]"' ), true );
		check( 'import: D vyzývá k ověření', false !== strpos( $row_d, 'tehdy selhalo: „Connection timed out“ — ověřte v RAYNETu, že lead neexistuje' ), true );
		check( 'import: B, C, E, F na výchozí záložce nejsou', array( $import_row( $review['body'], $sub_b ), $import_row( $review['body'], $sub_c ), $import_row( $review['body'], $sub_e ), $import_row( $review['body'], $sub_f ) ), array( '', '', '', '' ) );
		check( 'import: pole štítku s výchozí hodnotou', (bool) preg_match( '~id="raynet-import-tag"[^>]*value="import-web"~', $review['body'] ), true );
		check( 'import: tlačítko odeslání', false !== strpos( $review['body'], 'id="raynet-import-send"' ), true );
		check( 'import: skript načtený', false !== strpos( $review['body'], 'raynet-elementor-import.js' ), true );
		// The preview is what preview_payload() gives for A, shown in A's own detail row.
		$detail_a  = preg_match( '~<tr[^>]*\bdata-detail="' . (int) $sub_a . '"[^>]*>.*?</tr>~s', $review['body'], $detail_m ) ? $detail_m[0] : '';
		$prepared_a = Raynet_Elementor_Submissions::prepare( Raynet_Elementor_Submissions::load( $import_page, 'impform', array( $sub_a ) )[ $sub_a ], Raynet_Elementor_Forms::form_settings( $import_page, 'impform' ), 'import-web' );
		$payload_a = ( new Raynet_Lead_Form() )->preview_payload( $prepared_a['values'], $prepared_a['settings'], $prepared_a['context'] );
		$owner_names = Raynet_Lead_Users::names();
		check( 'detail A: předmět leadu', '' !== $detail_a && false !== strpos( $detail_a, esc_html( $payload_a['topic'] ) ), true );
		check( 'detail A: priorita', false !== strpos( $detail_a, '<dt>Priorita</dt>' ) && false !== strpos( $detail_a, 'Kritická' ), true );
		check( 'detail A: vlastník', false !== strpos( $detail_a, '<dt>Vlastník leadu</dt>' ) && false !== strpos( $detail_a, esc_html( isset( $owner_names[11] ) ? $owner_names[11] : '11' ) ), true );
		check( 'detail A: typ leadu', false !== strpos( $detail_a, '<dt>Typ leadu</dt>' ), true );
		check( 'detail A: poznámka přesně jako payload', false !== strpos( $detail_a, esc_html( $payload_a['notice'] ) ), true );
		check( 'detail A: poznámka nese import', false !== strpos( $payload_a['notice'], 'Původně odesláno: 12. 3. 2025 14:10' ), true );

		$spam_tab = req( $import_url . '&zalozka=spam', null, true )['body'];
		$row_b    = $import_row( $spam_tab, $sub_b );
		check( 'spam: B s důvodem písma', false !== strpos( $row_b, 'text v jiném písmu než latinka' ), true );
		check( 'spam: B nezaškrtnuté', '' !== $row_b && false === strpos( $row_b, 'checked' ), true );

		$sent_tab = req( $import_url . '&zalozka=sent', null, true )['body'];
		$row_c    = $import_row( $sent_tab, $sub_c );
		check( 'odeslané: C je tu', '' !== $row_c, true );
		check( 'odeslané: C bez zaškrtávátka', false !== strpos( $row_c, 'name="submissions[]"' ), false );

		// Review Focus 1: stored values are never markup.
		foreach ( array( 'výchozí' => $review['body'], 'spam' => $spam_tab ) as $tab_label => $tab_body ) {
			check( 'XSS (' . $tab_label . '): žádný skript', false !== strpos( $tab_body, '<script>alert(1)</script>' ), false );
			check( 'XSS (' . $tab_label . '): žádný onerror', false !== strpos( $tab_body, 'onerror=alert(1)>' ), false );
		}
		// Wherever the score puts G, its row and stored values are on the screen.
		$xss_body = '' !== $import_row( $review['body'], $sub_xss ) ? $review['body'] : $spam_tab;
		check( 'XSS: G je na obrazovce', '' !== $import_row( $xss_body, $sub_xss ), true );
		$xss_index = Raynet_Elementor_Submissions::index( $import_page, 'impform' );
		check( 'XSS: G je spam a není předvybrané', isset( $xss_index[ $sub_xss ] ) ? array( $xss_index[ $sub_xss ]['tab'], $xss_index[ $sub_xss ]['preselect'] ) : null, array( Raynet_Elementor_Submissions::TAB_SPAM, false ) );
		check( 'XSS: jméno escapované', false !== strpos( $xss_body, '&lt;script&gt;alert(1)&lt;/script&gt;' ), true );
		check( 'XSS: zpráva escapovaná', false !== strpos( $xss_body, '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;' ), true );

		$odd_size = req( $import_url . '&na_stranku=7', null, true )['body'];
		check( 'neplatný počet na stránku je 100', false !== strpos( $odd_size, 'data-per-page="100"' ), true );
		check( 'platný počet na stránku 50', false !== strpos( req( $import_url . '&na_stranku=50', null, true )['body'], 'data-per-page="50"' ), true );

		$far = req( $import_url . '&strana=99', null, true );
		check( 'stránka mimo rozsah: 200', $far['status'], 200 );
		check( 'stránka mimo rozsah: hláška', false !== strpos( $far['body'], 'Na této stránce nejsou žádná odeslání.' ), true );
		check( 'stránka mimo rozsah: žádné řádky', false !== strpos( $far['body'], 'data-submission=' ), false );

		$missing = req( '/wp-admin/admin.php?page=raynet-elementor-forms&import=999999:nic', null, true );
		check( 'neznámý formulář: 200', $missing['status'], 200 );
		check( 'neznámý formulář: hláška', false !== strpos( $missing['body'], 'Formulář nebyl nalezen.' ), true );

		// A crafted key is "not found", and never reaches the inline script.
		$crafted = req( '/wp-admin/admin.php?page=raynet-elementor-forms&import=' . rawurlencode( $import_page . ':impform"};alert(1);//' ), null, true );
		check( 'podvržený klíč: 200', $crafted['status'], 200 );
		check( 'podvržený klíč: formulář nenalezen', false !== strpos( $crafted['body'], 'Formulář nebyl nalezen.' ), true );
		check( 'podvržený klíč: skript se nenačte', false !== strpos( $crafted['body'], 'raynetElementorImport' ), false );
		check( 'podvržený klíč: nic z něj na stránce', false !== strpos( $crafted['body'], 'alert(1);//' ), false );

		// Paging links: first, last, two either side of the current page.
		check( 'stránkování: začátek', Raynet_Elementor_Import_Admin::page_numbers( 1, 400 ), array( 1, 2, 3, null, 400 ) );
		check( 'stránkování: uprostřed', Raynet_Elementor_Import_Admin::page_numbers( 200, 400 ), array( 1, null, 198, 199, 200, 201, 202, null, 400 ) );
		check( 'stránkování: konec', Raynet_Elementor_Import_Admin::page_numbers( 400, 400 ), array( 1, null, 398, 399, 400 ) );
		check( 'stránkování: bez mezery u kraje', Raynet_Elementor_Import_Admin::page_numbers( 4, 7 ), array( 1, 2, 3, 4, 5, 6, 7 ) );
		check( 'stránkování: jedna stránka', Raynet_Elementor_Import_Admin::page_numbers( 1, 1 ), array( 1 ) );
		check( 'stránkování: mimo rozsah', Raynet_Elementor_Import_Admin::page_numbers( 99, 1 ), array( 1 ) );
		check( 'stránkování: obrovská stránka', Raynet_Elementor_Import_Admin::page_numbers( 50000000, 3 ), array( 1, 2, 3 ) );
		check( 'stránkování: nula a záporná', array( Raynet_Elementor_Import_Admin::page_numbers( 0, 3 ), Raynet_Elementor_Import_Admin::page_numbers( -5, 3 ) ), array( array( 1, 2, 3 ), array( 1, 2, 3 ) ) );

		// A huge page number on a tab with several pages.
		$paged_page = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Mnoho odeslání', 'post_status' => 'publish' ) );
		update_post_meta( $paged_page, '_elementor_edit_mode', 'builder' );
		update_post_meta( $paged_page, '_elementor_data', wp_slash( wp_json_encode( $import_form( $import_map ) ) ) );
		$paged_ids = array();
		for ( $i = 0; $i < 51; $i++ ) {
			$paged_ids[] = $add_stored( $paged_page, 'Osoba' . $i, 'osoba' . $i . '@example.cz', 'Dobrý den, poptávka číslo ' . $i . ' na servis kotle v rodinném domě.', '198.18.0.' . $i );
		}
		$paged_log   = file_exists( $debug_log = WP_CONTENT_DIR . '/debug.log' ) ? count( file( $debug_log ) ) : 0;
		$paged_url   = '/wp-admin/admin.php?page=raynet-elementor-forms&import=' . rawurlencode( $paged_page . ':impform' ) . '&na_stranku=50';
		$paged_first = req( $paged_url, null, true );
		$paged_huge  = req( $paged_url . '&strana=50000000', null, true );
		$paged_new   = file_exists( $debug_log ) ? preg_grep( '/sqlite-database-integration|wp-cli\.phar/', preg_grep( '/PHP /', array_slice( file( $debug_log ), $paged_log ) ), PREG_GREP_INVERT ) : array();
		check( 'více stránek: druhá stránka v odkazech', false !== strpos( $paged_first['body'], 'strana=2' ), true );
		check( 'obrovská stránka: 200', $paged_huge['status'], 200 );
		check( 'obrovská stránka: hláška prázdné stránky', false !== strpos( $paged_huge['body'], 'Na této stránce nejsou žádná odeslání.' ), true );
		// Only the screen's own paging block: Elementor echoes the request URL elsewhere.
		$huge_pages = preg_match( '~<div class="raynet-import__pages">.*?</div>~s', $paged_huge['body'], $huge_m ) ? $huge_m[0] : '';
		check( 'obrovská stránka: stránkování vykreslené', false !== strpos( $huge_pages, 'strana=1' ), true );
		check( 'obrovská stránka: odkazy jen na skutečné stránky', (bool) preg_match( '~strana=(?:[3-9]|\d{2,})\b~', $huge_pages ), false );
		check( 'obrovská stránka: žádná PHP chyba', array_values( $paged_new ), array() );
		foreach ( $paged_ids as $paged_id ) {
			$import_query->delete_submission( $paged_id );
		}
		$drop_index( $paged_page . ':impform' );
		wp_delete_post( $paged_page, true );

		// A spam-score filter returning reasons in odd shapes does not break the screen.
		update_option( 'raynet_test_odd_reasons', 1 );
		$drop_index( $import_key );
		$odd = req( $import_url, null, true );
		delete_option( 'raynet_test_odd_reasons' );
		$drop_index( $import_key );
		$odd_a = $import_row( $odd['body'], $sub_a );
		check( 'divné důvody: obrazovka se načte', $odd['status'], 200 );
		check( 'divné důvody: řádek A je tu', '' !== $odd_a, true );
		check( 'divné důvody: řetězec escapovaný', false !== strpos( $odd_a, 'vlastni_&lt;b&gt;signal&lt;/b&gt;' ), true );
		check( 'divné důvody: neznámé ID ukázané', false !== strpos( $odd_a, 'cizi_id' ), true );

		// Without a mapped contact the screen sends the admin to the mapping.
		update_post_meta( $import_page, '_elementor_data', wp_slash( wp_json_encode( $import_form( array( $import_map[0], $import_map[2] ) ) ) ) );
		$unready = req( $import_url, null, true )['body'];
		update_post_meta( $import_page, '_elementor_data', wp_slash( wp_json_encode( $import_form( $import_map ) ) ) );
		check( 'bez kontaktu: odkaz na mapování', false !== strpos( $unready, 'mapovat=' . $import_key ), true );
		check( 'bez kontaktu: bez tlačítka odeslání', false !== strpos( $unready, 'id="raynet-import-send"' ), false );
		check( 'bez kontaktu: bez tabulky', false !== strpos( $unready, 'raynet-import"' ), false );

		// Without credentials there is nothing to send with.
		$credentials = get_option( Raynet_Lead_Settings::OPTION );
		update_option( Raynet_Lead_Settings::OPTION, array_merge( (array) $credentials, array( 'api_key' => '' ) ) );
		$unconfigured = req( $import_url, null, true )['body'];
		update_option( Raynet_Lead_Settings::OPTION, $credentials );
		check( 'bez nastavení: odkaz do nastavení', false !== strpos( $unconfigured, 'page=raynet-lead-integration' ), true );
		check( 'bez nastavení: bez tlačítka odeslání', false !== strpos( $unconfigured, 'id="raynet-import-send"' ), false );

		// --- One submission over AJAX (Review Focus 2) -------------------------
		preg_match( '/raynetElementorImport = \{[^;]*?"nonce":"([^"]+)"/', $review['body'], $import_nonce );
		$import_nonce = isset( $import_nonce[1] ) ? $import_nonce[1] : '';
		check( 'nonce importu na stránce', '' !== $import_nonce, true );
		$ajax_import = function ( $nonce, $as_user, $tag = 'import-web' ) use ( $import_key, $sub_k ) {
			$post = array(
				'action'     => 'raynet_elm_import_one',
				'form'       => $import_key,
				'submission' => $sub_k,
				'tag'        => $tag,
			);
			if ( null !== $nonce ) {
				$post['_wpnonce'] = $nonce;
			}
			return req( '/wp-admin/admin-ajax.php', $post, $as_user );
		};

		update_option( 'raynet_test_http_calls', array() );
		$forged = $ajax_import( null, true );
		wp_cache_flush();
		check( 'AJAX bez nonce: 403', $forged['status'], 403 );
		check( 'AJAX bez nonce: nic neodešlo', count( get_option( 'raynet_test_http_calls', array() ) ), 0 );

		$anonymous = $ajax_import( $import_nonce, false );
		wp_cache_flush();
		check( 'AJAX nepřihlášený: neúspěch', false !== strpos( $anonymous['body'], '"success":true' ), false );
		check( 'AJAX nepřihlášený: nic neodešlo', count( get_option( 'raynet_test_http_calls', array() ) ), 0 );

		$long_tag = 'import-ajax ' . str_repeat( 'x', 80 );
		$sent_k   = json_decode( $ajax_import( $import_nonce, true, $long_tag )['body'], true );
		wp_cache_flush();
		check( 'AJAX: úspěch', isset( $sent_k['success'] ) ? $sent_k['success'] : null, true );
		check( 'AJAX: číslo leadu', isset( $sent_k['data']['lead_id'] ) ? $sent_k['data']['lead_id'] : null, 4242 );
		$ajax_payload = null;
		foreach ( get_option( 'raynet_test_http_calls', array() ) as $call ) {
			if ( false !== strpos( $call['url'], '/lead/' ) ) {
				$ajax_payload = json_decode( $call['body'], true );
			}
		}
		check( 'AJAX: štítek oříznutý na 60 znaků', isset( $ajax_payload['tags'] ) && in_array( mb_substr( $long_tag, 0, 60 ), explode( ',', $ajax_payload['tags'] ), true ), true );

		update_option( 'raynet_test_http_calls', array() );
		$again_k = $ajax_import( $import_nonce, true );
		$again_j = json_decode( $again_k['body'], true );
		wp_cache_flush();
		check( 'AJAX podruhé: chyba', isset( $again_j['success'] ) ? $again_j['success'] : null, false );
		check( 'AJAX podruhé: 400', $again_k['status'], 400 );
		check( 'AJAX podruhé: kód', isset( $again_j['data']['code'] ) ? $again_j['data']['code'] : null, 'raynet_import_already_sent' );
		check( 'AJAX podruhé: hláška', isset( $again_j['data']['message'] ) && '' !== $again_j['data']['message'], true );
		check( 'AJAX podruhé: nic neodešlo', count( get_option( 'raynet_test_http_calls', array() ) ), 0 );

		// --- Elementor Pro switched off (Review Focus 3) -----------------------
		// This process keeps Pro loaded; the web server reads the option fresh,
		// so the screens are checked over HTTP. Pro is switched back on in any
		// case, even if this process dies on the way.
		$plugins_before = get_option( 'active_plugins' );
		$restore_pro    = function () use ( $plugins_before ) {
			update_option( 'active_plugins', $plugins_before );
		};
		register_shutdown_function( $restore_pro );
		$debug_log   = WP_CONTENT_DIR . '/debug.log';
		$debug_lines = file_exists( $debug_log ) ? count( file( $debug_log ) ) : 0;

		try {
			update_option( 'active_plugins', array_values( array_diff( (array) $plugins_before, array( 'elementor-pro/elementor-pro.php' ) ) ) );
			$without_pro_list   = req( '/wp-admin/admin.php?page=raynet-elementor-forms', null, true );
			$without_pro_import = req( $import_url, null, true );
		} finally {
			$restore_pro();
		}

		check( 'bez Pro: přehled se načte', $without_pro_list['status'], 200 );
		check( 'bez Pro: přehled je opravdu bez Pro', false !== strpos( $without_pro_list['body'], 'Elementor Pro není aktivní' ), true );
		check( 'bez Pro: žádný odkaz na import', false !== strpos( $without_pro_list['body'], 'Importovat starší odeslání' ), false );
		check( 'bez Pro: import se načte', $without_pro_import['status'], 200 );
		check( 'bez Pro: import vysvětlí proč', false !== strpos( $without_pro_import['body'], 'Import potřebuje aktivní Elementor Pro' ), true );
		check( 'bez Pro: bez tabulky', false !== strpos( $without_pro_import['body'], 'raynet-import"' ), false );
		$new_lines = file_exists( $debug_log ) ? array_slice( file( $debug_log ), $debug_lines ) : array();
		// The SQLite driver's deprecation noise is not ours, as at the end of the suite.
		$new_lines = preg_grep( '/sqlite-database-integration|wp-cli\.phar/', preg_grep( '/PHP /', $new_lines ), PREG_GREP_INVERT );
		check( 'bez Pro: žádná PHP chyba', count( $new_lines ), 0 );
		check( 'Pro je zase aktivní', in_array( 'elementor-pro/elementor-pro.php', (array) get_option( 'active_plugins' ), true ), true );

		$import_query->delete_submission( $sub_xss );
		$import_query->delete_submission( $sub_k );
		$import_query->delete_submission( $sub_sent );
		wp_delete_post( $empty_page, true );
		wp_delete_post( $sent_page, true );
		$drop_index( $import_key );

		// The lock is read and written with raw SQL only, as the class does.
		$lock_row = function ( $submission_id ) use ( $wpdb ) {
			return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Raynet_Elementor_Submissions::LOCK_PREFIX . $submission_id ) ); // phpcs:ignore WordPress.DB
		};
		$put_lock = function ( $submission_id, $value ) use ( $wpdb ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", Raynet_Elementor_Submissions::LOCK_PREFIX . $submission_id ) ); // phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", Raynet_Elementor_Submissions::LOCK_PREFIX . $submission_id, $value ) ); // phpcs:ignore WordPress.DB
		};

		$succeeded_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT actions_succeeded_count FROM {$wpdb->prefix}e_submissions WHERE id = %d", $sub_a ) ); // phpcs:ignore WordPress.DB
		$last_error_orig  = get_option( 'raynet_lead_last_error', null );
		$last_error_mark  = array( 'message' => 'značka testu importu', 'time' => 1 );
		update_option( 'raynet_lead_last_error', $last_error_mark, false );
		update_option( 'raynet_test_http_calls', array() );
		update_option( 'raynet_test_mails', array() );

		$imported = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_a, Raynet_Elementor_Submissions::DEFAULT_TAG );
		wp_cache_flush();

		check( 'import A vrátí lead', is_array( $imported ) ? $imported['lead_id'] : $imported->get_error_code(), 4242 );

		$import_payload = null;
		foreach ( get_option( 'raynet_test_http_calls', array() ) as $call ) {
			if ( false !== strpos( $call['url'], '/lead/' ) ) {
				$import_payload = json_decode( $call['body'], true );
			}
		}

		check( 'import A zavolal RAYNET', null !== $import_payload, true );
		check( 'leadDate je den uložení A', isset( $import_payload['leadDate'] ) ? $import_payload['leadDate'] : null, '2025-03-12' );
		check( 'štítek importu', isset( $import_payload['tags'] ) && in_array( 'import-web', explode( ',', $import_payload['tags'] ), true ), true );
		check( 'bez notifikačních e-mailů', is_array( $import_payload ) && ! isset( $import_payload['notificationEmailAddresses'] ), true );
		check( 'poznámka o importu', isset( $import_payload['notice'] ) && false !== strpos( $import_payload['notice'], 'Původně odesláno: 12. 3. 2025 14:10' ), true );
		check( 'žádný e-mail', get_option( 'raynet_test_mails', array() ), array() );

		$log_a       = $import_query->get_submissions_actions_log( $sub_a );
		$log_a_found = false;
		foreach ( (array) $log_a as $entry ) {
			if ( 'raynet_crm' === $entry['action_name'] && 'success' === $entry['status'] && false !== strpos( (string) $entry['log'], 'Importováno, lead' ) ) {
				$log_a_found = true;
			}
		}
		check( 'log A nese úspěšný import', $log_a_found, true );
		check( 'po úspěchu zámek uvolněn', $lock_row( $sub_a ), null );
		check( 'po úspěchu žádný záznam pokusu', $attempt_row( $sub_a ), null );
		check( 'počitadlo úspěchů A beze změny', (int) $wpdb->get_var( $wpdb->prepare( "SELECT actions_succeeded_count FROM {$wpdb->prefix}e_submissions WHERE id = %d", $sub_a ) ), $succeeded_before ); // phpcs:ignore WordPress.DB
		check( 'poslední chyba API beze změny po úspěchu', get_option( 'raynet_lead_last_error' ), $last_error_mark );

		$index_after = Raynet_Elementor_Submissions::index( $import_page, 'impform' );
		check( 'index po importu: A už v RAYNETu', isset( $index_after[ $sub_a ] ) ? $index_after[ $sub_a ]['tab'] : null, Raynet_Elementor_Submissions::TAB_SENT );
		check( 'po importu čekají B a D', Raynet_Elementor_Submissions::form_counts()[ $import_key ]['pending'], 2 );

		update_option( 'raynet_test_http_calls', array() );
		$again = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_a, 'import-web' );
		wp_cache_flush();
		check( 'druhý import A odmítnut', is_wp_error( $again ) ? $again->get_error_code() : null, 'raynet_import_already_sent' );
		check( 'druhý import A nic neposlal', count( get_option( 'raynet_test_http_calls', array() ) ), 0 );

		$trashed = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_e, 'import-web' );
		check( 'import z koše odmítnut', is_wp_error( $trashed ) ? $trashed->get_error_code() : null, 'raynet_import_not_found' );
		$foreign = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_f, 'import-web' );
		check( 'import cizího odeslání odmítnut', is_wp_error( $foreign ) ? $foreign->get_error_code() : null, 'raynet_import_not_found' );
		$sent_c = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_c, 'import-web' );
		check( 'import odeslaného C odmítnut', is_wp_error( $sent_c ) ? $sent_c->get_error_code() : null, 'raynet_import_already_sent' );
		check( 'odmítnutí bez odeslání nenechá záznam pokusu', array( $attempt_row( $sub_a ), $attempt_row( $sub_c ), $attempt_row( $sub_e ), $attempt_row( $sub_f ) ), array( null, null, null, null ) );

		// Without a mapped contact the form cannot make a lead at all.
		update_post_meta( $import_page, '_elementor_data', wp_slash( wp_json_encode( $import_form( array( $import_map[0], $import_map[2] ) ) ) ) );
		update_option( 'raynet_test_http_calls', array() );
		$log_d_before = count( (array) $import_query->get_submissions_actions_log( $sub_d ) );
		$no_contact   = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_d, 'import-web' );
		wp_cache_flush();
		check( 'bez mapování kontaktu odmítnuto', is_wp_error( $no_contact ) ? $no_contact->get_error_code() : null, 'raynet_import_no_form' );
		check( 'bez mapování kontaktu log D beze změny', count( (array) $import_query->get_submissions_actions_log( $sub_d ) ), $log_d_before );
		check( 'bez mapování kontaktu nic neodešlo', count( get_option( 'raynet_test_http_calls', array() ) ), 0 );
		update_post_meta( $import_page, '_elementor_data', wp_slash( wp_json_encode( $import_form( $import_map ) ) ) );

		// RAYNET is down (503): the lead may or may not exist, nothing is marked
		// in Elementor or remembered as the last API error.
		update_option( 'raynet_test_refuse_leads', 1 );
		$log_b_before = count( (array) $import_query->get_submissions_actions_log( $sub_b ) );
		$refused      = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_b, 'import-web' );
		delete_option( 'raynet_test_refuse_leads' );
		wp_cache_flush();
		check( 'výpadek RAYNETu (503): výsledek neznámý', is_wp_error( $refused ) ? $refused->get_error_code() : null, 'raynet_import_unknown' );
		check( 'chyba API nese diagnostiku', is_wp_error( $refused ) && false !== strpos( $refused->get_error_message(), '503' ), true );
		check( 'výpadek RAYNETu (503): pokus zapsán jako neznámý', is_array( $attempt_row( $sub_b ) ) ? $attempt_row( $sub_b )['outcome'] : null, 'unknown' );
		check( 'odmítnutí nezapsalo do logu B', count( (array) $import_query->get_submissions_actions_log( $sub_b ) ), $log_b_before );
		check( 'poslední chyba API beze změny po odmítnutí', get_option( 'raynet_lead_last_error' ), $last_error_mark );
		check( 'odmítnutí neposlalo e-mail', get_option( 'raynet_test_mails', array() ), array() );
		check( 'po odmítnutí zámek uvolněn', $lock_row( $sub_b ), null );
		check( 'po odmítnutí duplicity zámek uvolněn', $lock_row( $sub_a ), null );

		// The lock cannot be written at all: a database error, not "being imported".
		$break_lock = function ( $sql ) {
			return false !== stripos( $sql, 'INSERT IGNORE' ) && false !== strpos( $sql, Raynet_Elementor_Submissions::LOCK_PREFIX ) ? 'INSERT INTO raynet_no_such_table (x) VALUES (1)' : $sql;
		};
		update_option( 'raynet_test_http_calls', array() );
		$lock_suppressed = $wpdb->suppress_errors( true );
		add_filter( 'query', $break_lock );
		$lock_failed = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_d, 'import-web' );
		remove_filter( 'query', $break_lock );
		$wpdb->suppress_errors( $lock_suppressed );
		wp_cache_flush();
		check( 'chyba zámku: vlastní kód', is_wp_error( $lock_failed ) ? $lock_failed->get_error_code() : null, 'raynet_import_lock_failed' );
		check( 'chyba zámku: hláška netvrdí, že se importuje', is_wp_error( $lock_failed ) && false === strpos( $lock_failed->get_error_message(), 'právě importuje' ) && false !== strpos( $lock_failed->get_error_message(), 'databáz' ), true );
		check( 'chyba zámku: nic neodešlo', count( get_option( 'raynet_test_http_calls', array() ) ), 0 );
		check( 'chyba zámku: žádný zámek nezůstal', $lock_row( $sub_d ), null );
		check( 'chyba zámku: žádný záznam pokusu', $attempt_row( $sub_d ), null );

		// A submission another request is importing right now is left alone.
		$foreign_token = time() . ':cizipozadavek';
		$put_lock( $sub_d, $foreign_token );
		update_option( 'raynet_test_http_calls', array() );
		$locked = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_d, 'import-web' );
		wp_cache_flush();
		check( 'zamčené odeslání odmítnuto', is_wp_error( $locked ) ? $locked->get_error_code() : null, 'raynet_import_already_sent' );
		check( 'zamčené odeslání: hláška o běžícím importu', is_wp_error( $locked ) && false !== strpos( $locked->get_error_message(), 'právě importuje' ), true );
		check( 'zamčené odeslání nic neposlalo', count( get_option( 'raynet_test_http_calls', array() ) ), 0 );
		check( 'cizí zámek zůstane se svou hodnotou', $lock_row( $sub_d ), $foreign_token );
		check( 'zamčené odeslání: žádný záznam pokusu', $attempt_row( $sub_d ), null );

		// A lock left behind by a request that died is taken over.
		$put_lock( $sub_d, ( time() - 600 ) . ':mrtvypozadavek' );
		$stale = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_d, 'import-web' );
		wp_cache_flush();
		check( 'starý zámek převzat a D importováno', is_array( $stale ) ? $stale['lead_id'] : $stale->get_error_code(), 4242 );
		check( 'po importu D žádný zámek', $lock_row( $sub_d ), null );

		// Releasing never removes a lock taken with another token.
		$put_lock( $sub_d, '1:tokenX' );
		$unlock = new ReflectionMethod( 'Raynet_Elementor_Submissions', 'unlock' );
		$unlock->setAccessible( true );
		$unlock->invoke( null, $sub_d, '1:tokenY' );
		check( 'uvolnění cizím tokenem zámek nechá', $lock_row( $sub_d ), '1:tokenX' );
		$unlock->invoke( null, $sub_d, '1:tokenX' );
		check( 'uvolnění vlastním tokenem zámek smaže', $lock_row( $sub_d ), null );

		// A filter that breaks the verdict cannot make spam look clean.
		$rows_b      = Raynet_Elementor_Submissions::load( $import_page, 'impform', array( $sub_b ) );
		$row_b       = $rows_b[ $sub_b ];
		$plain_stats = array( 'text_senders' => array(), 'ip_counts' => array(), 'first_of' => array() );
		$config      = Raynet_Submission_Spam_Score::default_config();
		$form_now    = Raynet_Elementor_Forms::form_settings( $import_page, 'impform' );
		$broken      = array(
			'null'        => function () {
				return null;
			},
			'bez verdiktu' => function ( $result ) {
				unset( $result['verdict'] );
				return $result;
			},
			'cizí verdikt' => function ( $result ) {
				$result['verdict'] = 'fine';
				return $result;
			},
		);
		foreach ( $broken as $label => $filter ) {
			add_filter( 'raynet_import_spam_score', $filter );
			$judged = Raynet_Elementor_Submissions::classify( $row_b, $form_now, $plain_stats, $config );
			remove_filter( 'raynet_import_spam_score', $filter );
			check( 'rozbitý filtr (' . $label . ') nechá spam spamem', array( $judged['tab'], $judged['preselect'] ), array( Raynet_Elementor_Submissions::TAB_SPAM, false ) );
		}
		$overrule = function ( $result ) {
			return array( 'verdict' => Raynet_Submission_Spam_Score::VERDICT_SUSPECT, 'reasons' => array() );
		};
		add_filter( 'raynet_import_spam_score', $overrule );
		$judged = Raynet_Elementor_Submissions::classify( $row_b, $form_now, $plain_stats, $config );
		remove_filter( 'raynet_import_spam_score', $overrule );
		check( 'platný filtr verdikt změní', array( $judged['verdict'], $judged['tab'] ), array( Raynet_Submission_Spam_Score::VERDICT_SUSPECT, Raynet_Elementor_Submissions::TAB_REVIEW ) );

		// The lead is created but the log row cannot be written: the caller
		// learns the lead id, and the index stops offering the submission.
		Raynet_Elementor_Submissions::index( $import_page, 'impform' );
		$log_table   = $wpdb->prefix . 'e_submissions_actions_log';
		$break_log   = function ( $sql ) use ( $log_table ) {
			return false !== stripos( $sql, 'INSERT' ) && false !== strpos( $sql, $log_table ) ? 'INSERT INTO raynet_no_such_table (x) VALUES (1)' : $sql;
		};
		$suppressed  = $wpdb->suppress_errors( true );
		add_filter( 'query', $break_log );
		$unlogged = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_b, 'import-web' );
		remove_filter( 'query', $break_log );
		$wpdb->suppress_errors( $suppressed );
		wp_cache_flush();
		$unlogged_data = is_wp_error( $unlogged ) ? $unlogged->get_error_data() : null;
		check( 'nezapsaný log: chyba', is_wp_error( $unlogged ) ? $unlogged->get_error_code() : null, 'raynet_import_unlogged' );
		check( 'nezapsaný log: chyba nese lead', is_array( $unlogged_data ) && isset( $unlogged_data['lead_id'] ) ? $unlogged_data['lead_id'] : null, 4242 );
		check( 'nezapsaný log: hláška jmenuje lead', is_wp_error( $unlogged ) && false !== strpos( $unlogged->get_error_message(), '4242' ), true );
		$index_unlogged = Raynet_Elementor_Submissions::index( $import_page, 'impform' );
		check( 'nezapsaný log: index ukazuje odesláno', isset( $index_unlogged[ $sub_b ] ) ? $index_unlogged[ $sub_b ]['tab'] : null, Raynet_Elementor_Submissions::TAB_SENT );
		check( 'nezapsaný log: zámek uvolněn', $lock_row( $sub_b ), null );
		$unlogged_attempt = $attempt_row( $sub_b );
		check( 'nezapsaný log: pokus zapsán s leadem', is_array( $unlogged_attempt ) ? array( $unlogged_attempt['outcome'], $unlogged_attempt['lead_id'] ) : null, array( 'unlogged', 4242 ) );

		// Without any cache, the lead the plugin created is still known.
		$drop_index( $import_key );
		$index_unlogged = Raynet_Elementor_Submissions::index( $import_page, 'impform' );
		check( 'nezapsaný log bez keše: B už v RAYNETu', isset( $index_unlogged[ $sub_b ] ) ? array( $index_unlogged[ $sub_b ]['tab'], $index_unlogged[ $sub_b ]['preselect'] ) : null, array( Raynet_Elementor_Submissions::TAB_SENT, false ) );
		$unlogged_tab = req( $import_url . '&zalozka=sent', null, true )['body'];
		$row_unlogged = $import_row( $unlogged_tab, $sub_b );
		check( 'nezapsaný log: B na záložce Už v RAYNETu', '' !== $row_unlogged, true );
		check( 'nezapsaný log: řádek jmenuje lead', false !== strpos( $row_unlogged, 'lead 4242 — v Elementoru se nepodařilo označit' ), true );
		check( 'nezapsaný log: bez zaškrtávátka', false !== strpos( $row_unlogged, 'name="submissions[]"' ), false );
		update_option( 'raynet_test_http_calls', array() );
		$unlogged_again = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_b, 'import-web' );
		wp_cache_flush();
		check( 'nezapsaný log: znovu se neodešle', is_wp_error( $unlogged_again ) ? $unlogged_again->get_error_code() : null, 'raynet_import_already_sent' );
		check( 'nezapsaný log: znovu nic neodešlo', count( get_option( 'raynet_test_http_calls', array() ) ), 0 );

		// --- Attempts without a confirmed mark stay visible ---------------------
		// T, U, R and P are clean enquiries, preselected until an attempt says otherwise.
		$sub_t = $add_stored( $import_page, 'Tereza', 'tereza.pokusna@example.cz', 'Dobrý den, poptáváme pravidelný úklid kanceláří v centru Prahy dvakrát týdně.', '192.0.2.21' );
		$sub_u = $add_stored( $import_page, 'Urban', 'urban.pokusny@example.cz', 'Prosím o nabídku na výměnu střešní krytiny na chatě v Beskydech.', '192.0.2.22' );
		$sub_r = $add_stored( $import_page, 'Radka', 'radka.pokusna@example.cz', 'Zajímá nás pronájem lešení na tři měsíce pro rekonstrukci fasády.', '192.0.2.23' );
		$sub_p = $add_stored( $import_page, 'Pavel', 'pavel.pokusny@example.cz', 'Potřebujeme revizi elektroinstalace v provozovně před kolaudací.', '192.0.2.24' );
		$index_fresh = Raynet_Elementor_Submissions::index( $import_page, 'impform' );
		check( 'pokusy: T, U, R, P předvybrané na začátku', array( $index_fresh[ $sub_t ]['preselect'], $index_fresh[ $sub_u ]['preselect'], $index_fresh[ $sub_r ]['preselect'], $index_fresh[ $sub_p ]['preselect'] ), array( true, true, true, true ) );

		// RAYNET down, over AJAX: the code reaches the screen's script.
		$attempt_nonce = preg_match( '/raynetElementorImport = \{[^;]*?"nonce":"([^"]+)"/', req( $import_url, null, true )['body'], $attempt_m ) ? $attempt_m[1] : '';
		update_option( 'raynet_test_refuse_leads', 1 );
		$ajax_unknown = req( '/wp-admin/admin-ajax.php', array( 'action' => 'raynet_elm_import_one', '_wpnonce' => $attempt_nonce, 'form' => $import_key, 'submission' => $sub_t, 'tag' => 'import-web' ), true );
		delete_option( 'raynet_test_refuse_leads' );
		wp_cache_flush();
		$ajax_unknown_j = json_decode( $ajax_unknown['body'], true );
		check( 'AJAX výpadek: kód neznámého výsledku', isset( $ajax_unknown_j['data']['code'] ) ? $ajax_unknown_j['data']['code'] : null, 'raynet_import_unknown' );
		check( 'AJAX výpadek: hláška', isset( $ajax_unknown_j['data']['message'] ) && false !== strpos( $ajax_unknown_j['data']['message'], 'ověřte v RAYNETu' ), true );
		check( 'AJAX výpadek: pokus neznámý', is_array( $attempt_row( $sub_t ) ) ? $attempt_row( $sub_t )['outcome'] : null, 'unknown' );

		// The connection drops in this process: a transport error.
		$drop_connection = function ( $preempt, $args, $url ) {
			return false !== strpos( $url, '/lead/' ) ? new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) : $preempt;
		};
		update_option( 'raynet_test_http_calls', array() );
		add_filter( 'pre_http_request', $drop_connection, 5, 3 );
		$transport = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_u, 'import-web' );
		remove_filter( 'pre_http_request', $drop_connection, 5 );
		wp_cache_flush();
		$transport_attempt = $attempt_row( $sub_u );
		check( 'spojení spadlo: výsledek neznámý', is_wp_error( $transport ) ? $transport->get_error_code() : null, 'raynet_import_unknown' );
		check( 'spojení spadlo: hláška říká, že výsledek je neznámý', is_wp_error( $transport ) && false !== strpos( $transport->get_error_message(), 'neznámý' ) && false !== strpos( $transport->get_error_message(), 'Operation timed out' ), true );
		check( 'spojení spadlo: pokus neznámý s důvodem', is_array( $transport_attempt ) ? array( $transport_attempt['outcome'], false !== strpos( $transport_attempt['message'], 'Operation timed out' ), $transport_attempt['lead_id'] ) : null, array( 'unknown', true, 0 ) );
		check( 'spojení spadlo: v Elementoru žádný řádek logu', count( (array) $import_query->get_submissions_actions_log( $sub_u ) ), 0 );
		check( 'spojení spadlo: poslední chyba API beze změny', get_option( 'raynet_lead_last_error' ), $last_error_mark );

		// Tried again with no credentials: nothing leaves the site, and the
		// earlier unknown outcome is not forgotten.
		$attempt_credentials = get_option( Raynet_Lead_Settings::OPTION );
		update_option( Raynet_Lead_Settings::OPTION, array_merge( (array) $attempt_credentials, array( 'api_key' => '' ) ) );
		update_option( 'raynet_test_http_calls', array() );
		$unsent_u = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_u, 'import-web' );
		update_option( Raynet_Lead_Settings::OPTION, $attempt_credentials );
		wp_cache_flush();
		check( 'bez nastavení: chyba API', is_wp_error( $unsent_u ) ? $unsent_u->get_error_code() : null, 'raynet_api_error' );
		check( 'bez nastavení: nic neodešlo', count( get_option( 'raynet_test_http_calls', array() ) ), 0 );
		check( 'bez nastavení: dřívější neznámý výsledek zůstane', is_array( $attempt_row( $sub_u ) ) ? $attempt_row( $sub_u ) : null, $transport_attempt );

		// RAYNET refuses the lead itself (4xx): the reason is kept.
		$refuse_lead = function ( $preempt, $args, $url ) {
			return false !== strpos( $url, '/lead/' ) ? array( 'headers' => array(), 'body' => wp_json_encode( array( 'message' => 'Pole topic je povinné' ) ), 'response' => array( 'code' => 400, 'message' => 'Bad Request' ), 'cookies' => array(), 'filename' => null ) : $preempt;
		};
		add_filter( 'pre_http_request', $refuse_lead, 5, 3 );
		$refused_r = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_r, 'import-web' );
		remove_filter( 'pre_http_request', $refuse_lead, 5 );
		wp_cache_flush();
		$refused_attempt = $attempt_row( $sub_r );
		check( 'RAYNET odmítl (400): chyba API', is_wp_error( $refused_r ) ? $refused_r->get_error_code() : null, 'raynet_api_error' );
		check( 'RAYNET odmítl (400): pokus odmítnutý s důvodem', is_array( $refused_attempt ) ? array( $refused_attempt['outcome'], false !== strpos( $refused_attempt['message'], 'Pole topic je povinné' ) ) : null, array( 'refused', true ) );
		check( 'RAYNET odmítl (400): v Elementoru žádný řádek logu', count( (array) $import_query->get_submissions_actions_log( $sub_r ) ), 0 );

		// A request that died after sending: only the "pending" mark is left.
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'raynet_import_attempt_' . $sub_p, maybe_serialize( array( 'time' => time() - 60, 'outcome' => 'pending', 'message' => '', 'lead_id' => 0 ) ) ) ); // phpcs:ignore WordPress.DB
		$drop_index( $import_key );

		$index_attempts = Raynet_Elementor_Submissions::index( $import_page, 'impform' );
		foreach ( array( 'T' => $sub_t, 'U' => $sub_u, 'R' => $sub_r, 'P' => $sub_p ) as $attempt_label => $attempt_id ) {
			check( 'pokus ' . $attempt_label . ': selhalo, nepředvybrané', isset( $index_attempts[ $attempt_id ] ) ? array( $index_attempts[ $attempt_id ]['state'], $index_attempts[ $attempt_id ]['preselect'] ) : null, array( Raynet_Elementor_Submissions::STATE_FAILED, false ) );
		}

		$attempt_screen = req( $import_url, null, true )['body'];
		foreach ( array( 'T' => $sub_t, 'U' => $sub_u, 'P' => $sub_p ) as $attempt_label => $attempt_id ) {
			$attempt_html = $import_row( $attempt_screen, $attempt_id );
			check( 'obrazovka ' . $attempt_label . ': výsledek neznámý', false !== strpos( $attempt_html, 'tehdy selhalo: „při předchozím importu: výsledek neznámý' ) && false !== strpos( $attempt_html, 'ověřte v RAYNETu, že lead neexistuje' ), true );
			check( 'obrazovka ' . $attempt_label . ': zaškrtávátko bez zaškrtnutí', false !== strpos( $attempt_html, 'name="submissions[]"' ) && false === strpos( $attempt_html, 'checked' ), true );
		}
		$attempt_r_html = $import_row( $attempt_screen, $sub_r );
		check( 'obrazovka R: RAYNET odmítl s důvodem', false !== strpos( $attempt_r_html, 'při předchozím importu RAYNET odmítl: ' ) && false !== strpos( $attempt_r_html, 'Pole topic je povinné' ), true );
		check( 'obrazovka R: bez zaškrtnutí', false === strpos( $attempt_r_html, 'checked' ), true );

		// Ticked deliberately, a row with an attempt is sent, and the attempt goes.
		update_option( 'raynet_test_http_calls', array() );
		$retried = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_p, 'import-web' );
		wp_cache_flush();
		check( 'opakovaný import P vrátí lead', is_array( $retried ) ? $retried['lead_id'] : $retried->get_error_code(), 4242 );
		check( 'opakovaný import P smaže záznam pokusu', $attempt_row( $sub_p ), null );
		$index_retried = Raynet_Elementor_Submissions::index( $import_page, 'impform' );
		check( 'opakovaný import P: index už v RAYNETu', isset( $index_retried[ $sub_p ] ) ? $index_retried[ $sub_p ]['tab'] : null, Raynet_Elementor_Submissions::TAB_SENT );

		// The "pending" record cannot be written: nothing may be sent, since an
		// unknown outcome would then come back preselected.
		$sub_w         = $add_stored( $import_page, 'Wanda', 'wanda.zapisova@example.cz', 'Dobrý den, prosíme o nabídku na údržbu zeleně kolem firemního areálu.', '192.0.2.25' );
		$break_attempt = function ( $sql ) {
			return preg_match( '/^\s*(INSERT|UPDATE)\b/i', $sql ) && false !== strpos( $sql, Raynet_Elementor_Submissions::ATTEMPT_PREFIX ) ? 'INSERT INTO raynet_no_such_table (x) VALUES (1)' : $sql;
		};
		$import_unwritable = function () use ( $wpdb, $break_attempt, $import_page, $sub_w ) {
			update_option( 'raynet_test_http_calls', array() );
			$suppressed = $wpdb->suppress_errors( true );
			add_filter( 'query', $break_attempt );
			$result = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_w, 'import-web' );
			remove_filter( 'query', $break_attempt );
			$wpdb->suppress_errors( $suppressed );
			wp_cache_flush();
			return $result;
		};
		$unwritten = $import_unwritable();
		check( 'nezapsaný pokus: chyba databáze', is_wp_error( $unwritten ) ? $unwritten->get_error_code() : null, 'raynet_import_lock_failed' );
		check( 'nezapsaný pokus: nic neodešlo', count( get_option( 'raynet_test_http_calls', array() ) ), 0 );
		check( 'nezapsaný pokus: zámek uvolněn', $lock_row( $sub_w ), null );
		check( 'nezapsaný pokus: žádný záznam', $attempt_row( $sub_w ), null );
		check( 'nezapsaný pokus: v Elementoru žádný řádek logu', count( (array) $import_query->get_submissions_actions_log( $sub_w ) ), 0 );

		// The same over an earlier record: it stays as it was.
		$earlier_w = array( 'time' => time() - 60, 'outcome' => 'unknown', 'message' => 'cURL error 28', 'lead_id' => 0 );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'raynet_import_attempt_' . $sub_w, maybe_serialize( $earlier_w ) ) ); // phpcs:ignore WordPress.DB
		wp_cache_flush();
		$unwritten = $import_unwritable();
		check( 'nezapsaný pokus přes starší: chyba databáze', is_wp_error( $unwritten ) ? $unwritten->get_error_code() : null, 'raynet_import_lock_failed' );
		check( 'nezapsaný pokus přes starší: nic neodešlo', count( get_option( 'raynet_test_http_calls', array() ) ), 0 );
		check( 'nezapsaný pokus přes starší: záznam beze změny', $attempt_row( $sub_w ), $earlier_w );
		check( 'nezapsaný pokus přes starší: zámek uvolněn', $lock_row( $sub_w ), null );

		// update_option() says false for a value that is already stored: that is
		// a written record, not a failure.
		$record_attempt = new ReflectionMethod( 'Raynet_Elementor_Submissions', 'record_attempt' );
		$record_attempt->setAccessible( true );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'raynet_import_attempt_' . $sub_w ) ); // phpcs:ignore WordPress.DB
		wp_cache_flush();
		check( 'záznam pokusu: zápis se povede', $record_attempt->invoke( null, $sub_w, 'pending' ), true );
		check( 'záznam pokusu: stejná hodnota podruhé je zapsaná', $record_attempt->invoke( null, $sub_w, 'pending' ), true );
		check( 'záznam pokusu: v databázi pending', is_array( $attempt_row( $sub_w ) ) ? $attempt_row( $sub_w )['outcome'] : null, 'pending' );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'raynet_import_attempt_' . $sub_w ) ); // phpcs:ignore WordPress.DB
		wp_cache_flush();

		// RAYNET's reason is kept short: a refused request may echo what was sent.
		$sub_l       = $add_stored( $import_page, 'Libor', 'libor.dlouhy@example.cz', 'Dobrý den, zajímá nás pravidelný servis kotlů ve dvou provozovnách.', '192.0.2.26' );
		$long_reason = str_repeat( 'ž', 2000 );
		$refuse_long = function ( $preempt, $args, $url ) use ( $long_reason ) {
			return false !== strpos( $url, '/lead/' ) ? array( 'headers' => array(), 'body' => wp_json_encode( array( 'message' => $long_reason ) ), 'response' => array( 'code' => 400, 'message' => 'Bad Request' ), 'cookies' => array(), 'filename' => null ) : $preempt;
		};
		add_filter( 'pre_http_request', $refuse_long, 5, 3 );
		$refused_l = Raynet_Elementor_Submissions::import( $import_page, 'impform', $sub_l, 'import-web' );
		remove_filter( 'pre_http_request', $refuse_long, 5 );
		wp_cache_flush();
		$long_attempt = $attempt_row( $sub_l );
		check( 'dlouhý důvod: chyba API vrátí celý', is_wp_error( $refused_l ) && false !== strpos( $refused_l->get_error_message(), $long_reason ), true );
		check( 'dlouhý důvod: pokus odmítnutý', is_array( $long_attempt ) ? $long_attempt['outcome'] : null, 'refused' );
		check( 'dlouhý důvod: uloženo nejvýš 500 znaků', is_array( $long_attempt ) ? mb_strlen( $long_attempt['message'], 'UTF-8' ) : null, 500 );
		check( 'dlouhý důvod: platné UTF-8', is_array( $long_attempt ) && mb_check_encoding( $long_attempt['message'], 'UTF-8' ), true );
		check( 'dlouhý důvod: začátek zůstane', is_array( $long_attempt ) && 0 === strpos( $long_attempt['message'], 'RAYNET API vrátilo chybu 400: žžž' ), true );

		// --- The count in the link leaves out leads the plugin already created ---
		$count_page = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Počítaný import', 'post_status' => 'publish' ) );
		update_post_meta( $count_page, '_elementor_edit_mode', 'builder' );
		update_post_meta( $count_page, '_elementor_data', wp_slash( wp_json_encode( $import_form( $import_map ) ) ) );
		$count_key = $count_page . ':impform';
		$sub_n1    = $add_stored( $count_page, 'Nela', 'nela.pocitana@example.cz', 'Dobrý den, poptáváme montáž venkovních žaluzií na šest oken.', '192.0.2.41' );
		$sub_n2    = $add_stored( $count_page, 'Norbert', 'norbert.pocitany@example.cz', 'Prosíme o nabídku na pokládku zámkové dlažby u garáže.', '192.0.2.42' );
		$put_attempt = function ( $submission_id, $outcome ) use ( $wpdb ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'raynet_import_attempt_' . $submission_id ) ); // phpcs:ignore WordPress.DB
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'raynet_import_attempt_' . $submission_id, maybe_serialize( array( 'time' => time(), 'outcome' => $outcome, 'message' => '', 'lead_id' => 'unlogged' === $outcome ? 4343 : 0 ) ) ) ); // phpcs:ignore WordPress.DB
			wp_cache_flush();
		};
		$put_attempt( $sub_n2, 'unlogged' );
		$counted = Raynet_Elementor_Submissions::form_counts();
		check( 'form_counts: lead bez označení nečeká', isset( $counted[ $count_key ] ) ? $counted[ $count_key ] : null, array( 'pending' => 1, 'total' => 2 ) );
		$put_attempt( $sub_n2, 'unknown' );
		$counted = Raynet_Elementor_Submissions::form_counts();
		check( 'form_counts: neznámý výsledek dál čeká', isset( $counted[ $count_key ] ) ? $counted[ $count_key ] : null, array( 'pending' => 2, 'total' => 2 ) );
		foreach ( array( $sub_n1, $sub_n2 ) as $stored_id ) {
			$import_query->delete_submission( $stored_id );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'raynet_import_attempt_' . $stored_id ) ); // phpcs:ignore WordPress.DB
		}
		$drop_index( $count_key );
		wp_delete_post( $count_page, true );

		// --- The index cache: compact chunks, dropped by every import -------------
		$cache_page = (int) wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'Kešovaný import', 'post_status' => 'publish' ) );
		update_post_meta( $cache_page, '_elementor_edit_mode', 'builder' );
		update_post_meta( $cache_page, '_elementor_data', wp_slash( wp_json_encode( $import_form( $import_map ) ) ) );
		$cache_key   = $cache_page . ':impform';
		$cache_name  = Raynet_Elementor_Submissions::CACHE_PREFIX . md5( $cache_key );
		$copy_text   = 'Dobrý den, chtěli bychom nabídku na zastřešení terasy u rodinného domu.';
		// X and Y are one enquiry sent twice: X first, so Y is the duplicate.
		$sub_x  = $add_stored( $cache_page, 'Xenie', 'xenie.dvakrat@example.cz', $copy_text, '192.0.2.31' );
		$sub_v1 = $add_stored( $cache_page, 'Vilém', 'vilem.kes@example.cz', 'Poptáváme dodávku a montáž plotu kolem zahrady, zhruba čtyřicet metrů.', '192.0.2.32' );
		$sub_v2 = $add_stored( $cache_page, 'Věra', 'vera.kes@example.cz', 'Máme zájem o pravidelné čištění odpadů v bytovém domě se čtyřmi vchody.', '192.0.2.33' );
		$sub_v3 = $add_stored( $cache_page, 'Vojtěch', 'vojtech.kes@example.cz', 'Prosíme o cenu za broušení a lakování parket ve třech pokojích bytu.', '192.0.2.34' );
		$sub_y  = $add_stored( $cache_page, 'Xenie', 'xenie.dvakrat@example.cz', $copy_text, '192.0.2.35' );
		$cache_subs = array( $sub_x, $sub_v1, $sub_v2, $sub_v3, $sub_y );

		$chunk_two  = function () {
			return 2;
		};
		$classified = 0;
		$count_runs = function ( $result ) use ( &$classified ) {
			$classified++;
			return $result;
		};
		add_filter( 'raynet_import_index_chunk_size', $chunk_two );
		add_filter( 'raynet_import_spam_score', $count_runs );

		$drop_index( $cache_key );
		$built = Raynet_Elementor_Submissions::index( $cache_page, 'impform' );
		$head  = get_transient( $cache_name );
		check( 'keš: pět odeslání v indexu', array_keys( $built ), array_reverse( $cache_subs ) );
		check( 'keš: hlavička se třemi díly', is_array( $head ) && isset( $head['chunks'] ) ? $head['chunks'] : null, 3 );
		check( 'keš: tři díly uložené', array( false !== get_transient( $cache_name . '_0' ), false !== get_transient( $cache_name . '_1' ), false !== get_transient( $cache_name . '_2' ), get_transient( $cache_name . '_3' ) ), array( true, true, true, false ) );
		check( 'keš: tvar položky jako dřív', array_keys( $built[ $sub_v1 ] ), array( 'state', 'verdict', 'reasons', 'tab', 'preselect', 'block', 'number_warning' ) );
		check( 'keš: X předvybrané, Y duplicitní', array( $built[ $sub_x ]['preselect'], in_array( 'duplicate', array_column( $built[ $sub_y ]['reasons'], 'id' ), true ), $built[ $sub_y ]['preselect'] ), array( true, true, false ) );

		$classified = 0;
		$again      = Raynet_Elementor_Submissions::index( $cache_page, 'impform' );
		check( 'keš: druhé čtení z keše, beze skórování', $classified, 0 );
		check( 'keš: druhé čtení dá totéž', $again, $built );

		delete_transient( $cache_name . '_1' );
		$classified = 0;
		$rebuilt    = Raynet_Elementor_Submissions::index( $cache_page, 'impform' );
		check( 'keš: chybějící díl znamená nový výpočet', $classified > 0, true );
		check( 'keš: po výpočtu stejný výsledek', $rebuilt, $built );
		check( 'keš: chybějící díl je zase uložený', false !== get_transient( $cache_name . '_1' ), true );

		remove_filter( 'raynet_import_spam_score', $count_runs );

		// Another tab sent V1 meanwhile: the cache still says "preselected", the
		// screen asks the database and offers no checkbox.
		$import_query->add_action_log( $sub_v1, $action, 'success', 'Lead 79' );
		$stale_screen = req( '/wp-admin/admin.php?page=raynet-elementor-forms&import=' . rawurlencode( $cache_key ), null, true )['body'];
		$stale_v1     = $import_row( $stale_screen, $sub_v1 );
		check( 'stará keš: V1 stále v keši jako předvybrané', Raynet_Elementor_Submissions::index( $cache_page, 'impform' )[ $sub_v1 ]['preselect'], true );
		check( 'stará keš: řádek V1 je na obrazovce', '' !== $stale_v1, true );
		check( 'stará keš: V1 bez zaškrtávátka', false !== strpos( $stale_v1, 'name="submissions[]"' ), false );
		check( 'stará keš: V1 jako už v RAYNETu', false !== strpos( $stale_v1, 'Už v RAYNETu' ), true );
		check( 'stará keš: V2 zaškrtnuté', false !== strpos( $import_row( $stale_screen, $sub_v2 ), 'checked' ), true );

		// Y is sent: every cached row of the form goes, and X is the duplicate now.
		$sent_y = Raynet_Elementor_Submissions::import( $cache_page, 'impform', $sub_y, 'import-web' );
		wp_cache_flush();
		check( 'import Y vrátí lead', is_array( $sent_y ) ? $sent_y['lead_id'] : $sent_y->get_error_code(), 4242 );
		check( 'po importu žádná keš', array( get_transient( $cache_name ), get_transient( $cache_name . '_0' ), get_transient( $cache_name . '_1' ), get_transient( $cache_name . '_2' ) ), array( false, false, false, false ) );
		$after_y = Raynet_Elementor_Submissions::index( $cache_page, 'impform' );
		check( 'po odeslání Y je X duplicitní a nepředvybrané', array( in_array( 'duplicate', array_column( $after_y[ $sub_x ]['reasons'], 'id' ), true ), $after_y[ $sub_x ]['preselect'] ), array( true, false ) );

		// A refused import changes the row too, so the cache goes as well.
		update_option( 'raynet_test_refuse_leads', 1 );
		Raynet_Elementor_Submissions::import( $cache_page, 'impform', $sub_v3, 'import-web' );
		delete_option( 'raynet_test_refuse_leads' );
		wp_cache_flush();
		check( 'po neúspěšném importu žádná keš', array( get_transient( $cache_name ), get_transient( $cache_name . '_0' ) ), array( false, false ) );

		// V2 is imported while an index is being built: the build read V2 as
		// new before the import dropped the cache, and must not be served after.
		$generation_before = get_option( 'raynet_import_idx_gen', null );
		$mid_build_import  = null;
		$import_mid_build  = function ( $result ) use ( &$mid_build_import, $cache_page, $sub_v2 ) {
			if ( null === $mid_build_import ) {
				$mid_build_import = Raynet_Elementor_Submissions::import( $cache_page, 'impform', $sub_v2, 'import-web' );
			}
			return $result;
		};
		add_filter( 'raynet_import_spam_score', $import_mid_build );
		$during = Raynet_Elementor_Submissions::index( $cache_page, 'impform' );
		remove_filter( 'raynet_import_spam_score', $import_mid_build );
		check( 'souběh: V2 importováno během výpočtu', is_array( $mid_build_import ) ? $mid_build_import['lead_id'] : $mid_build_import, 4242 );
		check( 'souběh: výpočet ještě viděl V2 jako nové', isset( $during[ $sub_v2 ] ) ? $during[ $sub_v2 ]['state'] : null, Raynet_Elementor_Submissions::STATE_NEW );
		check( 'souběh: import posunul generaci keše', get_option( 'raynet_import_idx_gen', null ) !== $generation_before, true );
		$classified = 0;
		add_filter( 'raynet_import_spam_score', $count_runs );
		$after_race = Raynet_Elementor_Submissions::index( $cache_page, 'impform' );
		remove_filter( 'raynet_import_spam_score', $count_runs );
		check( 'souběh: další čtení počítá znovu', $classified > 0, true );
		check( 'souběh: V2 už v RAYNETu', isset( $after_race[ $sub_v2 ] ) ? array( $after_race[ $sub_v2 ]['tab'], $after_race[ $sub_v2 ]['preselect'] ) : null, array( Raynet_Elementor_Submissions::TAB_SENT, false ) );

		remove_filter( 'raynet_import_index_chunk_size', $chunk_two );
		foreach ( $cache_subs as $stored_id ) {
			$import_query->delete_submission( $stored_id );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'raynet_import_attempt_' . $stored_id ) ); // phpcs:ignore WordPress.DB
		}
		$drop_index( $cache_key );
		wp_delete_post( $cache_page, true );

		// A form in a template is stored under the template's id, with the page
		// it was sent from in meta, as a number or as a string.
		$sub_g = $add_stored( $import_page + 200000, 'Gita', 'gita@example.cz', 'Odesláno z formuláře v šabloně, stránka je jen v meta údajích.', '192.0.2.7', array( 'meta' => wp_json_encode( array( 'edit_post_id' => $import_page ) ) ) );
		$sub_h = $add_stored( $import_page + 200000, 'Hana', 'hana@example.cz', 'Odesláno z šablony na stránce, jejíž ID je řetězec v meta údajích.', '192.0.2.8' );
		$wpdb->update( $wpdb->prefix . 'e_submissions', array( 'meta' => wp_json_encode( array( 'edit_post_id' => (string) $import_page ) ) ), array( 'id' => $sub_h ) ); // phpcs:ignore WordPress.DB
		$sub_i = $add_stored( $import_page + 200000, 'Ivo', 'ivo@example.cz', 'Stránka s ID, které jen začíná stejnými číslicemi, sem nepatří.', '192.0.2.9', array( 'meta' => wp_json_encode( array( 'edit_post_id' => (int) ( $import_page . '1' ) ) ) ) );
		check( 'stránka z meta jako číslo i řetězec, ne předpona', array_keys( Raynet_Elementor_Submissions::load( $import_page, 'impform', array( $sub_g, $sub_h, $sub_i ) ) ), array( $sub_h, $sub_g ) );

		foreach ( array( $sub_a, $sub_b, $sub_c, $sub_d, $sub_e, $sub_f, $sub_g, $sub_h, $sub_i, $sub_t, $sub_u, $sub_r, $sub_p, $sub_w, $sub_l ) as $stored_id ) {
			$import_query->delete_submission( $stored_id );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", 'raynet_import_attempt_' . $stored_id ) ); // phpcs:ignore WordPress.DB
		}
		$drop_index( $import_key );
		delete_option( 'raynet_import_idx_gen' );
		if ( null === $last_error_orig ) {
			delete_option( 'raynet_lead_last_error' );
		} else {
			update_option( 'raynet_lead_last_error', $last_error_orig, false );
		}
		wp_delete_post( $import_page, true );
	}
}

echo "\n=== PHP chyby ===\n";

$log      = WP_CONTENT_DIR . '/debug.log';
$mine     = array();

if ( file_exists( $log ) ) {
	foreach ( explode( "\n", (string) file_get_contents( $log ) ) as $line ) {
		if ( false === strpos( $line, 'PHP ' ) ) {
			continue;
		}

		// The SQLite driver is noisy on PHP 8.5 and is not ours.
		if ( false !== strpos( $line, 'sqlite-database-integration' ) || false !== strpos( $line, 'wp-cli.phar' ) ) {
			continue;
		}

		// Elementor Pro's own warnings when a request carries no file part at
		// all, which the forged-path test above sends on purpose and no browser
		// ever does.
		if ( false !== strpos( $line, 'elementor-pro/modules/forms/fields/upload.php' ) ) {
			continue;
		}

		$mine[] = $line;
	}
}

check( 'žádné PHP chyby z pluginu', count( $mine ), 0 );

foreach ( array_slice( $mine, 0, 5 ) as $line ) {
	echo '     ' . substr( $line, 0, 160 ) . "\n";
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
