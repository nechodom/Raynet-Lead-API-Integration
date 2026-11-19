# Atomový formulář Elementoru 4 — návrh

Schváleno 2026-11-18. Cílová verze pluginu 2.11.0.

## Záměr

Přehled **RAYNET CRM → Elementor formuláře** dnes atomový formulář (`e-form`) jen vypíše s hláškou „zatím nepodporovaný, RAYNET v něm nejde zapnout“. Elementor Pro 4.1 má pro atomový formulář vlastní registr akcí, takže to už neplatí. Odeslání atomového formuláře má založit lead v RAYNETu stejně jako odeslání widgetu Formulář.

## Cíle

- V editoru Elementoru je u atomového formuláře mezi akcemi po odeslání volba **RAYNET CRM**.
- Přehled pluginu umí atomový formulář zapnout, nasadit na něj šablonu a otevřít mapování polí.
- Lead vzniká stejnými pravidly jako u widgetu (`Raynet_Elementor_Lead_Builder`, `Raynet_Lead_Form::submit_lead()`): mapování po polích, automatický návrh podle popisku, souhlas ze zaškrtávátka, zbytek do poznámky, nastavení leadu po formuláři, notifikace, záložní e-mail, GDPR záznam.

## Mimo rozsah (první verze)

- Nahrání příloh do RAYNETu. Nahrávací pole atomového formuláře jde jen do poznámky (název souboru), nebo se vynechá.
- Import starších odeslání atomových formulářů. Odkaz na import u nich dál není.
- Formulář vložený uvnitř komponenty Elementoru (jiný dokument, `origin_id`). Když ho přehled v dokumentu nenajde, nevypíše ho.
- Nastavení leadu a mapování přímo v editoru Elementoru.

## Čemu se vyhnout

- Živé chování widgetu Formulář se nesmí změnit.
- Typ a popisek pole se nikdy neberou z požadavku návštěvníka (`form_fields[][type|label]` posílá prohlížeč). Čtou se z uloženého stromu stránky.
- Plugin nesmí zapsat do nastavení prvku `e-form` nic, co schéma Elementoru nezná, kromě hodnoty `raynet_crm` v `actions-after-submit`.
- Zapnutí RAYNETu nesmí vypnout výchozí e-mail: formulář bez uloženého `actions-after-submit` posílá e-mail, pokud má prvek uložené nastavení `email` (bez něj nespouští nic); takový formulář musí mít po zapnutí `email` i `raynet_crm`, formulář bez nastavení e-mailu jen `raynet_crm`.

## Cesty

1. **Vlastní akce v registru Elementoru, nastavení v pluginu (zvoleno).** Akce `raynet_crm` přes `elementor_pro/atomic_forms/actions/register`, volba v editoru přes filtr `elementor/atomic-widgets/controls`. Mapování a nastavení leadu drží plugin v post meta stránky. Důvod: jediný oficiální bod rozšíření, žádný zásah do schématu atomového editoru.
2. Všechno nastavení jako vlastnosti prvku v editoru. Zamítnuto: atomový editor nemá ovládací prvek pro mapování po polích, zásah do schématu je křehký a modul je u Elementoru ve stavu „dev“.
3. Webhook Elementoru na REST endpoint pluginu. Zamítnuto: ruční nastavení u každého formuláře, žádné mapování po polích.

## Jak to funguje

**Uložení.** Nastavení RAYNETu pro atomové formuláře je v post meta `_raynet_crm_atomic_forms` dokumentu, který formulář obsahuje: pole `ID prvku e-form => nastavení`. Nastavení má stejné klíče jako nastavení widgetu (`raynet_crm_fields_map`, `raynet_crm_notice_fields`, `raynet_crm_ignored_fields`, `raynet_crm_topic`, `raynet_crm_owner`, …). Odinstalace s mazáním dat meta maže.

