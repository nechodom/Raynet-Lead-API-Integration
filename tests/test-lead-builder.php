<?php
/**
 * Tests for the lead builder: how submitted Elementor fields become a lead,
 * without the submit action (and so without Elementor Pro) in the way.
 */

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

$form_settings = array(
	Raynet_Elementor_Forms::ACTION_NAME . '_fields_map' => array(
		array( 'remote_id' => 'email', 'local_id' => 'email' ),
		array( 'remote_id' => 'firstName', 'local_id' => 'name' ),
		array( 'remote_id' => 'message', 'local_id' => 'msg' ),
		array( 'remote_id' => 'gdprConsent', 'local_id' => 'gdpr' ),
		array( 'remote_id' => 'regNumber', 'local_id' => 'ico' ),
	),
	Raynet_Elementor_Forms::NOTICE_KEY => array( 'odkud', 'name' ),
	'form_fields'                      => array(
		array( 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'E-mail' ),
		array( 'custom_id' => 'name', 'field_type' => 'text', 'field_label' => 'Jméno' ),
		array( 'custom_id' => 'msg', 'field_type' => 'textarea', 'field_label' => 'Vaše zpráva' ),
		array( 'custom_id' => 'gdpr', 'field_type' => 'acceptance', 'field_label' => 'gdpr', 'acceptance_text' => 'Souhlasím se <b>zpracováním</b> údajů' ),
		array( 'custom_id' => 'ico', 'field_type' => 'number', 'field_label' => 'IČO' ),
		array( 'custom_id' => 'odkud', 'field_type' => 'text', 'field_label' => 'Odkud o nás víte' ),
		array( 'custom_id' => 'heslo', 'field_type' => 'password', 'field_label' => 'Heslo' ),
	),
);

$fields = array(
	'email' => array( 'type' => 'email', 'title' => 'E-mail', 'value' => 'jana@firma.cz', 'raw_value' => 'jana@firma.cz' ),
	'name'  => array( 'type' => 'text', 'title' => 'Jméno', 'value' => 'Jana', 'raw_value' => 'Jana' ),
	'msg'   => array( 'type' => 'textarea', 'title' => 'Vaše zpráva', 'value' => 'Dobrý den', 'raw_value' => 'Dobrý den' ),
	'gdpr'  => array( 'type' => 'acceptance', 'title' => 'gdpr', 'value' => 'on', 'raw_value' => 'on' ),
	'ico'   => array( 'type' => 'number', 'title' => 'IČO', 'value' => 2795281, 'raw_value' => '02795281' ),
	'odkud' => array( 'type' => 'text', 'title' => 'Odkud o nás víte', 'value' => 'Google', 'raw_value' => 'Google' ),
	'heslo' => array( 'type' => 'password', 'title' => 'Heslo', 'value' => 'tajne-heslo', 'raw_value' => 'tajne-heslo' ),
);

// ---------- mapped_values ----------
$mapped = Raynet_Elementor_Lead_Builder::mapped_values( $fields, $form_settings );

check( 'základní pole podle mapy', $mapped['basic'], array( 'email' => 'jana@firma.cz', 'firstName' => 'Jana', 'message' => 'Dobrý den' ) );
check( 'popisek zprávy je titulek pole', $mapped['message_label'], 'Vaše zpráva' );
check( 'zaškrtnutý souhlas', $mapped['consent'], true );
check( 'znění souhlasu je text zaškrtávátka', $mapped['consent_text'], 'Souhlasím se zpracováním údajů' );
check( 'číselné pole bere raw_value', $mapped['attributes']['regNumber'], '02795281' );

// A password stays out even when it is mapped and chosen for the note.
$leaky = $form_settings;
$leaky[ Raynet_Elementor_Forms::ACTION_NAME . '_fields_map' ][] = array( 'remote_id' => 'lastName', 'local_id' => 'heslo' );
$leaky[ Raynet_Elementor_Forms::NOTICE_KEY ][]                   = 'heslo';
$leaky_mapped = Raynet_Elementor_Lead_Builder::mapped_values( $fields, $leaky );
$leaky_noted  = Raynet_Elementor_Lead_Builder::noted_values( $fields, $leaky );
$leaky_ctx    = Raynet_Elementor_Lead_Builder::context( $leaky_mapped, $fields, $leaky );
check(
	'heslo se neobjeví v žádném výstupu',
	false !== strpos( var_export( array( $leaky_mapped, $leaky_noted, $leaky_ctx ), true ), 'tajne-heslo' ),
	false
);

