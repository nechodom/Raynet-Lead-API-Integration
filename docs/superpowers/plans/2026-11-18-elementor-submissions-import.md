# Import uložených odeslání Elementoru — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Správce na obrazovce formuláře Elementoru vidí jeho stará uložená odeslání ohodnocená na spam a zaškrtnutá odešle do RAYNETu jako leady, bez duplicit.

**Architecture:** Logika „pole formuláře → lead“ se vyjme z akce Elementoru do třídy bez závislosti na `Form_Record`; živé odeslání i import ji volají stejně. Čtení tabulek `e_submissions*` a import jsou v jedné třídě, hodnocení spamu v druhé, čisté a bez WordPressu. Obrazovka importu je podstránka stávající obrazovky *Elementor formuláře* a odesílá po jednom řádku přes `admin-ajax.php`.

**Tech Stack:** PHP 7.4+, WordPress 5.6+, Elementor Pro (ověřeno 4.1.1), čisté JS s jQuery bez build kroku. Testy: stubová sada `php tests/run.php`, integrační sada `bin/wp-test.sh`.

**Spec:** `docs/superpowers/specs/2026-11-18-elementor-submissions-import-design.md`

## Global Constraints

- Plugin běží od PHP 7.4: žádné `match`, pojmenované argumenty, `str_contains`, union typy.
- Kód a komentáře anglicky ve stylu okolních souborů (WordPress Coding Standards, docblock u každé metody). Texty pro uživatele česky, v `__()` s doménou `raynet-lead-api-integration`.
- Chování živého odeslání se nesmí změnit: stávající testy musí projít beze změny svých očekávání.
- Všechny uložené hodnoty odeslání jsou cizí vstup: `esc_html` / `esc_attr` na výstupu, SQL jen přes `$wpdb->prepare()`.
- Obrazovka i AJAX jen pro `manage_options` s nonce `Raynet_Elementor_Forms_Admin::NONCE`.
- Jediný zápis do tabulek Elementoru: řádek logu akcí přes `Query::add_action_log()`. `actions_succeeded_count` se nemění.
- Při importu: žádný GDPR záznam, žádné notifikační e-maily, žádný záložní e-mail, žádná pauza IP, žádný zápis „Poslední chyba API“.
- Hodnocení spamu je návrh: předvybrané jsou jen řádky s verdiktem `ok` a stavem `new`.
- Cílová verze `2.10.0`.
- Commit po každém úkolu, zprávy ve stylu repozitáře (`feat:`, `refactor:`, `docs:`), s řádkem `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`.

**Integrační prostředí:** worktree nemá `.wp-test/`. Hotová instalace s Elementor Pro je v hlavním checkoutu: `ln -s "/Users/kevin/Documents/Projects/raynet plugin/.wp-test" .wp-test` (adresář je v `.gitignore`). `bin/wp-test.sh` si do ní zkopíruje plugin z worktree.

## Review Focus

1. **Uložená hodnota s HTML nebo skriptem** (`<script>`, `"><img onerror>`) ve jméně či zprávě: v tabulce i v detailu se vypíše jako text, nic se nespustí. → Úkol 5.
2. **Dvě záložky nebo dvojklik:** druhý požadavek na totéž odeslání nezaloží druhý lead a vrátí chybu „už odesláno“. → Úkol 4 a 5.
3. **Elementor Pro vypnuté nebo tabulky chybí:** žádný fatal, žádná SQL chyba; odkaz na import není, obrazovka řekne proč. → Úkol 4 a 5.
4. **Mapování se změnilo mezi vykreslením tabulky a kliknutím** (odeslání už nemá e-mail ani telefon): import řádku vrátí chybu, lead nevznikne, log se nezapíše. → Úkol 4.
5. **Pole, které už ve formuláři není, a hodnota s více volbami** (checkbox uložený jako „a, b“): import nespadne; neznámé pole má typ `text` a popisek rovný ID, hodnota jde beze změny. → Úkol 4.

---

## Struktura souborů

Cesty relativně k `raynet-lead-api-integration/`.

| Soubor | Odpovědnost |
|---|---|
| `includes/elementor/class-raynet-elementor-lead-builder.php` | **nový.** Z pole polí (`id => [type, value, raw_value, title]`) a nastavení formuláře udělá hodnoty a kontext pro `submit_lead()`. Bez Elementoru. |
| `includes/elementor/class-raynet-elementor-form-action.php` | **upravit.** `run()` volá sestavovač; přesunuté privátní metody zmizí. |
| `includes/class-raynet-lead-form.php` | **upravit.** `submit_lead()` / `build_payload()` / `build_notice()` rozumí kontextu importu. |
| `includes/elementor/class-raynet-submission-spam-score.php` | **nový.** Čisté hodnocení jednoho odeslání. Bez WordPressu. |
| `includes/elementor/class-raynet-elementor-submissions.php` | **nový.** Čtení tabulek Elementoru, stavy, statistiky, index verdiktů, příprava leadu, import jednoho odeslání. |
| `includes/elementor/class-raynet-elementor-import-admin.php` | **nový.** Obrazovka importu a AJAX. |
| `includes/elementor/class-raynet-elementor-forms-admin.php` | **upravit.** Odkaz „Importovat starší odeslání (N)“ a předání na obrazovku importu. |
| `assets/js/raynet-elementor-import.js` | **nový.** Fronta odesílání, průběh, výsledek u řádku. |
| `assets/css/raynet-lead-admin.css` | **upravit.** Styl tabulky importu. |
| `raynet-lead-api-integration.php`, `uninstall.php` | **upravit.** `require_once`, registrace, verze; mazání cache indexu. |
| `tests/test-lead-builder.php`, `tests/test-spam-score.php`, `tests/test-import.php`, `tests/js/test-elementor-import.js` | **nové.** |
| `tests/integration/run.php` | **upravit.** Blok importu uvnitř větve s Elementor Pro. |