**Adaptér.** Nová třída převede prvek `e-form` na nastavení ve tvaru widgetu: `form_name`, `submit_actions` (z `actions-after-submit`, výchozí `email`), `form_fields` (z potomků) a uložené klíče `raynet_crm_*`. Zbytek pluginu (návrh mapování, validace, obrazovka mapování, šablony) pak pracuje beze změny. Při zápisu adaptér klíče RAYNETu uloží do meta a `submit_actions` promítne do typované hodnoty `actions-after-submit` ve stromu (i do rozpracovaných autosave, jako u widgetu).

**Pole.** ID pole = ID prvku widgetu (stejné jako klíč v `$form_data` a v uložených odesláních). Typy: `e-form-input` podle vlastnosti `type` (`text`, `email`, `tel`, `number`, `url`, `password`), `e-form-textarea` → `textarea`, `e-form-checkbox` → `acceptance`, `e-form-radio-button` → `radio`, `e-form-select` → `select`, `e-form-date-picker` → `date`, `e-form-time-picker` → `time`, `e-form-file-upload` → `upload`. Popisek: text prvku `e-form-label`, jehož `input-id` se rovná `_cssid` pole; jinak `placeholder`; jinak typ a pořadí („Pole 3 (email)“). U zaškrtávátka je popisek zároveň text souhlasu. Přepínače se stejným uloženým názvem skupiny (`name`) jsou jedno pole pod ID prvního z nich, s popiskem podle názvu skupiny (tak je pojmenovává i Elementor v prohlížeči); hodnota odeslaná pod kterýmkoli z nich patří tomuto poli.

**Odeslání.** Akce dostane `$form_data` (ID pole => hodnota, očištěné Elementorem) a kontext (`post_id`, `form_id`). Typy a popisky si načte ze stromu, hodnoty z `$form_data`; pole, která ve stromu nejsou, zahodí. Složí lead stejným postupem jako `Raynet_Elementor_Form_Action::run()`. Úspěch vrací jako `success`; selhání oznamuje výjimkou, protože registr akcí Elementoru Pro za selhání počítá jen výjimku (vrácený `failed` by návštěvníkovi ukázal úspěch). Co návštěvník uvidí, rozhoduje Elementor Pro podle všech akcí: projde-li aspoň jedna jiná akce (třeba `email`, který zapnutí přes plugin nechává), odpoví úspěchem a návštěvník uvidí úspěšný stav, i když lead nevznikl; chybový stav formuláře uvidí, jen když je `raynet_crm` jediná akce nebo selžou všechny (`Atomic_Form_Controller::send_response()`). Text zprávy akce front-end Elementoru nezobrazuje, jen ho posílá v odpovědi. Záložní e-mail odchází jako u widgetu, když RAYNET lead odmítne nebo neodpoví; formulář bez namapovaného e-mailu i telefonu skončí před voláním RAYNETu a záložní e-mail neposílá. Důvod odmítnutí se návštěvníkovi neposílá (jen obecná hláška), skutečný důvod jde do logu pluginu a do **Poslední chyba API**; do logu akcí Elementoru se zapíše jen obecná hláška.

**Přehled a mapování.** Řádek atomového formuláře se chová jako řádek widgetu: zaškrtávátko pro nasazení šablony, stav, počet namapovaných polí, odkaz na mapování. Hláška o nepodpoře mizí. Když registr akcí Elementoru Pro neběží (starší verze, vypnutý experiment `e_pro_atomic_form` nebo `e_atomic_elements`; poznává se podle akce `elementor_pro/atomic_forms/actions/register`, ne podle existence třídy), řádek zůstane neaktivní s hláškou, že tato verze Elementoru Pro akce atomového formuláře neumí.

## Ponecháno staviteli

- Přesný tvar hodnot zaškrtávátka, výběru a nahrávacího pole v `$form_data` (ověřit v `atomic-widgets-form-handler.js` a `File_Upload_Handler`).
- Tvar textu v `e-form-label` (`html-v3` a starší `html`/`string`): číst tolerantně.
- Rozdělení kódu mezi adaptér a `Raynet_Elementor_Forms`.
