# Import uložených odeslání Elementoru — návrh

**Datum:** 2026-11-18
**Verze pluginu:** 2.10.0 (cílová)
**Stav:** schváleno uživatelem

---

## 1. Cíl a proč

Plugin zakládá lead jen ve chvíli, kdy návštěvník formulář odešle (`Raynet_Elementor_Form_Action::run()`). Poptávky, které přišly dřív, než byla akce RAYNET CRM na formuláři zapnutá, leží jen v Elementoru (*Elementor → Submissions*) a do RAYNETu se nedostanou. Stejně tak ty, u kterých odeslání do RAYNETu tehdy selhalo.

Import je má do RAYNETu dostat dodatečně. V uložených odesláních je ale hodně spamu a spam lead se z CRM maže ručně. Proto import nic neposílá sám: každé odeslání ohodnotí, ukáže důvod a odešle jen to, co správce potvrdí.

**Úspěch:** správce otevře formulář, vidí pravé poptávky předvybrané a spam odškrtnutý, zkontroluje, klikne, leady jsou v RAYNETu se správným původním datem. Druhý běh nic nezdvojí.

### Cíle

- Dostat stará odeslání formulářů Elementor Pro do RAYNETu jako leady, stejným mapováním polí, jaké formulář používá pro nová odeslání.
- Spam zachytit návrhem, o odeslání rozhoduje člověk.
- Žádné duplicity: co už v RAYNETu je, se znovu neodešle.

### Co cílem není

- Automatický import bez kontroly (cron, „importuj vše“).
- Mazání nebo úklid spamu v Elementoru. Import tabulky odeslání jen čte, s jedinou výjimkou v části 6.
- Odeslání formulářů, které už na webu neexistují, a atomických formulářů Elementoru 4 (ty plugin neumí ani pro nová odeslání).
- Vlastní formuláře z builderu pluginu. Ty odeslání neukládají, není co importovat.
- Učící se filtr, Akismet ani jiná vnější služba. Data návštěvníků neopustí web jinam než do RAYNETu.

### Co by z toho udělalo neúspěch

- Spam v CRM, protože byl předvybraný.
- Pravá poptávka, která tiše zmizí. Odškrtnuté řádky proto zůstávají vidět i s důvodem.
- Lead založený dvakrát.
- Stovky notifikačních e-mailů nebo záložních e-mailů naráz.

---

## 2. Zvažované cesty

### Kdo rozhoduje o spamu

| Cesta | Co dává | Cena |
|---|---|---|
| **Návrh + kontrola člověkem** (vybráno uživatelem) | Plugin hodnotí, člověk potvrzuje. Chyba filtru je vidět dřív, než vznikne lead. | Správce musí tabulku projít. |
| Plně automaticky | Jedno kliknutí. | Část spamu projde, část pravých poptávek vypadne, a nikdo to neuvidí. |
| Export do CSV a import v RAYNETu | Žádný kód. | Nepoužije mapování formuláře, vlastní pole, poznámku ani kontrolu duplicit. |

### Jak z uloženého odeslání vznikne lead (rozhodnutí autora návrhu)

| Cesta | Co dává | Cena |
|---|---|---|
| **Společný sestavovač leadu** (vybráno) | Logika mapování z `Raynet_Elementor_Form_Action` se vyjme do třídy, která nepotřebuje `Form_Record`. Živé odeslání i import jdou stejným kódem, takže se nemůžou rozejít. | Refaktoring akce, krytý stávajícími testy. |
| Přehrát odeslání přes Elementor | Žádný refaktoring. | Musel by se podvrhnout `Form_Record`, `Ajax_Handler` a `$_POST`; znovu by se spustily i ostatní akce formuláře (e-mail, webhook). |
| Druhá kopie mapování jen pro import | Akce zůstane netknutá. | Dva kódy na totéž, časem se rozejdou. |

---

## 3. Jak to správce uvidí

Na obrazovce **RAYNET CRM → Elementor formuláře** přibude u každého formuláře, který má uložená odeslání, odkaz **Importovat starší odeslání (N)**. N je počet odeslání, která ještě v RAYNETu nejsou. Formulář bez uložených odeslání odkaz nemá.

Odkaz vede na obrazovku importu jednoho formuláře (stejná stránka, parametr `import=<post_id>:<widget_id>`, stejně jako dnes `mapovat=`):