---

### Task 1: Sestavovač leadu vyjmutý z akce

**Files:**
- Create: `includes/elementor/class-raynet-elementor-lead-builder.php`
- Modify: `includes/elementor/class-raynet-elementor-form-action.php` (`run()` a metody `mapped_values`, `noted_values`, `switch_consent`, `merge_lines`, `lead_settings`, `setting`)
- Modify: `raynet-lead-api-integration.php` (`require_once` za `class-raynet-elementor-forms.php`)
- Test: `tests/test-lead-builder.php`

**Interfaces:**
- Produces, vše `public static` na `Raynet_Elementor_Lead_Builder`:
  - `mapped_values( array $fields, array $form_settings ): array` — klíče `basic`, `attributes`, `custom`, `extras`, `consent` (`bool|null`), `consent_text`, `message_label`; tělo je dnešní `Raynet_Elementor_Form_Action::mapped_values()` s `$fields` místo `$record->get( 'fields' )`.
  - `noted_values( array $fields, array $form_settings ): array`
  - `switch_consent( array $fields, array $form_settings ): bool`
  - `merge_lines( array $first, array $second ): array`
  - `lead_settings( array $form_settings ): array`
  - `contact_error( array $values ): string` — `''`, nebo přesně dnešní texty „Lead neobsahuje e-mail ani telefon.“ / „Zadaná e-mailová adresa není platná.“
  - `context( array $mapped, array $fields, array $form_settings ): array` — klíče `raynet_fixed_topic`, `raynet_has_consent`, `raynet_consent_text`, `raynet_consent_record`, `raynet_message_label`, `raynet_attributes`, `raynet_custom_fields`, `raynet_extras`, se stejnými hodnotami, jaké dnes skládá `run()`. `raynet_source_url` a `raynet_files` doplňuje volající.

- [ ] **Step 1: Napsat `tests/test-lead-builder.php`** (hlavička a `check()` jako `tests/test-fields.php`). Formulář: mapa `email→email`, `firstName→name`, `message→msg`, `gdprConsent→gdpr`, `regNumber→ico`; `raynet_crm_notice_fields = [ 'odkud' ]`; `form_fields` s `acceptance_text` u `gdpr`. Kontroly:
  - `mapped_values` vrátí `basic` = `[ 'email' => 'jana@firma.cz', 'firstName' => 'Jana', 'message' => 'Dobrý den' ]`, `message_label` = titulek pole `msg`, `consent === true`, `consent_text` = text zaškrtávátka;
  - číselné pole s `raw_value '02795281'` a `value 2795281` dá `attributes['regNumber'] === '02795281'`;
  - pole typu `password` se nikdy neobjeví v žádném výstupu;
  - `noted_values` vrátí `[ 'Odkud o nás víte' => 'Google' ]` a vynechá pole, které už mapa posílá;
  - `merge_lines( [ 'A' => '1' ], [ 'A' => '2' ] )` = `[ 'A' => '1', 'A (2)' => '2' ]`;
  - `switch_consent`: přepínač `yes` + nezaškrtnuté pole `acceptance` → `false`; bez pole `acceptance` → `true`; přepínač vypnutý → `false`;
  - `contact_error`: prázdný e-mail i telefon → první text; `email = 'x'` → druhý; jinak `''`;
  - `context` má `raynet_consent_record === true` jen při `mapped['consent'] === true`.
- [ ] **Step 2: Spustit** `php tests/test-lead-builder.php` — očekávat fatal „Class Raynet_Elementor_Lead_Builder not found“.
- [ ] **Step 3: Vytvořit třídu** přesunem těl z akce (beze změny logiky, jen `$record->get( 'fields' )` → `$fields`).
- [ ] **Step 4: Přepsat `Raynet_Elementor_Form_Action::run()`**, aby volal sestavovač s `(array) $record->get( 'fields' )`; řádky příloh (`Raynet_Elementor_Attachments::note_lines()`), `raynet_source_url`, `raynet_files`, odeslání příloh a převod chyb na `\Exception` zůstávají v akci. Přesunuté privátní metody z akce smazat.
- [ ] **Step 5: Spustit** `php tests/run.php` — „All suites passed.“ Pak `bin/wp-test.sh` — žádný `FAIL` (blok Elementor Pro Forms kryje živé odeslání).
- [ ] **Step 6: Commit** `refactor: move Elementor lead building out of the submit action`

