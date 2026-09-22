# RAYNET Lead API Integration

WordPress plugin, který posílá poptávky z webového formuláře přímo do [RAYNET CRM](https://raynet.cz) jako **Leady**, přes oficiální [REST API v2](https://app.raynet.cz/api/doc/).

> **Verze 2.0.0 je bezpečnostní přepis.** Verze 1.x posílala API klíč do prohlížeče a volala RAYNET přímo z JavaScriptu. Kdokoli si mohl ve zdrojovém kódu stránky přečíst přihlašovací údaje a získat plný přístup k vašemu CRM. Ve verzi 2 komunikuje s RAYNETem výhradně server. Viz [Co se změnilo](#co-se-změnilo-oproti-verzi-1x).

---

## Obsah

- [Funkce](#funkce)
- [Požadavky](#požadavky)
- [Instalace](#instalace)
- [Nastavení](#nastavení)
- [Aktualizace](#aktualizace)
- [Builder formulářů](#builder-formulářů)
- [Elementor Pro Forms](#elementor-pro-forms)
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

- **Builder formulářů** — pole vyberete, přetažením seřadíte a přepíšete jim popisky, bez editoru kódu. Formulářů můžete mít víc, každý s vlastním nastavením leadu.
- **Zkratka `[raynet_lead_form]`** — formulář vložíte do libovolné stránky, příspěvku nebo widgetu.
- **Elementor Pro Forms** — leady umí zakládat i formuláře postavené v Elementoru, přes akci po odeslání.
- **Přihlašovací údaje zůstávají na serveru.** Prohlížeč mluví jen s WordPressem.
- **Podpora všech čtyř regionů RAYNETu** (`.cz`, `.sk`, `.com`, `eu.`) i vlastní adresy.
- **Test spojení** přímo v administraci — ověří údaje proti `GET /security/info` a rovnou vypíše ID číselníků (kategorie, stav leadu, zdroj kontaktu).
- **Ochrana proti spamu**: honeypot, časová past a omezení frekvence na IP adresu.
- **Souhlas se zpracováním údajů** (GDPR) včetně zápisu data souhlasu do poznámky leadu.
- **Záložní e-mail** — když RAYNET lead nepřijme, poptávka dorazí na zadanou adresu a neztratí se.
- **Skutečná kontrola chyb** — plugin rozlišuje HTTP 201, 401, 429 i výpadek spojení a chybu zapíše do logu.
- **Hooky a filtry** pro úpravu payloadu i navázání vlastní logiky.
- **Aktualizace přímo ve WordPressu** — plugin se hlásí o nové verze z GitHubu, včetně automatické aktualizace.
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

## Aktualizace

Plugin není na wordpress.org, takže si o nové verze říká sám — sleduje vydané verze v tomto repozitáři.

V **RAYNET CRM → Nastavení → Aktualizace** vidíte nainstalovanou i poslední vydanou verzi a tlačítkem **Zkontrolovat aktualizace** se zeptáte hned. Jinak se plugin ptá dvakrát denně, spolu s tím, jak WordPress kontroluje ostatní pluginy.

Nová verze se pak nabídne na stránce **Pluginy** jako u každého jiného pluginu, včetně odkazu **Zapnout automatické aktualizace**.

### Co to dělá a co ne

- Dotaz jde na veřejné API GitHubu, odpověď se drží 12 hodin v cache. Bez tokenu, bez odesílání čehokoliv o vašem webu.
- Instaluje se výhradně archiv přiložený k vydané verzi na `github.com`. Odkaz kamkoliv jinam se odmítne.
- Když je GitHub nedostupný nebo vyčerpá limit dotazů, kontrola tiše selže a stránka Pluginy funguje dál.
- Kontrolu vypnete zaškrtávátkem v nastavení.

> Verze 2.1.0 a starší tohle neumí. Z nich je potřeba přejít ručně; od 2.2.0 už to jde z administrace.
>
> **Verze 2.1.0 a 2.2.0 obsahují fatální chybu, která shodí web.** Pokud na některé z nich jste, nahrajte ručně 2.2.1.

---

## Builder formulářů

V administraci přibude **RAYNET CRM → Formuláře**. Každý formulář má vlastní pole i vlastní nastavení leadu, takže kontaktní stránka může zakládat leady pod jinou kategorií než poptávka ceníku.

### Pole

Vlevo je seznam polí, vpravo náhled, který vykresluje server stejným kódem jako ostrý formulář — co vidíte, to návštěvník dostane.

Pořadí změníte přetažením, nebo šipkami, když nemáte myš. Kliknutím na pole ho rozbalíte a nastavíte:

| Vlastnost | Platí pro |
|---|---|
| Popisek | všechna pole |
| Placeholder | textová pole |
| Nápověda pod polem | všechna kromě souhlasu |
| Povinné | všechna kromě souhlasu, ten je povinný vždy |
| Šířka (celá / poloviční) | jednořádková pole |
| Typ a možnosti | jen vlastní pole |

### Druhy polí

**Pole RAYNETu** — deset atributů leadu: Jméno, Příjmení, Společnost, E-mail, Telefon, Předmět, Zpráva, Ulice, Město, PSČ. Každý smí být ve formuláři jen jednou a jeho typ se měnit nedá; přepnutí e-mailu na dlouhý text by rozbilo validaci i mapování.

**Vlastní pole** — cokoliv, co v RAYNETu vlastní atribut nemá: „Odkud jste se o nás dozvěděli?", „Počet zaměstnanců", zaškrtávátko „Chci newsletter". Na výběr je jednořádkový text, víceřádkový text, výběr z možností a zaškrtávátko. **Hodnota se zapíše do poznámky leadu** pod popiskem, který jste poli dali:

```
Odkud jste se o nás dozvěděli?: Google
Chci newsletter: ne
```

U zaškrtávátka se zapíše i odpověď „ne" — u dotazu na newsletter je to ta zajímavější.

**Souhlas se zpracováním** — zaškrtávátko GDPR. Jeho text smí obsahovat odkaz na zásady. Formulář ho může mít nejvýš jeden a je vždy povinný; nepovinné zaškrtávátko souhlasu je horší než žádné. Do poznámky leadu se zapíše datum a čas udělení.

> Souhlas se od verze 2.1 nastavuje na formuláři, ne v nastavení pluginu. Formulář bez pole souhlasu do CRM nenapíše, že souhlas padl.

### Nastavení leadu

V pravém sloupci nastavíte pro tenhle formulář předmět, prioritu, typ leadu, předponu poznámky, ID z číselníků RAYNETu, štítky, notifikační e-maily, hlášku po odeslání a přesměrování.

**Prázdné pole znamená zdědit z nastavení pluginu.** U číselníkových ID znamená totéž nula. Globální hodnoty tak zůstávají na jednom místě a formulář z nich jen vybočuje tam, kde potřebuje.

### Výchozí formulář

Jeden formulář lze označit jako **výchozí**. Ten obslouží zkratku `[raynet_lead_form]` bez atributu `id`.

---

## Elementor Pro Forms

Formuláře postavené v Elementoru mohou leady zakládat taky. Není k tomu potřeba vlastní pole — plugin přidá **akci po odeslání**, stejným mechanismem, jakým fungují vestavěné integrace na Mailchimp nebo HubSpot.

> Vyžaduje **Elementor Pro**. Widget Formulář ve free verzi není.

### Zapnutí

Ve widgetu formuláře otevřete **Actions After Submit** a přidejte **RAYNET CRM**. Objeví se sekce se dvěma částmi.

**Mapování polí** — ke každému atributu RAYNETu vyberete pole formuláře. Nabídka ukazuje popisky polí, ne jejich ID. Nenamapované atributy se neodesílají.

**Nastavení leadu** — předmět, priorita, typ leadu, předpona poznámky, číselníková ID, štítky a notifikační e-maily, zvlášť pro tenhle formulář. Prázdné pole znamená zdědit z nastavení pluginu, stejně jako u builderu.

Dva přepínače navíc:

- **Zapsat udělení souhlasu** — zapněte jen tehdy, když formulář obsahuje pole se souhlasem. Do poznámky leadu se pak zapíše datum a čas. Vypnuté je záměrně: formulář, který se na souhlas neptá, nesmí do CRM napsat, že padl.
- **Uvést URL stránky** — připíše do poznámky adresu stránky, ze které poptávka přišla.

### Co obstarává Elementor a co plugin

Validaci, nonce, reCAPTCHA i honeypot řeší Elementor. Plugin je **znovu nekontroluje** — vlastní časová past ani limit na IP by se s Elementorem tloukly a odmítaly by legitimní odeslání.

Co zůstává na pluginu, je pravidlo RAYNETu, které Elementor nezná: **lead musí nést e-mail nebo telefon**. Bez jednoho z nich se odeslání odmítne.

### Když se odeslání nepovede

Návštěvník uvidí chybovou hlášku, kterou si nastavíte přímo v Elementoru (*Additional Options → Custom Messages → Error Message*).

Skutečný důvod — třeba `RAYNET odmítl přihlášení (401)` — se zobrazí **jen přihlášenému uživateli s právem stránku editovat**. Elementor na to má vlastní kanál a diagnostika se tak k návštěvníkovi nedostane.

Lead navíc zachytí záložní e-mail, pokud ho máte v nastavení vyplněný. U Elementoru to doporučuji dvojnásob: odeslání je synchronní, žádná fronta na opakování neexistuje.

### Export šablony

Při exportu formuláře jako šablony se mapování polí, číselníková ID, vlastník, štítky i notifikační e-maily odstraní. Jsou vázané na jednu instanci RAYNETu a v cizím webu by zakládaly leady pod cizí kategorií. Přihlašovací údaje v nastavení Elementoru vůbec nejsou — zůstávají v nastavení pluginu.

---

## Vložení formuláře

Výchozí formulář:

```
[raynet_lead_form]
```

Konkrétní formulář podle jeho zkratky, kterou najdete na jeho editační obrazovce:

```
[raynet_lead_form id="kontakt"]
```

Další příklady:

```
[raynet_lead_form id="cenik" title="Napište nám" button="Odeslat poptávku"]

[raynet_lead_form id="kontakt" topic="Poptávka ze stránky Ceník"]

[raynet_lead_form id="kontakt" redirect="https://example.cz/dekujeme/"]
```

V PHP šabloně:

```php
echo do_shortcode( '[raynet_lead_form id="paticka" topic="Kontakt z patičky"]' );
```

---

## Atributy zkratky

| Atribut | Výchozí | Popis |
|---|---|---|
| `id` | výchozí formulář | Který formulář vykreslit. Přijímá zkratku (slug) i číselné ID. |
| `topic` | – | Pevný předmět leadu. Má přednost před předmětem nastaveným na formuláři. |
| `title` | – | Nadpis nad formulářem. |
| `button` | `Odeslat` | Popisek odesílacího tlačítka. |
| `class` | – | Další CSS třída formuláře. |
| `redirect` | z formuláře, pak z nastavení | URL, kam přesměrovat po úspěšném odeslání. |

Pole se nastavují v builderu, ne ve zkratce.

Server vždy vyžaduje **e-mail nebo telefon**, i kdyby je builder označil jako nepovinné — bez kontaktu je lead k ničemu.

### Zastaralé atributy

`fields` a `required` fungují dál, ale jen jako filtr nad formulářem: `fields=` vybere a seřadí podmnožinu jeho polí, `required=` přepíše povinnost. Zůstávají kvůli stránkám, které je nesou z verze 2.0.

```
[raynet_lead_form fields="email,message"]
```

Pro nový web je místo nich lepší založit druhý formulář — nastavení je pak na jednom místě.

Souhlas a vlastní pole ve `fields=` pojmenovat nejde, a filtr je proto nikdy neodebere.

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

- Souhlas je pole formuláře. Přidáte ho v builderu, jeho text smí obsahovat odkazy, a je vždy povinný.
- Formulář bez pole souhlasu do poznámky leadu nic o souhlasu nezapíše.
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

Pokrývají sanitizaci nastavení, převod konfigurace z 1.x, sestavení payloadu pro RAYNET, zpracování odpovědí API, celý průchod odeslání včetně ochran proti spamu, vykreslení zkratky, stránku nastavení a aktualizace. Testy vyžadují PHP 8.0+; samotný plugin běží od PHP 7.4.

### Integrační sada

Stuby nesimulují jádro WordPressu, takže pořadí hooků, mapování oprávnění ani sestavení menu nepokryjí — a právě tam vznikly chyby ve verzích 2.1.0, 2.2.1 a 2.2.2. Na to je druhá sada, která běží proti skutečnému WordPressu na SQLite:

```bash
bin/wp-test-setup.sh   # jednou: stáhne WordPress, SQLite integraci a wp-cli do .wp-test/
bin/wp-test.sh         # 58 kontrol
```

Ověřuje pořadí hooků, že `manage_options` zůstává funkční, položky menu, načtení skriptů builderu, vykreslení formuláře na veřejné stránce, skutečné odeslání přes `admin-ajax.php` až k payloadu pro RAYNET, a aktualizační transient. Odchozí HTTP zachytává testovací dvojník, takže běh nikdy nesáhne na RAYNET ani na GitHub.

Adresář `.wp-test/` je mimo git a dá se kdykoliv zahodit; `bin/wp-test-setup.sh --fresh` ho postaví znovu.

Akce pro Elementor se testuje jen tehdy, když je Elementor Pro v testovací instalaci přítomné. Nakopírujte `elementor` a `elementor-pro` do `.wp-test/wp/wp-content/plugins/`, aktivujte je a sada přidá dalších 23 kontrol; jinak tuhle část přeskočí.

Historie změn je v [CHANGELOG.md](CHANGELOG.md).

---

## Licence

MIT — viz [LICENSE](LICENSE).

Autor: Matěj Kevin Nechodom
