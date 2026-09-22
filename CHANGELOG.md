# Changelog

Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/).

## [2.2.1] — 2026-09-22

Opravná verze. **Verze 2.1.0 a 2.2.0 shodí web fatální chybou** a je potřeba z nich přejít sem.

### Opraveno

- **Fatální chyba při každém načtení stránky.** Plugin registroval typ obsahu a spouštěl jednorázovou migraci formulářů na hooku `plugins_loaded`. WordPress ale `$wp_rewrite` sestavuje až po něm a `wp_insert_post()` si na něj sahá přes `get_permalink()`. Výsledkem bylo `Call to a member function get_extra_permastruct() on null` a nedostupný web včetně administrace. Obojí se přesunulo na `init` — registrace na prioritu 5, migrace na 20.
- `register_post_type()` se volalo dřív než na `init`, což je proti dokumentaci a novější WordPress na to upozorňuje.
- **Formulář, který po pádu zůstal rozepsaný, se dokončí.** Pád nastal až po vložení příspěvku, takže na webu mohl zůstat formulář bez polí. Migrace ho teď převezme a doplní mu pole, místo aby založila druhý.
- Když se formulář nepodaří vytvořit, migrace si už nepoznačí, že proběhla, a zkusí to při dalším požadavku znovu.

### Přidáno

- Testy na pořadí hooků (`tests/test-bootstrap.php`), které tuhle chybu chytnou: ověřují, že bootstrap nesahá na typ obsahu ani do databáze, a že registrace předchází migraci.

## [2.2.0] — 2026-09-22

### Přidáno

- **Aktualizace z administrace.** Plugin sleduje vydané verze na GitHubu a novou nabídne na stránce Pluginy jako kterýkoliv jiný plugin — včetně odkazu „Zapnout automatické aktualizace“.
- V nastavení přibyla sekce **Aktualizace**: nainstalovaná i poslední vydaná verze a tlačítko „Zkontrolovat aktualizace“.
- Zaškrtávátko, kterým se kontrola vypne.

### Poznámky k bezpečnosti

- Instaluje se výhradně archiv přiložený k vydané verzi a stažený z `github.com`. Odkaz na jiný host se odmítne, takže podvržená odpověď API nemůže WordPressu podstrčit cizí archiv.
- Zdrojový archiv GitHubu (`zipball`) se vědomě nepoužívá: jeho kořenová složka se jmenuje podle tagu a plugin by se nainstaloval do špatného adresáře.
- Poznámky k vydání se v okně s detaily zobrazují escapované, ne jako HTML.
- Odpověď se drží 12 hodin v cache, aby sdílená IP adresa nevyčerpala hodinový limit GitHubu. Neúspěšný dotaz se drží hodinu.
- Hlavička `Update URI` brání tomu, aby aktualizaci převzal stejnojmenný plugin z wordpress.org.

### Upgrade

Verze 2.1.0 a starší updater neobsahují, proto se na 2.2.0 musí přejít ručně. Od ní už aktualizace běží z administrace.

## [2.1.0] — 2026-09-22

Formulář se skládá v administraci, ne ve zkratce.

### Přidáno

- **Builder formulářů** v RAYNET CRM → Formuláře. Pole se vybírají ze seznamu, řadí přetažením nebo šipkami a editují na místě: popisek, placeholder, nápověda, povinnost, šířka.
- **Živý náhled**, který vykresluje server stejným kódem jako ostrý formulář, takže se s ním nemůže rozejít.
- **Víc pojmenovaných formulářů.** Každý má vlastní pole i vlastní nastavení leadu — předmět, prioritu, typ leadu, číselníková ID, štítky, notifikační e-maily, hlášku po odeslání a přesměrování. Prázdná hodnota znamená zdědit z nastavení pluginu.
- **Vlastní pole** bez protějšku v RAYNETu: jednořádkový text, víceřádkový text, výběr a zaškrtávátko. Hodnota se zapíše do poznámky leadu pod svým popiskem. U zaškrtávátka se zapisuje i odpověď „ne“.
- Zkratka přijímá `id`: `[raynet_lead_form id="kontakt"]`, podle zkratky formuláře i podle číselného ID.
- Jeden formulář lze označit jako **výchozí**; ten obslouží zkratku bez `id`.
- Seznam formulářů ukazuje zkratku k vložení a počet polí.

### Změněno

- **Souhlas se zpracováním je pole formuláře**, ne globální přepínač. Ze stránky nastavení zmizel a odkazuje na Formuláře. Formulář bez pole souhlasu už do poznámky leadu nezapíše, že souhlas padl — dřív to při zapnutém globálním přepínači udělal i formulář, který se na nic neptal.
- Odesílání se řídí definicí uloženou na serveru. Pole, které formulář nemá, se zahodí, i kdyby v požadavku přišlo.
- Půlená šířka se řídí nastavením pole. CSS už nestaví vedle sebe jmenovitě Jméno a Příjmení.
- Atributy `fields=` a `required=` jsou zastaralé. Fungují dál jako filtr nad formulářem, takže stránky z verze 2.0 vypadají stejně.

### Migrace

Při první aktivaci vznikne formulář „Kontaktní formulář“ se stejnými poli, jaká vydávala zkratka ve verzi 2.0, a označí se jako výchozí. Stránky se zkratkou `[raynet_lead_form]` tak vypadají dál stejně. Pole souhlasu se přidá jen tehdy, byl-li souhlas globálně zapnutý, i s jeho textem.

Nastavení leadu se nekopíruje — formulář dědí z globálního nastavení, aby zůstalo na jednom místě.

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
