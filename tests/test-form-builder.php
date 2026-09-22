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

check( 'katalog: 10 atributů + celé jméno + souhlas', count( Raynet_Lead_Form_Definition::catalogue() ), 12 );
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

// ---------- Rozdělení jména ----------
$split = fn( $s ) => Raynet_Lead_Form_Definition::split_name( $s );

check( 'dvě slova',          $split( 'Jan Novák' ), array( 'firstName' => 'Jan', 'lastName' => 'Novák' ) );
check( 'tři slova',          $split( 'Jan Petr Novák' ), array( 'firstName' => 'Jan Petr', 'lastName' => 'Novák' ) );
check( 'jedno slovo je příjmení', $split( 'Novák' ), array( 'firstName' => '', 'lastName' => 'Novák' ) );
check( 'přebytečné mezery',  $split( '  Jan   Novák  ' ), array( 'firstName' => 'Jan', 'lastName' => 'Novák' ) );
check( 'prázdné',            $split( '' ), array( 'firstName' => '', 'lastName' => '' ) );

check( 'celé jméno je zdrojem leadu', Raynet_Lead_Form_Definition::is_lead_source( 'fullName' ), true );

// Asking for the whole name and one of its halves at once is refused, because
// one would overwrite the other.
check( 'celé jméno + jméno se vyloučí',
	count( Raynet_Lead_Form_Definition::sanitize_fields( array(
		array( 'source' => 'fullName' ),
		array( 'source' => 'firstName' ),
	) ) ), 1 );
check( 'jméno + celé jméno se vyloučí',
	count( Raynet_Lead_Form_Definition::sanitize_fields( array(
		array( 'source' => 'lastName' ),
		array( 'source' => 'fullName' ),
	) ) ), 1 );

$nameform = new Raynet_Lead_Form();
$vals = ( new ReflectionMethod( $nameform, 'collect_values' ) )->invoke( $nameform,
	array( 'fullName' => 'Jan Novák' ),
	Raynet_Lead_Form_Definition::sanitize_fields( array( array( 'source' => 'fullName' ) ) )
);
check( 'rozdělené jméno v hodnotách',   $vals['firstName'], 'Jan' );
check( 'rozdělené příjmení v hodnotách', $vals['lastName'], 'Novák' );

// The Elementor action maps by attribute name and passes no field list, so
// both can arrive at once. The separately mapped one is the more deliberate
// answer and wins.
$vals2 = ( new ReflectionMethod( $nameform, 'collect_values' ) )->invoke( $nameform,
	array( 'fullName' => 'Jan Novák', 'firstName' => 'Petr' )
);
check( 'samostatné jméno má přednost', $vals2['firstName'], 'Petr' );
check( 'příjmení se doplní z rozdělení', $vals2['lastName'], 'Novák' );

$vals3 = ( new ReflectionMethod( $nameform, 'collect_values' ) )->invoke( $nameform, array( 'fullName' => 'Eva Dvořáková' ) );
check( 'celé jméno prochází i bez definice', $vals3['firstName'] . '/' . $vals3['lastName'], 'Eva/Dvořáková' );

// ---------- Odhad mapování pro Elementor ----------
function mapped_to( array $rows ) {
	$out = array();
	foreach ( $rows as $r ) {
		if ( '' !== $r['local_id'] ) { $out[ $r['remote_id'] ] = $r['local_id']; }
	}
	return $out;
}

$guess = mapped_to( Raynet_Elementor_Forms::auto_map( array(
	array( 'id' => 'f1', 'type' => 'text',     'label' => 'Jméno' ),
	array( 'id' => 'f2', 'type' => 'text',     'label' => 'Příjmení' ),
	array( 'id' => 'f3', 'type' => 'email',    'label' => 'Kontakt na vás' ),
	array( 'id' => 'f4', 'type' => 'tel',      'label' => 'Číslo' ),
	array( 'id' => 'f5', 'type' => 'textarea', 'label' => 'O co jde' ),
	array( 'id' => 'f6', 'type' => 'text',     'label' => 'Firma' ),
) ) );