---

### Task 2: `submit_lead()` rozumí importu

**Files:**
- Modify: `includes/class-raynet-lead-form.php` (`submit_lead`, `build_payload`, `build_notice`)
- Test: `tests/test-flow.php` (nový oddíl na konci)

**Interfaces:**
- Produces: nové nepovinné klíče `$context`:
  - `raynet_import` (`bool`) — vypne `mark_throttled()`, `send_fallback_email()`, `remember_last_error()`, `record_consent()` a z payloadu vynechá `notificationEmailAddresses` i `notificationMessage`. `log_error()` a hooky `raynet_lead_payload`, `raynet_lead_created`, `raynet_lead_failed` zůstávají.
  - `raynet_lead_date` (`Y-m-d`) — `leadDate` místo dneška; neplatný formát se ignoruje.
  - `raynet_consent_time` (`Y-m-d H:i`) — čas v řádku „Souhlas se zpracováním údajů udělen: …“ místo aktuálního.
  - `raynet_extra_tags` (`string`) — připojí se čárkou za `tags` z nastavení, bez duplicit a prázdných položek.
  - `raynet_import_note` (`string`) — vlastní odstavec poznámky, hned za předponou poznámky.
- Bez těchto klíčů je payload bajt po bajtu jako dnes.

- [ ] **Step 1: Napsat testy** v `tests/test-flow.php`, přes `submit_lead()` s nastavením `notify_emails = 'a@b.cz'`, `tags = 'web'`, `throttle_seconds = 20`, `fallback_email` vyplněný, a kontextem `[ 'raynet_import' => true, 'raynet_lead_date' => '2025-03-12', 'raynet_consent_time' => '2025-03-12 14:10', 'raynet_has_consent' => true, 'raynet_consent_record' => true, 'raynet_extra_tags' => 'import-web, web', 'raynet_import_note' => 'Importováno…' ]`:
  - odeslaný payload: `leadDate === '2025-03-12'`, `tags === 'web,import-web'`, klíč `notificationEmailAddresses` chybí, `notice` obsahuje „Importováno…“ a „udělen: 2025-03-12 14:10“;
  - byl odeslán právě jeden HTTP požadavek (žádný `PUT /gdpr/`);
  - po odpovědi 500: `$GLOBALS['wp_mails']` prázdné, option `raynet_lead_last_error` nezměněná, transient pauzy nenastaven;
  - `raynet_lead_date = 'včera'` → `leadDate` je dnešek;
  - kontrolní volání bez nových klíčů dá payload shodný s tím, co test získal před změnou (porovnat s existujícím očekáváním v souboru).
- [ ] **Step 2: Spustit** `php tests/test-flow.php` — nové kontroly `FAIL`.
- [ ] **Step 3: Implementovat** v uvedených třech metodách.
- [ ] **Step 4: Spustit** `php tests/run.php` — „All suites passed.“
- [ ] **Step 5: Commit** `feat: let submit_lead take an import context`

---

### Task 3: Hodnocení spamu

**Files:**
- Create: `includes/elementor/class-raynet-submission-spam-score.php`
- Modify: `raynet-lead-api-integration.php` (`require_once`)
- Test: `tests/test-spam-score.php`

**Interfaces:**
- Produces, `Raynet_Submission_Spam_Score`, bez volání funkcí WordPressu:
  - konstanty `VERDICT_OK = 'ok'`, `VERDICT_SUSPECT = 'suspect'`, `VERDICT_SPAM = 'spam'`; `STRONG = 'strong'`, `MEDIUM = 'medium'`, `WEAK = 'weak'`;
  - `default_config(): array` — `strong_to_spam 1`, `medium_to_spam 2`, `medium_to_suspect 1`, `weak_to_suspect 2`, `same_text_senders 3`, `same_ip_count 5`, `min_message_length 10`, `min_phone_digits 9`, `keywords` (aspoň: seo, backlink, casino, crypto, bitcoin, viagra, loan, guest post, ranking, traffic), `disposable_domains` (aspoň: mailinator.com, guerrillamail.com, 10minutemail.com, tempmail.com, yopmail.com, trashmail.com), `allowed_scripts [ 'Latin' ]`, `disabled []`;
  - `text_key( string $message ): string` — `''` pro prázdný text, jinak hash textu po převodu na malá písmena a sloučení bílých znaků;
  - `score( array $submission, array $stats, array $config ): array` → `[ 'verdict' => string, 'reasons' => [ [ 'id' => string, 'strength' => string, 'count' => int ], … ] ]`;
  - `verdict( array $reasons, array $config ): string`.
