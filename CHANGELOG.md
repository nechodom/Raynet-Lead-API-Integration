# Changelog

Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/).

## [2.4.0] — 2026-09-22

### Přidáno

- **Jedno pole pro celé jméno.** V mapování přibyl atribut **Jméno a příjmení**. Namapujete na něj jediné pole a plugin ho rozdělí: poslední slovo je příjmení, všechno před ním jméno. Jedno slovo se bere jako příjmení, protože podle něj se v CRM vyhledává. Funguje ve zkratce i v Elementoru.

  Namapovat současně celé jméno i jednu z jeho půlek nejde — jedno by přepsalo druhé. Builder takovou kombinaci odmítne, v Elementoru vyhraje samostatně namapovaná půlka. Pravidlo dělení jde přepsat filtrem `raynet_lead_split_name`.

- **Obrazovka RAYNET CRM → Elementor formuláře.** Vypíše každý formulář Elementoru na webu — stránku, název, pole a jestli u něj RAYNET běží, s odkazem do editoru.

- **Šablony.** Pojmenovaná sada nastavení leadu, kterou lze hromadně nasadit na vybrané formuláře. U každého se zapne akce RAYNET CRM a vyplní se nastavení ze šablony.

- **Odhad mapování.** Při nasazení se pole přiřadí k atributům automaticky: nejdřív podle typu pole (Email → E-mail, Tel → Telefon, Textarea → Zpráva), pak podle popisku, bez ohledu na diakritiku. Jedno pole nikdy neobsadí dva atributy a kombinace celého jména s jeho půlkou se rozpouští ve prospěch půlek.

- **Záloha a návrat.** Nasazení zapisuje do `_elementor_data`, tedy do obsahu stránek. Předchozí podoba se uloží a v tabulce se objeví odkaz Vrátit zpět. Záloha je jedna na stránku; druhé nasazení tu první přepíše.

### Bezpečnost dat

Zápis do `_elementor_data` prošel nezávislým adversariálním review, které našlo 14 potvrzených chyb. Všechny jsou opravené a pokryté testy. Dvě z nich byly kritické:

- **Druhé nasazení přepsalo zálohu tou už změněnou.** Stránka se dvěma formuláři se v jednom odeslání nastavuje dvakrát; první zápis uložil původní podobu, druhý ji nahradil polovičatou. Záloha se nově zapisuje jen tehdy, když ještě žádná není, a nasazení se seskupuje po stránkách, takže se stránka zapisuje jednou.
- **Vrácení přepsalo i novější úpravy.** „Vrátit zpět" vracelo uloženou podobu bez ohledu na to, co se se stránkou dělo mezitím — týdenní práce v Elementoru zmizela bez varování. Plugin si teď u zápisu poznamená otisk a při vracení pozná, že se stránka od té doby změnila. Odmítne to a vysvětlí proč; pokračovat jde jen po výslovném potvrzení. Vrácení je navíc POST, ne odkaz.

### Opraveno

- **Odhad mapování přepsán.** Rozhoduje popisek, teprve pak typ pole, takže formulář se dvěma poli typu textarea už nedá do zprávy fakturační adresu. Pole se přiřadí atributu, kterému jeho popisek sedí nejlépe, takže „E-mailová adresa" neskončí jako ulice. Zaškrtávátka, nahrávání souborů a reCAPTCHA se přeskakují, takže souhlas s popiskem „Chci novinky e-mailem" neukradne e-mail. Fragmenty se párují na celá slova nebo kmeny: „Obecné poznámky" je zpráva, ne město, a „Poznámky" se pozná stejně jako „Poznámka".
- „Vaše jméno" a „Vaše příjmení" se namapují jako dvě půlky. Dřív první z nich spolkl atribut celého jména a druhé pole zůstalo nenamapované.
- V Elementoru chyběl v mapování řádek **Jméno a příjmení**. Kdo akci zapínal ručně místo hromadně, neměl jak jediné pole rozdělit.
- Prázdné ID widgetu se chovalo jako zástupný znak a přenastavilo všechny formuláře na stránce. Nově se odmítne.
- Hromadné nasazení zahazovalo chyby a hlásilo jen počet úspěchů. Teď počítá i neúspěchy a upozorní na ně.
- Chybová hláška se brala z adresního řádku. Nešlo o XSS, ale kdo přiměl správce kliknout na upravený odkaz, mohl mu podstrčit vlastní text ve WordPressové hlášce. Putuje jen kód, text je v pluginu.
- Jedno pole namapované zároveň na celé jméno i na jméno zapsalo do jména „Jan Novák" místo „Jan".
- Styl administrace se načítal jen na stránce nastavení, takže nová obrazovka byla bez layoutu.
- Potvrzení u tlačítka „Vrátit zpět" mělo v obsluze překlep a skončilo chybou dřív, než se stihlo zeptat. Na změněné stránce tak návrat nešel dokončit vůbec. (Zachyceno klikáním v prohlížeči; testy teď obsluhu parsují.)

