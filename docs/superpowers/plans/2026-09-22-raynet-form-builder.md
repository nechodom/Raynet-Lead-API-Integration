# Builder formulářů — implementační plán

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Nahradit pevný desetipolový formulář builderem, kde správce pole vybere, seřadí a přepíše jim popisky.

**Architecture:** Formuláře jsou vlastní typ obsahu `raynet_form`. Definice polí žije v post meta. Jedna třída definice hlídá kanonický tvar dat, jedna třída renderer z ní dělá HTML, a tu volá zkratka i náhled v administraci, takže se nemohou rozejít. Odesílání načítá definici ze serveru podle skrytého `raynet_form_id`, nikdy z prohlížeče.

**Tech Stack:** PHP 7.4+, WordPress 5.6+, čisté JS bez build kroku, testovací suita nad stubem WordPressu (`php tests/run.php`).

**Spec:** `docs/superpowers/specs/2026-09-22-raynet-form-builder-design.md`

---

## Struktura souborů

| Soubor | Odpovědnost |
|---|---|
| `includes/class-raynet-form-definition.php` | **nový.** Katalog zdrojů, kanonizace definice pole a nastavení leadu. Čisté PHP, bez WordPressu kromě sanitizačních funkcí. |
| `includes/class-raynet-form-post-type.php` | **nový.** Registrace CPT, čtení a zápis meta, vyhledání formuláře podle ID/slugu/výchozího. |
| `includes/class-raynet-form-renderer.php` | **nový.** Z definice polí vydá HTML formuláře. Jediné místo, kde vzniká značkování. |
| `includes/class-raynet-form-builder-admin.php` | **nový.** Metaboxy, načtení skriptů builderu, AJAX náhledu, ukládání. |
| `includes/class-raynet-lead-form.php` | **upravit.** Zkratka načítá formulář; `collect_values()` bere definice; poznámka bere vlastní pole; vykreslování se stěhuje do rendereru. |
| `includes/class-raynet-settings.php` | **upravit.** Migrace zakládá výchozí formulář; souhlas mizí ze stránky nastavení. |
| `includes/class-raynet-admin.php` | **upravit.** Odstranit sekci souhlasu ze stránky nastavení. |
| `assets/js/raynet-form-builder.js` | **nový.** Přetahování, rozbalování, náhled. |
| `assets/css/raynet-form-builder.css` | **nový.** Vzhled builderu. |
| `assets/css/raynet-lead-form.css` | **upravit.** Obecný modifikátor půlené šířky místo jmenovitých `--firstName` / `--lastName`. |
| `raynet-lead-api-integration.php` | **upravit.** Nové `require_once`, bootstrap, verze 2.1.0. |
| `tests/wp-stubs.php` | **upravit.** Stuby pro příspěvky a meta. |
| `tests/test-form-builder.php` | **nový.** |

---

## Task 1: Katalog zdrojů a kanonizace definice pole

**Files:**
- Create: `raynet-lead-api-integration/includes/class-raynet-form-definition.php`
- Create: `tests/test-form-builder.php`
- Modify: `tests/wp-stubs.php`

- [ ] **Step 1: Doplnit stuby, které nová třída potřebuje**

Do `tests/wp-stubs.php` za `function sanitize_key(...)`:

```php
function sanitize_title( $t ) { return strtolower( preg_replace( '/[^a-z0-9_-]+/i', '-', trim( (string) $t ) ) ); }
function absint( $v ) { return abs( (int) $v ); }
```

- [ ] **Step 2: Napsat padající test**

Nový soubor `tests/test-form-builder.php`:

