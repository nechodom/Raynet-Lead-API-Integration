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

				check( 'nabízí deset atributů', count( $map_control['default'] ), 10 );
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
		add_filter(
			'pre_http_request',
			function () {
				return array(
					'headers'  => array(),
					'cookies'  => array(),
					'filename' => null,
					'body'     => wp_json_encode( array( 'message' => 'bad creds' ) ),
					'response' => array( 'code' => 401, 'message' => 'Unauthorized' ),
				);
			},
			5
		);

		$failed_msg = '';

		try {
			$action->run( $record, $handler );
		} catch ( \Exception $e ) {
			$failed_msg = $e->getMessage();
		}

		check( 'selhání API vyhodí výjimku', '' !== $failed_msg, true );
		check( 'výjimka nese diagnostiku', false !== strpos( $failed_msg, '401' ), true );

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