```
Import odeslání — Kontaktní formulář (Stránka: Kontakt)

Uloženo 1 284 odeslání · 212 už v RAYNETu · 1 072 k posouzení
  v pořádku 61 · podezřelé 38 · spam 973

[ V pořádku + podezřelé (99) ]  [ Spam (973) ]  [ Už v RAYNETu (212) ]  [ Nelze importovat (0) ]

Štítek pro importované leady: [ import-web      ]

☑  12. 3. 2025 14:10  Jana Nováková · jana@firma.cz · 777 123 456
                      „Dobrý den, poptávám nabídku na…“                 V pořádku      [detail]
☐  11. 3. 2025 03:02  Robertbok · rob@mail.ru
                      „Get 10000 backlinks https://…“                   Spam: odkaz ve zprávě, klíčová slova, stejný text 41×
☐   9. 3. 2025 09:44  Petr Dvořák · petr@seznam.cz · 123
                      „Zavolejte mi“                                    Podezřelé: nevěrohodný telefon
☐   8. 3. 2025 16:20  …                                                 V pořádku · tehdy selhalo: „Connection timed out“ — ověřte v RAYNETu

[ Odeslat zaškrtnuté do RAYNETu (61) ]                              ‹ 1 2 3 ›   Na stránku: 50 / 100 / 200
```

- **Výchozí pohled** ukazuje „v pořádku“ a „podezřelé“ dohromady. Spam je na vlastní záložce, aby ho nebylo nutné proklikávat, ale je vidět a dá se zaškrtnout.
- **Předvybrané** jsou jen řádky „v pořádku“, které se do RAYNETu nikdy neposílaly. Podezřelé, spam a „tehdy selhalo“ jsou odškrtnuté.
- **Detail** řádku rozbalí všechny uložené hodnoty, IP adresu, user agent, a náhled leadu: které atributy se odešlou a jak bude vypadat poznámka.
- **Odeslání** se týká zaškrtnutých řádků na aktuální stránce. Před odesláním potvrzovací dotaz s počtem. Pak se řádky posílají po jednom na pozadí; u každého se ukáže výsledek (číslo leadu, nebo chyba z RAYNETu) a nahoře průběh. Zavření stránky uprostřed nic nerozbije: odeslané jsou označené, zbytek zůstane k posouzení.
- **Formulář není připravený** (akce RAYNET CRM není namapovaná nebo nemapuje e-mail ani telefon): místo tabulky hláška s odkazem na obrazovku mapování. Zapnutá akce se nevyžaduje, stačí mapování; kdo chce jen jednorázově importovat, nemusí akci zapínat.
- **Plugin není nastavený** (chybí přihlašovací údaje): hláška s odkazem do nastavení, tlačítko odeslání chybí.

Texty česky, v textové doméně pluginu, jako zbytek administrace.

---

## 4. Odkud se čte

Elementor Pro ukládá odeslání do tří tabulek (ověřeno na Elementor Pro 4.1.1, schéma je stejné od zavedení Submissions):

- `{prefix}e_submissions` — `id`, `post_id`, `element_id`, `form_name`, `referer`, `user_ip`, `user_agent`, `status` (`new` / `trash`), `meta` (JSON s `edit_post_id`), `created_at`, `created_at_gmt`.
- `{prefix}e_submissions_values` — `submission_id`, `key` (ID pole formuláře, shodné s `local_id` v mapování), `value`.
- `{prefix}e_submissions_actions_log` — `submission_id`, `action_name`, `status` (`success` / `failed`), `log`.

Čte se přímo přes `$wpdb`, s `prepare()`. API třídy `Query` Elementoru neumí potřebné součty ani hromadné čtení hodnot.

**Párování odeslání s formulářem.** Přísné: formulář ze skeneru (`post_id`, `widget_id`) odpovídá odeslání, kde `element_id = widget_id` **a zároveň** `post_id` nebo `meta.edit_post_id` je `post_id` formuláře. Samotné `element_id` nestačí: zduplikovaná stránka nebo importovaná šablona ID widgetu zachová a odeslání by se importovala mapováním cizího formuláře. U šablon a globálních widgetů Elementor ukládá jako `post_id` ID šablony, takže přísné párování sedí i tam. Odeslání, která žádnému nalezenému formuláři neodpovídají, se nenabízejí.