```php
<?php
require __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/raynet-lead-api-integration/includes/class-raynet-form-definition.php';

$pass = 0; $fail = 0;
function check( $label, $got, $want ) {
	global $pass, $fail;
	$ok = $got === $want; $ok ? $pass++ : $fail++;
	printf( "%s %s\n", $ok ? 'ok  ' : 'FAIL', $label );
	if ( ! $ok ) { echo "     got:  " . var_export( $got, true ) . "\n     want: " . var_export( $want, true ) . "\n"; }
}

$fields = Raynet_Lead_Form_Definition::sanitize_fields( array(
	array( 'source' => 'email', 'label' => 'Váš e-mail', 'required' => '1', 'width' => 'half' ),
	array( 'source' => 'vymyšlený', 'label' => 'Nic' ),
	array( 'source' => 'email', 'label' => 'Duplikát' ),
	array( 'source' => 'custom', 'type' => 'select', 'label' => 'Odkud?', 'options' => array( 'Google', '', 'Známý' ) ),
	array( 'source' => 'message', 'type' => 'email', 'label' => 'Zpráva' ),
) );

check( 'neznámý zdroj vypadne',       count( $fields ), 3 );
check( 'e-mail zůstal první',          $fields[0]['source'], 'email' );
check( 'typ odvozen ze zdroje',        $fields[0]['type'], 'email' );
check( 'popisek zachován',             $fields[0]['label'], 'Váš e-mail' );
check( 'required je bool',             $fields[0]['required'], true );
check( 'šířka zachována',              $fields[0]['width'], 'half' );
check( 'id přiděleno',                 (bool) preg_match( '/^f_[a-z0-9]{6}$/', $fields[0]['id'] ), true );
check( 'duplicitní zdroj vypadne',     $fields[1]['source'], 'custom' );
check( 'vlastní pole si typ volí',     $fields[1]['type'], 'select' );
check( 'prázdná možnost vypadne',      $fields[1]['options'], array( 'Google', 'Známý' ) );
check( 'typ RAYNET pole nejde přepsat',$fields[2]['type'], 'textarea' );

$again = Raynet_Lead_Form_Definition::sanitize_fields( $fields );
check( 'existující id se zachová', $again[0]['id'], $fields[0]['id'] );

check( 'katalog má deset zdrojů + souhlas', count( Raynet_Lead_Form_Definition::catalogue() ), 11 );
check( 'výchozí sada má šest polí', count( Raynet_Lead_Form_Definition::default_fields() ), 6 );

printf( "\n%d passed, %d failed\n", $pass, $fail );
exit( $fail > 0 ? 1 : 0 );
```

- [ ] **Step 3: Spustit a ověřit pád**

Run: `php tests/test-form-builder.php`
Expected: FAIL — `Failed opening required .../class-raynet-form-definition.php`

- [ ] **Step 4: Napsat třídu**

`Raynet_Lead_Form_Definition` s veřejným rozhraním:

```php
const SOURCES = array( 'firstName', 'lastName', 'companyName', 'email', 'phone', 'topic', 'message', 'street', 'city', 'zipCode' );
const CUSTOM_TYPES = array( 'text', 'textarea', 'select', 'checkbox' );

public static function catalogue();                       // source => array( label, type, autocomplete )
public static function type_for_source( $source );        // email=>email, phone=>tel, message=>textarea, consent=>consent, jinak text
public static function sanitize_fields( $raw );           // pole definic v kanonickém tvaru
public static function sanitize_field( $raw, array $used );
public static function default_fields();                  // firstName, lastName, email, phone, message, consent
public static function sanitize_lead_settings( $raw );
private static function new_id();                         // 'f_' . šest znaků z wp_hash + počítadlo
```

Pravidla, která `sanitize_fields()` vynucuje:
- neznámý `source` vypadne,
- druhý výskyt téhož zdroje z RAYNETu i druhý `consent` vypadne, `custom` se smí opakovat,
- `type` u nevlastních polí přepíše `type_for_source()`,
- `type` u `custom` musí být v `CUSTOM_TYPES`, jinak `text`,
- `options` se vyčistí na neprázdné řetězce a použijí se jen u `select`,
- `label` prázdný se doplní z katalogu,
- `width` jen `full` nebo `half`, u `textarea` a `consent` vždy `full`,
- `id` se zachová, když odpovídá `/^f_[a-z0-9]{6}$/`, jinak se přidělí nové.

`new_id()` nesmí používat `Math.random`-obdobu závislou na čase způsobem, který rozbije test — použít `wp_hash()` nad rostoucím počítadlem a obsahem pole.

- [ ] **Step 5: Spustit testy**

Run: `php tests/test-form-builder.php`
Expected: PASS, 14 kontrol

- [ ] **Step 6: Commit**

```bash
git add raynet-lead-api-integration/includes/class-raynet-form-definition.php tests/test-form-builder.php tests/wp-stubs.php
git commit -m "feat: add form field definition catalogue and sanitizer"
```

---

## Task 2: Kanonizace nastavení leadu na formuláři

**Files:**
- Modify: `raynet-lead-api-integration/includes/class-raynet-form-definition.php`
- Modify: `tests/test-form-builder.php`

- [ ] **Step 1: Přidat padající test**

