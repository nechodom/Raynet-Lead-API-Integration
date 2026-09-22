<?php
/**
 * Tests for the form builder: field definitions, storage and rendering.
 */

require __DIR__ . '/wp-stubs.php';

$raynet_includes = dirname( __DIR__ ) . '/raynet-lead-api-integration/includes/';
require_once $raynet_includes . 'class-raynet-settings.php';
require_once $raynet_includes . 'class-raynet-form-definition.php';
require_once $raynet_includes . 'class-raynet-form-post-type.php';
require_once $raynet_includes . 'class-raynet-form-renderer.php';

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = $got === $want; $ok ? $pass++ : $fail++;
	printf( "%s %s\n", $ok ? 'ok  ' : 'FAIL', $label );
	if ( ! $ok ) { echo "     got:  " . var_export( $got, true ) . "\n     want: " . var_export( $want, true ) . "\n"; }
}

// ---------- Definition: fields ----------
$fields = Raynet_Lead_Form_Definition::sanitize_fields( array(
	array( 'source' => 'email', 'label' => 'Váš e-mail', 'required' => '1', 'width' => 'half' ),
	array( 'source' => 'vymysleny', 'label' => 'Nic' ),
	array( 'source' => 'email', 'label' => 'Duplikát' ),
	array( 'source' => 'custom', 'type' => 'select', 'label' => 'Odkud?', 'options' => array( 'Google', '', 'Známý' ) ),
	array( 'source' => 'message', 'type' => 'email', 'label' => 'Zpráva' ),
) );

check( 'neznámý zdroj vypadne',        count( $fields ), 3 );
check( 'e-mail zůstal první',          $fields[0]['source'], 'email' );
check( 'typ odvozen ze zdroje',        $fields[0]['type'], 'email' );
check( 'popisek zachován',             $fields[0]['label'], 'Váš e-mail' );
check( 'required je bool',             $fields[0]['required'], true );
check( 'šířka zachována',              $fields[0]['width'], 'half' );
check( 'id přiděleno',                 (bool) preg_match( '/^f_[a-z0-9]{6}$/', $fields[0]['id'] ), true );
check( 'duplicitní zdroj vypadne',     $fields[1]['source'], 'custom' );
check( 'vlastní pole si typ volí',     $fields[1]['type'], 'select' );
check( 'prázdná možnost vypadne',      $fields[1]['options'], array( 'Google', 'Známý' ) );
check( 'typ RAYNET pole nejde přepsat', $fields[2]['type'], 'textarea' );
check( 'textarea je vždy celá šířka',  $fields[2]['width'], 'full' );

$again = Raynet_Lead_Form_Definition::sanitize_fields( $fields );
check( 'existující id se zachová', $again[0]['id'], $fields[0]['id'] );

check( 'katalog má deset zdrojů + souhlas', count( Raynet_Lead_Form_Definition::catalogue() ), 11 );
check( 'výchozí sada kopíruje starou zkratku', count( Raynet_Lead_Form_Definition::default_fields() ), 7 );
check( 'prázdný popisek se doplní z katalogu',
	Raynet_Lead_Form_Definition::sanitize_fields( array( array( 'source' => 'city' ) ) )[0]['label'], 'Město' );
check( 'jen jeden souhlas',
	count( Raynet_Lead_Form_Definition::sanitize_fields( array(
		array( 'source' => 'consent', 'label' => 'A' ),
		array( 'source' => 'consent', 'label' => 'B' ),
	) ) ), 1 );
check( 'vlastní pole se smí opakovat',
	count( Raynet_Lead_Form_Definition::sanitize_fields( array(
		array( 'source' => 'custom', 'label' => 'A' ),
		array( 'source' => 'custom', 'label' => 'B' ),
	) ) ), 2 );
check( 'neznámý vlastní typ spadne na text',
	Raynet_Lead_Form_Definition::sanitize_fields( array( array( 'source' => 'custom', 'type' => 'range', 'label' => 'A' ) ) )[0]['type'], 'text' );
check( 'vlastní pole bez popisku vypadne',
	count( Raynet_Lead_Form_Definition::sanitize_fields( array( array( 'source' => 'custom', 'label' => '  ' ) ) ) ), 0 );

