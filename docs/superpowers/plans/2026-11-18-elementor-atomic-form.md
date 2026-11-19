# Elementor 4 Atomic Form Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A submission of Elementor 4's atomic form (`e-form`) creates a RAYNET lead by the same rules as the classic Form widget, and the plugin's forms screen can enable and map it.

**Architecture:** An adapter turns an `e-form` element into classic-widget-shaped settings so the existing mapping, template and lead-building code runs unchanged. RAYNET settings for atomic forms live in post meta of the document; the only thing written into the element is the `raynet_crm` slug in `actions-after-submit`. A second class registers an action in Elementor Pro's atomic action registry and adds the chip in the editor.

**Tech Stack:** PHP 7.4+, WordPress, Elementor 4.1.4, Elementor Pro 4.1.1 (`modules/atomic-form`), existing test suites (`php tests/run.php`, `node tests/js/*.js`, `bin/wp-test.sh`).

**Spec:** `docs/superpowers/specs/2026-11-18-elementor-atomic-form-design.md`

## Global Constraints

- PHP 7.4 syntax only in plugin code (no union types, no `match`, no named args, no `str_contains`). Elementor's `Action_Base` declares typed signatures; match them exactly.
- UI strings Czech, text domain `raynet-lead-api-integration`; code comments English.
- Live behavior of the classic Form widget must not change. All existing tests stay green.
- Field type and label never come from the request; they are read from the stored tree. Posted fields not present in the tree are dropped.
- The only thing written into an `e-form` element's settings is the slug `raynet_crm` inside `actions-after-submit` (typed value: `{"$$type":"string-array","value":[{"$$type":"string","value":"email"},…]}`). A form with no stored `actions-after-submit` runs `email` only if an `email` setting is stored on the element, and nothing otherwise (Elementor Pro's `resolve_widget_settings()`); enabling such a form must store `email` + `raynet_crm`, or only `raynet_crm` when it ran nothing.
- RAYNET settings for atomic forms: post meta `_raynet_crm_atomic_forms` on the document, `array<string form element id, array settings>`, settings keys identical to the classic widget's (`raynet_crm_fields_map`, `raynet_crm_notice_fields`, `raynet_crm_ignored_fields`, `raynet_crm_topic`, …). Uninstall with data removal deletes it.
- Field id = the field widget's element id. Type map: `e-form-input` → its `type` prop (`text` default; `email`, `tel`, `number`, `url`, `password`), `e-form-textarea` → `textarea`, `e-form-checkbox` → `acceptance`, `e-form-radio-button` → `radio`, `e-form-select` → `select`, `e-form-date-picker` → `date`, `e-form-time-picker` → `time`, `e-form-file-upload` → `upload`.
- Label: text of the `e-form-label` whose `input-id` equals the field's `_cssid`; else `placeholder`; else `Pole N (type)`. Typed values (`$$type`/`value`, nested `html-v3` content) are read tolerantly; labels are plain text (tags stripped).
- No file upload to RAYNET for atomic forms; an upload field's allowed targets are note or left out (never `__attach`).
- Classes that extend Elementor Pro classes are only loaded when the parent class exists.
- Never push; commit per task on branch `claude/elementor-atomic-form`.

## Review Focus

1. Request claims a field is `type=email` / label "E-mail" while the tree says otherwise → tree wins; unknown field ids are dropped.
2. Form never had `actions-after-submit` stored → after enabling, e-mail still runs.
3. Password field mapped by an old setting → value never leaves the site.
4. Elementor Pro without the atomic action registry (older version / experiment off) → no fatal, row stays inactive with a clear message.
5. Page duplicated or form deleted → stale meta entry for a missing form id is ignored and does not break the screen.

---

### Task 1: Adapter and storage

**Files:**
- Create: `raynet-lead-api-integration/includes/elementor/class-raynet-elementor-atomic-forms.php`
- Modify: `raynet-lead-api-integration/includes/elementor/class-raynet-elementor-forms.php` (`forms_in_post`, `atomic_row`, `form_settings`, `change_tree`/`update_forms`), main plugin file (require), `raynet-lead-api-integration/uninstall.php`
- Test: `tests/test-atomic-forms.php` (new, add to `tests/run.php`), `tests/test-uninstall.php`, `tests/integration/run.php`