```php
$lead = Raynet_Lead_Form_Definition::sanitize_lead_settings( array(
	'priority' => 'critical', 'category' => '-3', 'owner' => '7',
	'tags' => 'web, poptavka', 'notify_emails' => 'a@b.cz, spatny',
	'topic' => 'Poptávka', 'cizi_klic' => 'pryc',
) );

check( 'priorita velkými',        $lead['priority'], 'CRITICAL' );
check( 'záporné ID na nulu',      $lead['category'], 0 );
check( 'vlastník prošel',         $lead['owner'], 7 );
check( 'e-maily profiltrované',   $lead['notify_emails'], 'a@b.cz' );
check( 'cizí klíč vypadl',        isset( $lead['cizi_klic'] ), false );
check( 'prázdné zůstane prázdné', $lead['lead_phase'], 0 );
check( 'neznámá priorita = zdědit', Raynet_Lead_Form_Definition::sanitize_lead_settings( array( 'priority' => 'x' ) )['priority'], '' );
```

- [ ] **Step 2: Spustit a ověřit pád**

Run: `php tests/test-form-builder.php`
Expected: FAIL — `Call to undefined method ... ::sanitize_lead_settings()`

- [ ] **Step 3: Implementovat**

Klíče a pravidla podle spec § 3: `topic`, `priority` (prázdno nebo `MINOR`/`DEFAULT`/`CRITICAL`), `lead_person` (prázdno, `'0'`, `'1'`), `notice_prefix`, `category`, `lead_phase`, `contact_source`, `owner`, `security_level` (nezáporná celá čísla), `tags`, `notify_emails` (přes `Raynet_Lead_Settings::sanitize_email_list()`), `success_message`, `redirect_url`.

- [ ] **Step 4: Spustit testy**

Run: `php tests/test-form-builder.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git commit -am "feat: sanitize per-form lead settings"
```

---

## Task 3: Typ obsahu a úložiště

**Files:**
- Create: `raynet-lead-api-integration/includes/class-raynet-form-post-type.php`
- Modify: `tests/wp-stubs.php`
- Modify: `tests/test-form-builder.php`

- [ ] **Step 1: Doplnit stuby příspěvků a meta**

```php
$GLOBALS['wp_posts'] = array();
$GLOBALS['wp_meta']  = array();

function register_post_type( ...$a ) {}
function get_post( $id ) { return isset( $GLOBALS['wp_posts'][ $id ] ) ? (object) $GLOBALS['wp_posts'][ $id ] : null; }
function get_post_meta( $id, $key, $single = false ) {
	$v = isset( $GLOBALS['wp_meta'][ $id ][ $key ] ) ? $GLOBALS['wp_meta'][ $id ][ $key ] : '';
	return $single ? $v : array( $v );
}
function update_post_meta( $id, $key, $value ) { $GLOBALS['wp_meta'][ $id ][ $key ] = $value; return true; }
function get_page_by_path( $slug, $output = OBJECT, $type = 'post' ) {
	foreach ( $GLOBALS['wp_posts'] as $id => $p ) {
		if ( $p['post_name'] === $slug && $p['post_type'] === $type ) { return (object) $p; }
	}
	return null;
}
function wp_insert_post( $args ) {
	$id = count( $GLOBALS['wp_posts'] ) + 1;
	$GLOBALS['wp_posts'][ $id ] = array_merge( array( 'ID' => $id, 'post_name' => sanitize_title( $args['post_title'] ?? '' ), 'post_status' => 'publish', 'post_type' => 'post' ), $args );
	$GLOBALS['wp_posts'][ $id ]['ID'] = $id;
	return $id;
}
define( 'OBJECT', 'OBJECT' );
```

- [ ] **Step 2: Napsat padající test**

```php
$id = Raynet_Lead_Form_Post_Type::create( 'Kontakt', Raynet_Lead_Form_Definition::default_fields(), array() );
check( 'formulář založen',        $id > 0, true );
check( 'pole uložena',            count( Raynet_Lead_Form_Post_Type::get_fields( $id ) ), 6 );
check( 'nastavení leadu prázdné', Raynet_Lead_Form_Post_Type::get_lead_settings( $id )['priority'], '' );

Raynet_Lead_Form_Post_Type::set_default( $id );
check( 'výchozí nalezen bez id',  Raynet_Lead_Form_Post_Type::resolve( '' ), $id );
check( 'nalezen podle čísla',     Raynet_Lead_Form_Post_Type::resolve( (string) $id ), $id );
check( 'nalezen podle slugu',     Raynet_Lead_Form_Post_Type::resolve( 'kontakt' ), $id );
check( 'neznámý slug = 0',        Raynet_Lead_Form_Post_Type::resolve( 'neexistuje' ), 0 );
check( 'koš = 0', ( $GLOBALS['wp_posts'][ $id ]['post_status'] = 'trash' ) && Raynet_Lead_Form_Post_Type::resolve( 'kontakt' ) === 0, true );
```