// ---------- Definition: lead settings ----------
$lead = Raynet_Lead_Form_Definition::sanitize_lead_settings( array(
	'priority' => 'critical', 'category' => '-3', 'owner' => '7',
	'tags' => 'web, poptavka', 'notify_emails' => 'a@b.cz, spatny',
	'topic' => 'Poptávka', 'cizi_klic' => 'pryc',
) );

check( 'priorita velkými',          $lead['priority'], 'CRITICAL' );
check( 'záporné ID na nulu',        $lead['category'], 0 );
check( 'vlastník prošel',           $lead['owner'], 7 );
check( 'e-maily profiltrované',     $lead['notify_emails'], 'a@b.cz' );
check( 'cizí klíč vypadl',          isset( $lead['cizi_klic'] ), false );
check( 'prázdné zůstane prázdné',   $lead['lead_phase'], 0 );
check( 'předmět prošel',            $lead['topic'], 'Poptávka' );
check( 'neznámá priorita = zdědit', Raynet_Lead_Form_Definition::sanitize_lead_settings( array( 'priority' => 'x' ) )['priority'], '' );

// ---------- Post type: storage and resolution ----------
$id = Raynet_Lead_Form_Post_Type::create( 'Kontakt', Raynet_Lead_Form_Definition::default_fields(), array() );
check( 'formulář založen',        $id > 0, true );
check( 'pole uložena',            count( Raynet_Lead_Form_Post_Type::get_fields( $id ) ), 7 );
check( 'nastavení leadu prázdné', Raynet_Lead_Form_Post_Type::get_lead_settings( $id )['priority'], '' );

Raynet_Lead_Form_Post_Type::set_default( $id );
check( 'výchozí nalezen bez id', Raynet_Lead_Form_Post_Type::resolve( '' ), $id );
check( 'nalezen podle čísla',    Raynet_Lead_Form_Post_Type::resolve( (string) $id ), $id );
check( 'nalezen podle slugu',    Raynet_Lead_Form_Post_Type::resolve( 'kontakt' ), $id );
check( 'neznámý slug = 0',       Raynet_Lead_Form_Post_Type::resolve( 'neexistuje' ), 0 );
check( 'cizí typ = 0',           Raynet_Lead_Form_Post_Type::resolve( '999' ), 0 );

Raynet_Lead_Form_Post_Type::save_lead_settings( $id, array( 'priority' => 'MINOR', 'owner' => '4' ) );
check( 'nastavení leadu uloženo', Raynet_Lead_Form_Post_Type::get_lead_settings( $id )['owner'], 4 );

Raynet_Lead_Form_Post_Type::save_fields( $id, array( array( 'source' => 'email', 'label' => 'Mail' ) ) );
check( 'pole přepsána',          count( Raynet_Lead_Form_Post_Type::get_fields( $id ) ), 1 );
check( 'uložené pole prošlo sanitizací', Raynet_Lead_Form_Post_Type::get_fields( $id )[0]['type'], 'email' );

$GLOBALS['wp_posts'][ $id ]['post_status'] = 'trash';
check( 'formulář v koši = 0', Raynet_Lead_Form_Post_Type::resolve( 'kontakt' ), 0 );
$GLOBALS['wp_posts'][ $id ]['post_status'] = 'publish';

// ---------- Renderer ----------
function mkfield( array $over ) {
	return array_merge( array(
		'id' => 'f_aaaaaa', 'source' => 'firstName', 'type' => 'text', 'label' => 'X',
		'placeholder' => '', 'help' => '', 'required' => false, 'width' => 'full', 'options' => array(),
	), $over );
}