- `$submission`: `values` (`firstName`, `lastName`, `companyName`, `email`, `phone`, `topic`, `message`; chybějící = `''`), `texts` (`string[]`, ostatní uložené hodnoty), `ip` (`string`), `user_agent` (`string|null`, `null` = formulář ho neukládá), `has_message_field` (`bool`), `duplicate` (`bool`).
- `$stats`: `text_senders` (`text_key => int`, počet různých odesílatelů), `ip_counts` (`ip => int`).
- Identifikátory signálů a síla (spec, část 5): `link_short`, `markup`, `foreign_script`, `same_text`, `links_many` — strong; `link_one`, `keywords`, `same_ip`, `odd_name`, `bot_agent` — medium; `bad_phone`, `disposable_email`, `short_message` — weak; `duplicate` — medium. `links_many` a `link_one` se vylučují. `count` nese počet u `same_text` a `same_ip`, jinak `0`.
- Verdikt: počet strong ≥ `strong_to_spam` nebo medium ≥ `medium_to_spam` → `spam`; medium ≥ `medium_to_suspect` nebo weak ≥ `weak_to_suspect` → `suspect`; jinak `ok`.

- [ ] **Step 1: Napsat `tests/test-spam-score.php`.** Pomocná `ids( $result )` vrátí seřazená ID důvodů. Základ: čisté odeslání `Jana Nováková`, `jana@firma.cz`, `777 123 456`, zpráva „Dobrý den, poptávám nabídku na rekonstrukci koupelny.“ → `ok`, žádné důvody. Pak po jedné změně:
  - `firstName = 'Visit http://x.ru'` → `link_short`, `spam`;
  - zpráva s `<a href=…>` nebo `[url=…]` → `markup`;
  - zpráva „Здравствуйте, предлагаем продвижение сайта“ → `foreign_script`, `spam`; s `disabled = [ 'foreign_script' ]` → `ok`; česká diakritika „Příliš žluťoučký kůň“ signál nespustí;
  - `stats['text_senders'][ key ] = 3` → `same_text` s `count 3`; hodnota `2` nic;
  - dva odkazy ve zprávě → `links_many` bez `link_one`; jeden odkaz → `link_one`, `suspect`;
  - „best SEO backlinks“ → `keywords`; slovo „seolit“ nespustí (hranice slova);
  - `ip_counts[ ip ] = 5` → `same_ip`; `4` nic; prázdná IP nic;
  - `firstName = lastName = 'Robertbok'` → `odd_name`; `'J4na'` → `odd_name`; `'xQzTrWpL'` → `odd_name`; `'Křšťan'`, `'Nguyen'`, `'Ng'` nespustí;
  - `user_agent = 'python-requests/2.31'` a `''` → `bot_agent`; `null` nic;
  - `phone = '123'` a `'111111111'` → `bad_phone`; prázdný telefon nic; `'+420 777 123 456'` nic;
  - `email = 'x@mailinator.com'` → `disposable_email`;
  - zpráva „ahoj“ při `has_message_field = true` → `short_message`; při `false` nic;
  - `duplicate = true` → `duplicate`, verdikt aspoň `suspect`;
  - prahy: `link_one` + `keywords` → `spam`; `bad_phone` + `short_message` → `suspect`; samotné `bad_phone` → `ok`.
- [ ] **Step 2: Spustit** `php tests/test-spam-score.php` — fatal „Class … not found“.
- [ ] **Step 3: Implementovat třídu.** Písmo přes `\p{Latin}` / `\p{L}` v `preg_match_all` s příznakem `u`: `foreign_script`, když je nelatinkových písmen víc než polovina všech písmen textu a aspoň 5.
- [ ] **Step 4: Spustit** `php tests/run.php` — „All suites passed.“
- [ ] **Step 5: Commit** `feat: score stored submissions for spam`

---

### Task 4: Čtení odeslání, index a import jednoho odeslání

**Files:**
- Create: `includes/elementor/class-raynet-elementor-submissions.php`
- Modify: `raynet-lead-api-integration.php` (`require_once`), `uninstall.php`
- Test: `tests/test-import.php`, `tests/test-uninstall.php`, `tests/integration/run.php`