- [ ] **Step 3: Spustit a ověřit pád**

Run: `php tests/test-form-builder.php`
Expected: FAIL — třída neexistuje

- [ ] **Step 4: Implementovat**

```php
class Raynet_Lead_Form_Post_Type {
	const POST_TYPE     = 'raynet_form';
	const META_FIELDS   = '_raynet_form_fields';
	const META_LEAD     = '_raynet_form_lead';
	const OPTION_DEFAULT = 'raynet_lead_default_form';

	public static function register();                 // register_post_type na 'init'
	public static function create( $title, array $fields, array $lead );
	public static function get_fields( $post_id );     // vždy prožene sanitize_fields()
	public static function get_lead_settings( $post_id );
	public static function save_fields( $post_id, array $fields );
	public static function save_lead_settings( $post_id, array $lead );
	public static function resolve( $id );             // číslo | slug | '' => ID nebo 0
	public static function default_id();
	public static function set_default( $post_id );
}
```

`resolve()` vrací 0, když příspěvek neexistuje, není typu `raynet_form`, nebo není ve stavu `publish`.

- [ ] **Step 5: Spustit testy**

Run: `php tests/test-form-builder.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git commit -am "feat: add raynet_form post type and storage"
```

---

## Task 4: Renderer

**Files:**
- Create: `raynet-lead-api-integration/includes/class-raynet-form-renderer.php`
- Modify: `tests/test-form-builder.php`

- [ ] **Step 1: Napsat padající test**

```php
$html = Raynet_Lead_Form_Renderer::render_fields(
	array(
		array( 'id' => 'f_aaaaaa', 'source' => 'firstName', 'type' => 'text', 'label' => 'Křestní', 'placeholder' => '', 'help' => '', 'required' => false, 'width' => 'half', 'options' => array() ),
		array( 'id' => 'f_bbbbbb', 'source' => 'email', 'type' => 'email', 'label' => 'E-mail', 'placeholder' => 'a@b.cz', 'help' => 'Nápověda', 'required' => true, 'width' => 'full', 'options' => array() ),
		array( 'id' => 'f_cccccc', 'source' => 'custom', 'type' => 'select', 'label' => 'Odkud?', 'placeholder' => '', 'help' => '', 'required' => false, 'width' => 'full', 'options' => array( 'Google', 'Známý' ) ),
		array( 'id' => 'f_dddddd', 'source' => 'consent', 'type' => 'consent', 'label' => 'Souhlasím', 'placeholder' => '', 'help' => '', 'required' => true, 'width' => 'full', 'options' => array() ),
	),
	'raynet-form-1'
);

check( 'atribut RAYNETu má své jméno', str_contains( $html, 'name="firstName"' ), true );
check( 'vlastní pole má jmenný prostor', str_contains( $html, 'name="raynet_custom[f_cccccc]"' ), true );
check( 'souhlas se jmenuje consent',   str_contains( $html, 'name="consent"' ), true );
check( 'popisek správce vyhrál',       str_contains( $html, 'Křestní' ), true );
check( 'placeholder vykreslen',        str_contains( $html, 'placeholder="a@b.cz"' ), true );
check( 'nápověda vykreslena',          str_contains( $html, 'Nápověda' ), true );
check( 'povinné má required',          (bool) preg_match( '/name="email"[^>]*required/', $html ), true );
check( 'nepovinné nemá required',      (bool) preg_match( '/name="firstName"[^>]*required/', $html ), false );
check( 'půlená šířka má modifikátor',  str_contains( $html, 'raynet-lead-form__row--half' ), true );
check( 'select má obě možnosti',       substr_count( $html, '<option' ), 3 );
check( 'pořadí zachováno',             strpos( $html, 'name="firstName"' ) < strpos( $html, 'name="email"' ), true );
check( 'nic neescapovaného',           str_contains( $html, '<script' ), false );
```

- [ ] **Step 2: Spustit a ověřit pád**

Run: `php tests/test-form-builder.php`
Expected: FAIL — třída neexistuje

- [ ] **Step 3: Implementovat**