## [2.3.1] — 2026-09-22

### Opraveno

- **Mapování polí v Elementoru bylo nepoužitelné.** Řádky se jmenovaly „Item #1" místo atributů RAYNETu a rozbalovátko nabízelo jen „- None -", takže nešlo namapovat nic.

  Příčinou je zděděné `Integration_Base::register_fields_map_control()`. Deklaruje na repeateru pouze `remote_id` a `local_id`, a Elementor zahodí každý klíč statického PHP defaultu, který nemá odpovídající control. Jeho editor pak čte `remote_type` jako `undefined` a filtr

  ```js
  if ( 'text' !== remoteType && remoteType !== model.get('field_type') ) { return; }
  ```

  přeskočí každé pole formuláře. Elementorovy vlastní integrace se tomu vyhnou tím, že řádky vkládají z JavaScriptu po dotazu na vzdálené API — což pro pevný seznam deseti atributů nedává smysl.

  Control se nově registruje vlastní cestou, s repeaterem, který zná i `remote_label` a `remote_type`.

### Změněno

- Třída akce dědí z `Action_Base` místo `Integration_Base`. Jediné, co z `Integration_Base` používala, byla právě ta rozbitá metoda.

### Poznámka

Našlo se to až proklikáním živého editoru Elementoru. Integrační sada do té doby kontrolovala jen serverovou stranu — registraci akce a zpracování odeslání — a ta byla v pořádku po celou dobu. Rozbité bylo jen to, co uvidí editor.

Sada teď ověřuje, že repeater zná všechny čtyři klíče, že se nabízí deset atributů s popisky a že žádný řádek nedeklaruje jiný `remote_type` než `text`.

## [2.3.0] — 2026-09-22

### Přidáno

- **Akce pro Elementor Pro Forms.** Formuláře postavené v Elementoru umí zakládat leady v RAYNETu. Ve widgetu formuláře se v **Actions After Submit** objeví **RAYNET CRM** a s ní sekce nastavení: mapování polí na atributy leadu a per-formulář nastavení leadu — předmět, priorita, typ leadu, předpona poznámky, číselníková ID, štítky a notifikační e-maily. Prázdná hodnota dědí z nastavení pluginu.

  Mapování používá Elementorův vlastní `fields_map` control, takže se pole vybírají podle popisků, ne podle ID. Seznam deseti atributů RAYNETu je statický a nevyžaduje žádný dotaz z editoru.

  Vyžaduje Elementor Pro; bez něj se soubor s třídou vůbec nenačte.

- Přepínač **Zapsat udělení souhlasu**, ve výchozím stavu vypnutý. Formulář, který se na souhlas neptá, tak do CRM nenapíše, že padl.
- Přepínač **Uvést URL stránky**, odvozený z ID příspěvku, ne z referreru pod kontrolou odesílatele.

### Změněno

- `Raynet_Lead_Form::submit_lead()` je nový veřejný vstupní bod pro konec pipeline — sestavení payloadu, odeslání a ošetření selhání. Zkratka i akce Elementoru jím procházejí, takže dokumentované hooky `raynet_lead_payload`, `raynet_lead_created` a `raynet_lead_failed` i záložní e-mail existují jen jednou. `collect_values()` a `merge_lead_settings()` jsou nově veřejné.
- Chyba z API nese v datech `WP_Error` klíč `diagnostic` se skutečným důvodem. Návštěvníkovi se dál ukazuje jen obecná hláška; Elementor diagnostiku zobrazí v kanálu viditelném pouze uživatelům s právem editovat stránku.
- `submit_lead()` vrací i `lead_id`. Elementor ho předá do odpovědi jako `raynet_lead_id`.

### Co Elementor obstarává sám

Validaci, nonce, reCAPTCHA i honeypot řeší Elementor a plugin je znovu nekontroluje — vlastní časová past ani limit na IP by odmítaly legitimní odeslání. Zůstává pravidlo RAYNETu, které Elementor nezná: lead musí nést e-mail nebo telefon.

### Testy