**Interfaces:**
- Consumes: `Raynet_Elementor_Lead_Builder::*` (Úkol 1), kontext importu `submit_lead()` (Úkol 2), `Raynet_Submission_Spam_Score::*` (Úkol 3), `Raynet_Elementor_Forms::form_settings( $post_id, $widget_id )`, `::maps_contact( $settings )`, `::scan()`.
- Produces, `Raynet_Elementor_Submissions`, vše `public static`:
  - konstanty `STATE_NEW = 'new'`, `STATE_FAILED = 'failed'`, `STATE_SENT = 'sent'`; `TAB_REVIEW = 'review'`, `TAB_SPAM = 'spam'`, `TAB_SENT = 'sent'`, `TAB_BLOCKED = 'blocked'`; `CACHE_PREFIX = 'raynet_import_idx_'`; `DEFAULT_TAG = 'import-web'`.
  - `available(): bool` — Elementor Pro aktivní, třída `\ElementorPro\Modules\Forms\Submissions\Database\Query` existuje a tabulka `{prefix}e_submissions` existuje.
  - `pending_counts(): array` — `"post_id:element_id" => int`, počet odeslání mimo koš bez úspěšného `raynet_crm` v logu; klíč se tvoří z `post_id` i z `meta.edit_post_id`. Jeden až dva dotazy pro celý web. `array()` když `! available()`.
  - `each_batch( int $post_id, string $widget_id, callable $visit, int $size = 500 ): void` — volá `$visit( array $rows )` po dávkách, nejnovější první.
  - `load( int $post_id, string $widget_id, array $ids ): array` — řádky podle ID, jen ty, které k formuláři patří a nejsou v koši.
  - Řádek: `id` (`int`), `created_at` (místní čas, `Y-m-d H:i:s`), `referer`, `ip`, `user_agent` (`string`), `state`, `log` (text posledního neúspěchu `raynet_crm`, jinak `''`), `values` (`key => string`).
  - `fields_for( array $row, array $form_settings ): array` — tvar `Form_Record::get( 'fields' )`: `id => [ 'id', 'type', 'title', 'value', 'raw_value' ]`. Typ a titulek z `form_settings['form_fields']` (`custom_id`, `field_type`, `field_label`); neznámý klíč → `text` a titulek rovný klíči. Typ `number` s hodnotou `'0'` → `''`. `raw_value` = `value` (u importu jiné není).
  - `prepare( array $row, array $form_settings, string $tag ): array|WP_Error` — `[ 'values' => array, 'settings' => array, 'context' => array ]` pro `submit_lead()`, nebo `WP_Error( 'raynet_import_blocked', <text z contact_error()> )`. Kontext: výstup `Raynet_Elementor_Lead_Builder::context()` s `raynet_consent_record = false`, plus `raynet_import = true`, `raynet_lead_date` a `raynet_consent_time` z `created_at`, `raynet_extra_tags = $tag`, `raynet_source_url = $row['referer']` jen při `raynet_crm_source_url === 'yes'`, `raynet_import_note` = „Importováno z uložených odeslání Elementoru. Původně odesláno: 12. 3. 2025 14:10“ (datum `j. n. Y H:i`). Pole typu `upload` s hodnotou, která je mapované jako příloha nebo vybrané do poznámky, přidá do `raynet_extras` řádek `<titulek> => <uložený odkaz>`.
  - `stats( int $post_id, string $widget_id, array $form_settings ): array` — `text_senders`, `ip_counts` pro skóre a `first_of` (`"<email>|<text_key>" => id` nejstaršího; odeslání ve stavu `sent` má přednost před staršími neodeslanými).
  - `classify( array $row, array $form_settings, array $stats, array $config ): array` — `[ 'state', 'verdict', 'reasons', 'tab', 'preselect' (bool), 'block' (string), 'number_warning' (bool) ]`. `tab`: `sent` → `TAB_SENT`; chyba z `prepare()` → `TAB_BLOCKED` s `block` = text; verdikt `spam` → `TAB_SPAM`; jinak `TAB_REVIEW`. `preselect` jen při `tab === TAB_REVIEW`, verdiktu `ok` a stavu `new`. `number_warning`, když má odeslání neprázdnou hodnotu v poli typu `number`. Odesílatel pro `same_text` je e-mail, bez e-mailu IP. Volá filtr `raynet_import_spam_score` (`$result, $submission, $row`).
  - `index( int $post_id, string $widget_id ): array` — `id => výstup classify()` pro všechna odeslání formuláře, nejnovější první; konfigurace přes filtr `raynet_import_spam_config` nad `default_config()`; uloženo v transientu `CACHE_PREFIX . md5( "$post_id:$widget_id" )` na 10 minut spolu s otiskem nastavení formuláře a počtu odeslání, při neshodě otisku se přepočítá.
  - `mark_sent( int $post_id, string $widget_id, int $submission_id ): void` — v uloženém indexu přepne položku na `sent` / `TAB_SENT`.
  - `import( int $post_id, string $widget_id, int $submission_id, string $tag ): array|WP_Error` — `[ 'lead_id' => int ]`. Chyby: `raynet_import_unavailable` (`! available()`), `raynet_import_no_form` (formulář nenalezen nebo nemapuje kontakt), `raynet_import_not_found` (odeslání k formuláři nepatří nebo je v koši), `raynet_import_already_sent`, `raynet_import_blocked`, `raynet_api_error` (zpráva = diagnostika z `submit_lead()`). Stav se čte z databáze čerstvě, ne z indexu. Po úspěchu hned `Query::get_instance()->add_action_log( $id, <instance Raynet_Elementor_Form_Action>, Query::ACTIONS_LOG_STATUS_SUCCESS, 'Importováno, lead <id>' )`, pak `mark_sent()`.