```php
class Raynet_Lead_Form_Renderer {
	public static function render_fields( array $fields, $form_id_attr );
	private static function render_one( array $field, $form_id_attr );
	private static function input_name( array $field );   // source, nebo raynet_custom[id], nebo consent
	private static function autocomplete( array $field );
}
```

Značkování drží dnešní třídy z `render_shortcode()`: `raynet-lead-form__row`, `__label`, `__required`, `__notification`. Přibývá `raynet-lead-form__row--half` a `raynet-lead-form__help`.

Popisek souhlasu projde `wp_kses_post()` (smí mít odkaz na zásady), všechno ostatní `esc_html()`.

- [ ] **Step 4: Spustit testy**

Run: `php tests/test-form-builder.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git commit -am "feat: render form fields from definitions"
```

---

## Task 5: Zkratka načítá formulář

**Files:**
- Modify: `raynet-lead-api-integration/includes/class-raynet-lead-form.php:136-248`
- Modify: `tests/test-render.php`

- [ ] **Step 1: Napsat padající test** do `tests/test-render.php`

```php
$fid = Raynet_Lead_Form_Post_Type::create( 'Kontakt', Raynet_Lead_Form_Definition::default_fields(), array() );
Raynet_Lead_Form_Post_Type::set_default( $fid );

$out = $form->render_shortcode( array() );
check( 'zkratka bez id vzala výchozí', str_contains( $out, 'name="raynet_form_id" value="' . $fid . '"' ), true );
check( 'e-mail vykreslen',             str_contains( $out, 'name="email"' ), true );

$byslug = $form->render_shortcode( array( 'id' => 'kontakt' ) );
check( 'nalezeno podle slugu', str_contains( $byslug, 'name="raynet_form_id"' ), true );

$zuzeno = $form->render_shortcode( array( 'fields' => 'email,message' ) );
check( 'fields= zúžilo',    preg_match_all( '/name="(firstName|phone)"/', $zuzeno ), 0 );
check( 'fields= nechalo e-mail', str_contains( $zuzeno, 'name="email"' ), true );

$chybi = $form->render_shortcode( array( 'id' => 'neexistuje' ) );
check( 'neznámý formulář nevypíše nic', trim( $chybi ), '' );
```

- [ ] **Step 2: Spustit a ověřit pád**

Run: `php tests/test-render.php`
Expected: FAIL — `raynet_form_id` ve výstupu není

- [ ] **Step 3: Implementovat**

`render_shortcode()` nově:
1. `Raynet_Lead_Form_Post_Type::resolve( $atts['id'] )`; při 0 vrátit prázdný řetězec a zapsat `raynet_lead_last_error`-obdobu pro admin hlášku.
2. `$fields = Raynet_Lead_Form_Post_Type::get_fields( $form_id )`.
3. Zastaralý filtr: `fields=` vybere a seřadí podmnožinu podle `source`, `required=` přepíše `required`.
4. Skryté `raynet_form_id`.
5. Tělo polí vydá `Raynet_Lead_Form_Renderer::render_fields()`.

Smazat z `Raynet_Lead_Form`: `field_labels()`, `input_type()`, `autocomplete_for()`, `consent_label()` — přesunuly se do definice a rendereru. `parse_field_list()` zůstává pro zastaralý filtr.

- [ ] **Step 4: Spustit celou suitu**

Run: `php tests/run.php`
Expected: všechny sady projdou

- [ ] **Step 5: Commit**

```bash
git commit -am "feat: resolve form by id or slug in the shortcode"
```

---

## Task 6: Odeslání respektuje definici

**Files:**
- Modify: `raynet-lead-api-integration/includes/class-raynet-lead-form.php:301-412,486-510,598-633`
- Modify: `tests/test-flow.php`

- [ ] **Step 1: Napsat padající test**

