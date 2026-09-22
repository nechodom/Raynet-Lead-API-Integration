# RAYNET Lead API Integration

WordPress plugin, který posílá poptávky z webového formuláře přímo do [RAYNET CRM](https://raynet.cz) jako **Leady**, přes oficiální [REST API v2](https://app.raynet.cz/api/doc/).

> **Verze 2.0.0 je bezpečnostní přepis.** Verze 1.x posílala API klíč do prohlížeče a volala RAYNET přímo z JavaScriptu. Kdokoli si mohl ve zdrojovém kódu stránky přečíst přihlašovací údaje a získat plný přístup k vašemu CRM. Ve verzi 2 komunikuje s RAYNETem výhradně server. Viz [Co se změnilo](#co-se-změnilo-oproti-verzi-1x).

---

## Obsah

- [Funkce](#funkce)
- [Požadavky](#požadavky)
- [Instalace](#instalace)
- [Nastavení](#nastavení)
- [Vložení formuláře](#vložení-formuláře)
- [Atributy zkratky](#atributy-zkratky)
- [Jak vypadá lead v RAYNETu](#jak-vypadá-lead-v-raynetu)
- [Ochrana proti spamu](#ochrana-proti-spamu)
- [GDPR](#gdpr)
- [Hooky pro vývojáře](#hooky-pro-vývojáře)
- [REST endpoint](#rest-endpoint)
- [Řešení potíží](#řešení-potíží)
- [Co se změnilo oproti verzi 1.x](#co-se-změnilo-oproti-verzi-1x)
- [Vývoj a testy](#vývoj-a-testy)
- [Licence](#licence)

---

## Funkce

- **Zkratka `[raynet_lead_form]`** — formulář vložíte do libovolné stránky, příspěvku nebo widgetu.
- **Přihlašovací údaje zůstávají na serveru.** Prohlížeč mluví jen s WordPressem.
- **Podpora všech čtyř regionů RAYNETu** (`.cz`, `.sk`, `.com`, `eu.`) i vlastní adresy.
- **Test spojení** přímo v administraci — ověří údaje proti `GET /security/info` a rovnou vypíše ID číselníků (kategorie, stav leadu, zdroj kontaktu).
- **Ochrana proti spamu**: honeypot, časová past a omezení frekvence na IP adresu.
- **Souhlas se zpracováním údajů** (GDPR) včetně zápisu data souhlasu do poznámky leadu.
- **Záložní e-mail** — když RAYNET lead nepřijme, poptávka dorazí na zadanou adresu a neztratí se.
- **Skutečná kontrola chyb** — plugin rozlišuje HTTP 201, 401, 429 i výpadek spojení a chybu zapíše do logu.
- **Hooky a filtry** pro úpravu payloadu i navázání vlastní logiky.
- Plně lokalizovatelné, čeština v základu.

---

## Požadavky

| | |
|---|---|
| WordPress | 5.6 a vyšší |
| PHP | 7.4 a vyšší |
| RAYNET CRM | účet s vygenerovaným API klíčem |

API klíč vygenerujete v RAYNET CRM v **Nastavení → Uživatelé → *váš uživatel* → API klíč**.

---

## Instalace

### Z archivu

1. Stáhněte nebo naklonujte tento repozitář.
2. Zabalte složku `raynet-lead-api-integration/` do ZIP archivu.
3. Ve WordPressu jděte na **Pluginy → Instalace pluginů → Nahrát plugin** a archiv nahrajte.
4. Plugin aktivujte.

### Přes Git

```bash
cd wp-content/plugins
git clone https://github.com/nechodom/Raynet-Lead-API-Integration.git raynet-lead-tmp
mv raynet-lead-tmp/raynet-lead-api-integration .
rm -rf raynet-lead-tmp
```

> Do `wp-content/plugins/` patří **podsložka `raynet-lead-api-integration/`**, ne celý repozitář.

---

## Nastavení

Po aktivaci přibude v administraci položka **RAYNET CRM**.

### Připojení

| Pole | Co vyplnit |
|---|---|
| **Region API** | Doména, na které běží vaše instance. Pro české účty `https://app.raynet.cz/api/v2/`. |
| **Vlastní adresa API** | Jen při volbě „Vlastní adresa“. Včetně `/api/v2/`. |
| **Uživatelské jméno** | E-mail uživatele, na kterého je API klíč vydaný. |
| **API klíč** | Klíč z RAYNETu. Pole se nikdy nevypisuje zpět — prázdné pole při uložení znamená „ponechat stávající klíč“. |
| **Název instance** | Z adresy `https://app.raynet.cz/mujraynet/` je to `mujraynet`. Posílá se jako hlavička `X-Instance-Name`. |
| **ID instance** | Volitelné. Má přednost před názvem, posílá se jako `X-Instance-Id`. |
| **Timeout** | Kolik sekund čekat na odpověď RAYNETu (5–60). |

Nastavení uložte a klikněte na **Otestovat spojení**. Test běží proti uloženým hodnotám, takže nejdřív ukládejte, pak testujte.

### Výchozí hodnoty leadu

Priorita (`MINOR` / `DEFAULT` / `CRITICAL`), výchozí předmět, poznámka vkládaná před zprávu návštěvníka, štítky, notifikační e-maily a ID z číselníků RAYNETu — kategorie, stav leadu, zdroj kontaktu, vlastník, bezpečnostní úroveň. Hodnota `0` znamená „tento atribut neposílat“ a RAYNET si doplní výchozí.

**ID číselníků nemusíte hledat ručně** — tlačítko *Otestovat spojení* je po úspěšném přihlášení vypíše.

### Chování formuláře

Hlášky po odeslání i při chybě, přesměrování po úspěchu, text a povinnost souhlasu se zpracováním údajů, nastavení ochrany proti spamu, záložní e-mail, zapisování chyb do logu a mazání dat při odinstalaci.

---

## Vložení formuláře

```
[raynet_lead_form]
```

Výchozí formulář obsahuje pole Jméno, Příjmení, E-mail, Telefon, Předmět a Zpráva; povinné jsou E-mail a Zpráva.

Další příklady:

```
[raynet_lead_form title="Napište nám" button="Odeslat poptávku"]

[raynet_lead_form fields="firstName,lastName,email,phone,companyName,message" required="email,phone"]

[raynet_lead_form topic="Poptávka ze stránky Ceník" fields="email,message"]

[raynet_lead_form fields="email,message" redirect="https://example.cz/dekujeme/"]
```

V PHP šabloně:

```php
echo do_shortcode( '[raynet_lead_form topic="Kontakt z patičky" fields="email,message"]' );
```

---

## Atributy zkratky

| Atribut | Výchozí | Popis |
|---|---|---|
| `fields` | `firstName,lastName,email,phone,topic,message` | Která pole vykreslit, v tomto pořadí. |
| `required` | `email,message` | Která z vykreslených polí jsou povinná. |
| `topic` | – | Pevný předmět leadu. Pole „Předmět“ se pak nevykreslí. |
| `title` | – | Nadpis nad formulářem. |
| `button` | `Odeslat` | Popisek odesílacího tlačítka. |
| `class` | – | Další CSS třída formuláře. |
| `redirect` | hodnota z nastavení | URL, kam přesměrovat po úspěšném odeslání. |

**Dostupná pole:** `firstName`, `lastName`, `companyName`, `email`, `phone`, `topic`, `message`, `street`, `city`, `zipCode`.

Server vždy vyžaduje **e-mail nebo telefon**, i kdyby v `required` nebyly — bez kontaktu je lead k ničemu.

---

## Jak vypadá lead v RAYNETu

Plugin volá `PUT /api/v2/lead/` a mapuje pole takto:

| Pole formuláře | Atribut RAYNETu |
|---|---|
| Předmět / atribut `topic` / výchozí předmět | `topic` *(povinné)* |
| nastavení | `priority` *(povinné)* |
| – | `leadDate` — dnešní datum |
| Jméno | `firstName` |
| Příjmení | `lastName` |
| Společnost | `companyName` |
| E-mail | `contactInfo.email` |
| Telefon | `contactInfo.tel1` |
| Ulice / Město / PSČ | `address.street` / `address.city` / `address.zipCode` |
| Zpráva + poznámka z nastavení + URL stránky + datum souhlasu | `notice` |
| nastavení | `category`, `leadPhase`, `contactSource`, `owner`, `securityLevel`, `tags` |
| nastavení | `notificationEmailAddresses`, `notificationMessage` |

Příznak `leadPerson` se řídí nastavením, ale jakmile návštěvník vyplní **Společnost**, lead se založí jako firma.

Prázdné atributy se do RAYNETu vůbec neposílají.

---

## Ochrana proti spamu

Tři nezávislé vrstvy, všechny vypnutelné v nastavení:

1. **Honeypot** — skryté pole `website`. Vyplněné pole = robot.
2. **Časová past** — formulář nelze odeslat dřív než N sekund po načtení stránky. Časová značka je podepsaná přes `wp_hash()`, takže ji nelze podvrhnout.
3. **Omezení frekvence** — z jedné IP adresy nejvýš jedno odeslání za N sekund, drženo v transientu.

K tomu WordPress nonce. Pokud běží na webu plná cache stránek a nonce vyprší, JavaScript si jednou automaticky vyžádá nový a odeslání zopakuje — návštěvník nic nepozná.

---

## GDPR

- Zaškrtávátko souhlasu je v základu zapnuté a povinné; jeho text si nastavíte včetně odkazů.
- Datum a čas souhlasu se zapíše do poznámky leadu.
- Plugin **neukládá odeslaná data do databáze WordPressu**. Jdou rovnou do RAYNETu.
- IP adresa se používá jen pro omezení frekvence, ukládá se jako hash v transientu a do CRM se neposílá.
- Do poznámky leadu jde URL stránky, ze které poptávka přišla.

---

## Hooky pro vývojáře

### `raynet_lead_payload` (filtr)

Poslední místo před odesláním do RAYNETu.

```php
add_filter( 'raynet_lead_payload', function ( $payload, $values ) {
	$payload['customFields'] = array(
		'ZDROJ' => 'web',
	);

	if ( str_contains( $values['message'], 'urgentní' ) ) {
		$payload['priority'] = 'CRITICAL';
	}

	return $payload;
}, 10, 2 );
```

### `raynet_lead_created` (akce)

```php
add_action( 'raynet_lead_created', function ( $lead_id, $payload ) {
	error_log( 'Nový lead #' . $lead_id . ': ' . $payload['topic'] );
}, 10, 2 );
```

### `raynet_lead_failed` (akce)

```php
add_action( 'raynet_lead_failed', function ( WP_Error $error, $payload ) {
	// Vlastní notifikace, zápis do fronty k opakování apod.
}, 10, 2 );
```

---

## REST endpoint

Vedle `admin-ajax.php` je k dispozici i REST route — hodí se pro vlastní frontend nebo headless WordPress:

```
POST /wp-json/raynet-lead/v1/submit
```

Tělo požadavku musí obsahovat stejná pole jako formulář, včetně `raynet_nonce`, `raynet_ts` a `raynet_ts_hash`. Nejjednodušší je nechat je vykreslit zkratkou a přečíst si je ze skrytých polí.

---

## Řešení potíží

| Příznak | Příčina a řešení |
|---|---|
| **„Odeslání formuláře se nezdařilo“** a v logu `401` | Špatný e-mail, API klíč nebo název instance. Pozor: po 20 neúspěšných pokusech blokuje RAYNET vaši IP na 60 minut. |
| V logu `404` | Špatně zvolený region. Česká instance běží na `app.raynet.cz`, slovenská na `app.raynetcrm.sk`. |
| V logu `429` | Překročený limit API (24 000 požadavků/den, max. 4 souběžná spojení). |
| **„Platnost formuláře vypršela“** | Nonce vypršel. Plugin se zotaví sám; pokud ne, zkontrolujte, že cache nekešuje i `admin-ajax.php`. |
| **„Formulář byl odeslán příliš rychle“** | Časová past. Snižte „Minimální doba vyplňování“ nebo ji vypněte nulou. |
| **Formulář se nevykreslí** | Zkratka musí být v obsahu stránky, ne v úryvku. V blokovém editoru použijte blok *Zkratka*. |
| **Tlačítko nereaguje** | Chyba v konzoli prohlížeče. Nejčastěji jiný plugin rozbil načítání skriptů. |
| **Test spojení hlásí chybu, ale údaje jsou správné** | Nastavení nejdřív **uložte**, teprve pak testujte. |

Chyby API se zapisují do PHP logu s prefixem `[Raynet Lead API Integration]`. Poslední chyba se navíc zobrazuje nahoře na stránce nastavení. Pro zapnutí logu WordPressu:

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

---

## Co se změnilo oproti verzi 1.x

| | 1.x | 2.0 |
|---|---|---|
| Kde jsou přihlašovací údaje | **V prohlížeči** — `wp_localize_script()` je vypsal do zdrojového kódu každé stránky | Jen na serveru |
| Kdo volá RAYNET | JavaScript návštěvníka (přes doménu RAYNETu, což CORS stejně blokoval) | PHP na serveru |
| Serverový AJAX handler | Byl zaregistrovaný, ale JavaScript ho nikdy nezavolal; neměl nonce ani validaci | Jediná cesta k odeslání, s nonce, sanitizací a limity |
| Kontrola odpovědi | Žádná — HTTP 401 se tvářilo jako úspěch | Rozlišuje 201, 4xx, 5xx i výpadek spojení |
| Zpráva z formuláře | **Zahazovala se** — do CRM šla jen statická poznámka z nastavení | Jde do `notice` spolu s poznámkou a URL stránky |
| Formulář | Samostatný `form.html` k ručnímu zkopírování | Zkratka `[raynet_lead_form]` s volitelnými poli |
| Adresa API | Napevno v kódu, přestože nastavení mělo pole „API URL“ | Výběr regionu nebo vlastní adresa |
| Načítání skriptů | Na každé stránce webu | Jen tam, kde je formulář |
| Ochrana proti spamu | Žádná | Honeypot, časová past, limit na IP |
| API klíč v administraci | Textové pole, klíč viditelný | Pole typu `password`, klíč se nevypisuje zpět |

**Aktualizace z 1.x:** nastavení se převede automaticky při první aktivaci verze 2 — včetně API klíče, názvu instance a vlastní poznámky. Původní formulář z `form.html` nahraďte zkratkou `[raynet_lead_form]`.

> **Doporučení:** pokud jste verzi 1.x provozovali na veřejném webu, **vygenerujte v RAYNETu nový API klíč a starý zneplatněte.** Ten původní byl viditelný ve zdrojovém kódu stránek a mohl být zachycen.

---

## Vývoj a testy

Repozitář obsahuje testy, které plugin proženou bez instalace WordPressu — `tests/wp-stubs.php` nahrazuje potřebné funkce jádra.

```bash
php tests/run.php
```

Pokrývají sanitizaci nastavení, převod konfigurace z 1.x, sestavení payloadu pro RAYNET, zpracování odpovědí API, celý průchod odeslání včetně ochran proti spamu, vykreslení zkratky a stránku nastavení. Testy vyžadují PHP 8.0+; samotný plugin běží od PHP 7.4.

Historie změn je v [CHANGELOG.md](CHANGELOG.md).

---

## Licence

MIT — viz [LICENSE](LICENSE).

Autor: Matěj Kevin Nechodom