- [ ] **Step 1: Napsat `tests/test-import.php`** pro části bez databáze (`fields_for`, `prepare`, `classify`), s formulářem z testu Úkolu 1 rozšířeným o pole `soubor` (`upload`, vybrané do poznámky) a `ico` (`number`). Řádek: `created_at '2025-03-12 14:10:00'`, `referer 'https://example.test/kontakt/'`, hodnoty včetně `gdpr => 'on'`, `ico => '2795281'`, `soubor => 'https://example.test/wp-content/uploads/elementor/forms/a.pdf'`, `stare => 'x'` (pole, které ve formuláři není), `zajem => 'a, b'`. Kontroly:
  - `fields_for`: `ico` má typ `number`; s hodnotou `'0'` je `value === ''`; `stare` má typ `text` a titulek `stare`; `zajem` jde beze změny;
  - `prepare`: `context['raynet_import'] === true`, `raynet_lead_date === '2025-03-12'`, `raynet_consent_time === '2025-03-12 14:10'`, `raynet_has_consent === true`, `raynet_consent_record === false`, `raynet_extra_tags === 'import-web'`, `raynet_source_url` = referer, `raynet_import_note` obsahuje `12. 3. 2025 14:10`, `raynet_extras` nese titulek pole `soubor` s odkazem; `values['email'] === 'jana@firma.cz'`;
  - `prepare` s vypnutým `raynet_crm_source_url` → `raynet_source_url === ''`;
  - `prepare` bez e-mailu a telefonu → `WP_Error` s kódem `raynet_import_blocked`; s e-mailem `'x'` také;
  - `classify`: čistý řádek ve stavu `new` → `TAB_REVIEW`, `preselect true`; stejný ve stavu `failed` → `TAB_REVIEW`, `preselect false`; `sent` → `TAB_SENT`; bez kontaktu → `TAB_BLOCKED` s neprázdným `block`; zpráva azbukou → `TAB_SPAM`, `preselect false`; `number_warning === true` kvůli `ico`;
  - `classify` s `stats['first_of']` ukazujícím na jiné ID → důvod `duplicate`, `preselect false`; ukazujícím na vlastní ID → bez `duplicate`;
  - `classify` se `stats['text_senders'][ key ] = 3` → `TAB_SPAM`.
- [ ] **Step 2: Spustit** `php tests/test-import.php` — fatal „Class … not found“.
- [ ] **Step 3: Implementovat třídu.** Párování přísné: `element_id = %s AND ( post_id = %d OR meta LIKE %s )`, kde vzor je `'%"edit_post_id":' . $post_id . '%'` s ověřením shody po `json_decode` v PHP (LIKE by pustil i `12` v `123`). Koš: `status <> 'trash'`. `index()` smí projít dávky dvakrát (statistiky, pak klasifikace); nesmí držet hodnoty všech odeslání v paměti naráz.
- [ ] **Step 4: Spustit** `php tests/test-import.php` — vše `ok`.
- [ ] **Step 5: Odinstalace.** V `tests/test-uninstall.php` přidat kontrolu, že se smažou options `_transient_raynet_import_idx_*` i `_transient_timeout_raynet_import_idx_*`; spustit (FAIL); doplnit do `uninstall.php` ke stávajícímu mazání transientů pauzy; spustit (ok).
- [ ] **Step 6: Integrační testy** v `tests/integration/run.php`, uvnitř větve s Elementor Pro, nový oddíl `=== Import uložených odeslání ===`. Příprava: stránka s formulářem (pole `name`, `email`, `msg`) s namapovanou akcí; přes `Query::get_instance()->add_submission()` vložit: A čisté; B se zprávou azbukou; C čisté s řádkem logu `raynet_crm` / `success`; D čisté s řádkem `failed` a textem „Connection timed out“; E v koši; F se stejným `element_id`, ale cizím `post_id`. Kontroly:
  - `available() === true`; `pending_counts()[ "<post>:<widget>" ] === 3` (A, B, D);
  - `index()`: A `review` + `preselect`, B `spam`, C `sent`, D `review` bez `preselect` a s `log` „Connection timed out“ po `load()`, E a F chybí;
  - `import( A )`: vrátí `lead_id` z testovacího dvojníka; zachycený payload má `leadDate` = den vložení A, štítek `import-web`, nemá `notificationEmailAddresses`; nebyl odeslán žádný e-mail; `get_submissions_actions_log( A )` obsahuje `raynet_crm` / `success` s textem „Importováno, lead“; `actions_succeeded_count` u A se nezměnil;
  - druhé `import( A )` → `raynet_import_already_sent`, žádný další HTTP požadavek;
  - `import( E )` a `import( F )` → `raynet_import_not_found`;
  - `import( C )` → `raynet_import_already_sent`;
  - po odebrání mapování e-mailu z formuláře: `import( D )` → `raynet_import_no_form`, log D beze změny, žádný požadavek (Review Focus 4);
  - dvojník vrátí 500: `import( B )` → `raynet_api_error`, log B bez nového řádku, option `raynet_lead_last_error` nezměněná.
- [ ] **Step 7: Spustit** `php tests/run.php` a `bin/wp-test.sh` — bez `FAIL`.
- [ ] **Step 8: Commit** `feat: read stored Elementor submissions and import one as a lead`

---

### Task 5: Obrazovka importu