23 integračních kontrol proti skutečnému Elementor Pro 4.1.1: registrace akce mezi vestavěné, mapování polí včetně zahození neznámého atributu a chybějícího pole, přebití globálních hodnot, zápis souhlasu a URL do poznámky, předání ID leadu, odmítnutí bez kontaktu, výjimka s diagnostikou při selhání API a `on_export`.

## [2.2.4] — 2026-09-22

### Opraveno

- **Formulář v builderu nešel uložit.** Náhled se vykresluje dovnitř editačního formuláře WordPressu a nesl `required` i skutečné `name`. Prohlížeč proto při kliknutí na *Aktualizovat* validoval pole náhledu, ohlásil „Vyplňte prosím toto pole" u e-mailu v náhledu a uložení zablokoval — u každého formuláře, který měl aspoň jedno povinné pole, tedy u všech. Pole náhledu se navíc odesílala spolu s příspěvkem. Náhled je nově vykreslovaný jako inertní: bez `required`, bez `name` a s `disabled`, což ho z validace i z odeslání vyřazuje. Vzhledově zůstává stejný.
- Šipky pro posun pole si držely původní popisek v `aria-label`, dokud se seznam nepřekreslil. Při přejmenování pole tak čtečka obrazovky hlásila staré jméno.

### Poznámka

Obě chyby našlo proklikání builderu ve skutečném prohlížeči. První z nich dělala builder nepoužitelným a žádná z dosavadních sad ji neodhalila — stubovaná nevidí validaci prohlížeče, integrační kontrolovala jen odpověď serveru.

Testy teď kontrolují, že náhled nenese `required` ani `name` a že je `disabled`, zatímco ostrý formulář obojí má.

## [2.2.3] — 2026-09-22

Opravná verze. **Verze 2.1.0 až 2.2.2 rozbíjejí oprávnění `manage_options` pro celý web** — přejděte sem.

### Opraveno

- **Registrace typu obsahu rozbila `manage_options` na celém webu.** `register_post_type()` si každou hodnotu uvedenou u meta capabilit `edit_post`, `read_post` a `delete_post` zapíše jako meta capabilitu pro celý web:

  ```php
  $post_type_meta_caps[ $use ] = $store;
  ```

  Uvedením `manage_options` se tedy `manage_options` samo stalo meta capabilitou a `map_meta_cap()` každou kontrolu na něj přepisoval na `delete_post` bez ID příspěvku, což vrací `do_not_allow`. Dopad nebyl omezený na tento plugin — týkal se každé kontroly `manage_options` v administraci. Nastavení se uvádějí už jen primitivní capability, meta si z nich `map_meta_cap()` odvodí sám.

### Přidáno

- **Integrační sada proti skutečnému WordPressu.** `bin/wp-test-setup.sh` postaví jednorázový WordPress na SQLite v `.wp-test/`, `bin/wp-test.sh` proti němu pustí 58 kontrol: pořadí hooků, oprávnění, položky menu, načítání skriptů, vykreslení formuláře na veřejné stránce, skutečné odeslání přes `admin-ajax.php` až k payloadu pro RAYNET, a aktualizační transient. Odchozí HTTP zachytává testovací dvojník, takže běh nikdy nesáhne na RAYNET ani na GitHub.

  Tahle vrstva chybí stubované sadě a jsou v ní přesně ty chyby, které prošly ve verzích 2.1.0, 2.2.1 a 2.2.2. Vrácení téhle konkrétní chyby shodí 12 kontrol.
- Levnější pojistka i ve stubované sadě: kontroluje, že se meta capability v registraci typu obsahu neuvádějí.

## [2.2.2] — 2026-09-22

### Opraveno

- **Stránka nastavení nebyla v menu a hlásila „Nemáte dostatečné oprávnění pro přístup na tuto stránku“.** `add_menu_page()` si sama nepřidá položku do podmenu. Jediným potomkem menu tak byly Formuláře a `wp-admin/includes/menu.php` přepsal slug nadřazené položky na ně — nastavení tím zmizelo z menu i z dosahu. Stránka se teď registruje i jako vlastní podmenu s popiskem „Nastavení“.
- Načítání skriptů na stránce nastavení se řídilo příponou hooku `toplevel_page_…`, která se týmž přepisem měnila. Nově se pozná podle parametru `page`.

### Přidáno

- Testy zapojení menu, které tuhle chybu chytnou: ověřují, že stránka je zaregistrovaná i jako podmenu sebe sama a že přežije přepis nadřazené položky.

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