**Stav odeslání:**

| Stav | Podmínka | V tabulce |
|---|---|---|
| Už v RAYNETu | v logu akcí je `raynet_crm` se stavem `success` | záložka „Už v RAYNETu“, nejde zaškrtnout |
| Tehdy selhalo | v logu je `raynet_crm` jen se stavem `failed` | k posouzení, s původní chybou z logu; **nikdy předvybrané** (viz níže) |
| Nikdy neodesláno | v logu `raynet_crm` není | k posouzení |
| V koši | `status = trash` | nenabízí se vůbec |
| Nelze importovat | po namapování nemá e-mail ani telefon, nebo je e-mail neplatný | záložka „Nelze importovat“, s důvodem, nejde zaškrtnout |

**Tehdy selhalo není důkaz, že lead nevznikl.** Vypršení času nebo výpadek spojení neříká, jestli RAYNET lead založil. Řádky „tehdy selhalo“ proto nejsou předvybrané bez ohledu na verdikt a nesou výzvu „ověřte v RAYNETu, že lead neexistuje“. Totéž platí, když při importu selže samotný požadavek bez odpovědi serveru: řádek ukáže „výsledek neznámý, ověřte v RAYNETu, než to zkusíte znovu“ a do konce práce se stránkou zůstane odškrtnutý. Po novém načtení stránky se takový řádek nabídne znovu jako neodeslaný; trvale se to neeviduje a je to přijaté riziko.

**Elementor Pro musí být aktivní.** Tabulky deaktivaci Pro přežijí, ale import bez něj neběží: odkaz se nezobrazí a obrazovka importu řekne proč. Stejně tak když tabulky neexistují.

**Rozsah.** Obrazovka musí fungovat i s 20 000 odesláními jednoho formuláře: stránkované čtení, součty a statistiky pro hodnocení spamu jedním průchodem bez načtení všeho do paměti naráz. Jak přesně (agregace v SQL, průchod po dávkách, krátkodobá cache) je na staviteli.

---

## 5. Hodnocení spamu

Čistá třída bez závislosti na WordPressu i Elementoru (`Raynet_Submission_Spam_Score`), aby šla testovat ve stubové sadě. Vstup: hodnoty jednoho odeslání po namapování (jméno, e-mail, telefon, zpráva, ostatní texty), IP, user agent a statistiky přes všechna odeslání formuláře. Výstup: verdikt a seznam důvodů česky.

**Signály:**

| Síla | Signál |
|---|---|
| silný | odkaz (`http`, `www.`, `[url=`) v krátkém poli: jméno, příjmení, firma, telefon, předmět |
| silný | HTML nebo BBCode značky v jakékoli hodnotě |
| silný | text převážně v jiném písmu než latinka (azbuka, čínština, arabština…) |
| silný | stejný text zprávy (po normalizaci mezer a velikosti písmen) od 3 a více **různých e-mailů** (bez e-mailu: různých IP adres). Opakování od téhož odesílatele sem nepatří, to řeší pravidlo o duplicitách níže. Platí jen pro zprávu dlouhou aspoň 40 znaků (`same_text_min_length`), krátké běžné věty jako „Prosím o zavolání.“ píšou i skuteční lidé (doplněno při stavbě) |
| silný | dva a více odkazů ve zprávě |
| střední | jeden odkaz ve zprávě |
| střední | klíčová slova typická pro spam (SEO, backlink, casino, crypto, viagra, loan, guest post…) |
| střední | stejná IP adresa u 5 a více odeslání formuláře |
| střední | nesmyslné jméno: shodné jméno a příjmení, číslice ve jméně, shluk souhlásek bez samohlásky, náhodné střídání velkých a malých písmen |
| střední | user agent prázdný nebo strojový (`curl`, `python`, `bot`…); jen když ho formulář ukládá |
| slabý | nevěrohodný telefon (méně než 9 číslic, samé stejné číslice) |
| slabý | e-mail z jednorázové služby (krátký vestavěný seznam domén) |
| slabý | zpráva kratší než 10 znaků, pokud formulář pole zprávy má |

**Verdikt:**

- **spam** — aspoň jeden silný signál, nebo dva a více středních;
- **podezřelé** — jeden střední, nebo dva a více slabých;
- **v pořádku** — jinak. Jen tyto řádky jsou předvybrané.