```php
// Formulář s vlastním polem a souhlasem.
$fields = Raynet_Lead_Form_Definition::sanitize_fields( array(
	array( 'source' => 'email', 'label' => 'E-mail', 'required' => true ),
	array( 'source' => 'message', 'label' => 'Zpráva', 'required' => true ),
	array( 'source' => 'custom', 'type' => 'text', 'label' => 'Odkud jste se o nás dozvěděli?' ),
	array( 'source' => 'consent', 'label' => 'Souhlasím se zpracováním' ),
) );
$fid = Raynet_Lead_Form_Post_Type::create( 'Kontakt', $fields, array() );
$custom_id = $fields[2]['id'];

$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":9}}' );
$r = process( $form, valid_input( array(
	'raynet_form_id' => (string) $fid,
	'raynet_custom'  => array( $custom_id => 'Google' ),
	'consent'        => '1',
) ) );
check( 'odesláno', code( $r ), 'OK' );
$sent = json_decode( $GLOBALS['wp_requests'][0]['args']['body'], true );
check( 'vlastní pole v poznámce', str_contains( $sent['notice'], 'Odkud jste se o nás dozvěděli?: Google' ), true );
check( 'souhlas v poznámce',      str_contains( $sent['notice'], 'Souhlas' ), true );

// Bez souhlasu se odmítne.
configure();
check( 'nezaškrtnutý souhlas', code( process( $form, valid_input( array( 'raynet_form_id' => (string) $fid, 'consent' => '' ) ) ) ), 'raynet_consent_required' );

// Formulář bez pole souhlasu nepíše o souhlasu, i když je globálně zapnutý.
configure();
$fid2 = Raynet_Lead_Form_Post_Type::create( 'Bez souhlasu', Raynet_Lead_Form_Definition::sanitize_fields( array(
	array( 'source' => 'email', 'label' => 'E-mail', 'required' => true ),
) ), array() );
$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":10}}' );
process( $form, valid_input( array( 'raynet_form_id' => (string) $fid2, 'consent' => '' ) ) );
$sent2 = json_decode( $GLOBALS['wp_requests'][0]['args']['body'], true );
check( 'žádná věta o souhlasu', str_contains( $sent2['notice'], 'Souhlas' ), false );

// Podvržená definice z prohlížeče se ignoruje.
configure();
$GLOBALS['wp_next_response'] = array( 'code' => 201, 'body' => '{"success":true,"data":{"id":11}}' );
process( $form, valid_input( array( 'raynet_form_id' => (string) $fid2, 'firstName' => 'Podvrh' ) ) );
$sent3 = json_decode( $GLOBALS['wp_requests'][0]['args']['body'], true );
check( 'pole mimo definici se zahodí', isset( $sent3['firstName'] ), false );
```

- [ ] **Step 2: Spustit a ověřit pád**

Run: `php tests/test-flow.php`
Expected: FAIL

- [ ] **Step 3: Implementovat**

- `collect_values( array $input, array $fields = array() )` — prázdné `$fields` znamená dnešní chování nad `SUPPORTED_FIELDS`. S definicemi sbírá jen pole, která jsou ve formuláři.
- `collect_custom( array $input, array $fields )` — vrátí `array( popisek => hodnota )` z `$input['raynet_custom']`, jen pro `id` z definice.
- `build_notice( array $values, array $settings, $source_url, array $extras = array(), $has_consent = false )`.
- `process()` načte `raynet_form_id`, přes `Raynet_Lead_Form_Post_Type::get_fields()` získá definici, sloučí nastavení leadu formuláře přes globální.
- Souhlas se kontroluje jen tehdy, když je ve formuláři pole `consent`.
- Kontrola e-mail-nebo-telefon platí dál bez ohledu na definici.

- [ ] **Step 4: Spustit celou suitu**

Run: `php tests/run.php`
Expected: všechno projde

- [ ] **Step 5: Commit**

```bash
git commit -am "feat: drive submission from the stored form definition"
```

---

## Task 7: Migrace

**Files:**
- Modify: `raynet-lead-api-integration/includes/class-raynet-settings.php`
- Modify: `tests/test-form-builder.php`

- [ ] **Step 1: Napsat padající test**

```php
$GLOBALS['wp_options'] = array(); $GLOBALS['wp_posts'] = array(); $GLOBALS['wp_meta'] = array();
update_option( Raynet_Lead_Settings::OPTION, Raynet_Lead_Settings::sanitize( array(
	'region' => 'cz', 'username' => 'u@e.cz', 'api_key' => 'K', 'instance_name' => 'i',
	'consent_enabled' => '1', 'notice_prefix' => 'Z webu', 'category' => '5',
) ) );

Raynet_Lead_Settings::maybe_migrate_forms();
$fid = Raynet_Lead_Form_Post_Type::default_id();
check( 'výchozí formulář vznikl', $fid > 0, true );

$f = Raynet_Lead_Form_Post_Type::get_fields( $fid );
$sources = array_column( $f, 'source' );
check( 'stejná pole jako zkratka', array_slice( $sources, 0, 5 ), array( 'firstName', 'lastName', 'email', 'phone', 'message' ) );
check( 'souhlas přidán',           in_array( 'consent', $sources, true ), true );
check( 'e-mail povinný',           $f[2]['required'], true );

$lead = Raynet_Lead_Form_Post_Type::get_lead_settings( $fid );
check( 'kategorie převzata', $lead['category'], 5 );

Raynet_Lead_Settings::maybe_migrate_forms();
check( 'migrace běží jednou', count( $GLOBALS['wp_posts'] ), 1 );
```

