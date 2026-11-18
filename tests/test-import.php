<?php
/**
 * Tests for the parts of the stored-submission import that need no database:
 * turning a stored row into fields, into a lead, and into a verdict.
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

/**
 * IDs of the reasons in a classify() result.
 *
 * @param array $result Result.
 * @return string[] Sorted ids.
 */
function reason_ids( array $result ) {
	$ids = array_column( $result['reasons'], 'id' );
	sort( $ids );
	return $ids;
}

$form_settings = array(
	Raynet_Elementor_Forms::ACTION_NAME . '_fields_map' => array(
		array( 'remote_id' => 'email', 'local_id' => 'email' ),
		array( 'remote_id' => 'firstName', 'local_id' => 'name' ),
		array( 'remote_id' => 'message', 'local_id' => 'msg' ),
		array( 'remote_id' => 'gdprConsent', 'local_id' => 'gdpr' ),
		array( 'remote_id' => 'regNumber', 'local_id' => 'ico' ),
	),
	Raynet_Elementor_Forms::NOTICE_KEY => array( 'odkud', 'name', 'soubor' ),
	'raynet_crm_source_url'            => 'yes',
	'form_fields'                      => array(
		array( 'custom_id' => 'email', 'field_type' => 'email', 'field_label' => 'E-mail' ),
		array( 'custom_id' => 'name', 'field_type' => 'text', 'field_label' => 'Jméno' ),
		array( 'custom_id' => 'msg', 'field_type' => 'textarea', 'field_label' => 'Vaše zpráva' ),
		array( 'custom_id' => 'gdpr', 'field_type' => 'acceptance', 'field_label' => 'gdpr', 'acceptance_text' => 'Souhlasím se <b>zpracováním</b> údajů' ),
		array( 'custom_id' => 'ico', 'field_type' => 'number', 'field_label' => 'IČO' ),
		array( 'custom_id' => 'odkud', 'field_type' => 'text', 'field_label' => 'Odkud o nás víte' ),
		array( 'custom_id' => 'heslo', 'field_type' => 'password', 'field_label' => 'Heslo' ),
		array( 'custom_id' => 'soubor', 'field_type' => 'upload', 'field_label' => 'Výkres' ),
		array( 'custom_id' => 'zajem', 'field_type' => 'checkbox', 'field_label' => 'Zájem o' ),
	),
);

$message = 'Dobrý den, poptávám nabídku na rekonstrukci koupelny v rodinném domě.';

$row = array(
	'id'         => 7,
	'created_at' => '2025-03-12 14:10:00',
	'referer'    => 'https://example.test/kontakt/',
	'ip'         => '192.0.2.10',
	'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
	'state'      => Raynet_Elementor_Submissions::STATE_NEW,
	'log'        => '',
	'values'     => array(
		'email'  => 'jana@firma.cz',
		'name'   => 'Jana',
		'msg'    => $message,
		'gdpr'   => 'on',
		'ico'    => '2795281',
		'odkud'  => 'Google',
		'soubor' => 'https://example.test/wp-content/uploads/elementor/forms/a.pdf',
		'stare'  => 'x',
		'zajem'  => 'a, b',
		'heslo'  => 'tajne-heslo',
	),
);

// ---------- fields_for ----------
$fields = Raynet_Elementor_Submissions::fields_for( $row, $form_settings );

check( 'ico má typ number', $fields['ico']['type'], 'number' );
check( 'ico nese uloženou hodnotu', $fields['ico']['value'], '2795281' );
check( 'raw_value je value', $fields['ico']['raw_value'], '2795281' );
check( 'titulek z definice formuláře', $fields['msg']['title'], 'Vaše zpráva' );
check( 'pole má id', $fields['msg']['id'], 'msg' );
check( 'pole, které ve formuláři není, má typ text', $fields['stare']['type'], 'text' );
check( 'pole, které ve formuláři není, má titulek rovný klíči', $fields['stare']['title'], 'stare' );
check( 'zajem jde beze změny', $fields['zajem']['value'], 'a, b' );

$zero_row                  = $row;
$zero_row['values']['ico'] = '0';
check( 'číslo 0 se bere jako nevyplněno', Raynet_Elementor_Submissions::fields_for( $zero_row, $form_settings )['ico']['value'], '' );

// ---------- prepare ----------
$prepared = Raynet_Elementor_Submissions::prepare( $row, $form_settings, Raynet_Elementor_Submissions::DEFAULT_TAG );

check( 'prepare vrátí pole', is_array( $prepared ), true );