check( 'typ email rozhodne',    $guess['email'], 'f3' );
check( 'typ tel rozhodne',      $guess['phone'], 'f4' );
check( 'typ textarea rozhodne', $guess['message'], 'f5' );
check( 'popisek Jméno',         $guess['firstName'], 'f1' );
check( 'popisek Příjmení',      $guess['lastName'], 'f2' );
check( 'popisek Firma',         $guess['companyName'], 'f6' );

// Diacritics must not matter, and one field cannot feed two attributes.
$guess2 = mapped_to( Raynet_Elementor_Forms::auto_map( array(
	array( 'id' => 'a', 'type' => 'text', 'label' => 'PSČ' ),
	array( 'id' => 'b', 'type' => 'text', 'label' => 'Město' ),
	array( 'id' => 'c', 'type' => 'text', 'label' => 'Ulice a číslo popisné' ),
) ) );
check( 'PSČ bez diakritiky', $guess2['zipCode'], 'a' );
check( 'Město',              $guess2['city'], 'b' );
check( 'Ulice',              $guess2['street'], 'c' );

// A single name box maps to the whole-name attribute, not to one half.
$guess3 = mapped_to( Raynet_Elementor_Forms::auto_map( array(
	array( 'id' => 'n', 'type' => 'text',  'label' => 'Jméno a příjmení' ),
	array( 'id' => 'e', 'type' => 'email', 'label' => 'E-mail' ),
) ) );
check( 'celé jméno rozpoznáno',   $guess3['fullName'], 'n' );
check( 'nenastavuje se firstName', isset( $guess3['firstName'] ), false );

// With both halves present the whole-name row is dropped, so nothing overwrites.
$guess4 = mapped_to( Raynet_Elementor_Forms::auto_map( array(
	array( 'id' => 'x', 'type' => 'text', 'label' => 'Celé jméno' ),
	array( 'id' => 'y', 'type' => 'text', 'label' => 'Příjmení' ),
) ) );
check( 'při konfliktu vyhraje půlka', isset( $guess4['fullName'] ), false );
check( 'příjmení zůstává',            $guess4['lastName'], 'y' );

$all = Raynet_Elementor_Forms::auto_map( array() );
check( 'vždy 11 řádků',       count( $all ), 11 );
check( 'souhlas není v mapě', in_array( 'consent', array_column( $all, 'remote_id' ), true ), false );
check( 'řádky nesou popisek', ! empty( $all[0]['remote_label'] ), true );
check( 'řádky nesou typ',     $all[0]['remote_type'], 'text' );

// configure() turns the action on and writes the lead settings through.
$configured = Raynet_Elementor_Forms::configure(
	array( 'form_fields' => array( array( 'custom_id' => 'e', 'field_type' => 'email', 'field_label' => 'E-mail' ) ) ),
	array( 'priority' => 'CRITICAL', 'category' => '5', 'tags' => 'web' ),
	true
);
check( 'akce zapnuta',        in_array( 'raynet_crm', $configured['submit_actions'], true ), true );
check( 'priorita zapsána',    $configured['raynet_crm_priority'], 'CRITICAL' );
check( 'kategorie zapsána',   $configured['raynet_crm_category'], '5' );
check( 'nula = zdědit',       $configured['raynet_crm_owner'], '' );
check( 'mapa vytvořena',      mapped_to( $configured['raynet_crm_fields_map'] )['email'], 'e' );

$twice = Raynet_Elementor_Forms::configure( $configured, array(), false );
check( 'akce se nezdvojí', count( array_keys( $twice['submit_actions'], 'raynet_crm', true ) ), 1 );

// ---------- Nálezy z adversariálního review ----------

// Zaškrtávátko souhlasu nesmí ukrást e-mail jen proto, že má v popisku "mail".
$r3 = mapped_to( Raynet_Elementor_Forms::auto_map( array(
	array( 'id' => 'ok',   'type' => 'acceptance', 'label' => 'Chci dostávat novinky e-mailem' ),
	array( 'id' => 'mail', 'type' => 'text',       'label' => 'Váš e-mail' ),
	array( 'id' => 'tel',  'type' => 'tel',        'label' => 'Telefon' ),
) ) );
check( 'souhlas neukradne e-mail', $r3['email'], 'mail' );
check( 'souhlas se nemapuje',      in_array( 'ok', $r3, true ), false );

