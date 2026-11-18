<?php
/**
 * Tests for the adapter that makes an Elementor 4 atomic form (e-form) look
 * like the settings of the classic Form widget, and for where it keeps the
 * RAYNET settings.
 */

require __DIR__ . '/wp-stubs.php';

if ( ! function_exists( 'wp_slash' ) ) {
	function wp_slash( $value ) { return $value; }
}

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

/** A typed atomic value. */
function typed( $type, $value ) {
	return array( '$$type' => $type, 'value' => $value );
}

/** A typed string. */
function str( $value ) {
	return typed( 'string', $value );
}

/** An atomic widget element. */
function widget( $id, $type, array $settings = array() ) {
	return array( 'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => array() );
}

/** A label as Elementor 4.1 stores it: html-v3 with a typed string inside. */
function label( $id, $html, $input ) {
	return widget(
		$id,
		'e-form-label',
		array(
			'text'     => typed( 'html-v3', array( 'content' => str( $html ), 'children' => array() ) ),
			'input-id' => str( $input ),
		)
	);
}

$form = array(
	'id'       => 'af1',
	'elType'   => 'e-form',
	'settings' => array(
		'form-name' => str( 'Poptávka' ),
		'classes'   => typed( 'classes', array( 'e-af1-1' ) ),
		'email'     => typed( 'email', array( 'to' => str( 'obchod@example.cz' ), 'subject' => str( 'Nová poptávka' ) ) ),
	),
	'elements' => array(
		label( 'l1', 'E-mail', 'mail' ),
		widget( 'f1', 'e-form-input', array( 'type' => str( 'email' ), '_cssid' => str( 'mail' ) ) ),
		label( 'l2', 'Zpráva', 'msg' ),
		widget( 'f2', 'e-form-textarea', array( '_cssid' => str( 'msg' ), 'placeholder' => str( 'Napište nám' ) ) ),
		array(
			'id'       => 'row',
			'elType'   => 'e-flexbox',
			'settings' => array(),
			'elements' => array(
				widget( 'f3', 'e-form-checkbox', array( '_cssid' => str( 'gdpr' ) ) ),
				label( 'l3', '<strong>Souhlasím</strong> se zpracováním', 'gdpr' ),
			),
		),
		label( 'l4', 'Služba', 'svc' ),
		widget( 'f4', 'e-form-select', array( '_cssid' => str( 'svc' ) ) ),
		label( 'l5', 'Příloha', 'file' ),
		widget( 'f5', 'e-form-file-upload', array( '_cssid' => str( 'file' ) ) ),
		widget( 'f6', 'e-form-input', array( 'placeholder' => str( 'Název firmy' ), '_cssid' => str( 'company' ) ) ),
		widget( 'f7', 'e-form-input', array() ),
		widget( 'sb', 'e-form-submit-button', array( 'text' => typed( 'html-v3', array( 'content' => str( 'Odeslat' ) ) ) ) ),
	),
);

// --- fields() ---------------------------------------------------------------
$fields = Raynet_Elementor_Atomic_Forms::fields( $form );

check( 'pole v pořadí stromu', array_column( $fields, 'custom_id' ), array( 'f1', 'f2', 'f3', 'f4', 'f5', 'f6', 'f7' ) );
check( 'typy polí', array_column( $fields, 'field_type' ), array( 'email', 'textarea', 'acceptance', 'select', 'upload', 'text', 'text' ) );
check(
	'popisky polí',
	array_column( $fields, 'field_label' ),
	array( 'E-mail', 'Zpráva', 'Souhlasím se zpracováním', 'Služba', 'Příloha', 'Název firmy', 'Pole 7 (text)' )
);
check( 'zaškrtávátko nese text souhlasu', $fields[2]['acceptance_text'], 'Souhlasím se zpracováním' );
check( 'jiné pole text souhlasu nemá', array_key_exists( 'acceptance_text', $fields[0] ), false );
check( 'popisek ze stromu, ne z tlačítka', in_array( 'sb', array_column( $fields, 'custom_id' ), true ), false );

// Readable by the classic helpers as they are.
check( 'čitelné pro mapování widgetu', array_column( Raynet_Elementor_Forms::readable_fields( array( 'form_fields' => $fields ) ), 'type', 'id' )['f1'], 'email' );

// Labels in older shapes and an input type the schema does not know.
$legacy = array(
	'id'       => 'af2',
	'elType'   => 'e-form',
	'settings' => array(),
	'elements' => array(
		widget( 'a', 'e-form-label', array( 'text' => typed( 'html', 'Telefon <em>mobil</em>' ), 'input-id' => str( 'tel' ) ) ),
		widget( 'b', 'e-form-input', array( 'type' => str( 'tel' ), '_cssid' => str( 'tel' ) ) ),
		widget( 'c', 'e-form-label', array( 'text' => str( 'Web &amp; blog' ), 'input-id' => 'url' ) ),
		widget( 'd', 'e-form-input', array( 'type' => 'url', '_cssid' => 'url' ) ),
		widget( 'e', 'e-form-input', array( 'type' => str( 'search' ) ) ),
		widget( 'f', 'e-form-input', array( 'type' => str( 'password' ) ) ),
		widget( 'g', 'e-form-radio-button', array() ),
		widget( 'h', 'e-form-date-picker', array() ),
		widget( 'i', 'e-form-time-picker', array() ),
		widget( 'j', 'e-form-label', array( 'text' => typed( 'html-v3', array( 'content' => null, 'children' => array( array( 'type' => 'span', 'content' => 'Jen v potomcích' ) ) ) ), 'input-id' => str( 'kids' ) ) ),
		widget( 'k', 'e-form-input', array( '_cssid' => str( 'kids' ) ) ),
	),
);
$legacy_fields = Raynet_Elementor_Atomic_Forms::fields( $legacy );
check( 'popisek html', $legacy_fields[0]['field_label'], 'Telefon mobil' );
check( 'popisek jako prostý řetězec, entity dekódované', $legacy_fields[1]['field_label'], 'Web & blog' );
check( 'netypované hodnoty se čtou také', $legacy_fields[1]['field_type'], 'url' );
check( 'neznámý typ vstupu je text', $legacy_fields[2]['field_type'], 'text' );
check( 'heslo zůstane heslem', $legacy_fields[3]['field_type'], 'password' );
check( 'radio, datum, čas', array_column( array_slice( $legacy_fields, 4, 3 ), 'field_type' ), array( 'radio', 'date', 'time' ) );
check( 'pořadí v náhradním popisku', $legacy_fields[4]['field_label'], 'Pole 5 (radio)' );
check( 'popisek jen z potomků html-v3', $legacy_fields[7]['field_label'], 'Jen v potomcích' );

// --- actions() ----------------------------------------------------------------
check( 'bez uložených akcí běží e-mail', Raynet_Elementor_Atomic_Forms::actions( $form ), array( 'email' ) );

$with_actions                                       = $form;
$with_actions['settings']['actions-after-submit']   = typed( 'string-array', array( str( 'email' ), str( 'collect-submissions' ) ) );
check( 'uložené akce', Raynet_Elementor_Atomic_Forms::actions( $with_actions ), array( 'email', 'collect-submissions' ) );

$no_actions                                     = $form;
$no_actions['settings']['actions-after-submit'] = typed( 'string-array', array() );
check( 'vypnuté všechny akce zůstanou vypnuté', Raynet_Elementor_Atomic_Forms::actions( $no_actions ), array() );

// Elementor Pro's controller falls back to e-mail only when the form stores
// e-mail settings; with neither, the form runs nothing.
$bare = $form;
unset( $bare['settings']['email'] );
check( 'bez akcí i bez nastavení e-mailu neběží nic', Raynet_Elementor_Atomic_Forms::actions( $bare ), array() );
$bare_on = Raynet_Elementor_Atomic_Forms::apply( $bare, array( 'submit_actions' => array( 'raynet_crm' ) ), 0 );
check( 'zapnutí bez e-mailu e-mail nepřidá', Raynet_Elementor_Atomic_Forms::actions( $bare_on ), array( 'raynet_crm' ) );
check( 'beze změny se prázdný seznam nezapíše', array_key_exists( 'actions-after-submit', Raynet_Elementor_Atomic_Forms::apply( $bare, array( 'submit_actions' => array() ), 0 )['settings'] ), false );

// --- settings() / store() / stored() --------------------------------------------
$GLOBALS['wp_meta'] = array();

check( 'neznámý formulář nemá uložené nic', Raynet_Elementor_Atomic_Forms::stored( 10, 'af1' ), array() );

$GLOBALS['wp_meta'][10][ Raynet_Elementor_Atomic_Forms::META ] = 'poškozené';
check( 'meta, které není pole, se ignoruje', Raynet_Elementor_Atomic_Forms::stored( 10, 'af1' ), array() );

$GLOBALS['wp_meta'][10][ Raynet_Elementor_Atomic_Forms::META ] = array( 'af1' => 'také poškozené', 'zmizely' => array( 'raynet_crm_topic' => 'Starý' ) );
check( 'poškozená položka se ignoruje', Raynet_Elementor_Atomic_Forms::stored( 10, 'af1' ), array() );

check(
	'store() nechá jen klíče RAYNETu',
	Raynet_Elementor_Atomic_Forms::store( 10, 'af1', array( 'raynet_crm_topic' => 'Poptávka z webu', 'form_fields' => $fields, 'submit_actions' => array( 'email' ), 'jiny' => 1 ) ),
	true
);
check( 'uloženo jen RAYNET', Raynet_Elementor_Atomic_Forms::stored( 10, 'af1' ), array( 'raynet_crm_topic' => 'Poptávka z webu' ) );
check( 'cizí položka meta zůstala', $GLOBALS['wp_meta'][10][ Raynet_Elementor_Atomic_Forms::META ]['zmizely'], array( 'raynet_crm_topic' => 'Starý' ) );

$settings = Raynet_Elementor_Atomic_Forms::settings( 10, $form );
check( 'název formuláře', $settings['form_name'], 'Poptávka' );
check( 'akce ve tvaru widgetu', $settings['submit_actions'], array( 'email' ) );
check( 'pole ve tvaru widgetu', $settings['form_fields'], $fields );
check( 'uložené nastavení RAYNETu', $settings['raynet_crm_topic'], 'Poptávka z webu' );
check( 'nic navíc', array_keys( $settings ), array( 'raynet_crm_topic', 'form_name', 'form_fields', 'submit_actions' ) );

// --- apply() ------------------------------------------------------------------
$enabled_settings                   = $settings;
$enabled_settings['submit_actions'] = array( 'email', 'raynet_crm' );
$enabled_settings['raynet_crm_priority'] = 'CRITICAL';

$applied = Raynet_Elementor_Atomic_Forms::apply( $form, $enabled_settings, 10 );
check(
	'akce zapsané jako typovaný seznam',
	$applied['settings']['actions-after-submit'],
	typed( 'string-array', array( str( 'email' ), str( 'raynet_crm' ) ) )
);

$rest = $applied['settings'];
unset( $rest['actions-after-submit'] );
check( 'ostatní nastavení prvku beze změny', json_encode( $rest ), json_encode( $form['settings'] ) );
check( 'potomci beze změny', json_encode( $applied['elements'] ), json_encode( $form['elements'] ) );
check( 'apply() uložil nastavení RAYNETu', Raynet_Elementor_Atomic_Forms::stored( 10, 'af1' ), array( 'raynet_crm_topic' => 'Poptávka z webu', 'raynet_crm_priority' => 'CRITICAL' ) );

// Nothing changed in the actions: the element is left exactly as it was.
$untouched = Raynet_Elementor_Atomic_Forms::apply( $form, $settings, 0 );
check( 'beze změny akcí se prvek nemění', json_encode( $untouched ), json_encode( $form ) );
check( 'bez ID stránky se nic neukládá', Raynet_Elementor_Atomic_Forms::stored( 10, 'af1' )['raynet_crm_priority'], 'CRITICAL' );

// The classic helpers enable through submit_actions; the adapter carries it.
$via_helpers = Raynet_Elementor_Atomic_Forms::apply( $form, Raynet_Elementor_Forms::configure( $settings, array(), true ), 0 );
check( 'configure() + apply() nechá e-mail', Raynet_Elementor_Atomic_Forms::actions( $via_helpers ), array( 'email', 'raynet_crm' ) );

// --- radio groups ---------------------------------------------------------------
// Buttons sharing a stored group name ("name" prop) are one field, keyed by
// the first member; a button without a stored name is a field of its own.
$radios = array(
	'id'       => 'rf',
	'elType'   => 'e-form',
	'settings' => array(),
	'elements' => array(
		widget( 'r1', 'e-form-radio-button', array( 'name' => str( 'typ' ), 'value' => str( 'firma' ) ) ),
		label( 'rl2', 'Fyzická osoba', 'fo' ),
		widget( 'r2', 'e-form-radio-button', array( 'name' => str( 'typ' ), 'value' => str( 'fo' ), '_cssid' => str( 'fo' ) ) ),
		widget( 'mail', 'e-form-input', array( 'type' => str( 'email' ) ) ),
		widget( 'r3', 'e-form-radio-button', array( 'name' => 'typ', 'value' => str( 'jine' ) ) ),
		widget( 'r4', 'e-form-radio-button', array( 'value' => str( 'sam' ) ) ),
		widget( 'r5', 'e-form-radio-button', array( 'name' => str( '' ) ) ),
	),
);
$radio_fields = Raynet_Elementor_Atomic_Forms::fields( $radios );
check( 'skupina přepínačů je jedno pole', array_column( $radio_fields, 'custom_id' ), array( 'r1', 'mail', 'r4', 'r5' ) );
check( 'skupina je přepínač', $radio_fields[0]['field_type'], 'radio' );
check( 'popisek skupiny z kteréhokoli člena', $radio_fields[0]['field_label'], 'Fyzická osoba' );
check( 'samostatný přepínač má svůj náhradní popisek', $radio_fields[2]['field_label'], 'Pole 3 (radio)' );
check( 'členové skupiny ukazují na první', Raynet_Elementor_Atomic_Forms::radio_groups( $radios ), array( 'r1' => 'r1', 'r2' => 'r1', 'r3' => 'r1' ) );

$lone_group = $radios;
$lone_group['elements'] = array( widget( 'q1', 'e-form-radio-button', array( 'name' => str( 'a' ) ) ), widget( 'q2', 'e-form-radio-button', array( 'name' => str( 'b' ) ) ) );
check( 'různé skupiny zůstanou dvě pole', array_column( Raynet_Elementor_Atomic_Forms::fields( $lone_group ), 'field_label' ), array( 'Pole 1 (radio)', 'Pole 2 (radio)' ) );
check( 'bez přepínačů žádné skupiny', Raynet_Elementor_Atomic_Forms::radio_groups( $form ), array() );

check( 'bez registru akcí není podpora', Raynet_Elementor_Atomic_Forms::supported(), false );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