if ( is_array( $prepared ) ) {
	$ctx = $prepared['context'];

	check( 'e-mail ve hodnotách', $prepared['values']['email'], 'jana@firma.cz' );
	check( 'nastavení leadu přítomno', is_array( $prepared['settings'] ) && isset( $prepared['settings']['tags'] ), true );
	check( 'kontext: import', $ctx['raynet_import'], true );
	check( 'kontext: datum leadu z created_at', $ctx['raynet_lead_date'], '2025-03-12' );
	check( 'kontext: čas souhlasu z created_at', $ctx['raynet_consent_time'], '2025-03-12 14:10' );
	check( 'kontext: souhlas z pole', $ctx['raynet_has_consent'], true );
	check( 'kontext: bez GDPR záznamu', $ctx['raynet_consent_record'], false );
	check( 'kontext: štítek importu', $ctx['raynet_extra_tags'], 'import-web' );
	check( 'kontext: URL stránky z refereru', $ctx['raynet_source_url'], 'https://example.test/kontakt/' );
	check( 'kontext: poznámka o importu s původním datem', false !== strpos( $ctx['raynet_import_note'], '12. 3. 2025 14:10' ), true );
	check( 'kontext: poznámka o importu', false !== strpos( $ctx['raynet_import_note'], 'Importováno z uložených odeslání Elementoru.' ), true );
	check( 'kontext: odkaz na soubor v poznámce', isset( $ctx['raynet_extras']['Výkres'] ) ? $ctx['raynet_extras']['Výkres'] : null, 'https://example.test/wp-content/uploads/elementor/forms/a.pdf' );
	check( 'kontext: pole vybrané do poznámky', isset( $ctx['raynet_extras']['Odkud o nás víte'] ) ? $ctx['raynet_extras']['Odkud o nás víte'] : null, 'Google' );
	check( 'kontext: IČO z uložené hodnoty', $ctx['raynet_attributes']['regNumber'], '2795281' );
	check( 'heslo nikde', false !== strpos( var_export( $prepared, true ), 'tajne' ), false );
}

$off                          = $form_settings;
$off['raynet_crm_source_url'] = '';
$prepared_off                 = Raynet_Elementor_Submissions::prepare( $row, $off, 'import-web' );
check( 'vypnuté URL stránky → prázdné', is_array( $prepared_off ) ? $prepared_off['context']['raynet_source_url'] : null, '' );

// Elementor does not store a switch left at its default, which is "yes".
$unset = $form_settings;
unset( $unset['raynet_crm_source_url'] );
$prepared_unset = Raynet_Elementor_Submissions::prepare( $row, $unset, 'import-web' );
check( 'neuložený přepínač URL = výchozí zapnuto', is_array( $prepared_unset ) ? $prepared_unset['context']['raynet_source_url'] : null, 'https://example.test/kontakt/' );

// An upload field the mapping screen attaches by default lands in the note too.
$attach = $form_settings;
$attach[ Raynet_Elementor_Forms::NOTICE_KEY ] = array( 'odkud' );
$prepared_attach = Raynet_Elementor_Submissions::prepare( $row, $attach, 'import-web' );
check( 'příloha (výchozí cíl) dá řádek s odkazem', is_array( $prepared_attach ) && isset( $prepared_attach['context']['raynet_extras']['Výkres'] ), true );

// Left out on purpose: no line.
$ignored = $attach;
$ignored[ Raynet_Elementor_Forms::IGNORED_KEY ] = array( 'soubor' );
$prepared_ignored = Raynet_Elementor_Submissions::prepare( $row, $ignored, 'import-web' );
check( 'vynechané pole nahrávání bez řádku', is_array( $prepared_ignored ) && isset( $prepared_ignored['context']['raynet_extras']['Výkres'] ), false );

$no_contact                    = $row;
$no_contact['values']['email'] = '';
$blocked                       = Raynet_Elementor_Submissions::prepare( $no_contact, $form_settings, 'import-web' );
check( 'bez e-mailu a telefonu → chyba', is_wp_error( $blocked ) ? $blocked->get_error_code() : null, 'raynet_import_blocked' );
check( 'chyba nese text', is_wp_error( $blocked ) ? $blocked->get_error_message() : null, 'Lead neobsahuje e-mail ani telefon.' );

$bad_email                    = $row;
$bad_email['values']['email'] = 'x';
$blocked_email                = Raynet_Elementor_Submissions::prepare( $bad_email, $form_settings, 'import-web' );
check( 'neplatný e-mail → chyba', is_wp_error( $blocked_email ) ? $blocked_email->get_error_code() : null, 'raynet_import_blocked' );

// ---------- classify ----------
$config = Raynet_Submission_Spam_Score::default_config();
$stats  = array( 'text_senders' => array(), 'ip_counts' => array(), 'first_of' => array() );