- [ ] **Step 2: Spustit a ověřit pád**

Run: `php tests/test-form-builder.php`
Expected: FAIL — metoda neexistuje

- [ ] **Step 3: Implementovat**

`Raynet_Lead_Settings::maybe_migrate_forms()`:
- hlídá volbu `raynet_lead_migrated_forms`,
- pole vezme z `Raynet_Lead_Form_Definition::default_fields()`, souhlas zařadí jen při zapnutém `consent_enabled`, popisek souhlasu z `consent_label`,
- povinnost nastaví podle dnešního výchozího `required="email,message"`,
- nastavení leadu převezme `category`, `lead_phase`, `contact_source`, `owner`, `security_level`, `tags`, `notify_emails`, `notice_prefix`, `priority`, `lead_person`, `success_message`, `redirect_url`,
- formulář označí jako výchozí.

Volat z `raynet_lead_bootstrap()` hned za `maybe_migrate()`.

- [ ] **Step 4: Spustit testy**

Run: `php tests/run.php`
Expected: všechno projde

- [ ] **Step 5: Commit**

```bash
git commit -am "feat: migrate shortcode defaults into a default form"
```

---

## Task 8: Obrazovka builderu

**Files:**
- Create: `raynet-lead-api-integration/includes/class-raynet-form-builder-admin.php`
- Create: `raynet-lead-api-integration/assets/js/raynet-form-builder.js`
- Create: `raynet-lead-api-integration/assets/css/raynet-form-builder.css`

Tato úloha nemá automatické testy — obrazovka i přetahování vyžadují živý WordPress. Testuje se ručně podle § Ruční zkouška.

- [ ] **Step 1: Metaboxy**

`Raynet_Lead_Form_Builder_Admin::register()` napojí:
- `add_meta_boxes` — box „Pole formuláře" (kontext `normal`, priorita `high`) a „Nastavení leadu" (kontext `side`),
- `save_post_raynet_form` — ukládání s nonce, kontrolou `manage_options` a přeskočením `DOING_AUTOSAVE`,
- `admin_enqueue_scripts` — skripty jen na obrazovce úprav `raynet_form`,
- `wp_ajax_raynet_lead_preview`.

Box „Pole formuláře" vypíše `<input type="hidden" name="raynet_form_fields_json">` s aktuální definicí, prázdný `<div id="raynet-builder">` a `wp_nonce_field( 'raynet_form_save', 'raynet_form_nonce' )`.

Do `wp_localize_script` jde katalog zdrojů, popisky a `ajaxUrl` s nonce náhledu.

- [ ] **Step 2: JS builderu**

`assets/js/raynet-form-builder.js`, čisté JS:
- stav drží pole objektů, zdroj pravdy je skryté JSON pole,
- vykreslí seznam řádků s `draggable="true"`, obslouží `dragstart` / `dragover` / `drop`,
- šipky nahoru a dolů dělají totéž pro klávesnici,
- klik na řádek rozbalí editaci; změna pole zapíše do stavu a přepíše JSON,
- rozbalovací seznam přidání nabízí jen nepoužité zdroje plus „Vlastní pole",
- po změně s odkladem 400 ms požádá o náhled.

- [ ] **Step 3: AJAX náhledu**

`handle_preview()`: `check_ajax_referer( 'raynet_lead_preview' )`, `current_user_can( 'manage_options' )`, definici prožene `Raynet_Lead_Form_Definition::sanitize_fields()` a vrátí `Raynet_Lead_Form_Renderer::render_fields()`. Náhled nevydává nonce, časovou značku ani honeypot.

- [ ] **Step 4: CSS**

