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
		check( 'atomový formulář nejde nastavit', is_wp_error( Raynet_Elementor_Forms::apply( $atomic_page, 'atomform', array(), true ) ), true );

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

		$mine[] = $line;
	}
}

check( 'žádné PHP chyby z pluginu', count( $mine ), 0 );

foreach ( array_slice( $mine, 0, 5 ) as $line ) {
	echo '     ' . substr( $line, 0, 160 ) . "\n";
}

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