$clean = Raynet_Elementor_Submissions::classify( $row, $form_settings, $stats, $config );
check( 'čistý řádek: verdikt ok', $clean['verdict'], 'ok' );
check( 'čistý řádek: záložka k posouzení', $clean['tab'], Raynet_Elementor_Submissions::TAB_REVIEW );
check( 'čistý řádek: předvybraný', $clean['preselect'], true );
check( 'čistý řádek: stav', $clean['state'], 'new' );
check( 'čistý řádek: bez blokace', $clean['block'], '' );
check( 'čistý řádek: varování u čísla', $clean['number_warning'], true );
check( 'čistý řádek: žádné důvody', $clean['reasons'], array() );

$failed_row          = $row;
$failed_row['state'] = Raynet_Elementor_Submissions::STATE_FAILED;
$failed              = Raynet_Elementor_Submissions::classify( $failed_row, $form_settings, $stats, $config );
check( 'tehdy selhalo: k posouzení', $failed['tab'], Raynet_Elementor_Submissions::TAB_REVIEW );
check( 'tehdy selhalo: nepředvybrané', $failed['preselect'], false );

$sent_row          = $row;
$sent_row['state'] = Raynet_Elementor_Submissions::STATE_SENT;
$sent              = Raynet_Elementor_Submissions::classify( $sent_row, $form_settings, $stats, $config );
check( 'odeslané: záložka už v RAYNETu', $sent['tab'], Raynet_Elementor_Submissions::TAB_SENT );
check( 'odeslané: nepředvybrané', $sent['preselect'], false );

$blocked_class = Raynet_Elementor_Submissions::classify( $no_contact, $form_settings, $stats, $config );
check( 'bez kontaktu: nelze importovat', $blocked_class['tab'], Raynet_Elementor_Submissions::TAB_BLOCKED );
check( 'bez kontaktu: důvod', '' !== $blocked_class['block'], true );
check( 'bez kontaktu: nepředvybrané', $blocked_class['preselect'], false );

$cyrillic                  = $row;
$cyrillic['values']['msg'] = 'Здравствуйте, предлагаем продвижение вашего сайта в поисковых системах';
$spam                      = Raynet_Elementor_Submissions::classify( $cyrillic, $form_settings, $stats, $config );
check( 'azbuka: spam', $spam['tab'], Raynet_Elementor_Submissions::TAB_SPAM );
check( 'azbuka: nepředvybrané', $spam['preselect'], false );
check( 'azbuka: důvod', in_array( 'foreign_script', reason_ids( $spam ), true ), true );

$no_number                  = $row;
$no_number['values']['ico'] = '0';
check( 'bez čísla: bez varování', Raynet_Elementor_Submissions::classify( $no_number, $form_settings, $stats, $config )['number_warning'], false );

$key = 'jana@firma.cz|' . Raynet_Submission_Spam_Score::text_key( $message );

$dup_stats             = $stats;
$dup_stats['first_of'] = array( $key => 3 );
$dup                   = Raynet_Elementor_Submissions::classify( $row, $form_settings, $dup_stats, $config );
check( 'duplicita: důvod', in_array( 'duplicate', reason_ids( $dup ), true ), true );
check( 'duplicita: nepředvybrané', $dup['preselect'], false );

$own_stats             = $stats;
$own_stats['first_of'] = array( $key => 7 );
$own                   = Raynet_Elementor_Submissions::classify( $row, $form_settings, $own_stats, $config );
check( 'vlastní nejstarší: bez duplicity', in_array( 'duplicate', reason_ids( $own ), true ), false );
check( 'vlastní nejstarší: předvybrané', $own['preselect'], true );

$same_stats                 = $stats;
$same_stats['text_senders'] = array( Raynet_Submission_Spam_Score::text_key( $message ) => 3 );
$same                       = Raynet_Elementor_Submissions::classify( $row, $form_settings, $same_stats, $config );
check( 'stejný text od tří odesílatelů: spam', $same['tab'], Raynet_Elementor_Submissions::TAB_SPAM );

// A form that stores no user agent does not judge an empty one.
$no_agent_row               = $row;
$no_agent_row['user_agent'] = '';
check( 'prázdný user agent u formuláře, který ho ukládá', in_array( 'bot_agent', reason_ids( Raynet_Elementor_Submissions::classify( $no_agent_row, $form_settings, $stats, $config ) ), true ), true );
$no_meta                         = $form_settings;
$no_meta['submissions_metadata'] = array( 'remote_ip' );
check( 'prázdný user agent u formuláře, který ho neukládá', in_array( 'bot_agent', reason_ids( Raynet_Elementor_Submissions::classify( $no_agent_row, $no_meta, $stats, $config ) ), true ), false );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