Dva filtry, oba volá volající, třída sama o WordPressu neví:

- `raynet_import_spam_config` — konfigurace hodnocení před výpočtem: prahy, seznam klíčových slov, seznam jednorázových domén, povolená písma a seznam vypnutých signálů (každý signál má stálý identifikátor). Web, který píše azbukou, tak signál písma vypne jedním řádkem.
- `raynet_import_spam_score` — hotový výsledek jednoho odeslání (verdikt, důvody s identifikátorem signálu a silou) spolu s hodnotami; vrací výsledek. Pro vlastní pravidla.

**Duplicity uvnitř importu.** Stejný e-mail a stejná zpráva ve více odesláních (typicky návštěvník, kterému odeslání selhalo a zkusil to znovu): nejstarší se hodnotí normálně, další dostanou důvod „duplicitní odeslání“ a verdikt nejméně „podezřelé“. Odeslání, která už v RAYNETu jsou, se počítají jako nejstarší: jejich neodeslané kopie jsou duplicity.

Hodnocení je návrh, ne zámek. Správce smí zaškrtnout i řádek označený jako spam.

---

## 6. Jak z odeslání vznikne lead

**Společný sestavovač.** Z `Raynet_Elementor_Form_Action` se do nové třídy (načítané i bez Elementor Pro) přesunou `mapped_values()`, `noted_values()`, `switch_consent()`, `merge_lines()` a `lead_settings()`. Vstupem je pole polí ve tvaru, jaký vrací `Form_Record::get( 'fields' )` (`id => [ type, value, raw_value, title ]`), a nastavení formuláře. Akce ji volá se živým záznamem, import s polem sestaveným z uložených hodnot; typ a popisek pole se berou z aktuální definice formuláře (`form_fields`), u pole, které už ve formuláři není, typ `text` a popisek rovný ID. Chování živého odeslání se nemění, stávající testy musí projít beze změny.

**Číselná pole.** Elementor u pole typu Číslo ukládá jen výsledek `intval()`; co návštěvník napsal, se nedochovalo (živá akce proto čte `raw_value`, které v uložených odesláních není). Import posílá hodnotu, jak je uložená, s jednou výjimkou: `0` se bere jako nevyplněno. Úvodní nuly a desetinná část jsou ztracené a import je nedomýšlí. Detail řádku u každého číselného pole s hodnotou upozorní „Elementor uložil jen celé číslo; úvodní nuly a desetinná část se nedochovaly“.

**Čím se importovaný lead liší od živého:**

| Věc | Živé odeslání | Import |
|---|---|---|
| `leadDate` | dnešek | den původního odeslání (`created_at`) |
| Poznámka | — | navíc řádek „Importováno z uložených odeslání Elementoru. Původně odesláno: 12. 3. 2025 14:10“ |
| URL stránky | z `queried_id` / ID dokumentu | `referer` uloženého odeslání (pokud má formulář zapnuto „Uvést URL stránky“) |
| Souhlas v poznámce | s aktuálním časem | s časem původního odeslání |
| GDPR záznam v RAYNETu | podle nastavení | **nezakládá se** (rozhodnutí autora návrhu: záznam nese datum platnosti a zakládat ho zpětně bez vědomí správce je riskantní; souhlas zůstává v poznámce) |
| Notifikační e-maily | podle nastavení | **neposílají se** |
| Záložní e-mail při chybě | ano | ne, chyba se ukáže u řádku |
| Pauza mezi odesláními z jedné IP | spouští se | nespouští se |
| „Poslední chyba API“ v nastavení | zapisuje se | nezapisuje se |
| Přílohy | nahrají se k leadu | soubor už na serveru být nemusí; do poznámky jde řádek s uloženým odkazem, pokud je pole namapované jako příloha nebo vybrané do poznámky |
| Štítky | podle nastavení | navíc štítek z pole na obrazovce importu (výchozí `import-web`, jde vymazat), aby šly importované leady v RAYNETu najít a případně hromadně smazat |
| Hooky `raynet_lead_payload`, `raynet_lead_created`, `raynet_lead_failed` | volají se | volají se stejně |

Vlastník, kategorie, stav, zdroj, priorita, předmět a ostatní nastavení leadu se berou z formuláře a nastavení pluginu jako u živého odeslání.