**Files:**
- Create: `includes/elementor/class-raynet-elementor-import-admin.php`, `assets/js/raynet-elementor-import.js`
- Modify: `includes/elementor/class-raynet-elementor-forms-admin.php` (`render()`: větev `import`; řádek formuláře: odkaz), `assets/css/raynet-lead-admin.css`, `raynet-lead-api-integration.php` (`require_once`, registrace v `is_admin()`), `includes/class-raynet-admin.php` (`enqueue_assets`, pokud tam obrazovka bere styl a skript)
- Test: `tests/js/test-elementor-import.js`, `tests/integration/run.php`

**Interfaces:**
- Consumes: vše z Úkolu 4.
- Produces:
  - `Raynet_Elementor_Import_Admin::register(): void` — `wp_ajax_raynet_elm_import_one`, načtení skriptu na obrazovce.
  - `Raynet_Elementor_Import_Admin::render( string $key ): void` — `$key` je `"<post_id>:<widget_id>"`; volá ji `Raynet_Elementor_Forms_Admin::render()`, když je v URL `import=`.
  - `Raynet_Elementor_Import_Admin::url( array $form ): string` — `admin.php?page=raynet-elementor-forms&import=<post_id>:<widget_id>`.
  - Parametry URL: `zalozka` (`review` výchozí, `spam`, `sent`, `blocked`), `strana` (od 1), `na_stranku` (`50`, `100` výchozí, `200`; cokoli jiného → `100`).
  - AJAX `raynet_elm_import_one`: POST `_wpnonce`, `form` (`post_id:widget_id`), `submission` (`int`), `tag`. Úspěch `wp_send_json_success( [ 'lead_id' => int ] )`; chyba `wp_send_json_error( [ 'code' => string, 'message' => string ], 400 )`; bez oprávnění 403. Štítek se čistí `sanitize_text_field` a ořízne na 60 znaků.
  - JS: globální `raynetElementorImport` (`ajaxUrl`, `nonce`, `form`, texty). Čistá část `window.raynetImportQueue( ids, sendOne, onResult, onDone )` posílá sériově, jeden požadavek po druhém; `sendOne( id )` vrací Promise s `{ ok: bool, leadId: int, code: string, message: string, unknown: bool }`, kde `unknown` znamená, že server neodpověděl.
- HTML háčky pro testy: tabulka `table.raynet-import`, řádek `tr[data-submission="<id>"]`, zaškrtávátko `input[name="submissions[]"]`, tlačítko `#raynet-import-send`, pole štítku `#raynet-import-tag`.
- Popisky důvodů (ID signálu → text) a verdiktů vznikají zde, v `__()`: `link_short` „odkaz ve jméně nebo jiném krátkém poli“, `markup` „HTML nebo BBCode značky“, `foreign_script` „text v jiném písmu než latinka“, `same_text` „stejný text %d×“, `links_many` „více odkazů ve zprávě“, `link_one` „odkaz ve zprávě“, `keywords` „klíčová slova spamu“, `same_ip` „stejná IP adresa %d×“, `odd_name` „nevěrohodné jméno“, `bot_agent` „strojový prohlížeč“, `bad_phone` „nevěrohodný telefon“, `disposable_email` „jednorázový e-mail“, `short_message` „velmi krátká zpráva“, `duplicate` „duplicitní odeslání“. Verdikty: „V pořádku“, „Podezřelé“, „Spam“.

Co obrazovka ukazuje, je ve spec, část 3. Pevné texty: u stavu `failed` „tehdy selhalo: „<log>“ — ověřte v RAYNETu, že lead neexistuje“; u `number_warning` v detailu „Elementor uložil jen celé číslo; úvodní nuly a desetinná část se nedochovaly“; po neznámém výsledku „výsledek neznámý, ověřte v RAYNETu, než to zkusíte znovu“ a řádek se odškrtne; potvrzení před odesláním „Odeslat %d odeslání do RAYNETu?“.

- [ ] **Step 1: Napsat `tests/js/test-elementor-import.js`** (načtení zdroje přes `vm` jako `test-elementor-editor.js`, s prázdnými stand-iny pro `jQuery` a `document`):
  - `raynetImportQueue( [ 1, 2, 3 ], … )` volá `sendOne` v pořadí 1, 2, 3 a druhé volání nezačne dřív, než první Promise skončí;
  - neúspěch u 2 frontu nezastaví, `onResult` dostane tři výsledky, `onDone` souhrn `{ sent: 2, failed: 1, unknown: 0 }`;
  - odmítnutá Promise se počítá jako `unknown`;
  - prázdný seznam zavolá `onDone` hned a `sendOne` nikdy.