`assets/css/raynet-form-builder.css` ve vizuálu wp-adminu podle schváleného mockupu.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat: add the form builder admin screen"
```

---

## Task 9: CSS půlené šířky

**Files:**
- Modify: `raynet-lead-api-integration/assets/css/raynet-lead-form.css`

- [ ] **Step 1: Nahradit jmenovité pravidlo**

Smazat blok `@media (min-width: 40rem)` s `--firstName` / `--lastName` a nahradit:

```css
@media (min-width: 40rem) {
	.raynet-lead-form__row--half {
		display: inline-block;
		width: calc(50% - 0.5rem);
		vertical-align: top;
	}

	.raynet-lead-form__row--half + .raynet-lead-form__row--half {
		margin-left: 0.9rem;
	}
}
```

Přidat styl `.raynet-lead-form__help` — menší písmo, tlumená barva s kontrastem alespoň 4,5:1.

- [ ] **Step 2: Commit**

```bash
git commit -am "style: generic half-width modifier for form rows"
```

---

## Task 10: Zapojení a úklid nastavení

**Files:**
- Modify: `raynet-lead-api-integration/raynet-lead-api-integration.php`
- Modify: `raynet-lead-api-integration/includes/class-raynet-admin.php`

- [ ] **Step 1: Bootstrap**

Přidat `require_once` pro čtyři nové třídy, zvednout `RAYNET_LEAD_VERSION` a hlavičku na `2.1.0`, v `raynet_lead_bootstrap()` zavolat `Raynet_Lead_Form_Post_Type::register()`, `Raynet_Lead_Settings::maybe_migrate_forms()` a v administraci `( new Raynet_Lead_Form_Builder_Admin() )->register()`.

- [ ] **Step 2: Odstranit sekci souhlasu ze stránky nastavení**

Ze `render_page()` vyjmout řádek se souhlasem. Klíče `consent_enabled` a `consent_label` zůstávají v `defaults()` a v `sanitize()` — migrace je čte a `tests/test-admin.php` kontroluje, že každý klíč má pole. Proto test upravit: oba klíče vyjmout z očekávané množiny a doplnit kontrolu, že se po uložení nemění.

- [ ] **Step 3: Spustit celou suitu**

Run: `php tests/run.php`
Expected: všechno projde

- [ ] **Step 4: Commit**

```bash
git commit -am "feat: wire the builder into the plugin bootstrap"
```

---

## Task 11: Dokumentace a vydání

**Files:**
- Modify: `README.md`, `CHANGELOG.md`

- [ ] **Step 1: README**

Nová sekce „Builder formulářů" před „Vložení formuláře": jak formulář založit, co se dá u pole nastavit, kam jdou vlastní pole. Sekci „Atributy zkratky" přepsat — `fields=` a `required=` označit jako zastaralé s doporučením založit formulář.

- [ ] **Step 2: CHANGELOG**

Záznam `## [2.1.0]` s oddíly Přidáno, Změněno a poznámkou o migraci.

- [ ] **Step 3: Spustit celou suitu a lint**

```bash
php tests/run.php
for f in $(find raynet-lead-api-integration -name '*.php'); do php -l "$f" >/dev/null || echo "FAIL $f"; done
node --check raynet-lead-api-integration/assets/js/raynet-form-builder.js
```

- [ ] **Step 4: Commit**

```bash
git commit -am "docs: document the form builder"
```

---

## Ruční zkouška

Bez živého WordPressu se neověří. Po nasazení projít:

1. Menu RAYNET CRM obsahuje Formuláře; migrace založila jeden výchozí.
2. Stránka se starou zkratkou `[raynet_lead_form]` vypadá stejně jako před aktualizací.
3. V builderu jde pole přidat, přetáhnout, přejmenovat a odebrat; náhled se překreslí.
4. Šipky nahoru a dolů mění pořadí bez myši.
5. Vlastní pole typu výběr se vykreslí a jeho hodnota dorazí do poznámky leadu.
6. Formulář bez pole souhlasu nezapíše do poznámky větu o souhlasu.
7. Zkratka s neznámým `id` nevypíše návštěvníkovi nic a správci ukáže hlášku.

---

## Self-review

**Pokrytí spec:** § 3 datový model → Task 1–3. § 4 builder → Task 8. § 5 vykreslení → Task 4–5, 9. § 6 odeslání → Task 6. § 7 zpětná kompatibilita → Task 5 (filtr `fields=`) a Task 7 (migrace). § 8 chybové stavy → Task 5 a 6. § 9 testování → Task 1–7, 10. § 10 mimo rozsah → nic se nestaví.

**Placeholdery:** žádné „doplnit později"; Task 8 vědomě nemá automatické testy a důvod je uveden.

**Konzistence jmen:** `sanitize_fields`, `sanitize_lead_settings`, `default_fields`, `catalogue`, `type_for_source` na definici; `create`, `get_fields`, `get_lead_settings`, `resolve`, `default_id`, `set_default` na typu obsahu; `render_fields` na rendereru. Použito shodně napříč úlohami.