$html = Raynet_Lead_Form_Renderer::render_fields( array(
	mkfield( array( 'id' => 'f_aaaaaa', 'source' => 'firstName', 'type' => 'text', 'label' => 'Křestní', 'width' => 'half' ) ),
	mkfield( array( 'id' => 'f_bbbbbb', 'source' => 'email', 'type' => 'email', 'label' => 'E-mail', 'placeholder' => 'a@b.cz', 'help' => 'Nápověda', 'required' => true ) ),
	mkfield( array( 'id' => 'f_cccccc', 'source' => 'custom', 'type' => 'select', 'label' => 'Odkud?', 'options' => array( 'Google', 'Známý' ) ) ),
	mkfield( array( 'id' => 'f_dddddd', 'source' => 'consent', 'type' => 'consent', 'label' => 'Souhlasím', 'required' => true ) ),
), 'raynet-form-1' );

check( 'atribut RAYNETu má své jméno',   str_contains( $html, 'name="firstName"' ), true );
check( 'vlastní pole má jmenný prostor', str_contains( $html, 'name="raynet_custom[f_cccccc]"' ), true );
check( 'souhlas se jmenuje consent',     str_contains( $html, 'name="consent"' ), true );
check( 'popisek správce vyhrál',         str_contains( $html, 'Křestní' ), true );
check( 'placeholder vykreslen',          str_contains( $html, 'placeholder="a@b.cz"' ), true );
check( 'nápověda vykreslena',            str_contains( $html, 'Nápověda' ), true );
check( 'povinné má required',            (bool) preg_match( '/name="email"[^>]*required/', $html ), true );
check( 'nepovinné nemá required',        (bool) preg_match( '/name="firstName"[^>]*required/', $html ), false );
check( 'půlená šířka má modifikátor',    str_contains( $html, 'raynet-lead-form__row--half' ), true );
check( 'select má prázdnou + dvě volby', substr_count( $html, '<option' ), 3 );
check( 'pořadí zachováno',               strpos( $html, 'name="firstName"' ) < strpos( $html, 'name="email"' ), true );
check( 'nápověda propojena přes aria',   str_contains( $html, 'aria-describedby="raynet-form-1-f_bbbbbb-help"' ), true );

$xss = Raynet_Lead_Form_Renderer::render_fields( array(
	mkfield( array( 'source' => 'city', 'label' => '<script>alert(1)</script>', 'placeholder' => '"><script>x</script>' ) ),
), 'uid' );
check( 'popisek escapován',     str_contains( $xss, '<script' ), false );
check( 'textarea je textarea',  str_contains( Raynet_Lead_Form_Renderer::render_fields( array( mkfield( array( 'source' => 'message', 'type' => 'textarea' ) ) ), 'u' ), '<textarea' ), true );

// ---------- Migration from 2.0 ----------
$GLOBALS['wp_options'] = array(); $GLOBALS['wp_posts'] = array(); $GLOBALS['wp_meta'] = array();
update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array(
	'region' => 'cz', 'username' => 'u@e.cz', 'api_key' => 'K', 'instance_name' => 'i',
	'consent_enabled' => '1', 'consent_label' => 'Souhlasím s <a href="/gdpr">podmínkami</a>.',
	'notice_prefix' => 'Z webu', 'category' => '5',
) ) );

Raynet_Lead_Form_Post_Type::maybe_migrate();
$fid = Raynet_Lead_Form_Post_Type::default_id();
check( 'výchozí formulář vznikl', $fid > 0, true );

$mf      = Raynet_Lead_Form_Post_Type::get_fields( $fid );
$sources = array_column( $mf, 'source' );
check( 'stejná pole jako stará zkratka', $sources,
	array( 'firstName', 'lastName', 'email', 'phone', 'topic', 'message', 'consent' ) );
check( 'e-mail povinný',   $mf[2]['required'], true );
check( 'zpráva povinná',   $mf[5]['required'], true );
check( 'telefon nepovinný', $mf[3]['required'], false );
check( 'popisek souhlasu převzat', str_contains( $mf[6]['label'], 'podmínkami' ), true );

$mlead = Raynet_Lead_Form_Post_Type::get_lead_settings( $fid );
check( 'nastavení leadu se dědí, nekopíruje', $mlead['category'], 0 );

Raynet_Lead_Form_Post_Type::maybe_migrate();
check( 'migrace běží jednou', count( $GLOBALS['wp_posts'] ), 1 );