- [ ] **Step 2: Spustit** `node tests/js/test-elementor-import.js` — selže, soubor neexistuje.
- [ ] **Step 3: Napsat `assets/js/raynet-elementor-import.js`**: fronta, a DOM část (potvrzení, zablokování tlačítka během běhu, průběh „3 / 61“, výsledek do řádku, zaškrtnout / odškrtnout vše na stránce, rozbalení detailu).
- [ ] **Step 4: Spustit** `node tests/js/test-elementor-import.js` — vše `ok`.
- [ ] **Step 5: Integrační testy** v oddílu importu (data z Úkolu 4, navíc odeslání G se jménem `<script>alert(1)</script>` a zprávou `"><img src=x onerror=alert(1)>`):
  - obrazovka formulářů obsahuje odkaz s `import=<post>:<widget>` a textem „Importovat starší odeslání (“ ; formulář bez odeslání odkaz nemá;
  - `GET …&import=<post>:<widget>` vrátí 200, obsahuje `table.raynet-import`, řádek A se zaškrtnutým zaškrtávátkem (`checked`), řádek D bez `checked` s textem „ověřte v RAYNETu“, neobsahuje řádky B, C, E, F;
  - `&zalozka=spam` obsahuje B s textem „text v jiném písmu než latinka“; `&zalozka=sent` obsahuje C bez zaškrtávátka;
  - tělo neobsahuje `<script>alert(1)</script>` ani `onerror=alert(1)>` mimo escapovanou podobu (`&lt;script&gt;` přítomno) (Review Focus 1);
  - `&na_stranku=7` se chová jako 100; `&strana=99` nevrátí chybu a ukáže prázdnou stránku s hláškou;
  - `&import=999999:nic` vrátí 200 s hláškou, že formulář nebyl nalezen;
  - formulář bez namapovaného kontaktu: hláška s odkazem na `mapovat=` a žádné `#raynet-import-send`;
  - AJAX bez nonce → 403 a žádný požadavek na RAYNET; nepřihlášený → nic neodešle; s nonce pro A → `success` a `lead_id`; totéž podruhé → `error` s kódem `raynet_import_already_sent` (Review Focus 2);
  - s deaktivovaným Elementor Pro (`deactivate_plugins` na dobu kontroly, pak zpět): obrazovka formulářů se načte bez odkazu na import a `&import=…` vrátí 200 s hláškou, bez PHP chyby v `debug.log` (Review Focus 3).
- [ ] **Step 6: Spustit** `bin/wp-test.sh` — nové kontroly `FAIL`.
- [ ] **Step 7: Implementovat** `Raynet_Elementor_Import_Admin`, větev v `Raynet_Elementor_Forms_Admin::render()` a odkaz v řádku formuláře (počty z jednoho volání `pending_counts()` na vykreslení obrazovky), styl.
- [ ] **Step 8: Spustit** `php tests/run.php` a `bin/wp-test.sh` — bez `FAIL`.
- [ ] **Step 9: Ruční kontrola v prohlížeči** na `.wp-test` (`http://localhost:8765/wp-admin/`): otevřít import formuláře, odeslat dva řádky, ověřit průběh, čísla leadů u řádků a že po obnovení stránky jsou na záložce „Už v RAYNETu“. Snímek obrazovky přiložit k hlášení.
- [ ] **Step 10: Commit** `feat: import screen for stored Elementor submissions`

---

### Task 6: Dokumentace a verze

**Files:**
- Modify: `README.md`, `CHANGELOG.md`, `raynet-lead-api-integration/raynet-lead-api-integration.php`, `raynet-lead-api-integration/languages/raynet-lead-api-integration.pot`
- Test: `tests/test-bootstrap.php` nebo `tests/test-updater.php`, pokud verzi kontrolují

- [ ] **Step 1: Verze** `2.10.0` v hlavičce pluginu i v `RAYNET_LEAD_VERSION`.
- [ ] **Step 2: README.** Řádek ve *Funkce*; nová podkapitola „Import starších odeslání“ v *Elementor Pro Forms* (kde odkaz je, záložky a verdikty, co je předvybrané, tabulka rozdílů importovaného leadu ze spec části 6, číselná pole, přísné párování, potřeba aktivního Elementor Pro); filtry `raynet_import_spam_config` a `raynet_import_spam_score` s příkladem vypnutí `foreign_script` v *Hooky pro vývojáře*; věta v *Ochrana proti spamu*, že hodnocení importu je samostatné; řádky v *Řešení potíží* („odkaz na import nevidím“, „odeslání v importu chybí“); doplnit výčet pokrytí v *Vývoj a testy*.
- [ ] **Step 3: CHANGELOG.** Oddíl `## [2.10.0] — <datum vydání>` nad 2.9.0, část *Přidáno* (import, hodnocení spamu, filtry) a *Změněno* (odinstalace maže cache importu); položky z *Nevydáno* přesunout pod něj.
- [ ] **Step 4: `.pot`** doplnit o všechny nové řetězce (ručně ve formátu souboru, nebo `php .wp-test/wp-cli.phar i18n make-pot raynet-lead-api-integration raynet-lead-api-integration/languages/raynet-lead-api-integration.pot`, pokud je příkaz dostupný).
- [ ] **Step 5: Spustit** `php tests/run.php` a `bin/wp-test.sh` — bez `FAIL`.
- [ ] **Step 6: Commit** `docs: stored submissions import in README and CHANGELOG, version 2.10.0`
