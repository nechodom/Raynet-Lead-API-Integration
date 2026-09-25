# RAYNET Lead API Integration

WordPress plugin, který posílá poptávky z webových formulářů přímo do [RAYNET CRM](https://raynet.cz) jako **Leady**, přes oficiální [REST API v2](https://app.raynet.cz/api/doc/). Umí vlastní formuláře z builderu i formuláře **Elementor Pro**. GDPR souhlas zapíše z obou; formuláře Elementoru navíc zvládnou vlastní pole RAYNETu a přílohy.

**Stažení:** poslední verze je v [Releases](https://github.com/nechodom/Raynet-Lead-API-Integration/releases/latest) jako ZIP připravený k nahrání do WordPressu. Další verze vám plugin nabídne přímo ve WordPressu na stránce Pluginy, volitelně i s automatickou aktualizací.

> Používáte ještě verzi 1.x? Ta posílala API klíč do prohlížeče, kde si ho mohl přečíst kdokoli. Nová verze ji **nepřepíše** — starý plugin nejdřív deaktivujte a smažte, pak nainstalujte nový a v RAYNETu vygenerujte nový API klíč. Viz [Co se změnilo oproti verzi 1.x](#co-se-změnilo-oproti-verzi-1x).

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
- **Hromadné nasazení** — plugin najde všechny formuláře Elementoru na webu, i ty v popupech, hlavičkách, patičkách a globálních widgetech, a nastaví je podle šablony, včetně odhadu mapování polí.
- **Mapování po polích formuláře** — plugin načte pole, která formulář opravdu má, a u každého se zeptá, kam v RAYNETu patří. Co v RAYNETu protějšek nemá, zapíše do poznámky leadu pod názvem pole.
- **Všechna pole leadu, i vlastní** — v Elementoru namapujete kromě jména a kontaktu i IČO, DIČ, druhý e-mail, sociální sítě a vlastní pole, která si vaše instance RAYNETu definuje. Plugin je načte přímo z RAYNETu a hodnoty převede na správný typ.
- **Přílohy** — soubory nahrané do formuláře Elementoru se k leadu v RAYNETu přiloží jako přílohy.
- **GDPR souhlas v RAYNETu** — zaškrtnutý souhlas se zapíše do poznámky s datem a zněním a volitelně založí k leadu i právní titul (GDPR záznam).
- **Jedno pole pro celé jméno** — „Jan Novák" se do RAYNETu rozdělí na jméno a příjmení.
- **Přihlašovací údaje zůstávají na serveru.** Prohlížeč mluví jen s WordPressem.
- **Podpora všech čtyř regionů RAYNETu** (`.cz`, `.sk`, `.com`, `eu.`) i vlastní adresy.
- **Test spojení** přímo v administraci — ověří údaje proti `GET /security/info` a rovnou vypíše ID číselníků (kategorie, stav leadu, zdroj kontaktu, šablony a formy GDPR souhlasu).
- **Ochrana proti spamu**: honeypot, časová past a omezení frekvence na IP adresu.
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
| Elementor Pro | volitelně, jen pro formuláře Elementoru |

API klíč vygenerujete v RAYNET CRM v **Nastavení → Uživatelé → *váš uživatel* → API klíč**.

---

## Instalace

### Z archivu

1. Z [Releases](https://github.com/nechodom/Raynet-Lead-API-Integration/releases/latest) stáhněte `raynet-lead-api-integration-X.Y.Z.zip`.
2. Ve WordPressu jděte na **Pluginy → Přidat nový → Nahrát plugin** a archiv nahrajte.
3. Plugin aktivujte.

Verzi 2.x můžete stejným postupem přepsat; nastavení ani formuláře se neztratí.

Verzi 1.x nahrání nepřepíše: pro WordPress je to jiný plugin (jiný hlavní soubor, obvykle i složka `raynet-lead-API-integration/`) a zůstal by aktivní vedle nového — a dál by vypisoval API klíč do stránek. Starý plugin proto nejdřív deaktivujte a smažte, pak nahrajte nový. Nastavení včetně API klíče se převede samo.

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

Priorita (`MINOR` / `DEFAULT` / `CRITICAL`), přepínač *Fyzická osoba* (`leadPerson`), výchozí předmět, poznámka vkládaná před zprávu návštěvníka, štítky, notifikační e-maily a ID z číselníků RAYNETu — kategorie, stav leadu, zdroj kontaktu, vlastník, bezpečnostní úroveň. Hodnota `0` znamená „tento atribut neposílat“ a RAYNET si doplní výchozí.

**ID kategorie, stavu leadu a zdroje kontaktu nemusíte hledat ručně** — tlačítko *Otestovat spojení* je po úspěšném přihlášení vypíše. ID vlastníka (kontaktní osoby, která je zároveň uživatelem) a bezpečnostní úrovně najdete v RAYNETu.

### GDPR souhlas v RAYNETu

Šablona právního titulu, forma udělení souhlasu a platnost v měsících. Vyplněná šablona znamená, že plugin k leadu se zaškrtnutým souhlasem založí i GDPR záznam — viz [GDPR](#gdpr).

### Chování formuláře

Hlášky po odeslání i při chybě, přesměrování po úspěchu, nastavení ochrany proti spamu, záložní e-mail, zapisování chyb do logu a mazání dat při odinstalaci. Souhlas se zpracováním údajů se nastavuje na formuláři, ne tady.

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

> Verze 2.1.0 a starší tohle neumí a 2.2.0 shodí web dřív, než by se aktualizace nabídla. Z nich je potřeba přejít ručně.
>
> **Verze 2.1.0 a 2.2.0 shodí web fatální chybou a verze 2.1.0 až 2.2.2 rozbíjejí oprávnění `manage_options` pro celý web.** Pokud jste na některé z nich, nahrajte ručně poslední verzi z [Releases](https://github.com/nechodom/Raynet-Lead-API-Integration/releases/latest).

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

**Pole RAYNETu** — deset atributů leadu: Jméno, Příjmení, Společnost, E-mail, Telefon, Předmět, Zpráva, Ulice, Město, PSČ. K tomu **Jméno a příjmení**, které se do RAYNETu rozdělí na jméno a příjmení — viz [Jedno pole pro celé jméno](#jedno-pole-pro-celé-jméno). Každé smí být ve formuláři jen jednou a jeho typ se měnit nedá; přepnutí e-mailu na dlouhý text by rozbilo validaci i mapování. Jméno a příjmení nejde kombinovat se samostatným Jménem nebo Příjmením: pole přidané jako druhé se při uložení zahodí.

**Vlastní pole** — cokoliv, co v RAYNETu vlastní atribut nemá: „Odkud jste se o nás dozvěděli?", „Počet zaměstnanců", zaškrtávátko „Chci newsletter". Na výběr je jednořádkový text, víceřádkový text, výběr z možností a zaškrtávátko. **Hodnota se zapíše do poznámky leadu** pod popiskem, který jste poli dali:

```
Odkud jste se o nás dozvěděli?: Google
Chci newsletter: ne
```

U zaškrtávátka se zapíše i odpověď „ne" — u dotazu na newsletter je to ta zajímavější.

**Souhlas se zpracováním** — zaškrtávátko GDPR. Jeho text je prostý: HTML včetně odkazů se při uložení odstraní, odkaz na zásady proto dejte do textu nad formulářem. Formulář ho může mít nejvýš jeden a je vždy povinný; nepovinné zaškrtávátko souhlasu je horší než žádné. Do poznámky leadu se zapíše datum, čas a znění souhlasu, a s nastavenou šablonou vznikne i GDPR záznam — viz [GDPR](#gdpr).

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

**Mapování polí** — ke každému atributu RAYNETu vyberete pole formuláře. Nabídka ukazuje popisky polí, ne jejich ID. Nenamapované atributy se neodesílají. Pod mapováním je výběr **Zapsat do poznámky** pro pole bez protějšku v RAYNETu.

> Pohodlnější je opačný pohled: **RAYNET CRM → Elementor formuláře → Namapovat pole** vypíše pole formuláře a u každého se zeptá, kam patří. Viz [Mapování po polích formuláře](#mapování-po-polích-formuláře).

Seznam atributů se doplňuje sám: nový atribut v pluginu nebo vlastní pole, které už plugin z RAYNETu načetl (viz *Pole z RAYNETu* níže), se objeví v mapování i u formuláře nastaveného dřív, jakmile sekci RAYNET CRM otevřete. Nové vlastní pole z RAYNETu se v editoru ukáže až po načtení na obrazovce **Elementor formuláře** — samo nejdřív po 12 hodinách, hned tlačítkem **Načíst pole z RAYNETu znovu**. Co už bylo namapované, zůstane.

**Nastavení leadu** — předmět, priorita, typ leadu, předpona poznámky, číselníková ID, štítky a notifikační e-maily, zvlášť pro tenhle formulář. Prázdné pole znamená zdědit z nastavení pluginu, stejně jako u builderu.

Dva přepínače navíc:

- **Zapsat udělení souhlasu** — zapněte jen tehdy, když formulář obsahuje povinné pole se souhlasem. Do poznámky leadu se pak zapíše datum a čas. Vypnuté je záměrně: formulář, který se na souhlas neptá, nesmí do CRM napsat, že padl. Má-li formulář zaškrtávátko typu Souhlas, musí být zaškrtnuté, jinak se souhlas nezapíše. Přepínač zapisuje jen do poznámky; formální GDPR záznam v RAYNETu vzniká jen z namapovaného pole. Přesnější je proto namapovat samotné zaškrtávátko na **Souhlas se zpracováním údajů (GDPR)** — viz [GDPR](#gdpr); pak rozhoduje jeho zaškrtnutí a přepínač se nepoužije.
- **Uvést URL stránky** — připíše do poznámky adresu stránky, ze které poptávka přišla.

### Jedno pole pro celé jméno

Formuláře často mají jediné pole „Jméno a příjmení". RAYNET ale ukládá `firstName` a `lastName` zvlášť.

V mapování je proto vedle Jména a Příjmení i atribut **Jméno a příjmení**. Namapujete na něj to jedno pole a plugin ho rozdělí: poslední slovo je příjmení, všechno před ním jméno.

| Zadáno | firstName | lastName |
|---|---|---|
| `Jan Novák` | Jan | Novák |
| `Jan Petr Novák` | Jan Petr | Novák |
| `Novák` | – | Novák |

Jedno slovo se bere jako příjmení, protože podle něj se v CRM vyhledává.

Namapovat současně celé jméno i jednu z jeho půlek nejde — jedno by přepsalo druhé. V builderu se taková kombinace neuloží: pozdější z obou polí se z formuláře tiše vypustí. V Elementoru vyhraje samostatně namapovaná půlka.

Potřebujete-li jiné pravidlo, třeba pro formulář ptající se na „Příjmení a jméno", přepište ho filtrem:

```php
add_filter( 'raynet_lead_split_name', function ( $parts, $full ) {
	$words = explode( ' ', $full, 2 );

	return array(
		'lastName'  => $words[0],
		'firstName' => isset( $words[1] ) ? $words[1] : '',
	);
}, 10, 2 );
```

### Všechna pole RAYNETu, včetně vlastních

Mapování nabízí tři skupiny atributů:

1. **Základní** — jméno, příjmení, celé jméno, společnost, e-mail, telefon, předmět, zpráva, ulice, město, PSČ.
2. **Další standardní** — titul před a za jménem, IČO, DIČ, datová schránka, druhý e-mail a telefon, web, fax, jiný kontakt, kraj, země, souhlas se zpracováním údajů (GDPR), souhlas s marketingovými sděleními a sociální sítě.
3. **Vlastní pole** vaší instance — v mapování je poznáte podle přípony *(vlastní pole)*.

Vlastní pole plugin načítá z RAYNETu (`GET /customField/config/`). Stav najdete v **RAYNET CRM → Elementor formuláře → Pole z RAYNETu**: seznam polí s typem a kódem v API, čas posledního načtení a tlačítko **Načíst pole z RAYNETu znovu**. Samo se načítání obnovuje jednou za 12 hodin při návštěvě té obrazovky. Po chybě to zkouší až za hodinu, protože RAYNET po 20 neúspěšných přihlášeních blokuje IP adresu.

Nenabízejí se vlastní pole jen pro čtení a vlastní pole typu soubor. Soubory z formuláře se místo toho přikládají k leadu jako přílohy — viz [Přílohy z formuláře](#přílohy-z-formuláře).

**Převod hodnot.** Formulář posílá text, RAYNET chce u vlastních polí správný typ. Plugin hodnotu převede:

| Typ pole v RAYNETu | Co formulář může poslat | Co dostane RAYNET |
|---|---|---|
| číslo, částka, procenta | `1 234,50` · `1,234.50` · `1 499,- Kč` · `15 %` | `1234.5` · `1499` · `15` |
| datum | `2026-12-01` · `1. 12. 2026` · `01/12/2026` | `2026-12-01` |
| datum a čas | `1. 12. 2026 9:30` · `2026-12-01T09:30` | `2026-12-01 09:30` |
| čas | `9:30` · `9.30` | `09:30` |
| ano/ne | `ano`, `souhlasím`, `chci`, zaškrtnuté pole · `ne`, `nesouhlasím`, `nechci`, `no` | `true` · `false` |
| výběr z číselníku | položka číselníku, velikost písmen a diakritika nevadí | položka přesně tak, jak ji zná RAYNET |
| text, odkaz | cokoliv | text, nejvýš 255 znaků (delší se zkrátí) |
| dlouhý text | cokoliv | text, nejvýš 5 000 znaků (delší se zkrátí) |

Co převést nejde — nečitelné datum, položka, která v číselníku není, odpověď ano/ne, ze které nejde poznat ani jedno, pole, které mezitím v RAYNETu smazali — se **nezahodí a neshodí lead**. Zapíše se do poznámky leadu pod názvem pole v RAYNETu (u pole, které v RAYNETu smazali, pod jeho kódem v API) a lead se založí bez něj. Smazané pole plugin pozná až z nově načteného seznamu polí z RAYNETu.

Plugin radši odmítne, než aby hádal. Číslo se slovy („50 tis.", „od 10 do 20") nebo s jediným oddělovačem a přesně třemi číslicemi za ním („25.000" je česky dvacet pět tisíc, anglicky dvacet pět) skončí v poznámce, ne jako věrohodně vypadající špatné číslo v CRM.

Pole typu **Číslo** v Elementoru bere plugin tak, jak ho návštěvník napsal. Elementor sám z něj dělá celé číslo, takže by z nevyplněného pole byla nula a z IČO „02795281" číslo 2795281.

Prázdná hodnota se neposílá vůbec. RAYNET by prázdnou hodnotou pole vymazal.

Dvě standardní pole se chovají zvlášť:

- **Země** — RAYNET bere jen dvoupísmenný kód ISO (`CZ`). Plugin pozná platné kódy, zkratky ČR, SR a UK i běžné názvy: Česko, Česká republika, Slovensko, Polsko, Německo, Rakousko, Maďarsko, Velká Británie, USA.
- **Souhlas s marketingovými sděleními** — RAYNET ukládá opak, „neposílat marketingová sdělení". Souhlas platí jen jako jasné ano: zaškrtnuté zaškrtávátko, nebo odpověď typu „Ano" či „Souhlasím". Nezaškrtnuté pole, „Nesouhlasím" i odpověď, ze které nejde nic poznat, znamenají *neposílat*; nejasnou odpověď plugin navíc zapíše do poznámky.

Vyplněné IČO, stejně jako název společnosti, přepne lead na firmu.

Když RAYNET lead nepřijme, **záložní e-mail** obsahuje všechno namapované — i rozšířené atributy, vlastní pole a hodnoty určené do poznámky, ne jen jméno a kontakt.

U formuláře v popupu, hlavičce nebo patičce zapíše **Uvést URL stránky** adresu stránky, na které se formulář zobrazil, ne adresu šablony.

### Mapování po polích formuláře

V **RAYNET CRM → Elementor formuláře** má každý formulář odkaz **Namapovat pole**. Obrazovka vypíše pole, která formulář teď má — s popiskem, ID a typem — a u každého nabídne, kam patří:

- **atribut RAYNETu** — základní, další standardní nebo vlastní pole vaší instance,
- **Zapsat do poznámky leadu** — pro pole, které v RAYNETu protějšek nemá; hodnota se připíše do poznámky pod popiskem pole, třeba `Preferovaná barva: Modrá`,
- **Neodesílat** — pole se do RAYNETu nedostane vůbec.

Pole, o kterých ještě nikdo nerozhodl, obrazovka předvyplní **návrhem** a zvýrazní ho: podle popisku a typu odhadne atribut, a co odhadnout nejde, navrhne do poznámky. Pole typu **Souhlas** (Acceptance) pozná jako souhlas podle popisku a textu, který návštěvník vidí vedle něj (obyčejné pole **Zaškrtávátko** takhle nerozpozná, souhlas v něm namapujte ručně): „gdpr", „zpracování osobních údajů" a podobné navrhne jako **Souhlas se zpracováním údajů (GDPR)**, „newsletter" nebo „obchodní sdělení" jako **Souhlas s marketingovými sděleními**. Zaškrtávátko, které souhlas **odmítá** („Nepřeji si zasílat obchodní sdělení", „Nesouhlasím…") nebo jen potvrzuje seznámení („Beru na vědomí…"), jako souhlas nikdy nenavrhne — zaškrtnutí by zapsalo opak toho, co návštěvník řekl. Takové a jiné nerozpoznané navrhne vynechat. U zaškrtávátka, které je uložené jako „Neodesílat", ale vypadá jako souhlas (typicky z verze 2.6.0), obrazovka upozorní, ať ho přepnete. Návrh se uloží až tlačítkem **Uložit mapování**.

Přehled formulářů u zapnutého formuláře ukáže, kolik polí je **bez určení** — třeba když do formuláře v Elementoru přibude nové pole — a odkazem vede rovnou na mapování.

Jeden atribut může plnit jen jedno pole a celé jméno nejde kombinovat se samostatným jménem nebo příjmením; takové uložení obrazovka odmítne a řekne proč. Pole pro nahrání souboru nabízí jen **Přiložit k leadu** nebo **Neodesílat** — viz [Přílohy z formuláře](#přílohy-z-formuláře). Pole, která odeslat nejde nebo nemá (**heslo**, reCAPTCHA, honeypot, HTML, krok), se nenabízejí vůbec. Heslo se do RAYNETu nepošle nikdy, ani když ho někdo namapuje v editoru.

**Uložení mění jen to, co jste na obrazovce změnili.** Pole, které v Elementoru plní dva atributy, obrazovka ukáže s poznámkou „Pole plní také…" a druhé mapování zůstane, dokud výběr u pole nezměníte. Mapování, které obrazovka neukazuje (třeba nahraný soubor namapovaný v editoru), zůstane taky.

Uložení zapisuje do stránky stejně jako nasazení šablony: předchozí podoba se zálohuje a neuložený koncept dostane změnu taky. Obrazovka ale ukazuje publikovanou verzi; má-li stránka koncept, upozorní na to, a pole, která má jen koncept, nechá, jak jsou.

Hromadné nasazení šablony rozhodnutí z obrazovky respektuje: pole, které jste poslali do poznámky nebo vynechali, odhad znovu nenamapuje.

Totéž jde i přímo v Elementoru: sekce **RAYNET CRM** má pod mapováním výběr **Zapsat do poznámky** s poli formuláře.

### Přílohy z formuláře

Soubory z polí typu **Nahrání souboru** (Upload) se po založení leadu nahrají do RAYNETu a připojí k leadu jako **přílohy** — v RAYNETu je najdete v záložce Přílohy u leadu. Poznámka leadu je navíc jmenuje pod názvem pole, třeba `Fotografie střechy: strecha1.jpg, strecha2.jpg`.

- Platí pro každé nahrávací pole, u kterého jste na obrazovce **Namapovat pole** nezvolili **Neodesílat**.
- Funguje v obou režimech Elementoru — odkaz i příloha e-mailu. U přílohy e-mailu Elementor soubor po odeslání e-mailu maže, plugin si ho proto zajistí dřív.
- Soubor se v RAYNETu jmenuje tak, jak ho návštěvník nahrál, ne náhodným jménem, pod kterým ho uloží Elementor.
- Limit je 20 MB na soubor a 50 MB na jeden lead. Co je větší, se nepřiloží a poznámka to u souboru uvede.
- Když RAYNET přílohu odmítne, lead zůstane; chyba se zapíše do logu a ukáže nahoře na stránce nastavení. Když odmítne celý lead, dorazí soubory jako přílohy **záložního e-mailu** — do 10 MB celkem, protože větší e-maily poštovní servery odmítají; zbytek e-mail vyjmenuje. Kdyby server e-mail s přílohami přesto odmítl, odejde znovu bez nich.
- Plugin posílá jen soubory, které Elementor sám přijal a uložil. Cesta k souboru, kterou by někdo podstrčil v odeslaných datech, se nepoužije.

Každá příloha jsou dva požadavky na API (nahrání souboru a připojení k leadu), což se počítá do denního limitu 24 000 požadavků.

### Hromadné nasazení na víc formulářů

V **RAYNET CRM → Elementor formuláře** plugin vypíše každý formulář Elementoru na webu: kde je, jak se jmenuje, jaká má pole a jestli u něj RAYNET běží.

Hledá ve všech typech obsahu a v knihovně šablon Elementoru. Najde tedy i formulář v **popupu**, **hlavičce**, **patičce**, **uložené sekci** nebo **globálním widgetu**; sloupec *Umístění* říká, o co jde. Formulář vložený do šablony se nastavuje v té šabloně, protože odtud si ho Elementor při odeslání čte — nasazení se tak projeví všude, kde se šablona zobrazuje.

Dva druhy formulářů tabulka ukáže, ale nastavit nedovolí:

- **Stránka přepnutá zpět do editoru WordPressu.** Elementor ji už nevykresluje, starý formulář na webu není.
- **Atomový formulář Elementoru 4.** Je to jiný prvek než widget Formulář, s pevným seznamem akcí (e-mail, sběr odeslání, webhook), do kterého se RAYNET zatím zapojit nedá. Pro leady použijte widget **Formulář**.

**Šablona** nese nastavení leadu — předmět, prioritu, typ leadu, předponu poznámky, číselníková ID, štítky a notifikační e-maily. Šablon můžete mít víc, třeba zvlášť pro poptávky a zvlášť pro kontaktní formuláře.

Vyberete formuláře, zvolíte šablonu a nasadíte. U každého se zapne akce RAYNET CRM a vyplní se nastavení ze šablony. Akce, které formulář měl — třeba e-mailová notifikace — zůstanou.

**Odhad mapování** doplní jen to, co formulář namapované nemá. Co jste namapovali ručně, zůstane i při opakovaném nasazení. Odhad se řídí nejdřív popiskem a pak typem pole. Pozná „PSČ", „Jméno a příjmení", „IČO" i „IČ DPH", diakritika nevadí. Vlastní pole přiřadí, když se popisek pole formuláře shoduje s jeho názvem v RAYNETu. Jedno pole nikdy neobsadí dva atributy.

Volba **Pole bez protějšku v RAYNETu zapsat do poznámky** (ve výchozím stavu zapnutá) pošle do poznámky všechna pole, pro která odhad atribut nenašel a o kterých jste dřív nerozhodli jinak, takže se nic z vyplněného neztratí. Výjimkou je nerozpoznané zaškrtávátko typu Souhlas: to nastaví na **Neodesílat**, protože v poznámce by stálo jen „on“.

Odhad není věštec. Po nasazení se vyplatí formulář zkontrolovat přes **Namapovat pole**. Formulář, u kterého chybí namapovaný e-mail i telefon, tabulka označí: RAYNET by z něj žádný lead nepřijal.

> **Nasazení zapisuje do vašich stránek.** Předchozí podoba se uloží a v tabulce přibude **Vrátit stránku zpět**. Vrací celou stránku, tedy i ostatní formuláře na ní.
>
> Záloha je jedna na stránku. Několik nasazení po sobě ji nepřepíše, takže se vracíte k podobě před prvním z nich. Upravíte-li ale stránku mezi nasazeními v Elementoru, další nasazení uloží jako zálohu už upravenou podobu — vrácení tak vaši práci nesmaže. Když stránku upravíte po posledním nasazení, plugin to pozná a vrácení odmítne; pokračovat jde jen po potvrzení.
>
> **Neuložený koncept.** Má-li stránka v Elementoru rozpracovaný, nepublikovaný koncept, nasazení se zapíše do stránky i do konceptu. Koncept tak zůstane, jak byl, a po publikování nastavení RAYNETu neztratí. Vrácení stránky zpět se při čekajícím konceptu zastaví a řekne proč: koncept nejdřív publikujte nebo zahoďte.

### Co obstarává Elementor a co plugin

Validaci povinných polí, reCAPTCHA i honeypot (pokud je ve formuláři máte) řeší Elementor; nonce Elementor u odeslání formuláře nekontroluje. Plugin tyhle kontroly **neopakuje** — vlastní časová past ani limit na IP by se s Elementorem tloukly a odmítaly by legitimní odeslání.

Co zůstává na pluginu, je pravidlo RAYNETu, které Elementor nezná: **lead musí nést e-mail nebo telefon**. Bez jednoho z nich se odeslání odmítne.

### Když se odeslání nepovede

Návštěvník uvidí chybovou hlášku, kterou si nastavíte přímo v Elementoru (*Additional Options → Custom Messages → Error Message*).

Skutečný důvod — třeba `RAYNET odmítl přihlášení (401)` — se zobrazí **jen přihlášenému uživateli s právem stránku editovat**. Elementor na to má vlastní kanál a diagnostika se tak k návštěvníkovi nedostane.

Lead navíc zachytí záložní e-mail, pokud ho máte v nastavení vyplněný. U Elementoru to doporučuji dvojnásob: odeslání je synchronní, žádná fronta na opakování neexistuje.

### Export šablony

Při exportu formuláře jako šablony se mapování polí (včetně polí do poznámky a vynechaných polí), předmět, předpona poznámky, číselníková ID, vlastník, štítky i notifikační e-maily odstraní. Zůstane jen priorita, typ leadu a oba přepínače. Jsou vázané na jednu instanci RAYNETu a v cizím webu by zakládaly leady pod cizí kategorií. Přihlašovací údaje v nastavení Elementoru vůbec nejsou — zůstávají v nastavení pluginu.

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
| `topic` | – | Předmět leadu pro případ, že formulář nemá pole Předmět nebo ho návštěvník nevyplní. Má přednost před předmětem nastaveným na formuláři i v nastavení pluginu. |
| `title` | – | Nadpis nad formulářem. |
| `button` | `Odeslat` | Popisek odesílacího tlačítka. |
| `class` | – | Další CSS třída formuláře. |
| `redirect` | z formuláře, pak z nastavení | URL, kam přesměrovat po úspěšném odeslání. |

Pole se nastavují v builderu, ne ve zkratce.

Server vždy vyžaduje **e-mail nebo telefon**, i kdyby je builder označil jako nepovinné — bez kontaktu je lead k ničemu.

### Zastaralé atributy

`fields` a `required` fungují dál, ale jen jako filtr nad poli RAYNETu ve formuláři: `fields=` vybere a seřadí jejich podmnožinu, `required=` jim přepíše povinnost. Zůstávají kvůli stránkám, které je nesou z verze 2.0.

```
[raynet_lead_form fields="email,message"]
```

Pro nový web je místo nich lepší založit druhý formulář — nastavení je pak na jednom místě.

Souhlas a vlastní pole ve `fields=` pojmenovat nejde. Filtr je proto nikdy neodebere, jen je přesune na konec, a `required=` je nemění. Pole Jméno a příjmení pojmenovat nejde taky: `fields=` ho odebere a `required=` ho udělá nepovinným.

---

## Jak vypadá lead v RAYNETu

Plugin založí lead přes `PUT /api/v2/lead/`:

| Z formuláře nebo nastavení | Atribut RAYNETu |
|---|---|
| Předmět / atribut `topic` / předmět z formuláře nebo nastavení, jinak „Poptávka z webu *název webu*“ | `topic` *(povinné)* |
| nastavení | `priority` *(povinné)* |
| – | `leadDate` — dnešní datum |
| Jméno, Příjmení (nebo rozdělené celé jméno) | `firstName`, `lastName` |
| Společnost | `companyName` |
| E-mail, Telefon | `contactInfo.email`, `contactInfo.tel1` |
| Ulice / Město / PSČ | `address.street` / `address.city` / `address.zipCode` |
| další standardní atributy (Elementor) | `regNumber`, `taxNumber`, `databox`, `titleBefore`, `titleAfter`, `contactInfo.email2`, `contactInfo.tel2`, `contactInfo.www`, `contactInfo.fax`, `contactInfo.otherContact`, `contactInfo.doNotSendMM`, `address.province`, `address.country`, `socialNetworkContact.*` |
| vlastní pole instance (Elementor) | `customFields` |
| viz níže | `notice` |
| nastavení | `category`, `leadPhase`, `contactSource`, `owner`, `securityLevel`, `tags` |
| nastavení | `notificationEmailAddresses`, `notificationMessage` |

Příznak `leadPerson` se řídí nastavením, ale jakmile návštěvník vyplní **Společnost** nebo **IČO**, lead se založí jako firma. Prázdné atributy se do RAYNETu vůbec neposílají.

**Poznámka leadu** (`notice`) se skládá v tomto pořadí:

```
Přidáno z webu example.cz                      ← předpona poznámky z nastavení

Popis poptávky:                                ← zpráva pod názvem svého pole
Dobrý den, prosím o nabídku zateplení…

Preferovaná barva: Modrá                        ← pole určená do poznámky
Velikost zakázky: Obrovská                      ← hodnoty, které nešly převést na typ pole
Fotografie střechy: strecha1.jpg, strecha2.jpg  ← přílohy

Odesláno ze stránky: https://example.cz/kontakt/

Souhlas se zpracováním údajů udělen: 2026-09-24 15:30 — „Souhlasím se zpracováním osobních údajů"
```

Po založení leadu následují dva kroky, oba jen když mají co dělat a oba bez vlivu na to, co uvidí návštěvník:

1. **GDPR záznam** — `PUT /gdpr/` se šablonou z nastavení, když je pole souhlasu zaškrtnuté (v Elementoru pole namapované na Souhlas se zpracováním údajů; přepínač *Zapsat udělení souhlasu* záznam nezakládá).
2. **Přílohy** (jen formuláře Elementoru) — každý soubor `POST /fileUpload` a `PUT /attachment/lead/{id}/`.

Selže-li některý z nich, lead zůstane a chyba se ukáže nahoře na stránce nastavení.

---

## Ochrana proti spamu

Tři nezávislé vrstvy, všechny vypnutelné v nastavení:

1. **Honeypot** — skryté pole `website`. Vyplněné pole = robot.
2. **Časová past** — formulář nelze odeslat dřív než N sekund od chvíle, kdy server stránku vykreslil. Časová značka je podepsaná přes `wp_hash()`, takže ji nelze podvrhnout, a platí 24 hodin; starší stránku past odmítne.
3. **Omezení frekvence** — z jedné IP adresy nejvýš jedno odeslání za N sekund, drženo v transientu.

K tomu WordPress nonce. Pokud běží na webu plná cache stránek a nonce vyprší, JavaScript si jednou automaticky vyžádá nový a odeslání zopakuje. Časovou značku ale neobnoví: stránku z cache starší než 24 hodin časová past odmítne. Stránky s formulářem proto držte v cache kratší dobu než den, nebo časovou past vypněte nulou.

---

## GDPR

- Souhlas je pole formuláře. V builderu ho přidáte jako pole Souhlas a je vždy povinný. V Elementoru namapujete zaškrtávátko na **Souhlas se zpracováním údajů (GDPR)**.
- Souhlas platí jen tehdy, když je pole **zaškrtnuté**. Formulář bez pole souhlasu nebo s nezaškrtnutým polem do RAYNETu o souhlasu nic nezapíše. Výjimkou je starší přepínač *Zapsat udělení souhlasu* v Elementoru: bez namapovaného pole souhlasu zapíše souhlas do poznámky i u formuláře, který nemá žádné zaškrtávátko typu Souhlas, a má-li jich formulář víc, stačí zaškrtnout jedno. Takový souhlas je bez znění a bez GDPR záznamu — spolehlivější je namapovat zaškrtávátko na **Souhlas se zpracováním údajů (GDPR)**.
- Udělený souhlas se zapíše do poznámky leadu s datem, časem a zněním — textem, který návštěvník u zaškrtávátka viděl, ne interním popiskem pole. Třeba: `Souhlas se zpracováním údajů udělen: 2026-09-24 15:30 — „Souhlasím se zpracováním osobních údajů"`.
- **GDPR záznam v RAYNETu.** Vyberete-li v **Nastavení → GDPR souhlas v RAYNETu** šablonu právního titulu, plugin k novému leadu založí i právní titul (`PUT /gdpr/`) — v RAYNETu ho uvidíte v GDPR záložce leadu. Volitelně s formou souhlasu (třeba „elektronicky") a platností v měsících od data odeslání (31. 1. + 1 měsíc = 28. 2.). ID šablon a forem vypíše tlačítko **Otestovat spojení**. Záznam vzniká jen z doloženého souhlasu: ze zaškrtnutého pole Souhlas v builderu nebo ze zaškrtnutého pole namapovaného na Souhlas se zpracováním údajů v Elementoru.
- Kdyby RAYNET GDPR záznam odmítl, lead už existuje a odeslání se nezruší. Chyba se zapíše do logu a ukáže nahoře na stránce nastavení; souhlas zůstane zaznamenaný v poznámce.
- Plugin **neukládá odeslaná data do databáze WordPressu**. Jdou rovnou do RAYNETu.
- Soubory nahrané přes formulář Elementoru ukládá Elementor sám do `uploads/elementor/forms/` (a u režimu příloha e-mailu je po odeslání maže). Plugin si dělá jen dočasné kopie v dočasném adresáři serveru, čitelné jen pro systémového uživatele, pod kterým běží PHP, a na konci požadavku je maže. Kopie po přerušeném požadavku smaže nejpozději další odeslání formuláře po hodině.
- IP adresa se používá jen pro omezení frekvence, ukládá se jako hash v transientu a do CRM se neposílá.
- Do poznámky leadu jde URL stránky, ze které poptávka přišla.

---

## Hooky pro vývojáře

### `raynet_lead_payload` (filtr)

Poslední místo před odesláním do RAYNETu.

```php
add_filter( 'raynet_lead_payload', function ( $payload, $values ) {
	// Přidat, ne přepsat: customFields už mohou nést namapovaná vlastní pole.
	$payload['customFields'] = array_merge(
		isset( $payload['customFields'] ) ? $payload['customFields'] : array(),
		array( 'Zdroj_ab12c' => 'web' )
	);

	if ( false !== strpos( $values['message'], 'urgentní' ) ) {
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

### `raynet_lead_gdpr_record` (filtr)

Upraví GDPR záznam (právní titul) před odesláním do RAYNETu, třeba jinou šablonu pro určitý formulář.

```php
add_filter( 'raynet_lead_gdpr_record', function ( array $record, $lead_id ) {
	$record['validTill'] = gmdate( 'Y-m-d', strtotime( '+3 years' ) );
	return $record;
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
| **„Platnost formuláře vypršela. Zkuste to prosím znovu.“** | Nonce vypršel. Plugin se zotaví sám; pokud ne, zkontrolujte, že cache nekešuje i `admin-ajax.php`. |
| **„Platnost formuláře vypršela. Načtěte prosím stránku znovu.“** | Chybí nebo nesedí podepsaná časová značka (`raynet_ts`, `raynet_ts_hash`) — typicky stránka z cache po změně bezpečnostních klíčů webu, nebo vlastní frontend, který tato pole neposílá. Vyprázdněte cache stránek. |
| **„Formulář byl odeslán příliš rychle“** | Časová past: návštěvník odeslal dřív, než uplynula „Minimální doba vyplňování“, nebo je stránka s formulářem starší než 24 hodin (plná cache stránek, dlouho otevřená záložka). Snižte dobu, držte stránky s formulářem v cache kratší dobu než 24 hodin, nebo časovou past vypněte nulou. |
| **Formulář se nevykreslí** | Zkratka musí být v obsahu stránky, ne v úryvku. V blokovém editoru použijte blok *Zkratka*. |
| **Tlačítko nereaguje** | Chyba v konzoli prohlížeče. Nejčastěji jiný plugin rozbil načítání skriptů. |
| **Test spojení hlásí chybu, ale údaje jsou správné** | Nastavení nejdřív **uložte**, teprve pak testujte. |
| **Formulář Elementoru v přehledu chybí** | Od verze 2.5.0 přehled prohledává i popupy, hlavičky, patičky a globální widgety. Chybí-li formulář dál, jde o jiný prvek než widget Formulář Elementor Pro. |
| **Po nasazení šablony akce v editoru Elementoru chybí** | Verze do 2.4.0 nechávaly vyhrát starší automaticky uloženou verzi stránky. Aktualizujte a šablonu nasaďte znovu. |
| **V mapování chybí vlastní pole z RAYNETu** | **Elementor formuláře → Pole z RAYNETu → Načíst pole z RAYNETu znovu**. Pole jen pro čtení a pole typu soubor se nenabízejí. |
| **Hodnota vlastního pole je v poznámce leadu, ne v poli** | Nešla převést na typ pole — třeba datum v nečitelném tvaru nebo položka mimo číselník. Viz [převod hodnot](#všechna-pole-raynetu-včetně-vlastních). |
| **Souhlas je v poznámce bez znění** | Zapsal ho přepínač *Zapsat udělení souhlasu*, ne namapované pole. V **Namapovat pole** přepněte zaškrtávátko souhlasu na **Souhlas se zpracováním údajů (GDPR)**. |
| **GDPR záznam u leadu nevzniká** | V nastavení chybí šablona právního titulu, nebo zaškrtávátko není namapované na Souhlas se zpracováním údajů (samotný přepínač GDPR záznam nezakládá). Když ho RAYNET odmítl, důvod je nahoře na stránce nastavení. |
| **Přílohy u leadu chybí** | Nahrávací pole je v **Namapovat pole** nastavené na Neodesílat, soubor je větší než 20 MB nebo s ostatními přesáhl 50 MB na lead, server ho nemohl zkopírovat do dočasného adresáře (v těchto případech to říká poznámka leadu u souboru), nebo ho RAYNET odmítl — důvod je nahoře na stránce nastavení. |
| **Formulář hlásí chybu, ale lead v RAYNETu je** | Selhala jiná akce formuláře, typicky e-mailová notifikace Elementoru (nefunkční odesílání pošty na webu). Přihlášený správce vidí u chyby, která akce to byla. |

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
| Serverový AJAX handler | Byl zaregistrovaný, ale JavaScript ho nikdy nezavolal; neměl nonce ani validaci | Cesta k odeslání (spolu s REST route se stejnými kontrolami), s nonce, sanitizací a limity |
| Kontrola odpovědi | Žádná — HTTP 401 se tvářilo jako úspěch | Rozlišuje 201, 4xx, 5xx i výpadek spojení |
| Zpráva z formuláře | **Zahazovala se** — do CRM šla jen statická poznámka z nastavení | Jde do `notice` spolu s poznámkou a URL stránky |
| Formulář | Samostatný `form.html` k ručnímu zkopírování | Zkratka `[raynet_lead_form]` s volitelnými poli |
| Adresa API | Celá adresa endpointu v poli „API URL“, kterou četl JavaScript v prohlížeči; serverový handler ji měl napevno | Výběr regionu nebo vlastní adresa |
| Načítání skriptů | Na každé stránce webu | Jen tam, kde je formulář |
| Ochrana proti spamu | Žádná | Honeypot, časová past, limit na IP |
| API klíč v administraci | Textové pole, klíč viditelný | Pole typu `password`, klíč se nevypisuje zpět |

**Aktualizace z 1.x:** starý plugin nejdřív deaktivujte a smažte — nová verze ho nepřepíše, viz [Instalace](#instalace). Nastavení se převede automaticky při první aktivaci verze 2 — včetně API klíče, názvu instance a vlastní poznámky. Původní formulář z `form.html` nahraďte zkratkou `[raynet_lead_form]`.

> **Doporučení:** pokud jste verzi 1.x provozovali na veřejném webu, **vygenerujte v RAYNETu nový API klíč a starý zneplatněte.** Ten původní byl viditelný ve zdrojovém kódu stránek a mohl být zachycen.

---

## Vývoj a testy

Repozitář obsahuje testy, které plugin proženou bez instalace WordPressu — `tests/wp-stubs.php` nahrazuje potřebné funkce jádra.

```bash
php tests/run.php
```

Pokrývají sanitizaci nastavení, převod konfigurace z 1.x, sestavení payloadu pro RAYNET, zpracování odpovědí API, celý průchod odeslání včetně ochran proti spamu, vykreslení zkratky, builder, stránku nastavení, aktualizace, odhad mapování, převod hodnot vlastních polí, souhlas a přílohy. Testy vyžadují PHP 8.0+; samotný plugin běží od PHP 7.4.

### Integrační sada

Stuby nesimulují jádro WordPressu, takže pořadí hooků, mapování oprávnění ani sestavení menu nepokryjí — a právě tam vznikly chyby ve verzích 2.1.0, 2.2.1 a 2.2.2. Na to je druhá sada, která běží proti skutečnému WordPressu na SQLite:

```bash
bin/wp-test-setup.sh   # jednou: stáhne WordPress, SQLite integraci a wp-cli do .wp-test/
bin/wp-test.sh         # přes 280 kontrol
```

Ověřuje pořadí hooků, že `manage_options` zůstává funkční, položky menu, načtení skriptů builderu, vykreslení formuláře na veřejné stránce, skutečné odeslání přes `admin-ajax.php` až k payloadu pro RAYNET, a aktualizační transient. Odchozí HTTP i e-maily zachytává testovací dvojník, takže běh nikdy nesáhne na RAYNET ani na GitHub a nic neodešle.

Adresář `.wp-test/` je mimo git a dá se kdykoliv zahodit; `bin/wp-test-setup.sh --fresh` ho postaví znovu.

Většina kontrol se týká Elementoru a běží jen tehdy, když je Elementor Pro v testovací instalaci přítomné: nakopírujte `elementor` a `elementor-pro` do `.wp-test/wp/wp-content/plugins/` a aktivujte je. Pak sada projde i hledání formulářů v šablonách a popupech, nasazení šablon se zálohou a koncepty, obrazovku mapování přes HTTP, vlastní pole, souhlas s GDPR záznamem a skutečné nahrání přílohy přes AJAX Elementoru. Bez Elementor Pro tuhle část přeskočí.

Historie změn je v [CHANGELOG.md](CHANGELOG.md).

---

## Licence

MIT — viz [LICENSE](LICENSE).

Autor: Matěj Kevin Nechodom