`Raynet_Lead_Form::submit_lead()` dostane pro tyto rozdíly nové klíče v `$context`. Jejich názvy a to, jestli jde o jeden přepínač „import“ nebo několik jednotlivých, je na staviteli; bez nich se metoda musí chovat přesně jako dnes.

**Označení odeslaného.** Po úspěchu se do logu akcí Elementoru k odeslání zapíše řádek `raynet_crm` / `success` s textem „Importováno, lead 12345“, přes `Query::add_action_log()` Elementoru. Je to jediný zápis do tabulek Elementoru; počitadlo `actions_succeeded_count` u odeslání se nemění (akce RAYNET CRM mezi tehdejšími akcemi být nemusela a počitadlo by pak přesáhlo počet akcí). `add_action_log()` chce objekt akce, ne název: použije se instance `Raynet_Elementor_Form_Action`, která s aktivním Elementor Pro existuje. Důvod: log akcí je už dnes místo, podle kterého se pozná, že odeslání v RAYNETu je; import tak nepotřebuje vlastní tabulku, výsledek je vidět i v detailu odeslání v Elementoru a zmizí spolu s odesláním. Zápis následuje hned po založení každého leadu, ne až na konci dávky. Neúspěch se do logu nezapisuje.

**Odeslání na pozadí.** Jeden AJAX požadavek (`admin-ajax.php`) = jedno odeslání. Server před odesláním znovu ověří oprávnění `manage_options`, nonce, že odeslání patří k danému formuláři, že není v koši a že v logu ještě nemá úspěch (ochrana proti dvojímu kliknutí a dvěma otevřeným záložkám). Prohlížeč posílá požadavky sériově, jeden po druhém.

---

## 7. Hranice a bezpečnost

- Obrazovka i AJAX jen pro `manage_options`, s nonce, jako ostatní operace obrazovky.
- Všechny uložené hodnoty jsou cizí vstup: na výstupu escapované, do RAYNETu jdou přes stejnou sanitizaci jako živé odeslání. Pole typu heslo se nikdy nezobrazí ani neodešle.
- SQL jen přes `$wpdb->prepare()`; názvy tabulek z `$wpdb->prefix` a pevných konstant.
- Odinstalace: import si nic vlastního neukládá (kromě případné krátkodobé cache v transientu, kterou odinstalace smaže).

---

## 8. Testy

- **Stubová sada** (`php tests/run.php`): hodnocení spamu (každý signál, prahy, filtr, duplicity); sestavení leadu z uložených hodnot včetně všech rozdílů z tabulky v části 6; stavy odeslání; že živé odeslání se po refaktoringu chová stejně.
- **Integrační sada** (`bin/wp-test.sh`, jen s Elementor Pro): skutečné tabulky naplněné odesláními; odkaz s počtem na obrazovce formulářů; obrazovka importu přes HTTP; AJAX import jednoho odeslání až k payloadu pro RAYNET (zachytí testovací dvojník); zápis do logu akcí; druhý pokus o totéž odeslání nic neodešle; odmítnutí bez oprávnění a bez nonce.
- JavaScript obrazovky: test v `tests/js/` v rozsahu, v jakém jde logika oddělit od DOM.

---

## 9. Dokumentace a vydání

- README: nová část v kapitole Elementor Pro Forms (jak import spustit, co znamenají verdikty, čím se importovaný lead liší), řádek ve Funkcích, filtr `raynet_import_spam_score` v Hooky pro vývojáře.
- CHANGELOG: záznam pod 2.10.0.
- `.pot` soubor doplnit o nové texty.
- Verze v hlavičce pluginu a v `RAYNET_LEAD_VERSION` na 2.10.0.

---

## 10. Co je ponecháno staviteli

- Názvy tříd, souborů a metod, rozdělení čtení / hodnocení / obrazovky do souborů (v duchu stávajících `includes/elementor/class-raynet-elementor-*.php`).
- Způsob výpočtu statistik přes velký počet odeslání a případná cache.
- Přesné regulární výrazy a seznamy pro signály spamu; prahy z části 5 jsou výchozí bod a testy je mají zafixovat.
- Podoba kontextových klíčů pro `submit_lead()`.
- Vzhled obrazovky v mezích stylu stávající administrace (`raynet-lead-admin.css`).