**Interfaces — Produces** (`Raynet_Elementor_Atomic_Forms`, all static):
- `const META = '_raynet_crm_atomic_forms'`, `const ACTIONS_PROP = 'actions-after-submit'`, `const FIELD_TYPES` (widgetType => classic type; input resolved by prop).
- `supported(): bool` — true when `\ElementorPro\Modules\AtomicForm\Actions\Action_Runner` exists.
- `fields( array $element ): array` — descendants as `form_fields` rows: `custom_id`, `field_type`, `field_label`, `acceptance_text` (checkbox only, = label).
- `actions( array $element ): string[]` — slugs from the typed prop; `array( 'email' )` when the prop is absent.
- `settings( int $post_id, array $element ): array` — classic-shaped: `form_name`, `form_fields`, `submit_actions`, plus stored `raynet_crm_*` keys.
- `stored( int $post_id, string $form_id ): array` and `store( int $post_id, string $form_id, array $settings ): bool` — only keys starting `raynet_crm_` are kept.
- `apply( array $element, array $settings, int $post_id ): array` — returns the element with `actions-after-submit` rewritten from `$settings['submit_actions']` (other element settings untouched) and stores the RAYNET keys.

`Raynet_Elementor_Forms` changes: `forms_in_post()` returns a full row for atomic forms (`fields`, `enabled`, `mapped`, `has_contact`, `undecided`, `noted` computed from adapter settings; `atomic => true`; new key `supported`); `form_settings()` finds an `e-form` by id and returns adapter settings; `change_tree()` also matches `elType === 'e-form'` and routes `$change` through `settings()` → `apply()`. `change_tree` needs the post id for storage: add it as a parameter; for an autosave tree only the element is rewritten, meta is stored once on the parent document.

- [ ] **Step 1: failing stubbed tests** in `tests/test-atomic-forms.php` using a fixture tree (form with label+email input, textarea, checkbox in an `e-flexbox` row with its label, select, file upload, an input with no label but a placeholder, one with neither):
  - `fields()` returns ids in tree order with types `email, textarea, acceptance, select, upload, text, text` and labels `E-mail`, `Zpráva`, `Souhlasím se zpracováním`, `Služba`, `Příloha`, placeholder text, `Pole 7 (text)`.
  - label stored as `html-v3` typed value with `<strong>` inside → plain text.
  - `actions()` on a form without the prop → `array( 'email' )`; with `[email, collect-submissions]` → both.
  - `apply()` with `submit_actions = [email, raynet_crm]` on a form without the prop → prop is the typed string-array with both; every other settings key byte-identical.
  - `store()` drops keys not starting `raynet_crm_`; `stored()` for an unknown form id → `array()`; meta holding a non-array → `array()`.
- [ ] **Step 2: run, see them fail.** `php tests/run.php`
- [ ] **Step 3: implement the adapter and the `Raynet_Elementor_Forms` routing.**
- [ ] **Step 4: integration tests** (`tests/integration/run.php`): create a page with the fixture tree; `Raynet_Elementor_Forms::scan()` lists it with 7 fields and `enabled = false`; `Raynet_Elementor_Forms::apply()` with a template enables it → stored tree has `email` + `raynet_crm`, meta holds the lead settings and an auto-mapped `email`; classic widget on the same page still configured as before; `save_targets()` on the atomic form round-trips through `field_targets()`; uninstall test: meta row is deleted.
- [ ] **Step 5: all three suites green, commit** `feat: read and configure Elementor 4 atomic forms`.

### Task 2: Submit action and editor chip

**Files:**
- Create: `raynet-lead-api-integration/includes/elementor/class-raynet-atomic-form-action.php` (extends `\ElementorPro\Modules\AtomicForm\Actions\Action_Base`), `raynet-lead-api-integration/includes/elementor/class-raynet-atomic-form-integration.php` (hooks; loadable without Elementor)
- Modify: main plugin file (require + register), `tests/wp-stubs.php` / `tests/integration/mu-raynet-test-double.php` as needed
- Test: `tests/test-atomic-action.php` (new), `tests/integration/run.php`

**Interfaces — Consumes:** Task 1 adapter; `Raynet_Elementor_Lead_Builder::{mapped_values,noted_values,merge_lines,contact_error,lead_settings,context}`; `Raynet_Lead_Form::{collect_values,merge_lead_settings,submit_lead}`; `Raynet_Lead_Settings::all()`.

