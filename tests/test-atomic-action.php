<?php
/**
 * Tests for how a submission of an Elementor 4 atomic form (e-form) becomes
 * the submitted fields the lead builder reads.
 *
 * The posted values below are shaped as Elementor's front-end handler sends
 * them (elementor/assets/js/atomic-widgets-form-handler.js) and Elementor
 * Pro's controller turns them into $form_data
 * (elementor-pro/modules/atomic-form/atomic-form-controller.php).
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

// The form as the adapter reads it from the stored tree.
$form_fields = array(
	array( 'custom_id' => 'f_mail', 'field_type' => 'email', 'field_label' => 'E-mail' ),
	array( 'custom_id' => 'f_msg', 'field_type' => 'textarea', 'field_label' => 'Zpráva' ),
	array( 'custom_id' => 'f_gdpr', 'field_type' => 'acceptance', 'field_label' => 'Souhlasím se zpracováním', 'acceptance_text' => 'Souhlasím se zpracováním' ),
	array( 'custom_id' => 'f_news', 'field_type' => 'acceptance', 'field_label' => 'Novinky', 'acceptance_text' => 'Novinky' ),
	array( 'custom_id' => 'f_svc', 'field_type' => 'select', 'field_label' => 'Služba' ),
	array( 'custom_id' => 'f_r1', 'field_type' => 'radio', 'field_label' => 'Firma' ),
	array( 'custom_id' => 'f_r2', 'field_type' => 'radio', 'field_label' => 'Fyzická osoba' ),
	array( 'custom_id' => 'f_date', 'field_type' => 'date', 'field_label' => 'Termín' ),
	array( 'custom_id' => 'f_file', 'field_type' => 'upload', 'field_label' => 'Příloha' ),
	array( 'custom_id' => 'f_pass', 'field_type' => 'password', 'field_label' => 'Heslo' ),
	array( 'custom_id' => 'f_name', 'field_type' => 'text', 'field_label' => 'Jméno' ),
	array( 'custom_id' => 'f_tags', 'field_type' => 'text', 'field_label' => 'Zájmy' ),
);

$form_data = array(
	'f_mail'  => 'jan@example.cz',
	'f_msg'   => "Dobrý den,\nmám zájem.",
	// A ticked box without a value attribute sends "on"; one with a value
	// sends that value; an unticked one sends an empty string.
	'f_gdpr'  => 'on',
	'f_news'  => '',
	'f_svc'   => 'web',
	// A radio group is one entry, under the id of the checked button.
	'f_r2'    => 'fo',
	'f_date'  => '2026-12-01',
	// Elementor Pro replaces an upload's value with the stored files' URLs.
	'f_file'  => array( 'https://example.test/wp-content/uploads/elementor/forms/nabidka%20v2.pdf', 'https://example.test/wp-content/uploads/elementor/forms/foto.jpg' ),
	'f_pass'  => 'tajne',
	// The browser said this field is an e-mail called "E-mail"; the tree says
	// it is a text field called "Jméno".
	'f_name'  => 'Jan',
	'f_tags'  => array( 'web', 'eshop' ),
	// Not in the tree: a field added by hand to the request.
	'f_extra' => 'podvržené',
);

$fields = Raynet_Atomic_Form_Integration::submitted_fields( $form_fields, $form_data );

check( 'neznámé pole zahozeno', array_key_exists( 'f_extra', $fields ), false );
check( 'pole v pořadí stromu', array_keys( $fields ), array( 'f_mail', 'f_msg', 'f_gdpr', 'f_news', 'f_svc', 'f_r2', 'f_date', 'f_file', 'f_pass', 'f_name', 'f_tags' ) );
check( 'tvar pole pro builder', $fields['f_mail'], array( 'id' => 'f_mail', 'type' => 'email', 'title' => 'E-mail', 'value' => 'jan@example.cz', 'raw_value' => 'jan@example.cz' ) );
check( 'typ ze stromu, ne z požadavku', $fields['f_name']['type'], 'text' );
check( 'popisek ze stromu, ne z požadavku', $fields['f_name']['title'], 'Jméno' );
check( 'víc řádků zprávy zůstane', $fields['f_msg']['value'], "Dobrý den,\nmám zájem." );
check( 'zaškrtnuto', $fields['f_gdpr']['value'], 'on' );
check( 'zaškrtávátko je souhlas', $fields['f_gdpr']['type'], 'acceptance' );
check( 'nezaškrtnuto je prázdné', $fields['f_news']['value'], '' );
check( 'výběr', $fields['f_svc']['value'], 'web' );
check( 'vybraný přepínač nese hodnotu skupiny', $fields['f_r2']['value'], 'fo' );
check( 'nevybraný přepínač se neodeslal', array_key_exists( 'f_r1', $fields ), false );
check( 'datum', $fields['f_date']['value'], '2026-12-01' );
check( 'soubor jen názvem', $fields['f_file']['value'], 'nabidka v2.pdf, foto.jpg' );
check( 'soubor ani v raw_value nemá adresu', $fields['f_file']['raw_value'], 'nabidka v2.pdf, foto.jpg' );
check( 'heslo zůstane heslem', $fields['f_pass']['type'], 'password' );
check( 'pole hodnot spojené', $fields['f_tags']['value'], 'web, eshop' );

// Fields of the tree the request did not carry are left out, so a mapping
// that names them is skipped rather than filled with nothing. A checkbox is
// the exception: one that was not posted was not ticked.
$partial = Raynet_Atomic_Form_Integration::submitted_fields( $form_fields, array( 'f_mail' => 'a@example.cz' ) );
check( 'jen odeslaná pole a zaškrtávátka', array_keys( $partial ), array( 'f_mail', 'f_gdpr', 'f_news' ) );
check( 'neodeslané zaškrtávátko je prázdné', $partial['f_gdpr']['value'], '' );

// A single upload URL, a server path and an empty upload.
$odd = Raynet_Atomic_Form_Integration::submitted_fields(
	array(
		array( 'custom_id' => 'u1', 'field_type' => 'upload', 'field_label' => 'A' ),
		array( 'custom_id' => 'u2', 'field_type' => 'upload', 'field_label' => 'B' ),
		array( 'custom_id' => 'u3', 'field_type' => 'upload', 'field_label' => 'C' ),
		array( 'custom_id' => 'n1', 'field_type' => 'text', 'field_label' => 'D' ),
	),
	array(
		'u1' => 'https://example.test/wp-content/uploads/elementor/forms/cv.pdf?ver=1',
		'u2' => '/var/www/html/wp-content/uploads/elementor/forms/tajne.pdf',
		'u3' => '',
		'n1' => array( 'a', array( 'vnořené' ), 'b' ),
	)
);
check( 'jedna adresa souboru', $odd['u1']['value'], 'cv.pdf' );
check( 'cesta na serveru jen názvem', $odd['u2']['value'], 'tajne.pdf' );
check( 'prázdné nahrávání', $odd['u3']['value'], '' );
check( 'vnořené hodnoty se přeskočí', $odd['n1']['value'], 'a, b' );

// A row without an id, or a form_fields value that is not a list of rows.
check( 'řádek bez ID', Raynet_Atomic_Form_Integration::submitted_fields( array( array( 'field_type' => 'text' ), 'x' ), array( '' => 'a' ) ), array() );

// The builder drops a password whatever the mapping says.
$settings = array(
	'form_fields'           => $form_fields,
	'raynet_crm_fields_map' => array(
		array( 'remote_id' => 'email', 'local_id' => 'f_mail' ),
		array( 'remote_id' => 'firstName', 'local_id' => 'f_pass' ),
		array( 'remote_id' => 'message', 'local_id' => 'f_msg' ),
	),
);
$mapped = Raynet_Elementor_Lead_Builder::mapped_values( $fields, $settings );
check( 'heslo neodejde', array_key_exists( 'firstName', $mapped['basic'] ), false );
check( 'e-mail namapován', $mapped['basic']['email'], 'jan@example.cz' );
check( 'popisek zprávy ze stromu', $mapped['message_label'], 'Zpráva' );

// The ticked box counts as consent for the older per-form switch.
check( 'přepínač souhlasu vidí zaškrtnutí', Raynet_Elementor_Lead_Builder::switch_consent( $fields, array( 'raynet_crm_consent_note' => 'yes' ) ), true );
$unticked = $fields;
$unticked['f_gdpr']['value'] = '';
check( 'nic nezaškrtnuto, souhlas není', Raynet_Elementor_Lead_Builder::switch_consent( $unticked, array( 'raynet_crm_consent_note' => 'yes' ) ), false );

// --- upload names in the note -------------------------------------------------
check( 'názvy souborů do poznámky', Raynet_Atomic_Form_Integration::upload_lines( $fields, array() ), array( 'Příloha' => 'nabidka v2.pdf, foto.jpg' ) );
check( 'vynechané nahrávání se nezapíše', Raynet_Atomic_Form_Integration::upload_lines( $fields, array( 'raynet_crm_ignored_fields' => array( 'f_file' ) ) ), array() );
check( 'prázdné nahrávání se nezapíše', Raynet_Atomic_Form_Integration::upload_lines( $odd, array() ), array( 'A' => 'cv.pdf', 'B' => 'tajne.pdf' ) );
check( 'builder nahrávání do poznámky sám nedá', Raynet_Elementor_Lead_Builder::noted_values( $fields, array( 'raynet_crm_notice_fields' => array( 'f_file', 'f_svc' ) ) ), array( 'Služba' => 'web' ) );

// --- radio groups, omitted checkboxes, mapped uploads ---------------------------
$group_rows = array(
	array( 'custom_id' => 'r1', 'field_type' => 'radio', 'field_label' => 'Typ' ),
	array( 'custom_id' => 'r4', 'field_type' => 'radio', 'field_label' => 'Sám' ),
	array( 'custom_id' => 'c1', 'field_type' => 'acceptance', 'field_label' => 'Souhlas', 'acceptance_text' => 'Souhlas' ),
	array( 'custom_id' => 't1', 'field_type' => 'text', 'field_label' => 'Text' ),
);
$groups = array( 'r1' => 'r1', 'r2' => 'r1', 'r3' => 'r1' );

$grouped = Raynet_Atomic_Form_Integration::submitted_fields( $group_rows, array( 'r2' => 'fo' ), $groups );
check( 'hodnota druhého člena patří skupině', isset( $grouped['r1'] ) ? $grouped['r1']['value'] : null, 'fo' );
check( 'člen sám pole nemá', array_key_exists( 'r2', $grouped ), false );
check( 'nevyplněný text se nepřidá', array_key_exists( 't1', $grouped ), false );
check( 'chybějící zaškrtávátko je nezaškrtnuté', isset( $grouped['c1'] ) ? $grouped['c1']['value'] : null, '' );

$untouched_group = Raynet_Atomic_Form_Integration::submitted_fields( $group_rows, array( 'r1' => '' ), $groups );
check( 'nevybraná skupina je prázdné pole', isset( $untouched_group['r1'] ) ? $untouched_group['r1']['value'] : null, '' );

$both = Raynet_Atomic_Form_Integration::submitted_fields( $group_rows, array( 'r1' => '', 'r3' => 'jine' ), $groups );
check( 'prázdná hodnota nepřebije vybranou', $both['r1']['value'], 'jine' );

check( 'chybějící souhlas přepínači souhlas nedá', Raynet_Elementor_Lead_Builder::switch_consent( $grouped, array( 'raynet_crm_consent_note' => 'yes' ) ), false );

check(
	'namapované nahrávání není v poznámce',
	Raynet_Atomic_Form_Integration::upload_lines( $fields, array( 'form_fields' => $form_fields, 'raynet_crm_fields_map' => array( array( 'remote_id' => 'cf:Priloha_l0', 'local_id' => 'f_file' ) ) ) ),
	array()
);

// --- action log label -------------------------------------------------------
check( 'popisek v logu akcí', Raynet_Atomic_Form_Integration::log_label( 'Raynet Crm', 'raynet_crm' ), 'RAYNET CRM' );
check( 'cizí akce beze změny', Raynet_Atomic_Form_Integration::log_label( 'Webhook', 'webhook' ), 'Webhook' );

// --- without Elementor ---------------------------------------------------------
// The class loads and registers without Elementor; the action class is never read.
Raynet_Atomic_Form_Integration::register();
check( 'třída akce bez Elementoru nenačtena', class_exists( 'Raynet_Atomic_Form_Action', false ), false );
check( 'čip bez Elementoru nic nemění', Raynet_Atomic_Form_Integration::add_chip( array( 'x' ), new stdClass() ), array( 'x' ) );
Raynet_Atomic_Form_Integration::register_action( 'Neexistujici_Trida' );
check( 'registrace bez registru nic nenačte', class_exists( 'Raynet_Atomic_Form_Action', false ), false );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