// ---------- noted_values ----------
check( 'poznámka nese vybrané pole a vynechá namapované', Raynet_Elementor_Lead_Builder::noted_values( $fields, $form_settings ), array( 'Odkud o nás víte' => 'Google' ) );

// ---------- merge_lines ----------
check( 'stejný popisek dostane číslo', Raynet_Elementor_Lead_Builder::merge_lines( array( 'A' => '1' ), array( 'A' => '2' ) ), array( 'A' => '1', 'A (2)' => '2' ) );

// ---------- switch_consent ----------
$switch_on = array( 'raynet_crm_consent_note' => 'yes' );
check(
	'přepínač + nezaškrtnuté acceptance → bez souhlasu',
	Raynet_Elementor_Lead_Builder::switch_consent( array( 'gdpr' => array( 'type' => 'acceptance', 'value' => '' ) ), $switch_on ),
	false
);
check(
	'přepínač bez pole acceptance → souhlas',
	Raynet_Elementor_Lead_Builder::switch_consent( array( 'name' => array( 'type' => 'text', 'value' => 'Jana' ) ), $switch_on ),
	true
);
check(
	'přepínač vypnutý → bez souhlasu',
	Raynet_Elementor_Lead_Builder::switch_consent( array( 'gdpr' => array( 'type' => 'acceptance', 'value' => 'on' ) ), array() ),
	false
);

// ---------- contact_error ----------
check( 'bez e-mailu i telefonu', Raynet_Elementor_Lead_Builder::contact_error( array( 'email' => '', 'phone' => '' ) ), 'Lead neobsahuje e-mail ani telefon.' );
check( 'neplatný e-mail', Raynet_Elementor_Lead_Builder::contact_error( array( 'email' => 'x', 'phone' => '' ) ), 'Zadaná e-mailová adresa není platná.' );
check( 'platný e-mail', Raynet_Elementor_Lead_Builder::contact_error( array( 'email' => 'jana@firma.cz', 'phone' => '' ) ), '' );
check( 'jen telefon', Raynet_Elementor_Lead_Builder::contact_error( array( 'email' => '', 'phone' => '+420 777 123 456' ) ), '' );

// ---------- lead_settings ----------
$lead = Raynet_Elementor_Lead_Builder::lead_settings( array( 'raynet_crm_category' => '12', 'raynet_crm_owner' => '-3' ) );
check( 'číselník z formuláře', $lead['category'], 12 );
check( 'záporné id se nebere', $lead['owner'], 0 );

// ---------- context ----------
$ctx = Raynet_Elementor_Lead_Builder::context( $mapped, $fields, $form_settings + array( 'raynet_crm_topic' => 'Poptávka' ) );
check( 'pevný předmět', $ctx['raynet_fixed_topic'], 'Poptávka' );
check( 'souhlas z namapovaného pole', $ctx['raynet_has_consent'], true );
check( 'zaškrtnutý souhlas dává GDPR záznam', $ctx['raynet_consent_record'], true );
check( 'kontext nese atributy, pole i řádky', array( $ctx['raynet_attributes'], $ctx['raynet_custom_fields'], $ctx['raynet_extras'] ), array( $mapped['attributes'], $mapped['custom'], $mapped['extras'] ) );
check( 'adresu a soubory doplní volající', isset( $ctx['raynet_source_url'] ) || isset( $ctx['raynet_files'] ), false );

$unmapped            = $mapped;
$unmapped['consent'] = null;
$ctx_switch          =Raynet_Elementor_Lead_Builder::context( $unmapped, array( 'name' => array( 'type' => 'text', 'value' => 'Jana' ) ), array( 'raynet_crm_consent_note' => 'yes' ) );
check( 'bez namapovaného pole rozhodne přepínač', $ctx_switch['raynet_has_consent'], true );
check( 'přepínač GDPR záznam nedává', $ctx_switch['raynet_consent_record'], false );

$refused            = $mapped;
$refused['consent'] = false;
check( 'nezaškrtnutý souhlas GDPR záznam nedává', Raynet_Elementor_Lead_Builder::context( $refused, $fields, $form_settings )['raynet_consent_record'], false );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
