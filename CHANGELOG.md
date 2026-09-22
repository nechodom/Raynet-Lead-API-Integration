# Changelog

Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/).

## [2.0.0] — 2026-09-22

Kompletní přepis. Verze 1.x posílala přihlašovací údaje do prohlížeče; tato verze je opravuje na serveru.

### Bezpečnost

- **Přihlašovací údaje už neopouštějí server.** `wp_localize_script()` vypisovalo uživatelské jméno, API klíč i název instance do zdrojového kódu každé stránky webu. Odesílání teď běží přes `admin-ajax.php` a REST, RAYNET volá výhradně PHP.
- Serverový AJAX handler dostal kontrolu nonce, sanitizaci vstupů a omezení frekvence. V 1.x byl registrovaný bez jakékoli ochrany a bez volání z frontendu.
- Technické detaily chyb API se návštěvníkovi nikdy nezobrazují — jdou jen do logu a do administrace.
- API klíč se v administraci zadává do pole typu `password` a nikdy se nevypisuje zpět.

### Opraveno

- **Kontrola odpovědi API.** `wp_remote_request()` vrací `WP_Error` jen při selhání spojení, takže HTTP 401 nebo 400 se dřív tvářilo jako úspěch. Nově se kontroluje stavový kód a rozlišuje se 201, 401, 403, 404, 429 a 5xx.
- **Zpráva z formuláře se už nezahazuje.** Pole „Zpráva“ se dřív vůbec neodesílalo — do CRM šla jen statická poznámka z nastavení. Teď jde do atributu `notice` spolu s poznámkou a URL stránky.
- **Nastavení „API URL“ se konečně používá.** Handler měl adresu napevno v kódu, takže uložená hodnota neměla žádný efekt.
- Volání z JavaScriptu na doménu RAYNETu stejně blokoval CORS — odeslání tak ve výchozím nastavení vůbec nefungovalo.
- Skripty se načítaly na každé stránce webu, i tam, kde žádný formulář nebyl.
- Chybějící kontrola HTTP stavu při ukládání nastavení a chybějící `sanitize_callback` u `register_setting()`.

### Přidáno

- Zkratka `[raynet_lead_form]` s volitelnými poli, povinností, pevným předmětem, vlastním tlačítkem a přesměrováním.
- Výběr ze čtyř regionálních API RAYNETu (`.cz`, `.sk`, `.com`, `eu.`) i vlastní adresa.
- Tlačítko **Otestovat spojení** proti `GET /security/info`; po úspěchu vypíše ID číselníků `leadCategory`, `leadPhase` a `contactSource`.
- Podpora hlavičky `X-Instance-Id` vedle `X-Instance-Name`.
- Ochrana proti spamu: honeypot, podepsaná časová past a omezení frekvence na IP adresu.
- Zaškrtávátko souhlasu se zpracováním údajů; datum souhlasu se zapisuje do poznámky leadu.
- Záložní e-mail pro případ, že RAYNET lead nepřijme.
- Mapování dalších atributů leadu: `companyName`, `contactInfo.tel1`, `address`, `leadDate`, `leadPerson`, `category`, `leadPhase`, `contactSource`, `owner`, `securityLevel`, `tags`, `notificationEmailAddresses`.
- Automatické obnovení vypršelého nonce — formulář funguje i na webech s plnou cache stránek.
- REST endpoint `POST /wp-json/raynet-lead/v1/submit`.
- Filtr `raynet_lead_payload`, akce `raynet_lead_created` a `raynet_lead_failed`.
- Soubor `uninstall.php` s volitelným úklidem dat.
- Šablona překladu `.pot` a 118 lokalizovatelných řetězců.
- Testy (`php tests/run.php`) — 115 kontrol nad stubem WordPressu.

### Změněno

- Nastavení se sloučilo do jedné option `raynet_lead_settings`. Hodnoty z 1.x se převedou automaticky při první aktivaci, včetně API klíče a vlastní poznámky.
- Složka pluginu se přejmenovala na `raynet-lead-api-integration/`, hlavní soubor na `raynet-lead-api-integration.php`.

### Odstraněno

- `form.html` — nahrazuje ho zkratka.
- `js/script.js`, které volalo RAYNET přímo z prohlížeče.

> **Po aktualizaci vygenerujte v RAYNETu nový API klíč a starý zneplatněte.** Ten původní byl viditelný ve zdrojovém kódu veřejných stránek.

## [1.0] — 2024

- První veřejná verze.
