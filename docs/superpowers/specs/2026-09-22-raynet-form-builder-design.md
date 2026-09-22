# Builder formulářů — návrh

**Datum:** 2026-09-22
**Verze pluginu:** 2.1.0 (cílová)
**Stav:** schváleno uživatelem

---

## 1. Cíl

Dnes zkratka `[raynet_lead_form]` vykresluje deset pevně daných polí. Vybrat se dají jen atributem `fields="…"`, popisky jsou zadrátované v `Raynet_Lead_Form::field_labels()` a změnit je znamená sáhnout do kódu.

Builder dá správci webu obrazovku, kde pole vybere, seřadí a přepíše jim popisky — bez editoru kódu.

---

## 2. Rozhodnutí

Pět rozhodnutí, ze kterých plyne zbytek návrhu.

**Víc pojmenovaných formulářů.** Každý má vlastní pole i vlastní nastavení leadu. Vkládá se `[raynet_lead_form id="kontakt"]`. Jeden je výchozí a obslouží zkratku bez `id`.

**Vlastní pole píšou do poznámky leadu.** Pole bez protějšku v RAYNETu (například „Odkud jste se o nás dozvěděli?") přidá do `notice` řádek `Popisek: hodnota`. Mapování na `customFields` RAYNETu se dá doplnit později — je to aditivní změna, nic nepřepisuje.

**Formuláře jsou vlastní typ obsahu.** `raynet_form`. WordPress tím dodá seznam, koš, hledání i oprávnění. Alternativa — jedna položka v `wp_options` — znamená míň kódu, ale žádný koš: smazání formuláře vloženého na třech stránkách by bylo nevratné.

**Náhled vykresluje server.** Builder pošle konfiguraci na `admin-ajax.php` a dostane zpět HTML z téže metody, která vydává ostrý formulář. Náhled tak nemá vlastní renderovací kód, se kterým by se mohl rozejít. Cena: překresluje se po opuštění pole, ne po každém stisku klávesy.

**Souhlas se zpracováním je typ pole.** Ne zvláštní přepínač. Dá se tím řadit a editovat jako všechno ostatní, a builder má jeden editační model místo dvou.

---

## 3. Datový model

### Typ obsahu

```
raynet_form
  public               false
  show_ui              true
  show_in_menu         'raynet-lead-integration'
  supports             array( 'title' )
  capability_type      'post'
  capabilities         mapované na 'manage_options'
  rewrite              false
```

Formuláře nesou ID vlastníka v RAYNETu a notifikační e-maily, proto je edituje jen správce. Zpřístupnit je redaktorům je později změna jednoho pole.

Výchozí formulář drží volba `raynet_lead_default_form` s ID příspěvku. Volba, ne meta příznak — jedna hodnota nemůže být ve dvou formulářích zároveň.

### Definice pole

Meta klíč `_raynet_form_fields`, pole definic v pořadí vykreslení:

```php
array(
    'id'          => 'f_a19c3',   // generuje server při uložení, pak se nemění
    'source'      => 'email',     // atribut RAYNETu, 'custom', nebo 'consent'
    'type'        => 'email',     // text|textarea|email|tel|select|checkbox
    'label'       => 'Váš e-mail',
    'placeholder' => 'jan.novak@example.cz',
    'help'        => 'Odpovíme do jednoho pracovního dne.',
    'required'    => true,
    'width'       => 'full',      // full|half
    'options'     => array(),     // jen pro type=select
)
```

`source` nabývá jedné z hodnot `Raynet_Lead_Form::SUPPORTED_FIELDS` (`firstName`, `lastName`, `companyName`, `email`, `phone`, `topic`, `message`, `street`, `city`, `zipCode`), nebo `custom`, nebo `consent`.

`type` volí uživatel jen u vlastních polí. U polí RAYNETu i u souhlasu ho odvozuje server ze `source` a přepsat nejde — ruční přehození `email` na `textarea` by rozbilo validaci i mapování.

`id` přiděluje server při ukládání těm definicím, které ho ještě nemají. Prohlížeč ho nenavrhuje. Slouží k pojmenování vlastních polí v HTML a musí přežít přejmenování popisku.

Každý `source` z RAYNETu smí být ve formuláři nejvýš jednou; totéž platí pro `consent`. Builder už použité v nabídce nenabízí, server to při ukládání kontroluje znovu.

### Jména polí v HTML

Pole s atributem RAYNETu nese `name` rovné svému `source` — `email`, `firstName`. Tím zůstává `collect_values()` beze změny v tom, jak čte vstup.

Vlastní pole nese `name="raynet_custom[<id>]"`. Jmenný prostor brání tomu, aby vlastní pole přepsalo `raynet_nonce`, `raynet_form_id` nebo atribut RAYNETu. Souhlas nese `name="consent"`.

### Nastavení leadu

Meta klíč `_raynet_form_lead`:

```php
array(
    'topic' => '', 'priority' => '', 'lead_person' => '',
    'category' => 0, 'lead_phase' => 0, 'contact_source' => 0,
    'owner' => 0, 'security_level' => 0, 'tags' => '',
    'notify_emails' => '', 'notice_prefix' => '',
    'success_message' => '', 'redirect_url' => '',
)
```

Prázdná hodnota znamená zdědit z `Raynet_Lead_Settings`. Nula u číselníkových ID znamená totéž — odpovídá dnešní logice na `class-raynet-lead-form.php:567`, kde se klíč vydá jen při hodnotě větší než nula.

Globální zůstávají přihlašovací údaje, region, timeout, antispam, záložní e-mail a logování. Ty na formuláři nedávají smysl.

---

## 4. Builder

### Obrazovka

Dva sloupce. Vlevo seznam polí, vpravo náhled a nastavení leadu.

Řádek pole nese úchyt pro přetažení, popisek, značku zdroje, značku povinnosti a šipku. Kliknutím se rozbalí editace: popisek, placeholder, nápověda, šířka, povinnost, u výběru seznam možností. Pod tím jednořádkové vysvětlení, kam hodnota v RAYNETu doputuje.

Pořadí se mění přetažením. Šipky nahoru a dolů dělají totéž pro klávesnici a dotyk — přetahování samo o sobě je pro obojí nedostupné.

Pole se přidává rozbalovacím seznamem nepoužitých atributů RAYNETu plus položkou „Vlastní pole".

### Ukládání

Celý stav formuláře jde na server jako JSON v jednom skrytém poli při uložení příspěvku. Žádné průběžné ukládání — WordPress dává tlačítko Uložit a uživatel od něj čeká, že rozhoduje.

Ukládání běží na `save_post_raynet_form` s vlastním nonce z metaboxu a kontrolou `current_user_can( 'manage_options' )`. Automatické uložení (`DOING_AUTOSAVE`) se přeskakuje, jinak by prázdný autosave smazal definici.

Server JSON dekóduje, projde definici pole po poli a přepíše ji do kanonického tvaru. Neznámý `source`, neznámý `type` ani cizí klíče neprojdou. Vstup z prohlížeče nikdy neurčuje, co se uloží — jen navrhuje hodnoty.

### Náhled

Akce `raynet_lead_preview` na `admin-ajax.php`, kontrola nonce a `manage_options`. Přijme tutéž definici polí, prožene ji `render_fields()` a vrátí HTML.

Náhled vykresluje jen strukturu. Neobsahuje nonce, časovou značku ani honeypot a nejde z něj odeslat — je to obrázek, ne funkční formulář.

---

## 5. Vykreslení a zkratka

`render_shortcode()` nově řeší dvě věci: který formulář a jaká pole.

Formulář se hledá podle `id`: číslo je ID příspěvku, text je slug. Bez `id` se vezme výchozí formulář z volby. Když se nenajde nic, zkratka nevypíše nic a správci se v adminu ukáže hláška — návštěvník nesmí vidět technickou chybu.

Vykreslování se mění z pevné smyčky přes `SUPPORTED_FIELDS` na `render_fields( array $fields )`, která dostane definice a vydá HTML. Tuto metodu volají obě cesty, zkratka i náhled.

Do formuláře přibude skryté `raynet_form_id`. Server podle něj při odeslání načte definici — nikdy nevěří seznamu polí z prohlížeče.

Šířka pole si vyžádá zásah do CSS. Dnešní `raynet-lead-form.css` staví vedle sebe jmenovitě `--firstName` a `--lastName`. To nahradí modifikátor `raynet-lead-form__row--half`, který renderer připojí podle `width`. Půlená pole se skládají do dvojic vedle sebe a pod šířkou 40 rem se rozpadnou pod sebe, jako dnes.

---

## 6. Odeslání

`process()` načte formulář podle `raynet_form_id` a jeho definici použije místo dnešního pevného seznamu.

`collect_values()` dostane druhý argument s definicemi: `collect_values( array $input, array $fields = array() )`. Prázdné pole znamená dnešní chování nad deseti atributy, takže stávající volání i testy fungují beze změny.

Hodnoty se rozdělí na dvě hromádky. Pole se `source` z RAYNETu jdou do `$values` a odtud do payloadu jako dnes. Vlastní pole se posbírají zvlášť a `build_notice()` je připíše do poznámky pod popisky, které jim dal správce.

Povinnost se kontroluje podle definice. Pravidlo RAYNETu — e-mail nebo telefon — platí dál a nezávisle na tom, co si správce nastavil; bez kontaktu je lead k ničemu.

Pole souhlasu musí být zaškrtnuté, jinak se odeslání odmítne, a jeho udělení se zapíše do poznámky s časem.

O souhlasu nadále rozhoduje jen definice formuláře. Nastavení `consent_enabled` a `consent_label` slouží po 2.1.0 už jen jako vstup migrace a ze stránky nastavení zmizí; v datech zůstanou, aby se migrace dala zopakovat. `Raynet_Lead_Form::build_notice()` je přestane číst. Jinak by formulář bez pole souhlasu zapisoval do CRM větu o uděleném souhlasu, který nikdo nedal — dnes je totiž globální výchozí hodnota zapnuto.

Antispam zůstává beze změny: honeypot, časová past i limit na IP jsou globální a na definici formuláře nezávisí.

---

## 7. Zpětná kompatibilita

Při aktualizaci na 2.1.0 se z dnešního nastavení založí jeden formulář „Kontaktní formulář". Pole vezme z dnešního výchozího seznamu zkratky, souhlas přidá, pokud je globálně zapnutý. Tento formulář se označí jako výchozí, takže `[raynet_lead_form]` bez `id` vykreslí dál totéž co předtím.

Atributy `fields=` a `required=` fungují dál jako filtr nad načteným formulářem: `fields=` vybere a seřadí podmnožinu jeho polí, `required=` přepíše povinnost. Stránky, kde dnes stojí `[raynet_lead_form fields="email,message"]`, se tak nezmění. V dokumentaci jsou označené jako zastaralé s doporučením založit vlastní formulář.

Migrace se pustí jednou a zapíše si to do volby, stejně jako `Raynet_Lead_Settings::maybe_migrate()`.

---

## 8. Chybové stavy

| Situace | Chování |
|---|---|
| Zkratka s `id`, které neexistuje | Návštěvník nevidí nic. Správce vidí v adminu hlášku s uvedeným `id`. |
| Žádný výchozí formulář | Totéž. Odkaz na založení formuláře. |
| Formulář bez jediného pole | Vykreslí se jen tlačítko. Builder na to upozorní při ukládání. |
| Formulář bez e-mailu i telefonu | Builder odmítne uložit. RAYNET by lead nepřijal, chyba patří do adminu, ne do produkce. |
| Náhled selže | Místo náhledu hláška a odkaz na zobrazení na skutečné stránce. Editace polí funguje dál. |
| Odeslání do nesmazaného formuláře v koši | Odmítne se jako neexistující formulář. |

---

## 9. Testování

Nový soubor `tests/test-form-builder.php` a rozšíření stubů o `register_post_type`, `get_post_meta`, `update_post_meta`, `get_post` a `get_page_by_path`.

Co se tvrdí:

- Sanitizace definice zahodí neznámý `source`, neznámý `type` i cizí klíče.
- Duplicitní `source` z RAYNETu se při ukládání odmítne.
- `render_fields()` vydá pole v uloženém pořadí, s popisky a placeholdery správce.
- Vlastní pole dorazí do poznámky pod svým popiskem.
- Pole RAYNETu dorazí do payloadu na správné atributy.
- Nezaškrtnutý souhlas odeslání odmítne.
- Chybějící e-mail i telefon odeslání odmítne, i když je správce označil jako nepovinné.
- Zkratka najde formulář podle ID i podle slugu, bez `id` vezme výchozí.
- `fields=` zúží a seřadí pole načteného formuláře.
- Migrace z 2.0.0 založí výchozí formulář se stejnými poli, jaká dnes vydává zkratka.
- Definice poslaná v odeslání se ignoruje; platí ta uložená na serveru.
- Vlastní pole se jmenuje `raynet_custom[<id>]` a nepřepíše atribut RAYNETu ani `raynet_form_id`.
- Formulář bez pole souhlasu nezapíše do poznámky větu o souhlasu, i když je `consent_enabled` v nastavení zapnuté.

Stávající suita musí projít beze změny. `collect_values()` volané s jedním argumentem se chová jako dnes, což hlídá `tests/test-units.php`.

Bez živého WordPressu se neotestuje přetahování, obrazovka builderu, překreslení náhledu ani to, jak se půlená pole zalomí. Zbývá ruční zkouška.

---

## 10. Mimo rozsah

Vědomě vynechané, aby první verze dojela:

- **Typy polí** nad rámec text, dlouhý text, výběr, zaškrtávátko a souhlas. Nahrávání souborů, datum ani opakovatelné skupiny — RAYNET by s nimi stejně neuměl nic chytrého.
- **Mapování vlastních polí na `customFields` RAYNETu.** Hodnoty jdou do poznámky. Doplnění znamená jedno pole navíc v definici.
- **Podmíněná logika** („ukaž toto pole, když…").
- **Ukládání odeslaných formulářů do WordPressu.** Data jdou do RAYNETu, plugin si je nedrží.
- **Blok pro Gutenberg.** Vkládá se zkratkou. Blok by znamenal build krok, který plugin dnes nemá.
- **Editace formulářů redaktory.** Jen správci.
- **Akce pro Elementor Pro Forms.** Pozastaveno na přání uživatele, návrh hotový a uložený zvlášť.