**Interfaces — Produces:**
- `Raynet_Atomic_Form_Integration::register(): void` — hooks `elementor_pro/atomic_forms/actions/register` (loads the action class file there, calls `$runner::register_action( new Raynet_Atomic_Form_Action() )`) and filter `elementor/atomic-widgets/controls` (priority 10, 2 args) that appends `{label: 'RAYNET CRM', value: 'raynet_crm'}` to the options of the `Chips_Control` bound to `actions-after-submit` when `$element instanceof \Elementor\Modules\AtomicWidgets\Elements\Atomic_Form\Atomic_Form`; and filter `elementor_pro/atomic_forms/action_log_label` → `RAYNET CRM` for type `raynet_crm`.
- `Raynet_Atomic_Form_Integration::submitted_fields( array $form_fields, array $form_data ): array` — pure: builder-shaped `id => array( 'id', 'type', 'title', 'value', 'raw_value' )` for tree fields only; arrays joined with `, `; upload value = file name(s) only, never a server path or URL with a path outside uploads.
- `Raynet_Atomic_Form_Action::get_type(): string` = `raynet_crm`; `execute( array $form_data, array $widget_settings, array $context ): array`.

`execute()`: locate the `e-form` element by `$context['post_id']` + `$context['form_id']` (reuse `Raynet_Elementor_Forms` tree reading); build fields; follow `Raynet_Elementor_Form_Action::run()` step by step minus attachments; source URL from `get_permalink( post_id )` when `raynet_crm_source_url` is `yes`. Success → `$this->success( 'Lead created', array( 'leadId' => N ) )`. Failure → `failed` with the diagnostic (it goes to Elementor's action log, admin-only); any `\Throwable` is caught and returned as `failed`. Check how `send_response()` in `atomic-form-controller.php` exposes `actionResults` to the visitor: if the per-action `error` text reaches the browser, return a generic Czech message there and keep the diagnostic in the plugin's own error report (`Raynet_Lead_Form::report_error()` / last API error) only.

- [ ] **Step 1: failing stubbed tests** for `submitted_fields()`: unknown posted id dropped; type comes from tree though the request says otherwise; checkbox ticked/unticked value shapes as the front-end handler sends them (read `elementor/assets/js/atomic-widgets-form-handler.js`); array value joined; password present with its type (builder drops it).
- [ ] **Step 2: implement.**
- [ ] **Step 3: integration tests** driving `Action_Runner::execute_actions( array( 'raynet_crm' ), $form_data, $widget_settings, $context )` against the test double: lead payload has mapped e-mail, name, message; unmapped field chosen for note appears in the note; ticked mapped consent → GDPR record call; RAYNET 400 → result `failed`, no fatal, fallback e-mail path taken as for the widget; form with RAYNET not enabled in `actions-after-submit` is never called by the runner; the controls filter returns the chip with our option for an `Atomic_Form` instance and leaves other elements alone; with the registry class absent nothing is hooked into fatal paths.
- [ ] **Step 4: all three suites green, commit** `feat: RAYNET CRM action for Elementor 4 atomic forms`.

### Task 3: Forms screen, mapping screen, docs, version

**Files:**
- Modify: `raynet-lead-api-integration/includes/elementor/class-raynet-elementor-forms-admin.php` (rows at ~180–255, `render_mapping`, `handle_apply`, `handle_save_mapping`), `README.md` (sections at ~352, ~396, ~819 and the Elementor chapter), `CHANGELOG.md` (`[2.11.0] — 2026-11-18`), main plugin file (version `2.11.0` in header and constant), `languages/*.pot` (regenerate as earlier commits did)
- Test: `tests/integration/run.php` (rendered screen HTML)

- [ ] **Step 1: failing integration tests:** the forms screen renders the atomic row with an enabled checkbox, a mapping link and no „zatím nepodporovaný“ text; with `Raynet_Elementor_Atomic_Forms::supported()` false the row is inactive with the text `tato verze Elementoru Pro akce atomového formuláře neumí`; the mapping screen for `post:formid` lists the 7 fields, offers no „příloha“ target for the upload field, and saving it stores into meta; the import link is absent for atomic forms.
- [ ] **Step 2: implement the screen changes.** Keep `atomic` checks only where behavior truly differs (upload targets, import link, a one-line hint on the mapping screen that settings are kept by the plugin and the action is switched in Elementor under *Actions after submit*).
- [ ] **Step 3: docs and version.** README: replace the "not supported" paragraph with how it works, what is not supported (attachments, import, forms inside components, Elementor Pro older than the action registry); CHANGELOG entry; version bump; `.pot`.
- [ ] **Step 4: all three suites green, commit** `feat: atomic forms on the forms and mapping screens` and `docs: atomic form support, version 2.11.0`.