// Bez globálního souhlasu se pole souhlasu nepřidá.
$GLOBALS['wp_options'] = array(); $GLOBALS['wp_posts'] = array(); $GLOBALS['wp_meta'] = array();
update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array( 'region' => 'cz' ) ) );
Raynet_Lead_Form_Post_Type::maybe_migrate();
check( 'bez globálního souhlasu žádné pole souhlasu',
	in_array( 'consent', array_column( Raynet_Lead_Form_Post_Type::get_fields( Raynet_Lead_Form_Post_Type::default_id() ), 'source' ), true ),
	false );

// A form left behind by the 2.1.0 fatal is finished, not duplicated.
$GLOBALS['wp_options'] = array(); $GLOBALS['wp_posts'] = array(); $GLOBALS['wp_meta'] = array();
update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array( 'region' => 'cz' ) ) );

$orphan = wp_insert_post( array( 'post_title' => 'Kontaktní formulář', 'post_type' => 'raynet_form', 'post_status' => 'publish' ) );
check( 'sirotek bez polí', count( Raynet_Lead_Form_Post_Type::get_fields( $orphan ) ), 0 );

Raynet_Lead_Form_Post_Type::maybe_migrate();
check( 'sirotek se nezduplikoval',   count( $GLOBALS['wp_posts'] ), 1 );
check( 'sirotek se stal výchozím',   Raynet_Lead_Form_Post_Type::default_id(), $orphan );
check( 'sirotek dostal pole',        count( Raynet_Lead_Form_Post_Type::get_fields( $orphan ) ) > 0, true );

// A form that already has fields is adopted untouched.
$GLOBALS['wp_options'] = array(); $GLOBALS['wp_posts'] = array(); $GLOBALS['wp_meta'] = array();
update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array( 'region' => 'cz' ) ) );
$kept = Raynet_Lead_Form_Post_Type::create( 'Ruční', Raynet_Lead_Form_Definition::sanitize_fields( array(
	array( 'source' => 'email', 'label' => 'Jen e-mail', 'required' => true ),
) ), array() );
Raynet_Lead_Form_Post_Type::maybe_migrate();
check( 'existující pole se nepřepíšou', count( Raynet_Lead_Form_Post_Type::get_fields( $kept ) ), 1 );
check( 'existující formulář výchozím',  Raynet_Lead_Form_Post_Type::default_id(), $kept );

// The builder's preview sits inside the post edit form. A required control
// there blocks WordPress's own Update button through browser validation, and a
// named one is submitted with the post. Disabled controls are exempt from both.
$preview_fields = array(
	mkfield( array( 'id' => 'f_111111', 'source' => 'email', 'type' => 'email', 'label' => 'E-mail', 'required' => true ) ),
	mkfield( array( 'id' => 'f_222222', 'source' => 'message', 'type' => 'textarea', 'label' => 'Zpráva', 'required' => true ) ),
	mkfield( array( 'id' => 'f_333333', 'source' => 'custom', 'type' => 'select', 'label' => 'Odkud?', 'options' => array( 'Google' ) ) ),
	mkfield( array( 'id' => 'f_444444', 'source' => 'consent', 'type' => 'consent', 'label' => 'Souhlasím', 'required' => true ) ),
);

$live    = Raynet_Lead_Form_Renderer::render_fields( $preview_fields, 'uid' );
$preview = Raynet_Lead_Form_Renderer::render_fields( $preview_fields, 'uid', true );

check( 'ostrý formulář má required',      (bool) preg_match( '/\srequired[\s>\/]/', $live ), true );
check( 'náhled nemá required',            (bool) preg_match( '/\srequired[\s>\/]/', $preview ), false );
check( 'náhled nemá name',                str_contains( $preview, 'name="' ), false );
check( 'náhled má disabled',              substr_count( $preview, 'disabled' ), 4 );
check( 'náhled má stále popisky',         str_contains( $preview, 'Odkud?' ), true );
check( 'náhled má stále souhlas',         str_contains( $preview, 'Souhlasím' ), true );
check( 'ostrý formulář má name',          str_contains( $live, 'name="email"' ), true );
check( 'ostrý formulář nemá disabled',    str_contains( $live, 'disabled' ), false );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
