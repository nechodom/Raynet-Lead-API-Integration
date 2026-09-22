<?php
/**
 * Tests for the form builder: field definitions, storage and rendering.
 */

require __DIR__ . '/wp-stubs.php';

$raynet_includes = dirname( __DIR__ ) . '/raynet-lead-api-integration/includes/';
require_once $raynet_includes . 'class-raynet-settings.php';
require_once $raynet_includes . 'class-raynet-form-definition.php';
require_once $raynet_includes . 'class-raynet-form-post-type.php';

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
check( 'výchozí sada má šest polí', count( Raynet_Lead_Form_Definition::default_fields() ), 6 );
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
check( 'pole uložena',            count( Raynet_Lead_Form_Post_Type::get_fields( $id ) ), 6 );
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

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