// "Vaše jméno" + "Vaše příjmení" musí dát obě půlky, ne celé jméno.
$r4 = mapped_to( Raynet_Elementor_Forms::auto_map( array(
	array( 'id' => 'a', 'type' => 'text',     'label' => 'Vaše jméno' ),
	array( 'id' => 'b', 'type' => 'text',     'label' => 'Vaše příjmení' ),
	array( 'id' => 'c', 'type' => 'email',    'label' => 'E-mail' ),
	array( 'id' => 'd', 'type' => 'textarea', 'label' => 'Zpráva' ),
) ) );
check( 'jméno zvlášť',        $r4['firstName'], 'a' );
check( 'příjmení zvlášť',     $r4['lastName'], 'b' );
check( 'celé jméno se nepoužije', isset( $r4['fullName'] ), false );

// Dvě textarea: rozhodne popisek, ne pořadí.
$r5 = mapped_to( Raynet_Elementor_Forms::auto_map( array(
	array( 'id' => 'addr', 'type' => 'textarea', 'label' => 'Fakturační adresa' ),
	array( 'id' => 'msg',  'type' => 'textarea', 'label' => 'Vaše zpráva' ),
	array( 'id' => 'e',    'type' => 'email',    'label' => 'E-mail' ),
) ) );
check( 'zpráva podle popisku', $r5['message'], 'msg' );
check( 'adresa podle popisku', $r5['street'], 'addr' );

// "E-mailová adresa" nesmí skončit jako ulice, ani když e-mail zabral jiný.
$r6 = mapped_to( Raynet_Elementor_Forms::auto_map( array(
	array( 'id' => 'mail1', 'type' => 'text',  'label' => 'E-mailová adresa' ),
	array( 'id' => 'mail2', 'type' => 'email', 'label' => 'Potvrzení e-mailu' ),
	array( 'id' => 'tel',   'type' => 'tel',   'label' => 'Telefon' ),
) ) );
check( 'e-mailová adresa není ulice', isset( $r6['street'] ), false );
check( 'e-mail namapován',            $r6['email'], 'mail1' );

// "Obecné poznámky" je zpráva, ne město.
$r7 = mapped_to( Raynet_Elementor_Forms::auto_map( array(
	array( 'id' => 'note', 'type' => 'text',  'label' => 'Obecné poznámky' ),
	array( 'id' => 'e',    'type' => 'email', 'label' => 'E-mail' ),
) ) );
check( 'poznámky jsou zpráva', $r7['message'], 'note' );
check( 'nejsou město',         isset( $r7['city'] ), false );

// Celé slovo versus kmen.
check( 'město se pozná',      mapped_to( Raynet_Elementor_Forms::auto_map( array( array( 'id' => 'm', 'type' => 'text', 'label' => 'Město' ) ) ) )['city'], 'm' );
check( 'obec se pozná',       mapped_to( Raynet_Elementor_Forms::auto_map( array( array( 'id' => 'o', 'type' => 'text', 'label' => 'Obec' ) ) ) )['city'], 'o' );
check( 'poznámka i poznámky', mapped_to( Raynet_Elementor_Forms::auto_map( array( array( 'id' => 'p', 'type' => 'text', 'label' => 'Poznámka' ) ) ) )['message'], 'p' );

// Jedno pole namapované na celé jméno i na jméno nesmí zdvojit příjmení.
$dup = ( new ReflectionMethod( $nameform, 'collect_values' ) )->invoke( $nameform,
	array( 'fullName' => 'Jan Novák', 'firstName' => 'Jan Novák' )
);
check( 'dvojí mapování nezdvojí', $dup['firstName'], 'Jan' );
check( 'příjmení z rozdělení',    $dup['lastName'], 'Novák' );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
