# InvoGrid — project state

A factual snapshot of what exists in the codebase **right now**, not a changelog.
Read it before starting work; rewrite the parts that changed when finishing.

**Last updated:** the duplicate check on the New Invoice route — the gate at the
end of the matching stage, and the queue that works it.

---

## 1. What is built

| Area | State |
|---|---|
| Repo scaffold, `.env` config, autoloader | done |
| Full database schema (all tables, including ones with no UI yet) | done |
| Session authentication, login throttling, CSRF | done |
| Themed application shell: nav, light/dark, logo/monogram, footer | done |
| Dashboard (counts by pipeline status, recent documents, setup gaps) | done |
| Verified end to end against a live MariaDB 12.3 | done |
| HTTP client with timeouts, retries and `Retry-After` (`Http`) | done |
| Ingest abstraction, and a manual upload route on it | done |
| Queue + cron worker, retries, backoff | done |
| Document list and detail, retry / ignore | done |
| PDF → page images (`PdfRenderer`, poppler) | done |
| LLM abstraction + OpenAI and Anthropic clients | done |
| OCR stage, seeded `ocr` prompt, transcription stored | done |
| Document detail: PDF, page images, transcription | done |
| Extraction: three focused calls, merged record | done |
| Prompt variable rendering (`PromptRenderer`) | done |
| Custom fields: fast path, then a fallback call | done |
| Document detail shows the extracted fields | done |
| Clear Books API client (OAuth2, PKCE, pagination) | done |
| Cached reference lists, cron refresh + "refresh now" | done |
| Matching stage, deterministic name fallback, `entity_matches` | done |
| `/admin/clearbooks`: connect, cache state, refresh | done |
| Review queue: triage list and the editable detail screen | done |
| Creating a supplier in Clear Books from a confirmed form | done |
| Submission to Clear Books, PDF attached to the record | done |
| Submission-produced fields recorded on the extraction | done |
| "Open in Clear Books" in one reused window | done |
| Credit note vs purchase refund, with a human confirming | done |
| Custom fields screen: define, edit, take out of use | done |
| Prompts screen: edit, version, roll back, reset to default | done |
| Users screen: create, edit, role, deactivate, reset password | done |
| Forced password change after an admin sets one | done |
| Every route asserted to carry a gate, by the smoke test | done |
| Dashboard: stuck documents, failure feed, activity feed | done |
| Filtering and search across `/documents` | done |
| Failures explain themselves without a server log | done |
| `Retry-After` honoured, Clear Books paced under its limit | done |
| Logo upload, light and dark variants, monogram fallback | done |
| Printable document summary, on its own layout | done |
| Light/dark parity swept and asserted across every page | done |
| Security review, permission sweep, pipeline audit | done |
| README complete enough to deploy from scratch | done |
| `install.sh` and `manage.sh`, modelled on the sibling projects | done |
| Application settings screen, with connection tests | done |
| Paperless removed entirely, replaced by native ingest | done |
| Full activity log: filters, paging, no route that writes | done |
| Local copy of Clear Books' purchase documents, synced on a schedule | done |
| The Existing / New Invoice branch, decided at transcription | done |
| Existing Invoice route: both flows one pipeline, exact checksum, PDF attached | done |
| Existing Invoice queue: link by hand, push to New, delete | done |
| Duplicate check against the synced Clear Books invoices | done |
| Duplicate queue: the side-by-side, delete or push it on | done |
| Desktop-first layout across every document-facing screen | done |
| Page images shown by default, with the PDF a button underneath | done |
| Per-field issue marks, replacing the top-of-page "things to check" banner | done |

A document now runs the whole way on its own when everything resolves, and a
person finishes the ones that do not. The pipeline itself still stops at
`ready_to_submit` — **submission is a human action, not a stage.** There is no
`submit` entry in `Pipeline::STAGES` and there should not be one: nothing should
be able to put a bill into somebody's accounts because a cron job fired.

**Every navigation entry is now a real destination.** The `soon` rendering
(`nav-link is-pending`, muted text with a badge instead of a link) is kept in
`templates/partials/nav.php` because the next unfinished screen will want it,
but nothing currently uses it.

**What is left**: nothing from the original build plan. `bin/console.php
settings:set` stays alongside the Settings screen rather than being replaced by
it — a credential has to be settable before anybody can sign in to change one,
and `install.sh` has no browser.

---

## 2. Stack and conventions

- PHP 8.2+ (developed on 8.4), MariaDB 10.6+, PDO with prepared statements only.
- **No Composer, no `vendor/`, no framework.** `src/bootstrap.php` registers a
  PSR-4-shaped autoloader for the `App\` namespace mapping to `src/`.
- All external integrations are plain cURL; no vendor SDKs, ever.
- Namespace roots: `App\Core`, `App\Models`, `App\Services`, `App\Controllers`,
  `App\Middleware`.
- Templates are plain PHP in `templates/`, rendered by `App\Core\View`, escaped
  with the `e()` helper. Every dynamic value in a template goes through `e()`.
- British English throughout, in code comments and in user-facing text.
- Code comments explain *why*, not *what*, matching the Kitwell house style.

### Kitwell relationship

Kitwell (`github.com/maeterlinckle/kitwell`) is a **pattern reference only**.
`src/Core/*` and the CSS were adapted from it and then diverged. There is no
shared code, no submodule, no shared database and no path reference back to it.
Do not add one.

Deliberate visual differences from Kitwell: indigo accent (`#4338ca` /
`#a5b4fc`) instead of blue. The desktop nav bar was at `900px` instead of
`1150px` while this menu had three top-level items; **a queue per question has
taken it to six, which is Kitwell's count, so it is back at `1150px`** and the
difference that justified the lower breakpoint has gone (§9). Everything else —
spacing scale, 17px body type, 44px tap targets, component vocabulary — is the
same on purpose.

---

## 3. Database

Migrations are plain `.sql` in `database/migrations/`, applied in filename order
by `App\Core\Migrator`, tracked in a `migrations` table.

- `001_schema.sql` — every table.
- `002_default_data.sql` — settings keys (values empty) and the two document types.
- `003_ocr_prompt.sql` — the `ocr` prompt, rendering settings, model defaults.
- `004_llm_base_urls.sql` — optional gateway base URLs for the two providers.
- `005_production_ocr_prompt.sql` — the real n8n OCR prompt, as `ocr` v2.
- `006_ocr_structured_output.sql` — split the OCR reply into columns.
- `007_extraction_support.sql` — `extractions.supplier_match`, the two annotation custom fields.
- `008_extraction_prompts.sql` — the three extraction prompts plus the custom-field fallback.
- `009_clearbooks_connection.sql` — the consent-flow settings, and two sync
  switches since removed by `017`.
- `010_review_and_submission.sql` — `custom_fields.source`, the two
  submission-produced fields, `extractions.edited_at`/`edited_by`, and settings
  since removed by `017`.
- `011_credit_notes_and_refunds.sql` — the corrected credit-note sign, the
  `purchase_refund` type, `requires_confirmation`, `default_credit_route`,
  the confirmation stamps, and `extract_lines` v2.
- `012_classification_reasoning.sql` — `extractions.doc_type_reason` and
  `extract_lines` v3, which asks the model to say what decided its answer.
- `013_prompt_origin.sql` — `prompt_templates.origin`, so "reset to default"
  knows which versions shipped.
- `014_user_management.sql` — `users.must_change_password` and
  `users.password_changed_at`, which is what makes an admin-set password a
  moment rather than a state.
- `015_diagnostics.sql` — `document_events.context`, and the two `stuck_*`
  thresholds, so a failure can be read on the page and a stalled document
  is noticed at all.
- `016_clearbooks_invoices.sql` — `clearbooks_invoices`, plus the sync interval
  and the last-run record. The local copy of what Clear Books already holds,
  which the duplicate check in Prompts 17 and 18 will be asked about.
- `017_remove_paperless.sql` — the pivot. Drops every column that existed only
  to talk to Paperless, renames the three that held real data under a Paperless
  name (`documents.supplier_raw`, `documents.matched_supplier_id`,
  `extractions.document_title`), adds the four `ingest_*` columns and
  `ingest_max_upload_mb`, deletes the Paperless settings rows, and re-seeds
  `extract_header` and `extract_supplier` at a new version. See §12.
- `018_invoice_routing.sql` — the Existing / New Invoice branch. Adds the
  `existing_invoice` status and `documents.route`, promotes
  `ocr_results.clearbooks_number` / `project_code` / `annotations_json`, and
  re-seeds `ocr` (v3, with the `### Notes` section removed), `extract_header`
  and `extract_custom_fields`. See §32.
- `019_existing_invoice_linking.sql` — the Existing Invoice route. Adds the
  `needs_link` status, and nothing else: there is no new table (the link is a
  `submissions` row) and nothing to configure (the checksum is exact). It also
  moves the fork Prompt 16 put at the transcription to the end of matching, so
  both flows run the same pipeline. See §33.
- `020_duplicate_check.sql` — the duplicate gate on the New Invoice route. Adds
  the `possible_duplicate` status and `documents.duplicate_cleared_at` /
  `duplicate_cleared_by`. No new table — the candidates are recomputed when the
  queue screen is opened — and no settings row: the natural off switch is an
  unsynced `clearbooks_invoices`. See §34.

### Tables in use

| Table | Purpose / notable columns |
|---|---|
| `users` | `username` (unique, lower-cased), `display_name`, `email`, `password_hash`, `role` ENUM(viewer,reviewer,admin), `active`, `last_login_at`, `last_login_ip` |
| `login_attempts` | throttling; `username`, `ip_address`, `successful`, `attempted_at` |
| `settings` | `setting_key` PK, `setting_value`, `is_secret`, `updated_by` |
| `document_types` | `type_key` (unique), `label`, `clearbooks_resource`, `amount_sign`, `sort_order`, `active` |
| `prompt_templates` | `template_key` + `version` unique, `content`, `is_active`, `updated_by` |
| `custom_fields` | `field_key` unique, `label`, `data_type`, `select_options`, `prompt_hint`, `source` ENUM(extracted, submission), `sort_order`, `active` |
| `documents` | `ingest_source`, `original_filename`, `ingested_by`, `ingested_at`, `status`, `route` ENUM(new_invoice, existing_invoice) NULL, `doc_type`, `supplier_raw`, `matched_supplier_id`, `pdf_path`, `page_count`, `failed_stage`, `error_message`, `attempts`, `locked_at`, `duplicate_cleared_at`/`duplicate_cleared_by` (§34 — NULL means nobody was ever asked, which is not the same as "not a duplicate") |
| `document_pages` | `document_id` + `page_number` unique, `image_path`, `width`, `height` |
| `ocr_results` | `document_id`, `llm_provider`, `llm_model`, `raw_text` (verbatim reply), `ocr_text` (transcription alone), `structured_json` (everything else), `notes_present`, `clearbooks_number` (indexed — the branch tests it), `project_code`, `annotations_json`, `prompt_template_id`, token counts, `duration_ms` |
| `extractions` | one row per reading; header columns including `document_title`, `vat_treatment`/`supplier_match`/`line_items`/`custom_field_values`/`confidence`/`review_notes` as JSON, `needs_review` |
| `entity_matches` | `extraction_id`, `entity_type` ENUM(supplier, account_code, vat_rate, vat_treatment), `line_index`, `raw_value`, `matched_id`, `matched_name`, `matched_via` ENUM(llm, code_fallback, manual), `confidence`, `status` ENUM(matched, unmatched, created, rejected), `resolved_by`, `resolved_at` |
| `clearbooks_cache` | `entity_type` + `remote_id` unique, `name`, `normalised_name` (indexed), `raw_json`, `default_credit_route`, `active`, `cached_at` |
| `clearbooks_invoices` | what Clear Books already holds: `clearbooks_id` unique across both endpoints, `purchase_type` ENUM(bill, creditNote), `document_number`, `supplier_id`, `document_date`, `due_date`, `reference`, `gross_amount`, `raw_json`, `synced_at`. Rows are **deleted** when they leave Clear Books, not deactivated |
| `submissions` | `document_id`, `clearbooks_type`, `clearbooks_id`, `clearbooks_url`, `status`, `submitted_by`, `response_json` |
| `document_events` | what the *machine* did: `stage`, `status` ENUM(started, succeeded, failed, skipped), `message`, `duration_ms` |
| `pipeline_jobs` | the work queue: `stage`, `status` ENUM(queued, running, done, failed), `attempts`, `available_at`, `last_error` |
| `audit_log` | what *people* did: `user_id`, `document_id`, `action`, `details`, `ip_address` |

### Naming conventions

- `snake_case` columns; `id` primary keys as `INT UNSIGNED AUTO_INCREMENT`
  (`BIGINT` for the high-volume log tables).
- Indexes `ix_<table>_<what>`, uniques `uq_<table>_<what>`, foreign keys
  `fk_<table>_<target>`.
- Clear Books identifiers are **`VARCHAR(64)`, never a foreign key** — Clear
  Books owns those ids, not this database.
- `TIMESTAMP ... DEFAULT CURRENT_TIMESTAMP [ON UPDATE CURRENT_TIMESTAMP]` for
  `created_at` / `updated_at`.

### Decisions worth knowing

- **`settings` uses `setting_key` / `setting_value`**, not `key` / `value`, to
  avoid reserved words.
- **What the Paperless removal did to the schema**, in one place, because a
  reader coming to this cold will wonder. `017_remove_paperless.sql` **drops**
  `documents.paperless_doc_id`, `clearbooks_cache.paperless_correspondent_id`,
  `custom_fields.paperless_field_id` and
  `document_types.paperless_document_type_id` rather than leaving them unused:
  a nullable column nobody ever populates again is exactly the kind of thing a
  future reader assumes still works. Two columns that held real data were
  **renamed** instead, because only the vocabulary had gone —
  `documents.correspondent_raw` to `supplier_raw`,
  `documents.correspondent_matched_supplier_id` to `matched_supplier_id`, and
  `extractions.paperless_title` to `document_title`.
- **`entity_matches.line_index`** was added beyond the specified columns: account
  codes and VAT rates are per line item, so without it two lines guessing
  different codes cannot be told apart.
- **`document_events` and `pipeline_jobs`** were added beyond the specified
  tables. The queue exists because an upload cannot be held open for an LLM
  call; the event log is what makes a failed stage retryable rather than lost. Both are
  here now to avoid a migration onto a live database later.
- `documents.matched_supplier_id` holds a Clear Books remote id, not a row here.

---

## 4. Pipeline status state machine

`documents.status`, defined in `App\Models\Document`:

```
received → ocr_pending → ocr_done → extracting → extracted → matching ─┬→ possible_duplicate ┐
                                                                       │        ↑____________│ (cleared, re-matched)
                                                                       ├→ needs_review
                                                                       │  → ready_to_submit → submitted
                                                                       │
                                                                       └→ existing_invoice ─┬→ submitted
                                                                                            └→ needs_link
```

- **`matching` has four successors, and two of them are branches** — see §32,
  §33 and §34. The route decides the first (`existing_invoice`); the duplicate
  check decides the second (`possible_duplicate`), and it runs only on the New
  Invoice arm, before the ready/needs-review decision rather than after it.
- **`possible_duplicate` has exactly one way on, and it is back to `matching`.**
  Confirming a document is genuinely new stamps `documents.duplicate_cleared_at`
  and re-runs the stage, which takes a different exit because the gate reads
  that column. The other answer is deleting the document, which is not a
  transition. Nothing may move a document *into* it by hand — `failed` does not
  list it, though it lists every other waiting status — because the screen it
  waits on is a comparison against records the matcher found, and a document
  parked there by a dropdown would arrive at a page with nothing on one side of
  it. `tests/smoke.php` asserts that `matching` is the only source.
  **Both flows run every stage above it**: a scan of an invoice already in Clear
  Books is extracted and matched exactly like a new one, because it is a
  document somebody will search for and report on whether or not anything is
  ever posted from it. `documents.route` is decided at OCR and read at the
  matching stage's *exit*.
- **Both flows end at `submitted`**, which is deliberate and is what
  `documents.route` exists to disambiguate — see §33. One arm creates a record
  in Clear Books; the other attaches a PDF to one that was already there.
- `failed` is reachable from every working state, and a retry moves the document
  back to the head of the stage that failed.
- `ignored` is a human decision, reachable from anywhere; `ignored → received`
  puts a document back into the pipeline.
- `needs_review ⇄ matching` so a resolved entity can be re-matched.
- `needs_review → existing_invoice` and `ready_to_submit → existing_invoice` let
  a person say "this is a scan of something already in Clear Books" from the
  document page. The reverse gesture is `needs_link → matching`, which the
  queue's "post it as a new invoice" makes by flipping the route and re-running
  the stage — so the document lands where that stage decides rather than
  wherever a dropdown was set to.
- `needs_link → existing_invoice` is "look the number up again", which is what
  the queue's Link action does when the number is left as it was — the invoice
  sync runs on a schedule, and the commonest reason a number matched nothing is
  a record entered in Clear Books since the last run.
- **`ocr_pending → existing_invoice` is deliberately *not* legal**, and
  `tests/smoke.php` asserts its absence. It was legal in Prompt 16, when the
  branch skipped extraction; a document that could still reach the Existing
  Invoice flow without being extracted is the thing §33 exists to prevent.

### Stages, and the working statuses

`App\Services\Pipeline::STAGES` maps each stage to the status it consumes
(`from`), the status the document wears while it runs (`during`, where the state
machine has one) and the status it produces (`to`).

| Stage | from | during | to | Handler |
|---|---|---|---|---|
| `ingest` | `received` | — | `ocr_pending` | `IngestStage` |
| `ocr` | `ocr_pending` | — | `ocr_done` | `OcrStage` |
| `extract` | `ocr_done` | `extracting` | `extracted` | `ExtractStage` |
| `match` | `extracted` | `matching` | `needs_review` | `MatchStage` |
| `link` | `existing_invoice` | — | `needs_link` | `LinkStage` |

**Two stages return something other than their declared `to`.** `match` returns
`ready_to_submit` when everything resolved, `existing_invoice` when
`documents.route` says so — that outcome is the branch, and it is why the
Existing Invoice flow costs nothing in duplicated pipeline — and
`possible_duplicate` when a New Invoice document looks like something Clear
Books already holds (§34). `link` returns `submitted` when the Clearbooks Number
found exactly one record whose date and total agreed exactly.

**There is no `dedup` stage**, and that is deliberate: the check wants
`documents.matched_supplier_id`, which the matching stage is what produces, so
running it earlier would deny it the strongest signal it has. It is the last
thing `MatchStage::run()` does on the New Invoice arm. `tests/smoke.php` asserts
that nothing in `STAGES` consumes `possible_duplicate` — a stage picking it up
would find the same records and queue itself for ever.

The registry records the **conservative** outcome in each case — the one where a
document stops and waits — because that is the one `Document::retryStatusFor()`
and the smoke test's consistency check have to be right about. Every alternative
destination is legal from the status it leaves, and the test asserts each one.

`ocr` has a single destination. It still decides the route — it is the stage
that reads the handwritten number — but it writes that to `documents.route` and
sends every document on to be extracted.

`needs_link` and `possible_duplicate` are statuses a document *waits* at, not
ones a stage consumes, so neither has a `STAGES` entry and
`Pipeline::stageFor()` returns null for both — the same arrangement
`needs_review` has, and the same one `existing_invoice` had while nothing ran
it. Every registered stage has a handler; the smoke test asserts it.

`during` is what `extracting` and `matching` are **for**, and getting this wrong
was a real bug caught by `tests/smoke.php`: the registry originally went
`ocr_done → extracted` directly, which the state machine forbids, so the
extraction stage would have done all its work and *then* thrown. Two rules the
test now enforces:

- every step (`from → during`, `during → to`) must be a legal transition;
- a `during` status must not be some other stage's `from`.

A stage that has a `during` status accepts a document back **in either
status** — a worker killed mid-extraction leaves the document in `extracting`,
and the released job has to pick it up without a human pressing Retry.

**`Pipeline::stageFor()` answers on `during` as well as on `from`**, which is
the other half of that and was a real gap until Prompt 18 walked into it. The
document page's "Reset to" control offers every status the state machine allows;
with `during` unanswered, choosing `matching` moved the document and enqueued
nothing, stranding it until the dashboard's stuck list noticed. It mattered
little while `matching` was one option among several — and a great deal once
`possible_duplicate` existed, because `matching` is the only status it can move
on to (§34). `from` is checked across every stage before `during` is, so a
status that is one stage's `during` cannot shadow another's `from`; the smoke
test asserts no status is both.

`Document::STATUSES` (ordered), `Document::TRANSITIONS`, `Document::LABELS` and
`Document::canTransition()` are the single source of truth. Nothing outside that
class may compare status strings it wrote out itself.

---

## 5. Routes

| Method | Path | Handler | Middleware |
|---|---|---|---|
| GET | `/health` | `AuthController::health` | — |
| GET | `/branding/{variant:light\|dark}` | `BrandingController::show` | — |
| GET | `/login` | `AuthController::showLogin` | `guest` |
| POST | `/login` | `AuthController::login` | `guest`, `csrf` |
| POST | `/logout` | `AuthController::logout` | `auth`, `csrf` |
| GET | `/` | `DashboardController::index` | `auth` |
| GET | `/documents` | `DocumentController::index` | `can:documents.view` |
| GET | `/documents/upload` | `UploadController::form` | `can:documents.upload` |
| POST | `/documents/upload` | `UploadController::store` | `can:documents.upload`, `csrf` |
| GET | `/documents/{id}` | `DocumentController::show` | `can:documents.view` |
| GET | `/documents/{id}/pdf` | `DocumentController::pdf` | `can:documents.view` |
| GET | `/documents/{id}/page/{page}` | `DocumentController::page` | `can:documents.view` |
| GET | `/documents/{id}/print` | `DocumentController::printable` | `can:documents.view` |
| POST | `/documents/{id}/retry` | `DocumentController::retry` | `can:documents.retry`, `csrf` |
| POST | `/documents/{id}/ignore` | `DocumentController::ignore` | `can:documents.retry`, `csrf` |
| GET | `/admin/clearbooks` | `ClearBooksController::index` | `can:settings.manage` |
| GET | `/admin/clearbooks/callback` | `ClearBooksController::callback` | `can:settings.manage` |
| POST | `/admin/clearbooks/connect` | `ClearBooksController::connect` | `can:settings.manage`, `csrf` |
| POST | `/admin/clearbooks/disconnect` | `ClearBooksController::disconnect` | `can:settings.manage`, `csrf` |
| POST | `/admin/clearbooks/refresh` | `ClearBooksController::refresh` | `can:settings.manage`, `csrf` |
| POST | `/admin/clearbooks/sync` | `ClearBooksController::sync` | `can:settings.manage`, `csrf` |
| POST | `/admin/clearbooks/sync-invoices` | `ClearBooksController::syncInvoices` | `can:settings.manage`, `csrf` |
| POST | `/admin/clearbooks/invoice-schedule` | `ClearBooksController::invoiceSchedule` | `can:settings.manage`, `csrf` |
| POST | `/admin/clearbooks/supplier-route` | `ClearBooksController::supplierRoute` | `can:settings.manage`, `csrf` |
| GET | `/admin/fields` | `FieldController::index` | `can:fields.manage` |
| GET | `/admin/fields/new` | `FieldController::edit` | `can:fields.manage` |
| POST | `/admin/fields` | `FieldController::save` | `can:fields.manage`, `csrf` |
| GET | `/admin/fields/{id}` | `FieldController::edit` | `can:fields.manage` |
| POST | `/admin/fields/{id}` | `FieldController::save` | `can:fields.manage`, `csrf` |
| POST | `/admin/fields/{id}/toggle` | `FieldController::toggle` | `can:fields.manage`, `csrf` |
| GET | `/admin/prompts` | `PromptController::index` | `can:prompts.manage` |
| GET | `/admin/prompts/{key}` | `PromptController::edit` | `can:prompts.manage` |
| POST | `/admin/prompts/{key}` | `PromptController::save` | `can:prompts.manage`, `csrf` |
| POST | `/admin/prompts/{key}/reset` | `PromptController::reset` | `can:prompts.manage`, `csrf` |
| POST | `/admin/prompts/{key}/activate/{id}` | `PromptController::activate` | `can:prompts.manage`, `csrf` |
| GET | `/admin/branding` | `BrandingController::index` | `can:settings.manage` |
| POST | `/admin/branding` | `BrandingController::upload` | `can:settings.manage`, `csrf` |
| POST | `/admin/branding/{variant}/remove` | `BrandingController::remove` | `can:settings.manage`, `csrf` |
| GET | `/admin/users` | `UserController::index` | `can:users.manage` |
| GET | `/admin/users/new` | `UserController::edit` | `can:users.manage` |
| POST | `/admin/users` | `UserController::save` | `can:users.manage`, `csrf` |
| GET | `/admin/users/{id}` | `UserController::edit` | `can:users.manage` |
| POST | `/admin/users/{id}` | `UserController::save` | `can:users.manage`, `csrf` |
| POST | `/admin/users/{id}/password` | `UserController::password` | `can:users.manage`, `csrf` |
| POST | `/admin/users/{id}/toggle` | `UserController::toggle` | `can:users.manage`, `csrf` |
| GET | `/admin/settings` | `SettingsController::index` | `can:settings.manage` |
| POST | `/admin/settings/document-types` | `SettingsController::saveDocumentTypes` | `can:settings.manage`, `csrf` |
| POST | `/admin/settings/test/{target}` | `SettingsController::test` | `can:settings.manage`, `csrf` |
| POST | `/admin/settings/{section}` | `SettingsController::save` | `can:settings.manage`, `csrf` |
| GET | `/admin/activity` | `ActivityController::index` | `can:audit.view` |
| GET | `/account/password` | `AccountController::password` | `auth` |
| POST | `/account/password` | `AccountController::updatePassword` | `auth`, `csrf` |

| GET | `/review` | `ReviewController::index` | `can:queue.view` |
| GET | `/review/{id}` | `ReviewController::show` | `can:queue.view` |
| POST | `/review/{id}/save` | `ReviewController::save` | `can:review.resolve`, `csrf` |
| POST | `/review/{id}/ignore` | `ReviewController::ignore` | `can:review.resolve`, `csrf` |
| POST | `/review/{id}/confirm-type` | `ReviewController::confirmType` | `can:review.resolve`, `csrf` |
| POST | `/review/{id}/entity/{matchId}/pick` | `ReviewController::pickEntity` | `can:review.resolve`, `csrf` |
| POST | `/review/{id}/entity/{matchId}/create` | `ReviewController::createEntity` | `can:entities.create`, `csrf` |
| POST | `/review/{id}/submit` | `ReviewController::submit` | `can:documents.submit`, `csrf` |
| POST | `/documents/{id}/resubmit` | `DocumentController::resubmit` | `role:admin`, `csrf` |
| GET | `/existing` | `ExistingInvoiceController::index` | `can:queue.view` |
| GET | `/existing/{id}` | `ExistingInvoiceController::show` | `can:queue.view` |
| POST | `/existing/{id}/link` | `ExistingInvoiceController::link` | `can:review.resolve`, `csrf` |
| POST | `/existing/{id}/new-invoice` | `ExistingInvoiceController::pushToNew` | `can:review.resolve`, `csrf` |
| POST | `/existing/{id}/delete` | `ExistingInvoiceController::delete` | `can:documents.delete`, `csrf` |
| GET | `/duplicates` | `DuplicateController::index` | `can:queue.view` |
| GET | `/duplicates/{id}` | `DuplicateController::show` | `can:queue.view` |
| POST | `/duplicates/{id}/not-duplicate` | `DuplicateController::notDuplicate` | `can:review.resolve`, `csrf` |
| POST | `/duplicates/{id}/delete` | `DuplicateController::delete` | `can:documents.delete`, `csrf` |

`/admin/fields/new` and `/admin/users/new` are declared **before** their
numeric forms, so "new" is never matched as an id.

Viewing the queue is `queue.view`, which a viewer has; everything that changes
something is `review.resolve` or higher, and **creating a record in somebody
else's accounts has its own capability** (`entities.create`) because it is a
different kind of act from correcting a date on a screen. `/existing` is the
same split: the Existing Invoice queue is its own screen because a document on
that flow has no extraction to correct, and **deleting a document has its own
capability** (`documents.delete`) for the same reason `entities.create` does.

`/duplicates` is the third queue and the same split again — see §34. It shares
`documents.delete` with `/existing` rather than adding a fourth capability: it
is the same act on the same kind of document, reached from the other side.

`/existing/{id}` and every action under it **redirect a document that is not at
`needs_link`**, checked in the controller rather than only in the template: each
one changes something, and a stale tab is a real way to reach a document that
has since been linked, deleted, or sent the other way. `/duplicates/{id}` does
the same against `possible_duplicate`, and additionally 404s a document with no
extraction — the gate cannot fire without one, so a document there without one
has had the row removed from underneath it.

`/admin/clearbooks/callback` is the **one signed-in route without `csrf`**, and
deliberately: it is a redirect from Clear Books, which has no token to carry. A
`state` parameter generated in `connect()`, kept in the session and compared
with `hash_equals()` does the same job, and the pending request is discarded
whether the exchange succeeds or fails — an authorisation code is single use and
a stale verifier invites a replay.

Routes are declared in `routes/web.php`. Middleware is named on the route, never
checked inside a controller.

**There is no unauthenticated way into this application any more.** The webhook
receiver was the one route with no middleware at all, authenticated by a shared
secret rather than a session; it went with Paperless. Every route that accepts
input is now behind `auth`, and every state-changing one behind `csrf`. The
`` list in the smoke test is down to four entries, and a fifth
appearing there should be argued for rather than added.

**No path is reserved any more.** `/admin/settings` and `/admin/activity` were
the last two and are built. The review queue was reserved as `/queue` and is
built as `/review`; Prompt 9 named the two admin screens `/settings/*` and they
are built under `/admin/*`, which is the prefix the rest of the application
already uses.

`/admin/settings/document-types` and `/admin/settings/test/{target}` are
declared **before** `/admin/settings/{section}`. The section pattern is
`[a-z_]+` and cannot match a hyphen or a second path segment, so today the
order is redundant — it is there so that the next section named with a hyphen
is not silently swallowed by the generic route.

### Middleware names

`auth`, `guest`, `csrf`, `can:<capability>`, `canany:<a,b>`, `role:<role>` —
resolved by `App\Middleware\MiddlewareRunner`. `can`, `canany` and `role` all
imply `auth`.

---

## 6. Roles and capabilities

`App\Core\Auth` holds both. Roles are ordered: `viewer` < `reviewer` < `admin`,
and capabilities are cumulative up that order.

| Role | Adds |
|---|---|
| `viewer` | `documents.view`, `queue.view` |
| `reviewer` | `documents.upload`, `documents.retry`, `review.resolve`, `entities.create`, `documents.submit`, `documents.delete` |
| `admin` | `settings.manage`, `prompts.manage`, `fields.manage`, `users.manage`, `audit.view` |

Always check `Auth::can('x')` / the `can('x')` template helper, or
`Auth::atLeast('admin')` / the `role_at_least('admin')` helper for the two
places gated on seniority rather than on a capability. **Never compare `role`
strings at a call site** — `Auth::can()` is the only method that should have to
change if the model grows.

`Auth::capabilityMap()` returns the same table, cumulative and in role order,
and is what the users screen renders. A hand-written table in a template would
describe a permission model the application does not enforce within one release.

**`documents.delete` is the one capability held by a reviewer that destroys
something.** It is the Existing Invoice queue's third action and the duplicate
queue's second, and both are reviewers' screens — a resolution its own audience
cannot reach is a resolution that does not get used, and the alternative on
either queue is a database filling with duplicate scans nobody will look at
again. The controls are the required reason and an audit row that outlives the
document. It is a **separate capability** rather than part of `review.resolve`
precisely so that moving it to `admin` is one line in `Auth::CAPABILITIES` and
nothing else.

**Prompt 10 named the middle role "Processor"; it is `reviewer` here.** The DB
enum, `Auth::ROLES`, every `can:` string and a year of audit entries already say
reviewer, the screen it names is called the Review queue, and renaming it buys
a word. Judgement was explicitly delegated on the naming.

### The gates are asserted, not assumed

`tests/smoke.php` walks `Router::routes()` and fails if:

- any route lacks `auth`/`can`/`role` and is not on the four-item
  `$deliberatelyOpen` list (health, branding, the two login routes);
- any non-GET route lacks `csrf`, other than the Clear Books callback;
- any `can:` names a capability no role holds — a typo would otherwise lock
  everybody out silently, because the gate would simply always say no.

Hiding a button is a courtesy. This is the enforcement, and a route added later
without a gate fails here rather than being found by whoever finds it.

---

## 7. Components and where they live

| Thing | File |
|---|---|
| Bootstrap, autoloader, error handling | `src/bootstrap.php` |
| Config (dot-notation, from `.env`) | `src/Core/Config.php`, `config/config.php` |
| `.env` loader | `src/Core/Env.php` |
| PDO wrapper, `insert()` / `update()` / `select()` | `src/Core/Database.php` |
| Router (`{param:regex}` patterns, named routes) | `src/Core/Router.php` |
| Request / Response (JSON, redirects, security headers, CSP) | `src/Core/Request.php`, `Response.php` |
| Session (idle timeout, keyed UA fingerprint) | `src/Core/Session.php` |
| CSRF tokens | `src/Core/Csrf.php` |
| Flash messages, old input, field errors | `src/Core/Flash.php` |
| Template rendering, error pages | `src/Core/View.php` |
| Rule-based validation | `src/Core/Validator.php` |
| **AES-256-GCM secret encryption under `APP_KEY`** | `src/Core/Crypto.php` |
| Auth, roles, capabilities, sign-in | `src/Core/Auth.php` |
| Failed-login throttling | `src/Core/LoginThrottle.php` |
| `.sql` migration runner | `src/Core/Migrator.php` |
| Template helpers (`e`, `url`, `can`, `format_money`, …) | `src/helpers.php` |
| Settings read/write, secret handling, `.env` fallback | `src/Models/Setting.php` |
| **What the Settings screen may edit, and how to render it** | `src/Models/SettingSchema.php` |
| Users | `src/Models/User.php` |
| Pipeline state machine, status counts | `src/Models/Document.php` |
| Document type registry | `src/Models/DocumentType.php` |
| Audit trail, and the log screen's filters and paging | `src/Models/AuditLog.php` |
| Logo resolution and safe path handling | `src/Services/Branding.php` |
| **cURL wrapper: timeouts, no redirects, error translation** | `src/Services/Http.php` |
| One HTTP response, `json()`, `errorSummary()` | `src/Services/HttpResponse.php` |
| DNS/connect/TLS/timeout failure (worth retrying) | `src/Services/HttpTransportException.php` |
| **Stage registry and job runner** | `src/Services/Pipeline.php` |
| Stage 1: check what was ingested before spending on it | `src/Services/IngestStage.php` |
| **Where every document enters: check, insert, store, queue** | `src/Services/Ingest/Ingestor.php` |
| A file being offered, and how to move it | `src/Services/Ingest/IngestCandidate.php` |
| The ingest routes that exist, and their labels | `src/Services/Ingest/IngestSource.php` |
| A candidate that was refused | `src/Services/Ingest/IngestException.php` |
| **Stage 2: render pages, then transcribe them** | `src/Services/OcrStage.php` |
| **Stage 3: three extraction calls, merged** | `src/Services/ExtractStage.php` |
| **`{{ name }}` prompt interpolation** | `src/Services/PromptRenderer.php` |
| Clear Books cache, read side + prompt shaping | `src/Models/ClearbooksCache.php` |
| Custom field definitions and type coercion | `src/Models/CustomField.php` |
| Merged extraction record, JSON readers | `src/Models/Extraction.php` |
| pdftoppm wrapper: DPI, size cap, proc_open | `src/Services/PdfRenderer.php` |
| **The LLM interface — nothing else names a provider** | `src/Services/Llm/LlmClient.php` |
| Picks the client for a stage from settings | `src/Services/Llm/LlmFactory.php` |
| Anthropic Messages API over plain HTTP | `src/Services/Llm/AnthropicClient.php` |
| OpenAI chat completions over plain HTTP | `src/Services/Llm/OpenAiClient.php` |
| One page image, lazily base64-encoded | `src/Services/Llm/LlmImage.php` |
| Provider-neutral reply, with `json()` fence-stripping | `src/Services/Llm/LlmResponse.php` |
| Provider failure, carrying whether it is retryable | `src/Services/Llm/LlmException.php` |
| Versioned prompts; an edit writes a new version | `src/Models/PromptTemplate.php` |
| Rendered page rows | `src/Models/DocumentPage.php` |
| Transcriptions, newest first | `src/Models/OcrResult.php` |
| Work queue: claim, backoff, release stalled | `src/Models/PipelineJob.php` |
| What the machine did, per stage | `src/Models/DocumentEvent.php` |
| The upload page: the one ingest route today | `src/Controllers/UploadController.php` |
| Document list, detail, PDF, retry, ignore | `src/Controllers/DocumentController.php` |
| **Clear Books v1 REST client, OAuth2 and all** | `src/Services/ClearBooksClient.php` |
| Clear Books failure, carrying whether a retry helps | `src/Services/ClearBooksException.php` |
| Clear Books needs somebody to sign in again | `src/Services/ClearBooksAuthException.php` |
| **Company-name reduction: the deterministic fallback** | `src/Services/Normaliser.php` |
| Refill the cached reference lists | `src/Services/CacheRefresh.php` |
| **Stage 4: check every id, fall back on names** | `src/Services/MatchStage.php` |
| One row per entity that has to resolve | `src/Models/EntityMatch.php` |
| Connect, cache state, refresh now, invoice sync | `src/Controllers/ClearBooksController.php` |
| **The queue, the editable detail, resolve, submit, skip** | `src/Controllers/ReviewController.php` |
| Accounts: create, edit, role, deactivate, reset a password | `src/Controllers/UserController.php` |
| Changing your own password | `src/Controllers/AccountController.php` |
| An exception that can explain itself | `src/Services/Diagnosable.php` |
| Files arriving from a browser, and the checks on them | `src/Core/Upload.php` |
| Every route x every role, over real HTTP | `tests/permissions.php` |
| Is every workflow step really implemented? | `tests/pipeline.php` |
| First-time install on a server | `install.sh` |
| Everything afterwards | `manage.sh` |
| Every check an install needs, each with what to do | `src/Services/Doctor.php` |
| The logo: serving it, and replacing it | `src/Controllers/BrandingController.php` |
| The shell for anything meant to end up on paper | `templates/layouts/print.php` |
| What counts as an acceptable password, in one place | `src/Core/PasswordPolicy.php` |
| Custom fields: define, edit, take out of use | `src/Controllers/FieldController.php` |
| Prompts: edit, version, roll back, reset to default | `src/Controllers/PromptController.php` |
| **Application settings, per-card saves, connection tests** | `src/Controllers/SettingsController.php` |
| The activity log: filters and paging, and nothing that writes | `src/Controllers/ActivityController.php` |
| **The only class that creates anything in Clear Books** | `src/Services/EntityCreator.php` |
| **Build the payload, submit, attach, record** | `src/Services/SubmitStage.php` |
| What was sent to Clear Books, and what came back | `src/Models/Submission.php` |
| The local copy of what Clear Books already holds | `src/Models/ClearbooksInvoice.php` |
| Fetching that copy, on a schedule and on a button | `src/Services/InvoiceSync.php` |
| **Stage 5: find the record a Clearbooks Number names, attach the PDF** | `src/Services/LinkStage.php` |
| **Which record a number means, and whether the page agrees** | `src/Services/InvoiceMatcher.php` |
| The Existing Invoice queue: link, push to New, delete | `src/Controllers/ExistingInvoiceController.php` |
| **Which field is each note, unresolved match and low confidence about?** | `src/Services/FieldIssues.php` |
| The scan viewer: page images first, PDF on request | `templates/partials/scan.php` |

**Still to be written**: the administration controllers and screens — settings,
prompts, custom fields, users, activity log. Every one of them makes its HTTP
calls through `Http`, and every LLM call through `LlmFactory::forStage()` —
never a provider class directly.

There is no `EntityMatcher` class, though earlier notes named one. The matching
is `MatchStage` (the policy: what has to resolve, and what happens when it does
not), `Normaliser` (the string reduction) and `ClearbooksCache::matchByName()`
(the lookup, beside the data it looks in). Splitting it three more ways would
have bought nothing.

---

## 8. Front end

- One stylesheet, `public/css/app.css`; one script, `public/js/app.js`. No build
  step, no framework, no CDN.
- Theming is CSS variables on `[data-theme]` on `<html>`. An inline script in
  both layouts applies the stored theme **before first paint** so the page never
  flashes. The choice is kept in `localStorage` *and* a `theme` cookie.
- `app.js` handles: theme toggle, nav drawer (measuring `--header-h`), nav
  groups, show/hide password, dismissable and auto-hiding flash messages,
  `data-confirm`, `data-clearbooks-window` (the reusable named
  `clearbooksWindow` for the "Open in Clear Books" action), the upload page's
  client-side file checks, and the scan viewer (`data-scan`) — see §35.
- Layouts: `templates/layouts/app.php` (signed in) and `auth.php` (signed out).
- Partials: `brand.php` (logo with light/dark variants, IG monogram fallback),
  `nav.php`, `footer.php`, `flash.php`, `scan.php`, `extraction.php`,
  `matches.php`.
- Layouts are three now: `app.php` (signed in), `auth.php` (signed out) and
  `print.php`, which includes neither the navigation nor the footer — see §28.
- **`app.php` takes a `wide` view variable** and puts `.container-wide` on
  `<main>`, which raises the content column from 1200px to 1760px. It is opt-in
  per screen and set by the document-facing ones: the dashboard, the document
  list and record, and all three queues and their detail screens. Forms keep
  their own `max-width`, so an administration screen set wide would not stretch
  anyway — the opt-in is so a reader can see which screens were designed for a
  monitor. See §35.
- Component classes available: `.card`, `.stat-grid`/`.stat-card`,
  `.table-wrap`/`.table`/`.table-compact`/`.amount`, `.badge-*`, `.btn-*`,
  `.field`/`.input`/`.field-error`/`.field-hint`, `.flash-*`, `.subnav`,
  `.filter-bar`, `.page-head`, `.section-title`, `.empty`, `.container-wide`,
  `.wide-split`, the column-width helpers (`.col-tight`, `.col-narrow`,
  `.col-date`, `.col-name`, `.col-wide`, `.col-grow`), the scan viewer
  (`.scan`, `.scan-stage`, `.scan-page`, `.scan-bar`, `.scan-strip`,
  `.scan-pdf`) and the field marks (`.is-flagged`, `.is-flagged-danger`,
  `.flag-tag`, `.flag-notes`, `.issue-index`, `.issue-jump`).

---

## 9. Navigation structure

```
[IG logo] InvoGrid  Documents  Review queue  Existing invoices  Duplicates  Upload  Settings ▾  [theme] [avatar →] [Sign out]
                                                                                                └ links to
                                                                                                  /account/password
                                                                                       ├ Application settings
                                                                                       ├ Branding
                                                                                       ├ Clear Books
                                                                                       ├ Prompts
                                                                                       ├ Custom fields
                                                                                       ├ Users
                                                                                       └ Activity log
```

No Dashboard entry: the logo is the link home. Items are filtered by capability;
a group with nothing visible in it disappears entirely.

**There are three queues and each is a top-level entry**, because each asks a
different question of different data:

| Queue | The question |
|---|---|
| Review queue | Is this extraction right, and does everything on it resolve? |
| Existing invoices | Which Clear Books record does this handwritten number point at? |
| Duplicates | Is this invoice one Clear Books already holds, though nobody wrote a number on it? |

A tab on another screen would be a queue nobody works, and the third is the one
where the wrong answer costs money rather than time.

**The desktop breakpoint moved from 900px to 1150px with the third queue** —
back to Kitwell's own, and §2's note about it is updated. Six top-level items
plus the account block and the brand run to about 1100px of content, which at
900 is a header wrapped onto two rows.

Footer, every page: `InvoGrid — by Junction Inc Ltd` (vendor linked), plus the
tagline and who is signed in.

---

## 10. Configuration split

- **`.env`** — what is needed before the database can be read: `APP_URL`,
  `APP_KEY`, DB credentials, session/HTTPS behaviour, `STORAGE_PATH`. Also holds
  an optional fallback for each integration credential.
- **`settings` table** — everything an administrator edits: Clear Books OAuth2
  credentials and business id, LLM API keys, the upload size limit, per-stage
  provider and model choices, logo paths.
- **Precedence: a non-empty setting wins; `.env` is the fallback.** Implemented
  in `Setting::ENV_FALLBACK`.
- Secrets (`is_secret = 1`) are encrypted with `APP_KEY`. `Setting::secret()` is
  the only reader. `Setting::put()` **returns `false` rather than writing a
  secret in the clear** when `APP_KEY` is missing. Nothing prints a secret back
  to a browser; `Setting::summary()` exposes only `configured: true|false`.

Seeded setting keys: `organisation_name`, `ingest_max_upload_mb`,
`clearbooks_base_url`,
`clearbooks_client_id`, `clearbooks_client_secret`*, `clearbooks_access_token`*,
`clearbooks_refresh_token`*, `clearbooks_token_expires_at`,
`clearbooks_business_id`, `clearbooks_web_url`, `clearbooks_cache_ttl_minutes`,
`openai_api_key`*, `anthropic_api_key`*, `llm_ocr_provider`, `llm_ocr_model`,
`llm_extraction_provider`, `llm_extraction_model`, `flash_auto_hide_seconds`,
`logo_light_path`, `logo_light_mime`, `logo_dark_path`, `logo_dark_mime`,
`clearbooks_authorise_url`, `clearbooks_redirect_uri`, `clearbooks_scopes`,
`clearbooks_invoice_sync_interval_minutes`, `clearbooks_invoice_sync_last_run`,
`clearbooks_attach_pdf`.
(`*` = secret.)

The last five have **no `.env` fallback** and are rows only. `clearbooks_scopes`
is asserted in `tests/smoke.php`: a scope added by accident fails a test rather
than quietly granting the integration the run of the ledger.

### What the Settings screen edits

`App\Models\SettingSchema` is the authority, not this list — it declares every
editable setting with its card, label, hint, field kind and validation rule, and
`SettingsController` and `templates/admin/settings.php` both render from it.
Adding a setting is an entry there plus a seeded row; `tests/smoke.php` fails if
a key named in the schema is not seeded, because such a field renders, accepts
what is typed and silently never comes back.

Three groups are **deliberately absent** from the schema, and a smoke check
asserts each stays absent:

- `logo_*` — the Branding screen owns those. A storage path typed by hand is a
  path to a file that is not there.
- `clearbooks_access_token`, `clearbooks_refresh_token`,
  `clearbooks_token_expires_at` — written by the consent flow. There is nothing
  useful a person could type, and a value typed by hand breaks a working
  connection.
- `clearbooks_invoice_sync_interval_minutes`,
  `clearbooks_invoice_sync_last_run` — already on the Clear Books screen, beside
  the sync they govern.

**The Existing Invoice route (§33) adds nothing here, and that is deliberate.**
An earlier draft of it carried three tolerances — how far a date might differ,
and how far a total might. The checksum is exact instead, so there is nothing to
configure: a tolerance setting is a licence to attach a scan to the wrong
invoice without anybody noticing, and if one is ever wanted it should be an
argued change rather than a row that appeared. `tests/smoke.php` asserts that no
settings key containing "tolerance" exists.

The form is filled from `Setting::stored()`, **not** `Setting::get()`. `get()`
answers "what does the application use", which for an empty row is the `.env`
value; putting that in the input would copy `.env` into the database the moment
somebody saved an unrelated field, and the fallback would stop being a fallback
without anyone deciding that. Where a row is empty and `.env` is answering, the
screen says so under the field.

A secret is never sent to the browser. It arrives at the template as
`configured: true|false`; the box is always empty, an empty box means "leave it
alone", and a separate checkbox clears one. `templates/admin/settings.php` may
not reference `Setting::` at all, asserted with the comments stripped first so
that deleting the paragraph explaining the rule cannot satisfy the check.

The **document types** card on this screen is read-only. It used to carry the one
editable thing about a type — which Paperless document type it was written back
as — and that has gone. What it shows is still worth the space: which Clear Books
resource each type is submitted to is a row in `document_types` rather than
anything in the code, and it is the only screen that answers "what does InvoGrid
do with a credit note". Changing one stays a migration, because
`clearbooks_resource` decides which endpoint somebody's accounts are written to
and a text box is the wrong amount of ceremony for that.

**Connection tests** (`POST /admin/settings/test/{target}`) call the `ping()`
that both LLM clients already carried for this screen.
`isConfigured()` only answers "is there a string in the box", which is not the
question anybody has. The model tests are real API calls, which is why they are
buttons rather than something done on page load, and why they are POST — a GET
is something a browser may repeat on its own.

---

## 11. Command-line tools

```bash
php bin/migrate.php [--status]      apply pending migrations
php bin/create-admin.php            create or reset an account (first one is admin)
php bin/process-queue.php           work the queue; --status, --verbose, --limit=N
php bin/refresh-clearbooks.php      refill the cache; --status, --sync, --dry-run
php bin/console.php secret:generate      a random shared secret
php bin/console.php settings:set <key>   set one, value read from stdin
                                         (the Settings screen does the same job;
                                          this is for an install with no browser)
php bin/console.php key:generate    print a new APP_KEY
php bin/console.php db:check        database reachable, schema current, key present
php bin/console.php settings:list   which settings are configured (never values)
php tests/smoke.php                 289 assertions; exits non-zero on failure
php tests/pipeline.php              every workflow step: implemented, reachable, has run
php tests/permissions.php <url>     every route x every role, over real HTTP
php -S 127.0.0.1:8484 -t public bin/serve.php   development server
```

`tests/smoke.php` is plain assertions, not a test framework — there is no
Composer here. Two halves:

- **Without a database:** config loading, the `Crypto` round trip (including
  that a tampered blob fails closed), the validator, the state machine's
  internal consistency, the helpers, named routes, template presence.
- **With one** (skipped, loudly, when the database is unreachable or
  unmigrated): that `documents.status`'s ENUM still matches
  `Document::STATUSES`, a `LoginThrottle` round trip against the real table, a
  `Setting` secret round trip proving the stored value is ciphertext and that
  `summary()` never returns it, and the seeded document types.

**A database-half check must leave the database exactly as it found it.** The
Clear Books round trip did not, at first: it called `deactivateMissing()` with
only its own test ids, which retired every real cached supplier and left the
Clear Books screen reading "Suppliers: none". It now names every currently
active row in the "seen" list, so only its own second row is touched, and it is
safe to run against a live database. A check that damages what it is checking is
worse than no check.

**Anything where PHP names a database column or an enum value belongs in the
second half.** It exists because of a real bug: `LoginThrottle` was adapted from
Kitwell and kept querying an `email` column, which InvoGrid's `login_attempts`
does not have — it uses `username`. Nothing without a live database noticed, and
every failed sign-in was a 500. When adapting anything else from Kitwell, check
the column names against `001_schema.sql` first.

---

## 12. The pivot away from Paperless

Prompts 1–13 built InvoGrid around Paperless-ngx: a workflow posted a webhook,
InvoGrid pulled the document and its PDF from the API, and once the bill reached
Clear Books it wrote correspondent, title, content, document type, tags, custom
fields and a note back. Prompt 15 removed all of it. This section records what
went, what replaced it, and the decisions that are not obvious from the diff.

### What was removed

| Gone | Was |
|---|---|
| `WebhookController` | the receiver, authenticated by a shared secret |
| `PaperlessClient` | the REST client |
| `PaperlessNotFoundException` | "deleted there, never retry" |
| `PaperlessWriteBack` | the six writes after a submission |
| `PaperlessFields` | the custom-field pairing and creation |
| `SupplierSync` | Clear Books suppliers → Paperless correspondents |
| `POST /webhook/paperless` | the only unauthenticated input route |
| `POST /admin/clearbooks/sync` | the correspondent sync, and its dry run |
| `POST /admin/settings/document-types` | the document-type mapping form |
| `manage.sh webhook-secret`, `--sync` on the refresh script | |

**No code anywhere calls a Paperless endpoint.** `Http::download()` survives
because it is a capability of the HTTP client rather than part of that
integration — nothing calls it today, and the next ingest route that pulls from
a URL will need it, size guard included.

### What replaced it

`src/Services/Ingest/` — see §21. A document is handed to InvoGrid directly, and
the pipeline from OCR onward is unchanged: same stages, same statuses, same
queue, same retry semantics.

### Decisions

- **Columns were dropped, not left unused.** Listed in §3. A nullable column
  nobody populates again is read by the next maintainer as something that still
  works.
- **Columns holding real data were renamed instead.** "Correspondent" was
  Paperless's word for the party a document is with; what
  `documents.correspondent_raw` held — the issuer as printed on the page — is as
  useful as it ever was. Same for `extractions.paperless_title`, which is the
  short "what was bought" line that heads the review screen.
- **Two extraction prompts were re-seeded at a new version**, not edited in
  place: `extract_header` (`paperlessTitle` → `documentTitle`) and
  `extract_supplier` (dropping the `paperlessId` it echoed back for the
  correspondent write-back). Every other prompt change in this application works
  the same way — the old version stays readable beside it, and a site that had
  customised the previous one still has that text to copy from. `extract_lines`
  never mentioned Paperless and is untouched.
- **The `submission` custom-field source was repurposed, not retired.**
  `clearbooks_bill_id` and `clearbooks_document_number` existed only to be
  written into Paperless. They are now written onto the extraction itself, so
  they reach the document page and the printed summary. See §23.
- **The correspondent sync went entirely, but the Clear Books side stayed.**
  Supplier listing, matching and creation are untouched — only the half that
  mirrored them into Paperless is gone. `EntityCreator::supplier()` no longer
  returns a `correspondentId`.
- **`manage.sh reset-storage` became genuinely destructive.** It used to say the
  PDFs were re-fetchable from Paperless. Nothing holds a second copy now, and the
  command says so in bold.

---

## 13. LLM access — the rules that hold the abstraction together

**Nothing outside `App\Services\Llm` may name a provider, hold an API key, or
build a provider-shaped request.** A stage asks `LlmFactory::forStage('ocr')`
and gets an `LlmClient`. That is what makes the provider a setting instead of a
rewrite, and it is the one rule in this section that must not be bent.

- Provider and model are chosen **per stage** (`llm_ocr_*`, `llm_extraction_*`),
  because transcription and structured extraction have different strengths and
  different costs.
- `anthropic_base_url` / `openai_base_url` are normally empty, meaning go direct.
  Set to a gateway *origin* — the factory appends `/v1/messages` or
  `/v1/chat/completions`. This is also how the stack is exercised against a
  local stand-in without spending money.
- `LlmException::$retryable` is the client's own judgement about its failure,
  and `Pipeline::isPermanent()` is the only consumer. A 429 or 5xx backs off; a
  rejected key, an unknown model or a refusal stops the document.
- **A truncated transcription is a failure, not a short answer.** Both clients
  throw on `stop_reason: max_tokens` / `finish_reason: length`. Half a document
  read as a whole one is how confidently wrong totals reach the accounts.

Anthropic specifics, from the current API:

- `x-api-key` plus `anthropic-version: 2023-06-01`. **Not** a bearer token.
- Model ids carry **no date suffix** — `claude-opus-5`, not
  `claude-opus-5-20260101`.
- `thinking: {type: "adaptive"}`. `budget_tokens` is rejected with a 400 on
  current models; do not reintroduce it.
- Content blocks are images first, then the instruction text. With adaptive
  thinking the reply also contains `thinking` blocks — filter to `type: "text"`.
- `stop_reason` is checked **before** `content`, because a refusal returns an
  empty content array.
- `fallbacks: "default"` with the `server-side-fallback-2026-07-01` beta header
  re-runs a classifier refusal on another model in the same round trip. Only the
  Opus 5 / Fable 5 families accept it — sent to anything else it is a 400, which
  is why `supportsServerSideFallback()` gates it.

OpenAI specifics:

- `Authorization: Bearer`. Images are `image_url` parts carrying a `data:` URI,
  with `detail: high` — `low` downsamples to a thumbnail and loses exactly the
  handwriting being read.
- The output limit has two names. The client sends `max_completion_tokens` and
  retries once with `max_tokens` when the API rejects it by name, rather than
  keeping a list of model names that would be stale within months.

---

## 14. Prompt variables — the contract

`App\Services\PromptRenderer` fills `{{ name }}` placeholders. A name only: it
cannot run code, unlike the n8n expressions it replaces, and **a name nothing
provides is an exception at render time**, before any request is made.

`ExtractStage::variables()` is the single supplier of them, and it offers all of
them to every call — a prompt takes what it names. Adding `{{ accountCodes }}`
to the header prompt is therefore an edit, not a code change.

| Variable | Is |
|---|---|
| `ocrText` | `OcrResult::text()` — the transcription, and nothing but |
| `today` | `date('Y-m-d')` |
| `suppliers` | `ClearbooksCache::forPrompt('supplier')` — carries `cbId` |
| `accountCodes` | `ClearbooksCache::forPrompt('account_code')` |
| `vatRates` | `ClearbooksCache::forPrompt('vat_rate')` — with the percentage, or VAT cannot be computed |
| `vatTreatments` | `ClearbooksCache::forPrompt('vat_treatment')` |
| `customFields` | `CustomField::forPrompt()` |
| `annotations` | `OcrResult::annotations()` — the handwritten marks as objects, ink colour and location intact |

**When adding a variable**, add it to `ExtractStage::variables()` *and* to
`PromptTemplate::EXTRACTION_VARIABLES`, which is the contract the Prompts editor
validates against and the smoke test reads. There is an assertion that the two
agree, so forgetting one fails loudly rather than at render time.

`PromptRenderer::encodeList([])` returns `[]` with a comment saying the list is
empty, rather than a bare `[]`: a model reads the bare form as "there are none"
and then invents values to fill the gap.

### Rules the extraction stage holds to

- **Any call failing to parse fails the whole stage.** Nothing is coerced, and
  nothing is stored. A document with two thirds of its fields and a silent gap
  is worse than one that plainly stopped.
- **Malformed output is not retried** (`LlmException` with `retryable: false`).
  Four more identical calls cost money and fail the same way.
- `reviewNotes` from all four calls are merged, each prefixed by which call
  raised it. `needs_review` is set when that list is non-empty.
- **An unmatched supplier is not flagged here.** It was until Prompt 5; see §20
  for why that had to move to the matching stage.
- **No sign is applied to a credit note's totals.** Whether Clear Books wants
  negatives is a question about its API and belongs with the submission;
  `document_types.amount_sign` is where that decision lives.
- VAT and gross are left null unless *every* line's rate percentage is known
  from the cache. A wrong VAT figure is worse than none.
- **`creditNote` vs `credit_note`** — settled by reducing both sides to letters
  and digits and comparing (`ExtractStage::sameKey()`), so a new document type
  is still just a row rather than an entry in a translation table. The same
  trick maps `clearbooks_number` to the OCR's `clearbooksNumber`.
- **Custom fields are resolved in two steps, not three.** The OCR response
  already answers whatever lines up with a field by name; only the rest goes to
  the fallback call, and that call is now given `{{ annotations }}` so it can
  still see the handwriting. The middle step — reading the values back out of
  the `### Notes` text by label — went with the section in Prompt 16, and was
  never anything but a worse copy of step one: the only fields the notes could
  state were the two the OCR prompt reports anyway.

---

## 15. The production prompts, as seeded

The four prompts from the existing n8n flow were supplied at the end of Prompt 3.
The OCR one is now seeded as `ocr` **version 2** and is active; version 1, which
was written to the specification before the real text was available, stays in the
table. The other three are **not yet seeded** — they belong with the extraction
stage.

### Structured output is data, not text

**The `### Notes` section is gone** — removed in Prompt 16, and it should not
come back. It existed because n8n had no database: every field the model found
had to be flattened into text appended to the transcription, and confidence
scores and extra fields became impractical almost immediately. InvoGrid has a
database, so:

- the response is parsed **once**, in `OcrResult::create()`, and stored as
  columns — `raw_text` (verbatim, for a human asking what actually came back),
  `ocr_text` (the transcription alone, which is what a downstream prompt is
  given), `structured_json` (everything else), and four promotions out of it:
  `notes_present`, `clearbooks_number`, `project_code`, `annotations_json`;
- **nothing downstream re-parses the raw text.** The document template used to
  `json_decode` it on every render; that was the n8n habit and it is gone.
  `OcrResult::structured()`, `text()`, `annotations()`, `clearbooksNumber()`
  and `projectCode()` are the readers;
- `ocrText` is the transcription and nothing else. It was carrying an appended
  restatement of the structured fields, which every extraction prompt then had
  to be told to skip past — and which put text into the permanent record of a
  page that is not printed on that page. A prompt that wants the annotations
  gets `{{ annotations }}`; the one screen that wants them for a human renders
  them from the column.

**A field is promoted to a column when something has to act on it, not when
something has to show it.** `structured_json` is enough to render from;
`clearbooks_number` is a column because the branch tests it on every document
and a decision that must decode a blob to reach its input is one nothing can
index or query. `notes_present` was promoted on the same grounds — it is the
one field worth filtering a list by.

**Applies to every stage from here on.** Confidence scores, per-field notes and
anything else a prompt reports are stored as data and rendered into text where
text is wanted — never the other way round. The current prompt asks for no
confidence scores, which is how n8n ran it; adding them is a prompt edit, and
`structured_json` already has room for them.

### The OCR contract, exactly

`ocrText`, `notesPresent`, `handwrittenAnnotations[]` of
`{text, inkColor, marksPrintedText, location}`, `clearbooksNumber`, `project`.

**`clearbooksNumber` has a lower-case b**, and the project field is `project`,
not `projectCode`. Version 1 invented `clearBooksNumber` / `projectCode`, and
`OcrStage` and the document template read those names — so the real prompt would
have reported no annotations on every document, silently, with nothing throwing.
Corrected, and `tests/smoke.php` now asserts that every key the code reads is
named in the active prompt.

There are **no confidence fields and no `reviewNotes` in the OCR prompt**. Those
appear in the three extraction prompts instead. Do not reintroduce them here.

**The prompt's Step 4 exists to stop the notes section coming back.** A model
given Steps 2 and 3 and told nothing about what to do with them will helpfully
summarise them at the end of the transcription anyway, which is the section
under another name. Step 4 says plainly that `ocrText` is the transcription and
nothing else, and `tests/smoke.php` asserts the string `### Notes` appears
nowhere in the active prompt.

**`clearbooksNumber` is now load-bearing.** It is what routes a document to the
Existing Invoice flow (§32), so an edit to that rule changes where documents
end up — not just what a screen displays.

### The other three, as seeded

All three are now rows in `prompt_templates` (migration 008), verbatim apart
from the interpolation — see §14. Things about them worth keeping in mind:

- **None of them mentions `### Notes` any more.** `extract_header` was told to
  ignore everything from that heading onward and `extract_custom_fields` was
  told to read it first; both were re-seeded in migration 018. An instruction to
  skip past a landmark that is no longer there is worse than no instruction —
  a model that goes looking for it will find some other heading and do as it was
  told. `extract_custom_fields` is given `{{ annotations }}` instead, which is a
  straight improvement on the section it replaces: the marks arrive as objects
  with ink colour and location intact rather than as flattened bullets.
- `accountCode` is **numeric**; `vatRateKey` is a string; `vatTreatment` is
  `{key, name}`. `documentType` is `bill` or `creditNote` — mapped onto
  `document_types.type_key` by reduction, not a lookup table.
- **`tradingNames[]` lives in `extractions.supplier_match`**, along with the
  address, VAT number and company number the supplier call returns when it
  finds no match. The matching stage turns that into `entity_matches` rows;
  until then it is a record of what the model said, not a decision.
- The supplier prompt returns `cbId` alone since Prompt 15, which is why
  `ClearbooksCache::forPrompt()` puts both into the injected list.

---

## 16. The local development machine

Recorded because it is not derivable from the repository, and because two of its
quirks cost time.

- **Database:** MariaDB 12.3 on `127.0.0.1:3306`, database `invogrid`, user
  `invogrid`. Both migrations are applied and a single `admin` account, `nick`,
  exists. Kitwell's local database (`kitwell`) is a separate database and user
  on the same server; the two share nothing.
- **PHP 8.4.22** (WinGet package `PHP.PHP.8.4`), with a `php.ini` written from
  `php.ini-development` in the package directory. It sets an **absolute**
  `extension_dir` — a relative one resolves against the working directory on
  Windows, so `php` run from anywhere else finds no extensions — enables
  `curl`, `fileinfo`, `mbstring`, `openssl` and `pdo_mysql`, and sets
  `date.timezone = Europe/London`. Plain `php` now runs everything; no `-c`
  override is needed.

  That file lives inside the WinGet package directory, so `winget upgrade
  PHP.PHP.8.4` may remove it. **If PHP suddenly has no extensions again, that is
  why.** The header comment in the file says so too.
- **poppler-utils 25.07.0** (WinGet package `oschwartz10612.Poppler`), with
  `PDFTOPPM_PATH` in `.env` pointing at the binary. Verified: a two-page A4 PDF
  renders at `-r 150` to 1240x1755 PNG and JPEG.

  Its bin directory was added to the *user* `PATH`, so a shell started before
  the install will not see `pdftoppm` — which is the whole reason
  `PDFTOPPM_PATH` exists and why it is set rather than left blank.
- **No Imagick and no Ghostscript**, deliberately. See §17.
- To look at it: `php -S 127.0.0.1:8484 -t public bin/serve.php`. That router
  exists because PHP's built-in server has no rewrite rules — it returns `false`
  for real files and otherwise hands the request to the front controller. Set
  `FORCE_HTTPS=false` in the local `.env` first, or every request is redirected
  to a URL nothing is listening on.

---

## 17. PDF page rendering — the decision, and how to use it

**poppler-utils (`pdftoppm`), shelled out. Not Imagick.** Settled on 2026-08-26
and baked into the later prompts. Do not write an Imagick branch "just in case":
one route, used consistently, is the point.

Imagick on Windows means matching a `php_imagick.dll` to the exact PHP ABI
(8.4 ZTS VS17 x64) plus the ImageMagick DLLs; poppler is one self-contained
executable with no ABI to match. On the LXC target both are one `apt install`
(`poppler-utils`), so the development machine was the tie-breaker.

Two things the renderer has to get right, both found while testing this:

1. **`pdftoppm` writes warnings to stderr and still exits 0.** A hand-made test
   PDF produced `Syntax Error: No display font for 'Symbol'` and rendered
   perfectly. Success must be judged on the **exit code and whether page files
   appeared** — never on stderr being empty.
2. **It appends the page number to the prefix**, so `-png … out` gives
   `out-1.png`, `out-2.png`, … The caller globs for what was produced rather
   than assuming names or a count. `pdfinfo` gives the page count up front if
   the count is needed before rendering.

Useful invocations, both verified on A4:

```
pdftoppm -png  -r 150                  in.pdf <prefix>   # 1240x1755
pdftoppm -jpeg -jpegopt quality=85 -r 150 in.pdf <prefix>
```

`PDFTOPPM_PATH` in `.env` (config key `pdf.pdftoppm`) is the binary's absolute
path; blank means look it up on `PATH`.

**Built as `App\Services\PdfRenderer`.** What it settles, so nobody has to
settle it again:

- `proc_open` with an **argument array**, never a shell string. A filename comes
  from the database, which means it ultimately comes from outside.
- Both pipes are drained in a loop while the process runs. Waiting on
  `proc_close` first deadlocks the moment either buffer fills, which a long
  document's warnings will do.
- Output is **globbed and sorted numerically**, because poppler zero-pads the
  page number to the width of the page count — `page-1.jpg` for a 9-page
  document, `page-01.jpg` for a 10-page one. Sorting as strings puts page 10
  before page 2 and hands the model a shuffled document.
- Settings: `pdf_render_dpi` (200), `pdf_max_edge_px` (2576),
  `pdf_render_format` (jpeg, quality 90).
- **200 DPI is chosen against the model limit, not by feel.** A4 at 200 DPI is
  1653x2339; current vision models take a 2576-pixel long edge without
  downscaling. Above that they downsample anyway, so the extra bytes buy
  nothing. A page that still exceeds the cap is re-rendered with `-scale-to`,
  and only that page.
- Quality 90 rather than the default 75: JPEG ringing around a thin red pen
  stroke on white is exactly the artefact that turns a 3 into an 8.
- A stale render is cleared before a retry, so a document that rendered 8 pages
  and now renders 3 does not appear to have 8.

---

## 18. Conventions to keep

1. Prepared statements only. Never concatenate input into SQL.
2. `csrf` middleware on every state-changing route.
3. Escape with `e()` in every template.
4. Credentials are used from PHP only; the browser never calls a third-party API.
5. Every external call gets a timeout and explicit error handling; a failed stage
   is recorded and retryable, never silently dropped.
6. An LLM stage that is unsure returns its **best guess plus a `reviewNotes`
   entry** — never a bare null, never a silent guess.
7. Nothing is auto-created in Clear Books. A human confirms every entity that did
   not match with full confidence.
8. Ask `Auth::can()`; never compare role strings.
9. Status changes go through `Document::canTransition()`.
10. A stage may not withdraw an earlier stage's review note. If a judgement can
    be overturned later, it belongs in the later stage — see §20.
11. Nothing InvoGrid writes to somebody else's system happens without an
    `audit_log` entry, and nothing irreversible happens without a guard that
    holds regardless of settings.
12. A resolution is stored where it was *decided* — in the extraction — not only
    in the row derived from it, or the next automatic pass undoes it.
13. Arithmetic that two screens need lives on the model. Three copies of the
    totals calculation drifted apart once already; see §22.
14. A model reader returns the same columns for every caller. A row missing a
    column a template prints is indistinguishable from a correct one until a
    page dies half-rendered — which it did, twice now.

## 19. Clear Books — the facts, and where they came from

Read out of the published OpenAPI specification, not inferred:

```
https://api.clearbooks.co.uk/spec/v1.yaml
```

(The rendered version at `api-docs.clearbooks.co.uk` is a Redoc page with the
spec inlined; fetch the YAML directly.) Where a later prompt needs to touch the
integration, start here.

- **Base URL `https://api.clearbooks.co.uk`, API under `/v1`, token endpoint at
  `/oauth/token` — outside `/v1`.** `clearbooks_base_url` holds the origin only.
- **OAuth 2, authorisation code, confidential clients only.** No
  client-credentials grant, no password grant. PKCE supported and used.
- **Refresh tokens are single use** and do not expire until used. Two processes
  refreshing at once therefore revoke each other and lock the integration out.
  `ClearBooksClient::refresh()` runs under `GET_LOCK('invogrid.clearbooks.token')`
  and **re-reads the settings after taking the lock** — the process that waited
  usually finds the work already done and spends nothing. Verified: three
  concurrent processes, one token exchange.
- **One access token per user per application.** Completing the consent flow
  again revokes whatever this instance holds.
- **The business is the header `X-Business-ID`**, not a path segment. Required
  only for multi-business authorisations; sent always.
- **Pagination is by response header**: `?page=N&limit=N` out (limit caps at
  200); `X-Pagination-Current-Page` and `X-Pagination-Total-Pages` back. The
  walk finishes when those are equal. **Not** on a short page: a total that is
  an exact multiple of the page size would stop it a page early. `vatTreatments`
  and `vatRates` are not paginated at all.
- **Errors are a JSON *array*** of `{errorCode, errorMessage}` — not the object
  shape `HttpResponse::errorSummary()` reads, hence `ClearBooksClient::explain()`.
- **Rate limiting starts above five requests a second**, answered with 429.
- **There is no projects endpoint and no project scope.** Prompt 5 asked for
  `GET projects`; it does not exist. Project codes are set by hand in the Clear
  Books web interface, which is what the "Open in Clear Books" link is for. Do
  not add a `ClearbooksCache::PROJECT` refresh — the constant exists, nothing
  fills it.
- Endpoint paths, all under `/v1/accounting/`: `suppliers`, `accountCodes`,
  `vatTreatments/{sales|purchases}`, `vatRates/{sales|purchases}?vatTreatment=`,
  `purchases/{bills|creditNotes|expenses}`,
  `purchases/{type}/{id}/attachments/{fileName}` — the attachment body is raw
  `application/octet-stream`, **not** multipart.
- Schemas worth knowing: `AccountCode` carries `sales` and `purchases` booleans;
  `Supplier` is a `Contact` (`name`, `vatNumber`, `companyNumber`, `address`,
  `externalId`, `archived`) and has **no** trading names; `VatRate` is
  `{key, name, rate}`; `LineItem` is
  `{description, unitPrice, quantity, accountCode, vatRateKey}` with
  `vatRateKey: "Manual"` requiring `vatAmount`; `Bill` is a `PurchaseDocument`
  plus `dateDue`.

### What the cache holds, and what it deliberately does not

| Entity type | `remote_id` | Filtered to |
|---|---|---|
| `supplier` | the numeric id | not archived |
| `account_code` | the numeric id | `purchases === true` |
| `vat_treatment` | the string key | purchases |
| `vat_rate` | the string key | purchases; `raw_json.treatments[]` records which treatments allow it |

- **Nothing is deleted, only deactivated** (`active = 0`). A document already
  matched against a supplier keeps a resolvable record, and
  the local knowledge held against the row survives — its usual credit route,
  and every document already matched to it.
- **An archived supplier takes the same path as a deleted one.** Archiving is
  how a supplier is retired in practice; nothing downstream has to know which
  happened.
- **`deactivateMissing()` refuses an empty list.** Nothing coming back is a
  failed fetch, not a business that deleted every supplier, and acting on it
  would put every document into review.
- `normalised_name` is written by `upsert()` from `Normaliser::key()` so it can
  never drift from the algorithm. **`matchByName()` recomputes it from `name`
  anyway** — a row cached before the normaliser last changed would otherwise
  never match. There are live rows in the development database proving that.

---

## 20. Matching — the rules

`App\Services\MatchStage` is **not a second opinion** on the extraction. The
supplier call already got the cached list; the line-items call already chose
from lists it was given. This stage does the three things a model cannot:

1. **Checks the ids are real** against the current cache. A returned id is a
   claim, and a hallucinated or since-archived one is the single kind of error
   that reaches Clear Books looking correct.
2. **Runs the deterministic fallback** on a supplier the model left open.
3. **Writes one `entity_matches` row per entity**, so the review screen names
   the thing that is unresolved.

`App\Services\Normaliser` is that fallback's comparison:

- `key()` — lower case, accents folded, `&` → `and`, apostrophes closed up,
  everything else a separator, leading "the" dropped, then legal suffixes
  stripped **repeatedly from the end** while something is left. Suffixes are
  *stripped*, not equated: equating Ltd and Limited would still leave "Acme Ltd"
  apart from "Acme", which is what a letterhead very often says.
- `compact()` — the same with spaces removed, settling "Clearbooks" against
  "Clear Books". Deliberately separate, and only believed when exactly one
  candidate matches, recorded at confidence 0.9 rather than 1.0.
- `keysFor()` — the legal name and every trading name.

**An ambiguous name resolves to nothing.** Two records reducing to the same key
means picking one is a coin toss, and the cost of losing is a bill posted
against the wrong supplier.

### The rule at the end of the stage

```
ready_to_submit   every entity_matches row resolved  AND  no review notes
needs_review      anything else, with the reason attached
```

`Pipeline::STAGES['match']['to']` records `needs_review` — the conservative
outcome, and the one the retry action and the smoke test's state-machine check
have to be right about. The handler returns `ready_to_submit` instead when it
can; both are legal from `matching`, and `tests/smoke.php` asserts it.

### Things that bit, and are now enforced

- **`ExtractStage` no longer flags an unmatched supplier.** It used to, because
  it was the only stage that knew about an unresolved entity. But it only ever
  consults the cache, the name fallback resolves much of what it leaves open,
  and nothing later has the standing to withdraw an earlier stage's note — so
  the note became false and held the document in review for good. The supplier's
  `entity_matches` row is the record now. **§14 of this document said the
  opposite until Prompt 5; this is the correction.**
- **Match-stage notes carry a `Matching: ` prefix** and a re-run replaces its
  own rather than appending. Notes from earlier stages are kept untouched.
- **A row a person resolved survives a re-match.**
  `EntityMatch::replaceAutomatic()` preserves anything with `resolved_by` set or
  `matched_via = manual`, and skips writing a fresh row into the same slot.
  Without it, `needs_review → matching` would undo the decision that made the
  re-match worth running.
- `Extraction::setMatchOutcome()` is the **only** thing allowed to change an
  extraction after it is written, and only `review_notes` and `needs_review`.
  The reading itself is a record of what a model said at a moment.

---

## 21. Ingest: the routes, and the boundary they sit on

A document enters InvoGrid through an **ingest route**. One exists — the upload
page — and the abstraction exists because a second one (a watched directory for
files dropped by other systems) is expected, and adding it should not touch the
pipeline.

```
src/Services/Ingest/
  IngestCandidate.php   a file being offered, and how to move it
  IngestSource.php      the routes that exist, and what to call them on screen
  Ingestor.php          the one entry point: check, insert, store, queue
  IngestException.php   a candidate that was refused
```

### The contract

A route's whole job: produce an `IngestCandidate` and hand it to
`Ingestor::accept()`. That method does everything else and is the only place any
of it happens — checking, the `documents` row, the file on disk, the queued
first stage. **Nothing past this class knows routes exist.**

`accept()` returns the created `documents` row, or throws `IngestException` with
a sentence written to be shown to whoever offered the file.

### Order of operations, and why

**Check, insert, store, queue** — and if storing fails, the row is deleted
again. The alternatives are both worse:

- storing first means naming the file before there is an id to name it after;
- leaving the row behind on a failed write means a document at `received` with
  no PDF, which the ingest stage picks up, fails, retries four times and finally
  parks in front of a person who can do nothing about it.

A candidate that could not be stored was never accepted, and the database should
say so.

### Why `IngestCandidate` is a class

`moveTo()`. A browser upload must be moved with `move_uploaded_file()`, which
refuses any path PHP did not itself receive as an upload — that refusal is the
only thing standing between an upload handler and being asked to move
`/etc/passwd` into the storage directory. A file found in a watched directory is
the opposite case: `move_uploaded_file()` would refuse *it*, and `rename()` fails
across volumes, so it falls back to a copy.

Each route knows which it is (`fromUpload()` / `fromFile()`); nothing downstream
does.

### Checked twice, and they are not the same check

| Where | What | On failure |
|---|---|---|
| `Ingestor::check()` | readable, not empty or truncated, within the limit, begins `%PDF-` | refused; nothing is written |
| `IngestStage::run()` | the *stored* file still exists, is still a PDF, `pdfinfo` finds pages | retryable stage failure |

The second is the gate in front of the expensive part — OCR renders every page
and sends each to a vision model — and it exists because a watched directory can
hand over a file another process is still writing. `Ingestor` reads a plausible
size and a `%PDF-` header from a file that is nonetheless half a document; the
stage runs a moment later from the queue and catches it.

That failure is deliberately **not** in `Pipeline::isPermanent()`. It looks
permanent and usually is not: the next attempt a minute later finds a whole
document.

### Keeping `received → ocr_pending` as a queued stage

The route could create the document at `ocr_pending` and skip the stage. It does
not, for three reasons: the queue processor stays the only thing that advances a
document however it arrived; the retry action has a stage to retry; and the
verification above has somewhere to live that is not inside every route.

### The size limit

`Ingestor::maxBytes()` reads `ingest_max_upload_mb` (default 25). A setting
rather than a constant because the answer is local policy, and because the
alternative is an administrator editing PHP to accept a large invoice.

`Ingestor::effectiveMaxBytes()` is the smaller of that and PHP's
`upload_max_filesize` / `post_max_size`, which cannot be raised from here. The
upload screen quotes the effective number: a form promising 25MB while PHP drops
anything over 2MB produces the worst kind of bug report — *"it just goes back to
the list"*. `UploadController` also tells an empty `$_FILES` apart from a body
PHP discarded whole for exceeding `post_max_size`, which is otherwise
indistinguishable to the person who just waited two minutes.

### What is recorded about arrival

`ingest_source`, `original_filename`, `ingested_by`, `ingested_at`. Nothing
downstream reads any of them — they answer *"where did this come from?"*, which
is the first question asked about a document that looks wrong. The document page
prints them under the heading; `/documents` searches the filename.

`ingest_source` is a `VARCHAR`, not an enum, so a route added later is not a
schema change — and `IngestSource::label()` falls back to the stored key so a row
written by a route since removed still renders. Rows that predate native ingest
are stamped `legacy` by the migration rather than `upload`, because attributing
them to a person who did not upload them would be a lie the document page would
then repeat.

### Adding a route

A constant and a label in `IngestSource`, then:

```php
Ingestor::accept(IngestCandidate::fromFile($path, basename($path), IngestSource::WATCHED_FOLDER));
```

The checks, the storage layout, the row and the queued first stage come with it.

---

## 22. The review screen — the rules

`/review` and `/review/{id}`, `App\Controllers\ReviewController`. Everything
before it is machinery; this is what a person uses the application for.

- **Every extracted value is an input.** A reviewer who can see that a date is
  wrong but can only accept or reject the document is worse off than one with no
  machine at all, because now they have to go somewhere else to fix it. The
  model's reading is a first draft.
- **The scan pane shows the rendered page images; the PDF is a button.**
  Prompt 19 turned this round — see §35 for the whole of it. The `<object>`
  pointing at `/documents/{id}/pdf` is still there and still the same-origin
  authenticated route, but it now opens *underneath* the images when somebody
  asks for it rather than being the thing embedded.
- **Nothing is created in Clear Books without a person confirming a form.**
  `App\Services\EntityCreator` is the only class that creates anything, every
  entry point is a POST from the review screen, and there is no scheduled or
  automatic caller. The form is *pre-filled* from the extraction; what is
  created is what was in the boxes when the button was pressed.
- **Every save is followed by a re-match** (`MatchStage::recheck()`), so a
  corrected account code turns green immediately rather than at the next cron
  tick. It is the same pass the queue runs — two implementations of "is this
  ready" would disagree eventually, and invisibly.
- **Skipping a document requires a reason**, at least three characters, checked
  on the server rather than only in the markup. `ignored by nick` is not an
  answer to "why was this one skipped" six months later.

### What can be created, and what cannot

| Entity | Offered |
|---|---|
| Supplier | Pick one on file, **or create in Clear Books** from a confirmed form |
| Account code | Pick one on file only |
| VAT rate, VAT treatment | Pick one on file only |

VAT rates and treatments have **no POST endpoint in the Clear Books API at
all** — they are defined by Clear Books. An account code *can* be created
(`POST /accounting/accountCodes`) but is not offered: it needs a `heading` a
reviewer has no basis to choose, and a nominal code is part of the chart of
accounts rather than a property of an invoice. Both say so on screen rather than
leaving somebody hunting for a button.

### Where a resolution is actually stored

**In the extraction, not in `entity_matches`.** Picking a supplier writes the
`cbId` into `extractions.supplier_match`; picking an account code writes it into
the line item. `entity_matches` is *derived* from those by the re-check, so a
resolution recorded only in the derived row would be undone by the next pass —
which is the bug that makes a review screen feel haunted.

The derived row is then stamped afterwards, and only for the two decisions
re-deriving would forget:

- a supplier InvoGrid created → `status = created`, `matched_via = manual`;
- an entity a person picked → `matched_via = manual`.

Stamped rows carry `resolved_by` and survive the next automatic pass — **but
only while they still point at something active in the cache.** A supplier
somebody picked by hand and Clear Books has since archived is dropped and
re-derived, because catching exactly that is what re-running the stage is for.

### Totals

`Extraction::totalsFromLines()` is the single implementation. It was written
three times — the extraction stage, the review form, and not at all on the path
where a reviewer picks a VAT rate — and the gap in the third was a real bug
found by submitting a document: the submitted bill said
`£250.00 net` where it should have said `£300.00`, because the totals were the
ones from before the rate was known. `Extraction::refreshTotals()` is called
after any line change outside the form.

A typed-in net wins over the calculation. Somebody looking at the scan is the
better authority on a rounding settlement or a discount applied to the total.

---

## 23. Submission and write-back

`App\Services\SubmitStage`. The only
irreversible thing InvoGrid does.

### The order, and why it is the order

```
1. refuse if a successful submission already exists
2. build the payload and validate it          <- before any call
3. create the record in Clear Books
4. write `submissions`, move to `submitted`   <- the critical pair
5. attach the PDF
6. record what Clear Books called it
```

Four comes before five and six deliberately. A crash between three and four
leaves a bill in the accounts that InvoGrid thinks it never sent, and the next
person to press submit creates a second one. A crash after four leaves a
document correctly marked submitted with an attachment missing — visible,
harmless, fixable. Of the two failure modes only one costs somebody a payment
run.

For the same reason **five and six do not throw.** They return warnings that are
shown and logged. Refusing to record a submission because a tag could not be
written would be choosing the worse failure on purpose.

### Idempotency

Three guards, and the one that matters is the third:

1. the status must be `ready_to_submit`, and `submitted` only transitions to
   `ignored`;
2. the screens hide the button once a submission exists;
3. **`SubmitStage::submit()` itself refuses**, naming the record that already
   exists. Verified by calling it directly on a submitted document.

`/documents/{id}/resubmit` is the escape hatch: admin only, on no ordinary path,
behind a confirmation, and it **does not withdraw the first record** — InvoGrid
has no business deleting from somebody's ledger. It says so on the button and in
the flash message afterwards.

### The payload

Built from the **current** extraction columns, which by then may have been
edited several times. That is what makes a reviewer's corrections the thing that
gets submitted.

```
date, supplierId, vatTreatment, lineItems[]   always
dateDue                                        bills only — a credit note has no
                                               such field and sending one is a 400
reference, description                         when present
currency                                       omitted for sterling, so Clear Books
                                               uses the home currency and no
                                               exchange rate is implied
```

**`document_types.amount_sign` is applied to `unitPrice` here** — see §24, which
is the rule and the reason it is not what InvoGrid first guessed.

`dateDue` is sent only when the resource is `bills` **and** the sign is
positive. A refund posts to the bills endpoint, where the field is structurally
valid, but the money has already moved — a due date on one shows in Clear Books
as an outstanding payable nobody owes. The test is the sign rather than the type
key, so a new type gets the right answer without that line being edited.

### What the submission records

`SubmitStage::recordProducedFields()`. The two `submission`-source custom fields
— `clearbooks_bill_id` and `clearbooks_document_number` — are filled in from the
Clear Books response and written onto the extraction's `custom_field_values`.

Until Prompt 15 they had nowhere to live but Paperless, and the write-back put
them there. Now they are stored where every other field value already is, which
is how they reach the document page and the printed summary without anybody
opening another system. The `submissions` row still holds `clearbooks_id`,
`clearbooks_url` and the whole response — this is a convenience laid over that,
not a second copy of the record.

Two details that are easy to get wrong:

- **Written straight to the column**, not through `Extraction::updateFields()`,
  which stamps `edited_at`. That stamp means *a person changed this* and the
  review screen says so; a value the submission produced by itself must not make
  an untouched extraction claim it was edited by hand.
- **A key this stage does not produce is skipped, not nulled.** Somebody can add
  a `submission` field on the custom-fields screen that nothing knows how to
  fill in. Leaving it empty is honest; overwriting whatever is there with null
  would lose a value a reviewer had typed.

Best effort, like everything else past step four: a failure here is logged to
`audit_log` and must not make a submitted document look unsubmitted.

### Custom fields have two origins

`custom_fields.source` is `extracted` or `submission`, and the distinction is
not cosmetic. `CustomField::extracted()` feeds the extraction prompt;
`forSubmission()` is filled in once Clear Books has answered. Asking a vision model to find
a Clear Books bill id on a supplier's invoice asks it to invent a number that
does not exist until InvoGrid creates the record — and it will oblige.
`tests/smoke.php` asserts the prompt is never offered one.

### "Open in Clear Books"

`data-clearbooks-window` on the link; `app.js` calls
`window.open(href, 'clearbooksWindow')`. Every such link reuses the one named
window, so working through a queue of twenty documents does not leave twenty
tabs. Verified: three clicks, three calls, all naming `clearbooksWindow`.

It is surfaced on the review screen, the document record **and** the document
list, because it is the only route to a purchase line's project code — Clear
Books has no API for that.

## 24. Amount signs, and the third document type

The rule, as Clear Books actually applies it. **InvoGrid guessed this wrong**
and shipped `credit_note.amount_sign = -1`; migration 011 corrects it.

| Type | Resource | Sign | What it means |
|---|---|---|---|
| `bill` | `purchases/bills` | **+1** | Money spent |
| `credit_note` | `purchases/creditNotes` | **+1** | An amount available against an invoice |
| `purchase_refund` | `purchases/bills` | **−1** | Money that actually came back |

- A **PurchaseDocument** is positive when it represents money spent and negative
  when it represents money refunded. Either way an actual movement of money has
  happened.
- A **PurchaseCreditNote** takes **positive** values at creation. Clear Books
  inverts them internally, because a credit note is an amount available to set
  against an existing invoice — *no money has moved*. Sending one negative
  inverts an inversion and puts the amount back where it started.

The refund case had nowhere to go before: a document where the supplier has
actually paid money back is a **bill with negative amounts**, not a credit note.

`dateDue` is sent only when the resource is `bills` **and** the sign is
positive. A credit note has no such field (a 400), and a refund's money has
already moved — a due date on one shows as an outstanding payable nobody owes.
The condition tests the sign rather than the type key, so a fourth type would
get the right answer with no edit.

### Why both of the last two stop and ask

Telling a credit note from a refund is **not reliably possible from the
document**. A page headed "Credit Note" that goes on to describe a refund
payment made *is a refund* — the title is the weaker signal, what happened to
the money is the stronger one. And often neither is written down, because the
arrangement was agreed by telephone.

So `document_types.requires_confirmation` is set for both, and:

- `MatchStage` raises a review note and **refuses `ready_to_submit`** until a
  person has agreed. This is the only review reason that is not a failure to
  match.
- `SubmitStage::submit()` refuses independently. A hidden button is not a
  guarantee, and the cost of being wrong is a movement of money recorded in the
  accounts that never happened, or a real refund left unreconciled — neither of
  which anything downstream catches.
- `DocumentType::requiresConfirmation(null)` is **true**. Unclassified is not
  "nothing to confirm": nobody has said what it is, so nobody has agreed to it.

### Agreement is not editing

`extractions.doc_type_confirmed_at` / `_by`, deliberately separate from
`edited_at` / `edited_by`. A reviewer may correct a due date without ever having
considered what kind of document it is, and treating an edit as agreement is
precisely the shortcut that would post a refund as a credit note.

**Changing the type in the ordinary Type box withdraws the agreement** —
`Extraction::updateFields()` nulls the stamps when `doc_type` is among the
changed columns. `confirmType()` sets the type first and stamps afterwards, so
it is unaffected; the agreement is *to* that type, and stamping first would
record consent to whatever was there before.

### What may pre-select, and what may not

This is the sharpest rule on the screen, and it distinguishes two things that
look alike:

- **A supplier default pre-selects.** `clearbooks_cache.default_credit_route` is
  established local knowledge that somebody recorded on purpose. Re-answering a
  settled question every month is how a confirmation step decays into a habit of
  clicking through.
- **The model's guess does not.** The box starts empty, `disabled` on an empty
  `selected` option so the form cannot be submitted without a deliberate choice.
  Confirming a guess with one click on an already-filled form is exactly the
  wave-through this step exists to prevent, and the two answers are opposite
  entries in somebody's accounts.

The guess is still shown, prominently, with its reasoning beside the choice —
what is withheld is the pre-filled answer, not the information.

The default is set in two places, because it is learned in two ways: on the
review screen (*remember this as the usual route*), where a reviewer works the
pattern out while reviewing; and on **Settings → Clear Books**, where somebody
who already knows can write it down without waiting for a document. Local
knowledge, so it survives a cache refresh for the same reason
`default_credit_route` does — `upsert()` writes only the columns it gets
from the API, and `tests/smoke.php` asserts it.

### Why the model's reasoning is stored

`extractions.doc_type_reason` — one sentence quoting the wording that decided
it: *"says 'refunded to card ending 4412 on 14 August', so money has moved"*.

Without it the reviewer is given a conclusion and no evidence, and has to read
the whole document again to check a judgement the model had already made and
could have explained. It is a column rather than a review note because it is not
a flag: it is present on every document, including the ones where nothing is in
doubt, and it belongs beside the choice rather than in the list of things to
check.

### The prompt

`extract_lines` **v2** returns `bill`, `creditNote` or `purchaseRefund`, is told
the trap explicitly, and is told to give its best answer with a `reviewNotes`
entry rather than inventing certainty. v1 said a negative or refund total made a
document a credit note, which is the error this all corrects.

The new key needed **no code change**: `ExtractStage::documentType()` maps by
reducing both sides to letters and digits, so `purchaseRefund` finds
`purchase_refund` the same way `creditNote` finds `credit_note`. That is what
the reduction was for.

### Still open

Classification quality is only as good as the prompt, and the prompt has not met
a real refund yet. If a pattern emerges — a supplier whose wording defeats
it — the fix is a prompt edit rather than code, and the confirmation step means a
wrong guess costs a reviewer one click rather than a wrong entry in the ledger.

## 25. Custom fields and prompts, managed without a deploy

`/admin/fields` and `/admin/prompts`, behind `fields.manage` and
`prompts.manage`.

**Paths.** Prompt 9 named these `/settings/custom-fields` and
`/settings/prompts`. They are built under `/admin/` because that is the prefix
the application already committed to — Prompt 1's nav listed `/admin/fields` and
`/admin/prompts` as forthcoming, and `/admin/clearbooks` shipped in Prompt 5.
Two prefixes for the same kind of screen would be worse than either one.

### Custom fields

Adding a field is a row plus a sentence of hint, and the extraction stage picks
it up on the very next document — `{{ customFields }}` is filled from
`CustomField::forPrompt()` at the moment each document runs. **Nothing is listed
in a prompt by hand**, and the Prompts screen says so, because a list written
into a prompt would go out of step and win silently.

Two rules the screen exists to enforce:

- **A field key never changes.** `extractions.custom_field_values` is a JSON
  object keyed by it, so a rename orphans every value already read off a
  document. `CustomField::update()` refuses, and the form shows the key as
  read-only text once the field exists.

  Because the form shows it as read-only *text*, a browser posts nothing for it.
  `FieldController::save()` therefore reads `field_key` **only when creating**.
  It used to read it always, defaulting to `''` — which made the guard above
  fire on every edit, since an empty key does not equal the stored one. Editing
  any existing field was impossible, and it failed with a confident sentence
  about why the key could not change, which is the sort of error nobody
  questions. Found and fixed in Prompt 15; `tests/smoke.php` now posts the shape
  the form actually sends and asserts it is accepted.
- **A field is deactivated, never deleted.** Last month's extraction still has
  to resolve what it stored. Deactivating stops it being offered to the prompt;
  nothing else changes.

The `source` split from Prompt 7 is shown as two sections: fields read off the
page, and the two the submission produces. The second group is never offered to
the extraction prompt, and the screen says why.

Select choices are stored as `[{id, label}]` — an id distinct from the label — so a
value written back needs no translation. The form takes one label per line.

### Prompts

An edit writes a **new version** and makes it active; nothing is overwritten. A
change that turns out badly is one click to undo, and every result records which
version produced it (`ocr_results.prompt_template_id`,
`extractions.prompt_template_id`).

`prompt_templates.origin` is `seed` for anything a migration applied and
`edited` for anything the screen saved. That is what makes **"reset to default"**
a question with an answer: it re-activates the newest `seed` version rather than
writing a copy, so the history stays honest and the reset is undone the same way
any other version switch is. Not version 1 — the OCR prompt's v1 predates the
real text, and v2 is the one anybody resetting wants back.

### The variable contract

`PromptTemplate::AVAILABLE` maps each template key to the variables its stage
supplies. It is read by the editor, to validate a save and to build the help
panel, and by `tests/smoke.php`. It was written out twice before — once in
`ExtractStage` and once in the test — and there is now an assertion that
`ExtractStage::variables()` supplies exactly what the contract promises.

**`ocr` is declared as taking none, and that is not an oversight.** `OcrStage`
sends that prompt to the model verbatim, alongside the page images; it never
runs it through `PromptRenderer`. A `{{ ocrText }}` added to it would be
transmitted as those literal characters and the model would answer confidently
about nothing. The editor refuses to save one and says why on the page.

**Validation happens on save, not at render.** `PromptRenderer::render()` throws
on a name nothing supplies, which is correct but happens with a document already
in the pipeline and the person who typed it long gone. `problemsWith()` catches
it while they are still looking at the box, and names what *is* available.

The editor also shows **what each variable holds right now** — the cached
supplier list, the account codes, the custom fields as they currently stand,
truncated at 1500 characters. Being able to see that `{{ customFields }}`
already contains the new field is the difference between trusting the injection
and hand-listing fields in the prompt "to be safe", which is exactly what it
exists to prevent.

### Audit actions this writes

`fields.created`, `fields.updated`, `fields.activated`, `fields.deactivated`,
`prompts.edited`, `prompts.activated`,
`prompts.reset`.

## 26. Accounts, and what an admin-set password implies

`/admin/users` behind `can:users.manage`, and `/account/password` behind nothing
but `auth`.

**Paths.** Prompt 10 named the screen `/settings/users`; it is `/admin/users`,
for the reason given in §25 — that is the prefix the rest of the application
uses, and Prompt 1's nav reserved this exact path.

### The password an administrator sets is a way in, not a password

Creating an account and resetting a password both set
`users.must_change_password`. `AuthMiddleware` reads it on every signed-in
request and lets that account reach exactly two paths — `/account/password` and
`/logout` — until it has chosen its own.

It is enforced in `AuthMiddleware::handle()` rather than on the routes it
protects, because "everything except two paths" is a list nobody remembers to
add to. `can`, `canany` and `role` all call `handle()` first, so a route added
next year is covered without being told about it. Signing out stays possible on
purpose: trapping somebody on one page with no way off it is how a session gets
abandoned on a shared machine.

`/account/password` asks for the current password even when the change is
forced, and regenerates the session id after. A session left open on an unlocked
screen should not be enough to change the credential that outlives it.

### Rules the screen enforces, and where

In `App\Models\User` rather than the controller, because several of them have
two ways in and a rule enforced on one path only is a rule with a way around it:

- **A username never changes.** Every audit entry, every "resolved by" line and
  the login throttle refer to it. `update()` refuses; the form shows it as
  read-only text.
- **Accounts are deactivated, never deleted**, for the same reason.
  Deactivation takes effect on the account's *next request* — `Auth::user()`
  re-reads the row every time and drops the session when it is no longer
  active — not whenever they next sign in.
- **The last active administrator cannot be demoted or deactivated.**
  `guardLastAdmin()` is called by both `update()` and `setActive()`. An
  application with no administrator can only be rescued from the server.
- **Nobody edits their own role or active flag**, and nobody resets their own
  password from this screen. Both are refused in `UserController`, and shown as
  fixed on the form.

### `User::COLUMNS`

Every reader but one selects an explicit column list that excludes
`password_hash`. `findByUsername()` is the exception, because it is the one that
verifies a password. A hash left in an array handed to a template is one
forgotten `unset()` from a page, and there is a smoke assertion that the list,
`find()` and the signed-in user never carry one.

### The password policy is one object

`App\Core\PasswordPolicy` — thresholds from `security.password.*`, the
`Validator` rule string, the sentence shown under every password box, and
`problems()`. Read by `bin/create-admin.php`, the users screen and the account
page, so there is no second copy to drift.

`problems()` returns **every** failure rather than the first: somebody told
their password is too short, who fixes that and is then told it also needs a
number, has been made to guess twice at a rule that could have been stated once.
It also refuses a password containing the username or the person's name —
`Jbloggs2026!` clears every character-class rule ever written and is the first
thing anybody tries.

### Read-only is shown as read-only

The review screen wraps its editable body in a single `<fieldset disabled>` when
the viewer lacks `review.resolve`, rather than repeating `disabled` on forty
controls. Verified in the browser: 28 of 28 controls disabled for a viewer, 0 of
28 for a reviewer, on the same document.

That is presentation. The enforcement is the route middleware, and a viewer
POSTing directly to `/review/{id}/save`, `/ignore`, `/submit`, `/confirm-type`
or `/documents/{id}/retry` gets 403 on every one.

### Audit actions this writes

`users.created`, `users.updated`, `users.password_reset`, `users.activated`,
`users.deactivated`, `account.password_changed`,
`account.password_change_failed`.

## 27. Seeing what happened, and not hammering anything

### The dashboard's third question

The counts say how much work there is. The failure feed says what the machine
tripped over. **"Not moving"** answers the one nothing else does: which
documents have sat still longer than they should have.

Two thresholds, two settings — `stuck_pipeline_minutes` (30) and
`stuck_review_days` (7) — because there are two kinds of waiting. A document in
`extracting` is waiting on a machine and should move in minutes; one in
`needs_review` is waiting on a person and may legitimately sit over a weekend.
One number covering both either cries wolf about the second or says nothing
about the first, and a dashboard that cries wolf is one nobody reads.

`failed` is deliberately excluded: it has its own count, and a document that has
stopped is not stuck, it is finished badly. The case this catches is the quiet
one — a job that exhausted its four attempts, so the queue has given up, the
status is *not* `failed`, and the document simply rots.

The two feeds are capability-gated: `audit.view` (admin) for who did what,
`documents.retry` (reviewer) for what the machine tripped over.

### Filtering, and a bug worth remembering

`/documents` filters on status, document type, supplier, a date range and
free text. Free text reaches **into the extraction** — supplier name, invoice
number, title — not just the `documents` row, so a document whose supplier
column says "Acme" but whose invoice was actually read as "Totally
Unknown Trading Co" is findable under either.

The date range is `COALESCE(the extraction's invoice_date, DATE(created_at))`.
"Show me July" means invoices dated July — but a document that has not been read
yet has no invoice date at all, and dropping those would hide exactly the ones
somebody hunting a missing invoice is looking for.

**`filterClause()` now requires a table alias, and that is not tidiness.**
`countMatching()` used to pass none, which was harmless while every condition
named a column of `documents`. The moment one of them correlated a subquery on
`extractions`, an unqualified `id` inside that subquery resolved to the *inner*
table — so the EXISTS stopped correlating and every document matched every
search. The count said five, the list showed three, and neither looked wrong on
its own. The method now throws without an alias, and `tests/smoke.php` asserts
that the count and the list agree across eleven filter combinations.

### The full activity log

`/admin/activity`, `can:audit.view`. The dashboard's feed carries the last
fifteen entries and answers "what has just happened"; this answers the other
question — "who changed this, and when" — which needs filters and pages. Both
exist on purpose. `AuditLog::recent()` serves the first, `paginate()` the
second.

Filters: action, person, a date range and free text over the details and the
names. A bare number in the search is read as a **document number first**,
because that is what somebody holding a piece of paper actually has, and then as
text so a numeric reference is not lost. A closing date covers the whole day —
`created_at <= '2026-03-03'` on its own excludes everything that happened after
midnight on the date somebody just typed. `countMatching()` and `paginate()`
share one `filterClause()`, and the smoke test asserts they agree across nine
combinations for the same reason it does for `/documents`.

The action and person lists are read **from the log**, not from a list in PHP:
an action is whatever string a call site passed to `record()`, so a hard-coded
list would go stale the first time somebody logged a new one and the filter
would quietly stop offering it.

**No route here writes.** There is no delete, no edit, no "clear the log": a log
a user interface can alter is not a log. `document_events` — what the *machine*
did — is deliberately not shown; it lives on each document's page, because an
administrator asking who approved a bill does not want forty OCR retries in the
answer.

### Retrying resumes; it always did

`Document::retryStatusFor()` returns the `from` status of the stage that failed,
so a retry re-runs that stage rather than the document. This was built in Prompt
2 and is asserted per stage in the smoke test; Prompt 11 added the part that was
missing, which is the screen **saying so** before you press it:

> It resumes at **Read** — the head of the stage that broke, not the beginning.

Verified end to end: a document failed at `extract`, was retried from the
document page, resumed at `ocr_done`, and finished at `needs_review` with
`ocr_results` still at 2 rows and the stored PDF's mtime unchanged. Nothing was
re-downloaded and nothing was re-read.

### Why a failure is answerable without a log

`documents.error_message` is one sentence, which is right for a list and not
enough to act on. `document_events.context` (migration 015, JSON) carries the
rest, put there by any exception implementing `App\Services\Diagnosable` —
`LlmException` and `ClearBooksException` both do.

What that looks like on the page, from a real run against a rate-limiting stub:

| | |
|---|---|
| Call | `extract_header` |
| Provider | anthropic |
| Model | claude-opus-5 |
| Answered | Number of request tokens has exceeded your per-minute rate limit. |
| Took ms | 21 |
| Http status | 429 |
| Retryable | yes |
| Asked us to wait | 45s |

`answered` is the provider's **own words**, not the friendly translation, which
is exactly what you want when the translation is the thing that is wrong.
`call` is added by `ExtractStage` on the way back up (`LlmException::during()`):
the client knows the provider and the model, but only the stage knows this was
the supplier call rather than the header one, and "extraction failed" against
four LLM calls is a shrug rather than a diagnosis.

**Never put a credential in a context array.** It is written to the database and
rendered on a page.

### Rate limits: three separate mechanisms

1. **Pacing, so the limit is not met.** `ClearBooksClient::pace()` keeps 200ms
   between requests — Clear Books throttles above five a second, and
   `allPages()` walking a real supplier list is exactly where that is met.
   Costs a cache refresh a few seconds; being 429'd costs it a backoff and a
   retry of work already done.

2. **In-call retry, so one 429 does not cost a stage.** `Http::request()` takes
   a `$retries` count and honours `Retry-After` up to `MAX_INLINE_WAIT` (20s).
   Extraction makes four LLM calls, and losing the fourth to a rate limit throws
   away the three that worked. Verified: with the stub answering 429 +
   `Retry-After: 2` on the first call, the stage **succeeded** in 2.2s.

3. **Queue backoff, for anything longer.** A wait over 20s is handed back as a
   status; `PipelineJob::fail()` takes the `Retry-After` and uses
   `max($ordinaryBackoff, min(3600, $retryAfter))`. Verified both directions:
   a 45s header left the ordinary 60s wait alone, a 300s header lengthened it to
   300s. It can only ever make the wait longer.

**Retrying is opt-in on every HTTP helper and defaults to none, and that must
not change.** A GET may be repeated; a Clear Books POST may not — there is no
idempotency key, so a POST that timed out after the record was written is
indistinguishable from one that never arrived, and repeating it puts a second
bill in somebody's accounts. `ClearBooksClient` gates it on
`strtoupper($method) === 'GET' ? 2 : 0`, and the smoke test asserts both that
string and the zero defaults.

## 28. Branding, theme parity and print

### The logo

The *rendering* half shipped in Prompt 1 — `Branding`, `BrandingController::show()`,
`partials/brand.php`, the CSS. Prompt 12 added the half that was missing: a way
to put a file there.

`/admin/branding`, behind `can:settings.manage`. Two independent slots. One form
with two file inputs, saved independently, so replacing only the dark variant
does not disturb the light one and does not report "no file chosen".

**`show()` is the only open method on `BrandingController`.** The sign-in page
needs the logo before anybody has signed in, so that route is deliberately
reachable by a signed-out visitor; everything that *writes* is gated, and
`tests/smoke.php` asserts exactly that split — one open branding route, three
gated ones.

**Uploads are checked three ways**, in `App\Core\Upload::validate()`:

1. the extension, against a whitelist;
2. the real content type, sniffed with finfo rather than believed from the
   browser's `Content-Type`;
3. whether it decodes as an image at all, via `getimagesize()`.

A PHP script with a PNG header passes a naive mime sniff and fails the third.
`is_uploaded_file()` is checked before any of them, so nothing that did not
arrive as a real upload can be named as a source path.

**SVG is absent from every whitelist and must stay absent.** An SVG is a
document that can carry script; serving one from this origin would let anybody
who can reach the branding screen run code in everybody else's browser. There is
a smoke assertion on it. A PNG at twice the display height is indistinguishable
in a 36-pixel-tall header.

The stored filename is generated, never the client's. Old files are deleted only
after the new one is safely written — the other order leaves the site with no
logo at all if the write fails.

### Where the logo appears

| Where | Variant | How |
|---|---|---|
| Site header | both shipped, CSS picks | `partials/brand.php` |
| Sign-in page and error pages | both shipped, CSS picks | same partial, via `layouts/auth` |
| Printed summary | **light**, because paper is white | `layouts/print.php` |

Both variants are always in the markup and the stylesheet chooses, because the
theme can change without a page load — a server-side choice would show the wrong
logo until the next navigation. Where only one is uploaded it is used for both;
with neither, the header falls back to the monogram.

**Aspect-ratio safety** is a height, an automatic width and a ceiling:
`height: 36px; width: auto; max-width: 176px; object-fit: contain`. The desktop
ceiling was 128px, which did not distort a wide logo — `contain` letterboxes —
but silently drew a 4:1 wordmark at 32px instead of 36 and left a strip of empty
box beside it. Measured: a 480×120 source now renders 144×36, exactly 4.00.

### The print view

`/documents/{id}/print`, `can:documents.view` — the same capability as the
screen it summarises, because it contains nothing that screen does not.

**Its own layout, not the ordinary one with the chrome hidden.** Hiding the
navigation still ships it: it is in the DOM, it is read aloud, and every future
nav change is a chance to break a printed record nobody looks at until a
supplier queries an invoice. `layouts/print.php` includes neither
`partials/nav` nor `partials/footer`, and there is an assertion on that.

Always the light palette, whatever the viewer's theme. Nobody chooses dark mode
meaning "print it dark", and the alternative is a wasted cartridge or pale grey
on white.

The `.print-*` classes style the page **on screen** as well, because somebody
looks at it before printing and a preview that looks nothing like the output is
not a preview. The `@media print` block is only the changes that make sense on
paper: drop the toolbar, drop the sheet's shadow and margins, repeat table
headings across a page break, and append the href to any external link, because
a URL is invisible on paper.

### Theme parity, measured

Every page added since Prompt 1 was loaded in both themes and each text element
sampled for (a) whether its colours change with the theme at all and (b) its
contrast against what is actually painted behind it.

| Pages swept | 15 |
| Elements sampled | ~1,150 |
| Colours frozen across themes | 0 |
| Under WCAG AA, either theme | 0 |

Two real defects came out of it:

- **`theme-color` was hardcoded white.** `app.js` updated it on toggle, but the
  inline before-paint script did not — so a dark-mode visitor got a white
  address bar above a near-black page until the first time they happened to
  press the toggle. Both layouts now set it before first paint.
- **`prefers-reduced-motion` covered three components, not the page.** Two
  narrow blocks killed the caret and flash transitions and left the nav drawer
  and buttons still moving. Replaced with one global rule, as Kitwell has.

`tests/smoke.php` now asserts that no colour in the screen half of the
stylesheet bypasses the variables, with three commented exemptions: the token
blocks themselves, the print layout, and the two previews that must sit on the
ground they are *for* rather than the page's current theme.

### Parity with Kitwell, concretely

The design tokens are byte-identical: same font stacks, `--radius` 10/7px,
`--tap` 44px, the six-step spacing scale, 17px body at 1.55, and h1/h2/h3 at
1.6/1.25/1.05rem. The deliberate differences remain the ones recorded in §2 —
indigo rather than blue, and a 900px nav breakpoint rather than 1150px because
this menu has three top-level items rather than six.

Checked at phone (532px), tablet (768px) and desktop (1280px): no horizontal
page overflow at any width, no interactive target under 40px, and the
navigation switching from drawer to bar at 900px as designed.

## 29. The security review, and what it found

Prompt 13 was a review rather than a build. What follows is what was actually
checked and how, so a later session can re-run it rather than re-derive it.

### The three harnesses

| | What it proves |
|---|---|
| `php tests/smoke.php` | 273 assertions that need no browser |
| `php tests/pipeline.php` | every workflow step is implemented, reachable, and has really run |
| `php tests/permissions.php <url>` | every route, every role, over real HTTP |

`tests/permissions.php` signs in as a viewer, a reviewer and an administrator in
turn and requests **every route in the table**, checking each against the
capability that route itself declares — so it compares the server with the route
table rather than with a second list somebody has to keep in step.

State-changing routes are probed **without a CSRF token**, deliberately: 403
means the capability gate refused; 419 means the gate let it through and CSRF
stopped it. That distinguishes "denied" from "would have been allowed" without
any handler running. It creates and removes its own throwaway accounts and is
safe against a live install.

Result: **138 checks, 66 expected denials, 0 mismatches.**

`tests/pipeline.php` checks each of the nine workflow steps twice — that the
class and method exist with real substance (not a stub), and that the database
holds rows proving the step has genuinely run. Result: every step implemented
and reachable, every stage with evidence.

### What the security review actually found

Most of it held. Two things did not:

**The PDF fetch had no size limit.** The download checked the `%PDF-` magic
bytes and deleted a partial, but nothing capped the length — so a misconfigured
or hostile far end could stream until the volume filled, taking down every other
document, the page images and the log with it.

Fixed at the time with a byte ceiling passed down to `Http::download()`, which
aborts **mid-transfer** via a cURL progress callback rather than measuring
afterwards, because measuring afterwards is measuring after the disk is full.
`CURLOPT_MAXFILESIZE` would not do: it only
works when the far end sends a `Content-Length`, and a streamed response sends
none. Demonstrated against a server streaming an endless file: aborted
immediately, partial deleted, and a legible message.

**`openssl` was missing from the documented prerequisites**, and it is not
optional — it is what encrypts every stored API token. A deployment following
the old list would have had `Setting::put()` refusing to store credentials with
no obvious reason why.

### What held

- **CSRF**: all 24 POST forms carry `csrf_field()`; every non-GET route carries
  the middleware bar the Clear Books callback (a redirect with no token to
  carry, guarded by a `state` parameter).
- **Prepared statements**: no variable is interpolated into SQL anywhere. The
  only concatenation is `LIMIT`/`OFFSET`, every one int-cast and clamped, and
  the table alias in `filterClause()`, which is a literal.
- **Session cookies**: `httponly`, `samesite=Lax`, `secure` whenever the request
  is HTTPS or `FORCE_HTTPS` is on, `use_strict_mode`, and the id regenerated on
  sign-in and on password change.
- **Login throttling**: exercised live — four wrong passwords answered "not
  recognised", the fifth locked out for fifteen minutes. Counted per username
  *and* per address, at three times the limit for the address.
- **No secret reaches a browser**: templates read only non-secret settings, and
  every `Response::json()` passes a literal. Both are now assertions, because
  `Setting::get()` decrypts rather than returning ciphertext and so offers no
  type-level barrier of its own.

### Upload paths, precisely

There are **two** browser upload paths since Prompt 15: the logo on
`/admin/branding`, and documents on `/documents/upload`. Both go through
`App\Core\Upload` for the checks that are about a browser — PHP's own upload
error, `is_uploaded_file()`, the size, the extension and a `finfo` sniff — and
then diverge on the deep check. A logo has to decode as an image; a document has
to begin `%PDF-`, and that check lives in `Ingestor` so every ingest route gets
it rather than only this one.

The document ceiling is `ingest_max_upload_mb`, floored by PHP's
`upload_max_filesize` and `post_max_size`. The old ceiling on the *download*
survives in `Http::download()`, which nothing calls today — see §12.

### Documentation drift is now asserted

`tests/smoke.php` fails if a route, or a migration, is not mentioned in this
document; if this document names a file that has been renamed away; or if the
README tells somebody to run a script that does not exist. PROJECT-STATE is what
the next session reads before touching anything, so a route it does not mention
is a route that session will not know exists.

Verified that the check can genuinely fail, by adding a route and watching it be
caught. It checks that a path is mentioned *somewhere* in the document, not that
a specific table row exists — enough to catch a forgotten route, not enough to
catch a row that has drifted in its details.

## 30. install.sh and manage.sh

Modelled on the sibling projects — Kitwell and the Production Tracker — so
somebody who administers one can administer all three. Same helper vocabulary
(`say`/`step`/`ok`/`info`/`warn`/`die`), same `--quiet`/`--yes` options, same
`env_get`/`env_set`, same permission model.

**The update takes the Production Tracker's shape, not Kitwell's**, because it
is the better one: with no argument it clones the repository into a temp
directory and installs from there, so an update is one command on a server that
has no checkout of its own. Kitwell's demands a source directory. A directory
can still be passed, and has to be when the machine cannot reach a private
GitHub — the failure message says so rather than just reporting a clone error.

### What is different here, and why

| | |
|---|---|
| No Composer anything | InvoGrid has no `vendor/`. `install-composer` and `composer-install` are gone rather than carried over dead. |
| **poppler is a first-class check** | It is the one local dependency without which no document can be read at all. The installer refuses to finish without `pdftoppm`, and `status` reports it above the fold. |
| Three cron jobs, not one | The queue worker every minute, the Clear Books cache hourly, the invoice sync every five minutes, plus the nightly backup. Without the first, nothing is ever processed. |
| Backup **must** include `storage/pdf` | InvoGrid holds the only copy of an uploaded document. `reset-storage` says so in bold, which it did not need to when the PDFs came from somewhere else. |
| `test` | Runs all three harnesses. "Is this install sound?" is a question with an answer. |
| `queue` / `refresh` / `retry` | The pipeline has command-line equivalents; the sibling projects have no pipeline. |
| Backup skips `storage/pages` | A page image is re-rendered from the PDF beside it in seconds. Including them doubles every backup to save a step that costs nothing. |
| Users are by **username** | Not email. Every user command takes a username. |

### The console layer

`manage.sh` never touches the database directly. Anything that does goes through
`bin/console.php`, which grew the commands to make that possible: `doctor`,
`stats`, `queue:retry`, `user:list`, `user:password`, `user:role`,
`user:activate`, `user:deactivate`, `user:unlock`.

That is not ceremony. The rules that matter live in the models — a username
never changes, an account is deactivated rather than deleted, the last active
administrator cannot be demoted or switched off — and going through the model
means they hold on the command line exactly as they do on the web. Verified:
`console user:deactivate nick` on the only administrator is refused with the
same sentence the web screen gives.

### `App\Services\Doctor`

Every check in one pass, grouped: PHP, Configuration, Storage, Database, Tools,
Integrations, Pipeline. Each row carries `status`, `detail` **and `hint`** —
a check that reports `FAIL: storage` and stops has told the reader nothing they
did not already suspect, so there is a smoke assertion that every non-`ok` row
has a hint.

It also supersedes `DashboardController::setupGaps()`, which was a second list
of what a working install needs. The dashboard now calls `Doctor::setupGaps()`.

Two checks worth knowing about:

- **APP_KEY is tested, not just read.** A truncated or re-encoded key is set and
  useless, and the failure otherwise surfaces much later as a token that will
  not decrypt. `Doctor` does an encrypt/decrypt round trip.
- **An overdue queue is a warning.** Jobs due and nothing taking them means the
  cron entry is missing or failing — the single most common way a working
  install stops working, and one that reports itself nowhere else.

### Asserted, so the scripts cannot rot

`tests/smoke.php` fails if: a `manage.sh` case label has no function behind it;
either script calls a `bin/` or `tests/` file that does not exist; either calls
a console verb that is not implemented; `cron-install` stops naming both jobs;
the installer stops refusing to finish without `pdftoppm`; or a `tar` over the
application directory loses its `--exclude=./.env`.

That last one matters most: an update that copied `.env` would replace `APP_KEY`
and turn every stored credential into an unreadable blob, with no error at the
moment it happened. Verified the assertion catches it by removing the flag and
watching it fail.

### What was tested, and what could not be

Run for real on the development machine: `help`, `status`, `users`, `stats`,
`doctor`, `health`, `migrate --status`, `queue --status`,
`test` (all three harnesses), every argument-error path,
the root guard, and `install.sh --dry-run` end to end.

`create-admin.php` accepting a piped password was tested for real, because
`install.sh` depends on it and a wrong guess there breaks the install at its
last step.

**Not run here**: the parts needing root, systemd, apt/dnf or a real MariaDB
service — package installation, the vhosts, the firewall, cron installation,
backup and restore. Those are read-checked and syntax-checked only. First
install on a real server should be `--dry-run` first.

## 31. The Clear Books invoice sync

Prompt 14. A local copy of every purchase document Clear Books already holds, so
that the matching and deduplication work in Prompts 17 and 18 can ask *has this
invoice already been posted?* without asking Clear Books per document.

This prompt built the sync only. **`InvoiceMatcher::lookup()` is now the reader**
(§33), which changes one thing recorded below: the note under *Deletion, and the
two guards on it* says rows may be deleted because nothing in InvoGrid points at
them. Something does now — a `submissions` row holds the `clearbooks_id` of a
linked record — but the reasoning survives, because it holds Clear Books'
identifier and not a foreign key into this table. A record deleted in Clear Books
takes its row here with it, and the link to it still reads, still opens in Clear
Books, and is still the honest statement of what was attached to what.

### Why a copy at all

Clear Books has **no search endpoint** for purchase documents. The only way to
find one is to walk the list. A lookup per arriving document would therefore be
a full walk per document, against an API that throttles above five requests a
second — and it would put every review behind somebody else's uptime. So the
list is fetched on a schedule and kept locally, which is the reasoning that
produced `clearbooks_cache` as well.

### One table for two endpoints

`GET purchases/bills` and `GET purchases/creditNotes`, both into
`clearbooks_invoices`. Clear Books' `id` is unique across the two — confirmed,
not assumed — so one `clearbooks_id` key covers both, and `purchase_type`
records which endpoint a row came from. That last part matters: the two are
posted to differently, and a duplicate matched against the wrong kind is a wrong
answer rather than a near miss.

Seven columns are broken out because a lookup will use them — `document_number`,
`supplier_id`, `document_date`, `due_date`, `reference`, `gross_amount`,
`purchase_type` — and **everything else stays in `raw_json`**. Guessing now
which of Clear Books' remaining fields a matcher will want is how a schema
acquires columns that are always NULL; the whole record is kept, so promoting a
field to a column later is an `UPDATE` rather than a re-sync.

Two deviations from the prompt's column list, both deliberate:

- **`document_date`, not `date`.** It holds Clear Books' `date` field. `date`
  alone reads as a type rather than a column in every query that follows, and a
  credit note's date is not an invoice date.
- **`clearbooks_id`, not `id`.** `id` is the local key, as in every other table
  here; Clear Books' identifier is theirs, and is `VARCHAR(64)` for the same
  reason `clearbooks_cache.remote_id` is — so the two join without a cast.

### The gross amount is the one guess in here

Clear Books' specification does not say what a total is called on a purchase
document, and the sample responses on file carry none at all.
`ClearbooksInvoice::gross()` therefore takes a reported total under any of the
names it could plausibly have (`grossAmount`, `totalAmount`, `total`, `gross`,
`amountGross`, or a `totals`/`amounts` sub-object) and otherwise **works it out
from the line items**, using the VAT percentages already cached from their own
API.

Which of the two happened is counted and reported — by the cron script, by the
flash message and on the settings screen. So the first real run answers the
question this guesswork exists because of: if every row is derived, the reported
total has a name not on that list, and adding it is one line.

The **sign is left alone**. A purchase refund is a bill with negative amounts
and a credit note is positive at creation; an absolute value would throw away
the distinction a duplicate check needs most.

### Deletion, and the two guards on it

Clear Books is the sole source of truth, as it is for suppliers. A document
deleted there is deleted here on the next run — **deleted**, not deactivated,
which is the opposite of what `clearbooks_cache` does. The two differ because a
deactivated supplier is kept for the documents already pointing at it, and
nothing points at these rows at all yet. `ClearbooksInvoice::deleteMissing()`
carries that reasoning, and is where it has to be re-made if anything ever does.

Two guards:

- **Both fetches complete before anything is deleted.** A failure part way
  through raises, having written what it already read — which is harmless, those
  documents really are in Clear Books — and having deleted nothing. Deleting
  from a half-read list would remove documents that exist.
- **An empty result deletes nothing**, exactly as `deactivateMissing()` refuses
  one. Nothing coming back at all is far likelier to be a failed fetch than a
  business that deleted every purchase document it ever had.

The seen-ids list is judged for the table as a whole rather than per endpoint.
Per-endpoint would have to read "no credit notes came back" as suspicious, and
for most businesses it is simply true — which would leave a deleted credit note
in the table for ever.

### The schedule is a database row, not a crontab line

Cron runs `bin/sync-invoices.php` every five minutes; the script decides whether
a run is due, from `clearbooks_invoice_sync_interval_minutes`. So changing the
schedule is a form field on the Clear Books screen rather than root editing
`/etc/cron.d/invogrid`, and **the "Sync now" button and the cron job are the
same code path** — which is the only arrangement in which the button proves
anything about the schedule.

0 turns the schedule off and leaves the button. Otherwise five minutes to a
week: below five, a full walk of somebody's ledger would be running more or less
continuously; above a week, "off" says it better.

The interval is measured from when the last run **started**, so "every 30
minutes" means every 30 minutes rather than 30 minutes after however long the
last one took. A failed run stamps the time too, so a broken integration is
retried on the schedule instead of on every cron tick.

`clearbooks_invoice_sync_last_run` holds the outcome as a JSON blob — time,
ok/failed, trigger, message, per-type counts, deletions, seconds. A blob rather
than columns because it is displayed and never queried, and because a failure
needs somewhere to put a sentence. **A failed run is recorded before the
exception is re-thrown**: a last-run time that quietly stops advancing is how a
stale duplicate check comes to be trusted.

### Neither control is on the Settings screen

`clearbooks_invoice_sync_interval_minutes` is not in `SettingSchema`: it belongs
beside the sync it governs, and two controls for one value is worse than one in
the less obvious place. The Clear Books screen carries the interval, the button, the last-run
panel and the eight most recently dated rows — that last as a sanity check that
what came back is what an administrator expected.

### Streaming, because this list has no ceiling

`ClearBooksClient::walkPages()` now hands each record to a callback;
`allPages()` is the same walk with the array collected, and the reference lists
still use it. The purchase lists do not: ten years of trading is tens of
thousands of records, each carrying its line items, and accumulating all of them
before the first is written is how a cron job exhausts a server's memory.
`eachPurchase()` is the streaming reader.

`deleteMissing()` batches its `NOT IN` at a thousand ids for the matching
reason — one statement with fifty thousand placeholders is one MariaDB refuses
to prepare.

### Locking

`InvoiceSync::lock()` owns `storage/invoices.lock`, and **both** the cron script
and the controller take it, so a person pressing Sync now during a scheduled run
waits rather than sending a second walk at a rate-limited API. `tests/smoke.php`
asserts that both callers do.

Its own file rather than `clearbooks.lock`: a long invoice sync must not be able
to starve the cache refresh that matching depends on. The one thing the two
genuinely must not do at once — spend the single-use refresh token twice — is
already held under a named database lock inside `ClearBooksClient`.

The web action also lifts the time limit and sets `ignore_user_abort(true)`. A
gateway timeout leaves the fetch half done, and half done is the one state that
must not be reconciled against, so the run is allowed to reach its own end.

### How it was verified

There is no live Clear Books connection on the development machine, so a
stand-in API was written: a PHP built-in server answering
`/v1/accounting/purchases/{bills|creditNotes}` with `?page=` and `?limit=`
honoured and the `X-Pagination-*` headers set, serving records from a file a
test could edit between runs.

Against it, end to end:

| Case | Result |
|---|---|
| 250 bills + 3 credit notes | fetched in two pages plus one; 253 rows stored, columns correct |
| pagination | asserted from the stand-in's own request log — page 1 and page 2 of bills, at `limit=200` |
| a bill and a credit note deleted remotely, one edited | 0 new, 1 changed, 248 unchanged, **2 deleted** |
| both endpoints returning nothing | 251 rows untouched, and the run says why |
| the *last* credit note deleted | its row removed — the case a per-endpoint guard would have got wrong |
| the API unreachable | run fails, exit 1, and the failure shows on the settings screen |
| the browser | signed in; schedule rejected at 3 and accepted at 30; **Sync now** fetched 12 bills and 4 credit notes |

`tests/smoke.php` gained nine assertions covering the enum, the seeded settings,
the column mapping, the gross rules including the sign, the id-less record, both
deletion guards, the schedule arithmetic, and that both callers take the lock.
The deletion tests name every real row in their "seen" list — the supplier test
in §28 learned that lesson the hard way, and a check that empties the table it
is checking is worse than no check.

---

## 32. The Existing / New Invoice branch

Prompt 16, **as corrected by Prompt 17** — read this section with §33
beside it. Prompt 16 forked the *pipeline* the moment the transcription landed,
and skipped extraction on one arm. Prompt 17 reversed that: the decision is
still made here, and it is still recorded here, but both flows now run every
stage and part company at the end of matching. The paragraphs below are marked
where they describe what changed.

### The decision

A **Clearbooks Number** is a number written on the page by hand, almost always
in red pen. It is a reference to an invoice that is already in Clear Books — so
a document carrying one is not a bill to post, it is a scan belonging to a
record that exists. Those are two different jobs.

`OcrStage::route()` decides, immediately after the OCR response is stored:

| On the page | Route | Status |
|---|---|---|
| a usable Clearbooks Number | `existing_invoice` | `ocr_done` |
| none | `new_invoice` | `ocr_done` |

**The status is the same in both rows, and that is Prompt 17s correction.**
Prompt 16 returned `existing_invoice` here, which skipped the extraction — four
model calls saved on a question the handwriting had already answered. It also
left the document with no supplier, no dates, no line items and nothing to
search on, and forked the pipeline in two so every later change to extraction
had to be made twice. §33 has the full reasoning. The route is still decided
here, because this is the stage that reads the number; it is *acted* on at the
end of matching.

**"Usable" means digits only** (`OcrResult::isUsableNumber()`). The prompt is
explicit about this and says why: a circled code with letters in it is a
Project, a different field with a different meaning. A value that came back
non-numeric is a misread, so it does **not** route — but it is still stored, and
the `route` event says what it was and why it was not used. Silently dropping it
would leave nothing to answer the person holding the page and asking why their
document went the other way.

### Two columns, because two questions

- **`documents.status`** — where the document is *now*. `existing_invoice` is a
  status, so the pipeline runner can act on the branch at all: `Pipeline` maps
  status to stage, and a fork the status cannot express is a fork the runner
  cannot see. **Prompt 17 moved it**: it is now what the matching stage produces
  and the linking stage consumes, rather than what the OCR stage produces.
- **`documents.route`** — which flow it is *on*, written once and kept. The
  status alone cannot answer this. Every status up to and including `matching`
  is shared by both flows, so nothing but this says which way a document is
  going; and once the Existing Invoice flow rejoins the ordinary statuses at
  `submitted` — as it does, since it ends in a Clear Books record like
  everything else — a document that took the branch would be indistinguishable
  from one that did not.

**Prompt 17 made `route` load-bearing rather than descriptive.** Under Prompt 16
the status carried the fork and the route merely recorded it; now the route is
the input `MatchStage` reads to decide where a document goes, and flipping it is
how a person overrules the decision.

`route` is **NULL until OCR decides**. A document that has not been read is not
on either flow, and defaulting it to `new_invoice` would state a guess as a
fact.

### What a person can do about it

The decision rests on handwriting read off a scan, so it has to be reversible in
both directions:

- **onto** the Existing Invoice flow — the document page's reset control offers
  `existing_invoice` from `needs_review` and `ready_to_submit`, and sets the
  route with it;
- **off** it — the queue's "post it as a new invoice", which flips the route and
  re-runs the matching stage.

The alternative — ignore the document and upload it again — throws away the PDF,
the page images, the transcription and now the extraction as well.

### What consumes it

`LinkStage` — §33, Prompt 17. Three notes here were written while nothing did,
and are corrected rather than deleted so the reasoning is readable in both
states:

- there is now a **`Pipeline::STAGES` entry** for it, `link`;
- it is now **in `Document::stuck()`'s machine list**. While nothing ran the
  status, every document there was sitting still by design and counting them
  would have filled the dashboard's one real alarm with documents nothing was
  wrong with. Now a document sitting in it for half an hour means the queue has
  stopped, exactly as one sitting in `extracted` does;
- it **is** in the dashboard's in-flight count, because it is work that has not
  finished. `needs_link` is not — that has its own card beside "Needs review",
  because it is a queue somebody has to work rather than something in motion.

### The `### Notes` section, and why it went with this

The two halves are the same change. The OCR prompt used to append a prose
restatement of its own structured output to the end of the transcription — the
n8n flow's only way of carrying structure, since it had no database. Routing on
`clearbooksNumber` meant reading it reliably, and the value was in two places
saying two things. So the appended section is gone (`ocr` v3), the fields are
columns (`clearbooks_number`, `project_code`, `annotations_json`), and the two
extraction prompts that referred to the section were re-seeded. §15 has the
detail.

---

## 33. The Existing Invoice route

Prompt 17. The other arm of the branch, from the route §32 records to a PDF
sitting on the right record in somebody's accounts.

A document is here because a **Clearbooks Number** was written on it in red pen.
That number is a reference to an invoice already in Clear Books, so there is
nothing to post: the job is to find that record and put the evidence on it.

### Both flows run the same pipeline, and this is the correction §32 needed

Prompt 16 forked at the transcription: a document carrying a Clearbooks Number
went straight to a waiting status and **skipped extraction**, saving four model
calls on a question the handwriting had already answered. That reasoning was
sound about the calls and wrong about everything else, and this prompt reverses
it.

- **A scan of an existing invoice is still a document.** Somebody will search
  for it, report on it, and read it next year. Skipping extraction left it with
  no supplier, no dates, no line items, no custom fields — a blank row in every
  list and nothing for any future feature to work with.
- **Two flows that diverge at stage two are two pipelines.** Every later change
  to extraction or matching would have had to be made twice, or would silently
  have applied to half the documents.
- **The checksum wants real values.** It compares an invoice date and a gross
  total, and the extraction is what produces those properly. The earlier version
  had to scrape them out of the transcription with regular expressions, which is
  exactly the guesswork the extraction stage exists to replace.

So:

```
received → ocr_pending → ocr_done → extracting → extracted → matching ─┬─ route = new_invoice
                                                                       │    → needs_review
                                                                       │    → ready_to_submit → submitted
                                                                       │
                                                                       └─ route = existing_invoice
                                                                            → existing_invoice
                                                                                 ↓ LinkStage
                                                       ┌─ number found one record, checksum holds → submitted
                                                       └─ anything else → needs_link → /existing
```

`OcrStage::route()` still makes the decision — it is the stage that reads the
handwritten number — but it now writes `documents.route` and returns `ocr_done`
like every other document. `MatchStage` reads the route on its way **out**.
`tests/smoke.php` asserts that `ocr_pending → existing_invoice` is *not* a legal
transition, so a future change cannot quietly reinstate the skip.

### What the matching stage does with an existing invoice

Everything, and then sends it to the linking stage **whatever the entities
did**. That is not laxness, and the difference is worth stating: the things that
gate a submission gate a **creation**. An unresolved account code decides which
nominal a new bill is posted to; a VAT rate decides what is reclaimed; the
credit-note/refund question (§24) decides which way money moves. None of that is
asked here, because nothing is posted — the record was entered by a person
months ago and InvoGrid is attaching a scan to it.

What was unresolved is still written down. `entity_matches`, the review notes
and `needs_review` on the extraction are all set exactly as they are for any
other document, so nothing is lost and a person can see it. It simply does not
hold the document up. The gate on this flow is the checksum, and it is stricter
than anything the matching stage applies.

### The key, and the checksum on it

**The Clearbooks Number is the primary key.** `InvoiceMatcher::lookup()` asks
`clearbooks_invoices` — the local copy §31 builds — for the purchase document
whose `document_number` it is, comparing twice in one statement:

1. **exactly as written**, which uses the index on `document_number`;
2. **the digits alone, leading zeros dropped from both sides**, which is what
   makes "80421" in red pen find a record Clear Books calls `PUR0080421`.

That second pass is a **normalisation, not a tolerance**: 80421 and 80422 stay
two different numbers. What it settles is that Clear Books writes a prefix and a
person writing on a page does not.

**Two records answering to one number resolve to nothing** — the same rule §20
holds to for an ambiguous supplier name, and for a heavier reason: guessing
wrong attaches this document's PDF to somebody else's invoice.

A hit on the number is already a high probability of a match.
`InvoiceMatcher::check()` is the checksum on that hit:

| | Agrees when |
|---|---|
| Invoice date | `extractions.invoice_date` is the same day as the record's `document_date` |
| Gross total | `extractions.gross_amount` is the same figure as the record's `gross_amount`, to the penny |

**Both must agree, exactly. There are no tolerances anywhere in this**, and
`tests/smoke.php` asserts that no settings row containing "tolerance" exists, so
one cannot appear without somebody arguing for it.

A tolerance would be a licence to attach a scan to the wrong invoice without
anybody noticing. A hit on the number whose date or total does not agree is
precisely the shape a **misread digit** takes, and it costs a person ten seconds
to settle on the queue. The cost of the other mistake is a document filed
against somebody else's invoice, found — if it is found — during an audit.

Two things are worth naming as *not* tolerances:

- **The absolute value of the total is compared.** The sync keeps Clear Books'
  sign because it tells a credit note from a purchase refund (§24); a page never
  prints one — a credit note says £240.00, not -£240.00 — so comparing signed
  figures would send every credit note and every refund to manual review for a
  convention rather than a difference. The figure still has to be identical to
  the penny.
- **Amounts are compared as whole pence, as integers.** `413.28` and
  `413.2800000001` are the same invoice, and `===` on floats is how that stops
  being true on somebody else's machine.

A value missing on either side is **not** an agreement. It cannot be confirmed,
so it is not confirmed, and the document goes to a person.

### Nothing in Clear Books is changed

The attachment is the **only** call this route makes — asserted end to end
against the stand-in, by counting every request the stage made. The record was
entered by a person and is not InvoGrid's to edit; a difference between the page
and the ledger is theirs to settle, not this application's to overwrite.

The endpoint is `purchases/{purchaseType}/{id}/attachments/{fileName}`, POST,
raw `application/octet-stream`. **The credit-note path mirrors the bill path
exactly** — re-read from the published OpenAPI spec (§19) rather than assumed:
there is **one** parameterised attachment path, not one per document type, with
`{purchaseType}` a path parameter over bills, creditNotes and expenses and
identical methods and media types for all three. Nothing needed adding:
`ClearBooksClient::attachToPurchase()` has taken the type as a checked path
segment since submission was built.

The spec also carries `GET .../attachments`, `GET .../attachments/{id}` and a
`DELETE`. None is used, deliberately — InvoGrid has no business deleting from a
ledger, and §31 records the same rule for the sync.

### Attaching first, which is the opposite of SubmitStage

`SubmitStage` records the submission **before** attaching the PDF, because the
irreversible act there is creating a record in somebody's accounts and a crash
between the two would leave a bill InvoGrid thinks it never sent (§23).

Here the attachment **is** the act. So `LinkStage` attaches first and only
claims the link once the file is on the record, and a failed attachment throws
rather than warning. A crash between the two leaves an attachment and no local
record, and the retry attaches again under the same name — untidy, and far
better than a document marked linked whose evidence never arrived anywhere.

### Where everything is written

"The local database is updated with the matched Clear Books ID and all extracted
data is saved in the correct places" — most of which has already happened by the
time this stage runs, which is the point of running the whole pipeline. The
extraction wrote the header, the lines and the custom fields; the matching stage
wrote `entity_matches` and the supplier. What is left is what only this stage
knows:

| What | Where | Why there |
|---|---|---|
| The Clear Books id and URL | a `submissions` row | The same three facts a submission records, so "Open in Clear Books", the document list's join and the idempotency check all work with no special case |
| The id and document number | `clearbooks_bill_id` and `clearbooks_document_number` custom fields, via `SubmitStage::recordProducedFields()` | Those fields exist for a value produced by *reaching* Clear Books rather than read off a page. One implementation, shared, so the two cannot drift |
| The supplier | `documents.matched_supplier_id` | The ledger's own answer beats a guess from a letterhead, and it resolves a document the name fallback could not place |
| The document type | `documents.doc_type` | The endpoint the record came back on is a fact, not a classification — which is why this flow never asks the credit-note/refund question |

The `submissions` row's `response_json` carries `linked: true`, the Clearbooks
Number it was linked by, the matched record's own fields and the checksum that
allowed it. A row that attached a PDF to an existing record is therefore never
mistaken for one that created a record, which is the one thing the shared table
could otherwise lose.

**The project code is not pushed anywhere**, because there is nowhere to push
it: Clear Books has no projects endpoint and no field for one on a purchase
document (§19). It stays on the OCR result and in the custom field values, and
"Open in Clear Books" is how a person sets it — exactly as for a submitted
document.

**`ocr_results.clearbooks_number` is never rewritten**, including when somebody
corrects it on the queue. That column is the record of what a model read off a
page, the same as an extraction is; overwriting it would destroy the evidence
that the reading was wrong, which is the only way anybody would notice the
prompt needed work. The correction lives on the submission, the audit row and
the event.

### Why `submitted` rather than a `linked` status

Reusing it. §32 anticipated this: `documents.route` exists precisely because the
existing-invoice flow "rejoins the ordinary statuses further down, since it ends
in a Clear Books record like everything else". A second terminal status meaning
*this document has reached its Clear Books record* would have to be added to the
dashboard, the queue counts, the document list, the stuck check and every
`in_array($status, [...])` in the templates, in order to say something `route`
already says better.

### The queue: three actions, and nothing that resolves itself

`/existing`, `queue.view` to look and `review.resolve` to act. A row shows the
Clearbooks Number, the extracted date and total — the two values the checksum
compares — and the reason the match stopped, so the queue can be worked in the
order of what is actually wrong with it.

| Action | What it does | Audit action |
|---|---|---|
| **Link it** | The number is looked up and the checksum re-run; the document is linked | `document.linked` |
| **Post it as a new invoice** | Flips `route` and re-runs the matching stage; the document lands in the ordinary review queue | `document.route_changed` |
| **Delete it** | The row, the files and everything derived from it | `document.deleted` |

Four details:

- **The number field arrives holding what was read off the page.** Correcting a
  digit is the commonest fix; leaving it alone and pressing Link is "look it up
  again", which is the commonest *other* fix, because the invoice sync runs on a
  schedule and the record may have been entered since. That is why there is no
  fourth action.
- **"Post it as a new invoice" re-reads nothing.** Both flows ran the identical
  pipeline, so the document already has its transcription, extraction and entity
  matches; only the matching stage's exit was different. So the route is flipped
  and `MatchStage::recheck()` runs, taking the other exit. Re-deciding through
  the one implementation is the point — a second copy of "where does this
  document go now" would disagree with the stage eventually, and invisibly.
- **A person may overrule the checksum; they may not overrule the lookup.** That
  is the trade the exactness buys: the machine never guesses, and a person
  always can. Somebody holding the scan with the record on screen beside it is
  the better authority — the same rule the review screen holds to about the
  extraction's numbers — and what they overrode is recorded on the submission so
  the decision is not invisible afterwards. A number matching nothing, or two
  things, is not a judgement call: there is no record to link to, or no way to
  tell which.
- **The lookup is re-run when the page is opened** rather than read off the
  event the stage recorded. An event saying "matches nothing" that was true an
  hour ago is exactly what would send somebody off to retype a number that was
  already right.

### Deleting, which is the only irreversible thing in here

`Document::delete()` removes the stored PDF and the page images, then the row —
and the database cascades to pages, OCR results, extractions, entity matches,
events, jobs and submissions. Enumerating those here would be a second list to
keep in step with the schema.

Three things make it survivable:

- **A reason is required**, as it is for the review screen's ignore action. Six
  months later somebody will ask what happened to a scan they remember
  uploading.
- **The audit row outlives the document.** `audit_log.document_id` is
  `ON DELETE SET NULL` precisely so the log can describe something that no
  longer exists, so the controller writes the row *before* the delete, with the
  document's number, filename, arrival time and Clearbooks Number in the text
  where a nulled column cannot take them away.
- **The files go before the row**, and `clearstatcache()` goes before the
  `rmdir`. That last part is load-bearing rather than defensive: PHP caches what
  it knows about a path, the `filesize` and `unlink` above have just made that
  knowledge wrong, and without it every deleted document leaves an empty folder
  behind for ever. Verified on this project's own storage — the same `rmdir`
  succeeds a line later once the cache is dropped.

It is `documents.delete`, its own capability, held by `reviewer` — §6 has the
reasoning and how to move it.

### What was verified

There is no live Clear Books connection on the development machine, so the same
stand-in API §31 describes was used, extended with the attachment endpoint.
Documents were driven from `matching` through the real `MatchStage` and the real
`LinkStage`, so the fork is exercised rather than assumed.

| Case | Result |
|---|---|
| an existing invoice with the number, date and total agreeing | linked, `submitted`, PDF attached, and it kept its full extraction, line items, custom fields and entity matches |
| the same, counting every request made | **one** call to Clear Books — the attachment, and nothing else |
| a total 28p out | `needs_link`; nothing attached; the event names the gross total |
| a date one day out | `needs_link`; the event names the invoice date |
| a credit note, whose stored gross is negative | linked; the figure is compared without the sign, and the PDF goes to `purchases/creditNotes` |
| a number matching nothing | `needs_link`, and the event quotes the sync's last-run time |
| a number matching two records | `needs_link`, and both are named |
| the queue's Link on a record the checksum refused | linked, because a person overrode it, with the failed checksum recorded on the submission |
| the queue's "post it as a new invoice" | lands at `needs_review` in the ordinary queue, with the same extraction and OCR rows it already had |
| the queue's delete | row, extraction, PDF and page images gone; the audit row survives with `document_id` NULL and the id in its text |
| attaching switched off | linked, reference recorded, no call made, and the event says why |
| Clear Books refusing the attachment | the stage fails rather than claiming a link; nothing recorded as linked |
| the browser | all three queue actions driven through the real forms, signed in |

`tests/smoke.php` gained eighteen assertions, and one existing one was inverted:
the OCR-routing check now asserts that **every** document goes to `ocr_done`, so
reinstating the skip fails a test. The new ones cover the matching stage forking
on the route and nothing else, the transitions the fork and the queue need, the
retry origin, the four checksum shapes including the one-day and one-penny near
misses, the missing-value cases, the credit-note sign, the pence comparison, the
absence of any tolerance setting, the lookup's two spellings and its refusal to
guess, and the resource-to-type mapping. `tests/pipeline.php` gained the Prompt
17 block: ten methods that have to exist and do something.

---

## 34. The duplicate check on the New Invoice route

Prompt 18. The gap §32 and §33 left, and the one place in this application
where the wrong answer costs money rather than time.

### The gap

The whole Existing Invoice branch turns on somebody having written a number on
the page in red pen. That annotation is what routes a document, and it is a
statement about *the page*, not about the ledger.

An invoice already in Clear Books very often carries no such number. It was
entered by hand months ago and nobody has ever printed it; or a colleague
scanned it once before, under a different image, and this is the second copy. So
it takes the New Invoice route from end to end, is extracted and matched
perfectly well, and arrives at the review queue looking exactly like a bill
nobody has posted — with a **Submit to Clear Books** button on it.

Submitting it puts the same purchase into somebody's accounts twice, and that is
found by a payment run rather than by anything in here. Every other failure in
this application is visible on a screen; this one is not.

So a New Invoice document is compared against `clearbooks_invoices` — the local
copy §31 builds — before it is offered for submission.

### Where the check runs, and why not earlier

At the **end of the matching stage**, on the New Invoice arm, and *before* the
ready/needs-review decision rather than after it.

`MatchStage::run()` therefore has four exits:

```
route = existing_invoice          → existing_invoice        (§33, unchanged)
route = new_invoice, a duplicate  → possible_duplicate      (this section)
route = new_invoice, everything resolved → ready_to_submit
route = new_invoice, something did not   → needs_review
```

Three reasons for that position, in order of how much they mattered:

- **The supplier is a signal, and this stage is what produces it.** The check
  compares `documents.matched_supplier_id` against Clear Books' own
  `supplier_id`. Run after extraction and before matching, it would have had
  only two strings typed by two different people, and would have thrown away its
  best means of telling one supplier's £300 invoice from another's.
- **There is no stage to add, and adding one would be the wrong shape.** A
  document whose duplicate check has not run is not waiting for a machine; the
  check is arithmetic against a local table and takes no measurable time. A
  `dedup` stage would be a status, a registry entry, a handler and a retry
  origin, in order to express something that is one branch at the end of a stage
  that already branches.
- **Before the disposition, not after it.** A document whose entities all
  resolved would otherwise sit at `ready_to_submit` inviting exactly the double
  post this exists to stop; one whose entities did not would send somebody off
  to resolve an account code on a bill they are about to delete.

Everything above the gate still runs and is still written down — `entity_matches`,
the review notes, `needs_review` on the extraction — exactly as for an
existing-invoice document (§33). A document confirmed as genuinely new keeps all
of it and lands wherever the stage would have sent it, because the re-run comes
straight back through the same code.

`tests/smoke.php` asserts that nothing in `Pipeline::STAGES` consumes
`possible_duplicate`, so nobody can quietly promote this to a stage without
arguing for it.

### The comparison: the same one Prompt 17 makes

`DuplicateMatcher` calls `InvoiceMatcher::day()` and `InvoiceMatcher::pence()`
themselves — the two methods were made public for this — rather than spelling
them a second time. Two implementations of "the same day" would disagree about
the same pair of records eventually, and the two screens would then say
different things about them.

So the rules §33 argues for hold here unchanged:

- **no tolerances anywhere.** The same day, the same figure to the penny;
- **the absolute value of the total.** The sync keeps Clear Books' own sign
  because it tells a credit note from a purchase refund; a page never prints
  one;
- **whole pence as integers**, so no float equality decides anything;
- **a value missing on either side is not an agreement.** It cannot be
  confirmed, so it is not confirmed.

What differs is the question's shape, and it differs completely. `InvoiceMatcher`
is asked about a document with a **key**: the lookup either finds one record or
it does not, and the date and total are a checksum on a hit that is already
almost certain. Nothing here has a key — that is *why* the document is on this
route — so it has to be recognised by the shape of what it says.

Four signals, and no single one decides:

| Signal | Compared |
|---|---|
| Supplier | `documents.matched_supplier_id` against the record's `supplier_id` |
| Their reference | `extractions.invoice_number` against `reference`, case and separators folded |
| Invoice date | `extractions.invoice_date` against `document_date`, the same day |
| Gross total | `extractions.gross_amount` against `gross_amount`, the same pence, unsigned |

**The supplier's reference folds case and separators and nothing else** —
`INV-2026/0042`, `inv 2026 0042` and `INV20260042` are one reference typed three
ways. Leading zeros are **not** stripped, which is the one place this differs
from the Clear Books document-number pass in §33: Clear Books writes its own
numbers to a fixed width and a person writing one on a page does not, so `80421`
and `PUR0080421` are the same number; a supplier's reference has no such
convention behind it, and `0042` against `42` is two references.

An **unresolved supplier is `missing`, not `disagreed`**. It says nothing either
way, and counting it against the document would quietly make every document
whose supplier the matcher could not place un-flaggable.

### The threshold, which is the judgement this turns on

**At least two signals agree, and at least one of the two is the gross total or
the supplier's reference.**

Both halves are load-bearing, and the negative cases are what they are for.

*Two rather than one*, because no single signal is evidence. A business pays
£49.99 to the same supplier every month; a reference of "1" or "INV001" belongs
to half the small traders in the country. One agreement is a coincidence that
would stop something every day — and a queue that cries wolf is a queue that
gets cleared without being read, which is a worse outcome than not having built
it at all.

*One of them a money figure or a reference*, because the other two agree by
themselves constantly. The supplier agrees for every invoice from a regular
supplier; the date agrees for everything that arrives in the same post.
Supplier-and-date is two agreements and would stop a weekly delivery every
single week without once being right.

A genuine duplicate normally agrees on **all four** — it is literally the same
invoice — so two is already generous. The slack is spent deliberately in the
direction of catching an extraction that misread one field: **the cost of a
false positive is ten seconds on a comparison screen; the cost of a false
negative is the same purchase in the accounts twice.**

There is **no settings row**, and `tests/smoke.php` asserts that none containing
"duplicate" or "dedup" appears — the same assertion §33 makes about tolerances,
and for the same reason. A threshold that can be turned down is a check that
quietly stops running. The natural off switch is the sync itself: a
`clearbooks_invoices` nobody has filled matches nothing, and every document
flows through exactly as it did before this prompt. The duplicate queue says so
on its own face rather than reporting an empty queue as reassurance.

### Narrowing, before judging

`ClearbooksInvoice::findPossibleDuplicates()` fetches; `DuplicateMatcher` judges.
The split is because the narrowing has to be something the database can do with
an index and the judgement has to be something a person can read on a screen.

The `WHERE` is an OR of exactly two things, and they are the two the threshold
requires:

1. **the gross total, to the penny, either sign** — compared as the two literal
   DECIMAL values rather than with `ABS()`, so `ix_clearbooks_invoices_amount` is
   used. A function of the column would scan every purchase document the
   business has ever had;
2. **the supplier's reference**, exactly as stored and then again with case and
   separators folded — the same two-pass shape `findByDocumentNumber()` uses
   (§33), the first spelling using `ix_clearbooks_invoices_reference` and the
   second catching the same reference written differently.

**Nothing is narrowed on the supplier or the date alone**, and that is the
point rather than an omission: either would return most of the table. Both are
still *scored* — they are how a candidate found on the money is confirmed or
dismissed.

Migration 016 created all four of those indexes in anticipation of this prompt,
so nothing needed adding.

### `possible_duplicate`, and the one way off it

A status, because the answer is a judgement and there is nowhere else honest to
put a document waiting for one. Not `needs_review`, which is about resolving
entities so a record can be created — mixing them would file "correct this
account code" and "this bill may already be in the accounts" under one heading.
Not `needs_link`, which asks which record a *known* reference points at.

It sits between `matching` and `needs_review` in `Document::STATUSES`, which is
its real position: the gate a New Invoice document passes through on the way to
a disposition, not a state it can reach from one.

**`matching` is the only status that may reach it, and `matching` is the only
status it may reach.** Together those are the two halves of "the machine decides
where this goes, and it decides through one implementation":

- confirming a document is genuinely new writes
  `documents.duplicate_cleared_at` and calls `MatchStage::recheck()`. The stage
  runs again, reads that column, skips the gate and takes a different exit. A
  person does not choose a destination — the same rule §33 holds to for "post it
  as a new invoice", and for the same reason: a second copy of "where does this
  document go now" would disagree with the stage eventually, and invisibly;
- the stamp goes on **before** the re-match, because the re-match is what reads
  it. Reversed, the stage would find the same records and put the document
  straight back where it came from;
- **nothing may move a document into it by hand.** `failed` does not list it,
  though it lists every other waiting status. The screen it waits on is a
  comparison against records the matcher found, so a document parked there by
  the document page's reset dropdown would arrive at a page with nothing on one
  side of it; and a retry resumes at the head of a *stage*, of which this is not
  one. A failed document whose check should run again is retried from
  `extracted`, which re-runs matching and re-applies the gate against whatever
  the sync has fetched since.

There is **no un-clear method**, deliberately. Somebody who decides afterwards
that a document really was a duplicate deletes it, which is the same answer the
queue offered them; putting it back to be asked a second time would produce the
same decision.

### The queue: two actions, and a screen that is one gesture

`/duplicates`, `queue.view` to look and `review.resolve` to act. **The whole
screen is compare, then decide.** The machine has already done everything it can
— if it could tell these apart the document would not be here — so the only
useful thing to build is the view that lets a person tell them apart in ten
seconds: InvoGrid's reading in one column, the Clear Books record in the next,
the four signals marked agreed / disagreed / missing between them, and the scan
itself underneath.

| Action | What it does | Audit action |
|---|---|---|
| **It is genuinely new** | Stamps the decision and re-runs the matching stage; the document lands in the ordinary review queue or at ready-to-submit | `document.duplicate_cleared` |
| **It is the same invoice** | The row, the files and everything derived from it | `document.deleted` |

Five details:

- **The comparison is re-run when the page is opened**, not read off the event
  the stage recorded — the same rule §33 gives for the Clearbooks Number lookup.
  The invoice sync runs on a schedule, so the record that stopped this document
  may since have been edited, withdrawn, or joined by a second one. An hour-old
  opinion is what would have somebody deleting a document against a record that
  no longer says what the event says it says.
- **Candidates that no longer clear the bar are still shown**, below the ones
  that do and visibly demoted. Hiding them would leave a page headed "possible
  duplicate" with nothing visible to be a duplicate of, and a near neighbour
  ruled out by eye is worth ten seconds.
- **A reason is optional on the first action and required on the second**, which
  is the asymmetry they deserve: confirming a document is new sends it on to
  somebody who will see it again, and deleting one is the last time anybody sees
  it at all.
- **The audit row names the Clear Books record**, and is written *before* the
  delete. `audit_log.document_id` is `ON DELETE SET NULL`, so the row survives
  with its link nulled, which is why the document's own number, filename,
  reference and total go into the text. Six months later the question is not
  only "what happened to that scan" but "and which invoice is it, then" — so the
  candidates are recomputed at the moment of deletion and named in the entry.
- **There is deliberately no third "link the scan to that record instead".** It
  would be a second implementation of §33 on a screen about a different
  question, and the path already exists without one: push the document on, and
  the document page's reset control offers `existing_invoice` from where it
  lands.

`ocr_results` is not rewritten by anything here, for the reason §33 gives about
the Clearbooks Number: it is the record of what a model read off a page.

### Nothing in Clear Books is touched, either way

The check reads the local copy and nothing else — no call is made. Deleting
removes InvoGrid's copy of the scan and leaves the ledger exactly as it was: the
record was entered by a person and is not InvoGrid's to edit, which is the same
rule §31 states for the sync and §33 for the link. The screen says so, because
"delete" beside a Clear Books record is a word that invites the wrong reading.

### Submission, which needed nothing

Item 5 of the prompt — *submission creates the record in Clear Books and
attaches the source PDF in the same action, there being no separate write-back
step now Paperless is gone* — was **already true and is unchanged.**
`SubmitStage::submit()` has created and attached in one action since §23, and
Prompt 15 removed the write-back with everything else Paperless-shaped. The
ordering §23 documents is deliberate and stays: the `submissions` row is written
*before* the attachment, because there the irreversible act is creating a record
and a crash between the two would leave a bill InvoGrid thinks it never sent.
(§33's `LinkStage` inverts that, because there the attachment *is* the act.)

What this prompt changes about submission is only what reaches it: a document
that plausibly duplicates a synced record never gets a submit button.

### What was verified

Driven against the real tables and the real `MatchStage`, with the same stand-in
approach §31 and §33 use — there is no live Clear Books connection on the
development machine, and none is needed, because this check makes no calls.

| Case | Result |
|---|---|
| a new invoice matching a synced record on reference, date and total | `possible_duplicate`, and it kept its full extraction, line items, custom fields and entity matches |
| the same, with the entities deliberately unresolvable | still `possible_duplicate` — the gate is before the disposition, not after it |
| a document matching nothing | straight through to `needs_review`, and the `dedup` event says what was compared |
| the same duplicate, already cleared by a person | `needs_review`; the stamp is what stops the re-match putting it back |
| the same duplicate on the Existing Invoice route | `existing_invoice` — that flow is never asked this question |
| a reference spelled `inv 2026 0042` against `INV-2026/0042` | agreed; case and separators fold and nothing else does |
| `0042` against `42` | two different references — leading zeros are not stripped |
| one penny out and one day out, reference and supplier agreeing | plausible on two signals, which is the misread-field case this exists for |
| the recurring monthly total, alone | not plausible — one agreement is never enough |
| a regular supplier and the same date, nothing else | not plausible — two agreements without a money anchor |
| an unresolved supplier and no reference on the record | both `missing`, neither counted against, and the date and total still carry it |
| a credit note whose stored gross is negative | found on the same figure; the sign is a convention, not a difference |
| a record sharing only the date | never fetched as a candidate at all |
| the queue's "it is genuinely new", through the real form | `ready_to_submit`, redirected to the review screen with its submit button, audit row written |
| a re-match after that | `ready_to_submit` again, not back to the queue |
| the queue's delete, through the real form | row, extraction and files gone; the audit row survives with `document_id` NULL, and names PUR0004417 as what it duplicated |
| the browser | both queue screens and both actions driven signed in, at 1180px |

`tests/smoke.php` gained fifteen assertions: the transitions the gate needs and
the ones it must not have, the absence of a stage, the shared comparisons, the
reference normalisation and where it stops, the threshold from both sides, the
missing-value cases, the narrowing including the sign and the date that must not
match, and the gate itself driven through the real `MatchStage` four times.
`tests/pipeline.php` gained the Prompt 18 block: ten methods that have to exist
and do something.

---

## 35. Presentation: desktop-first, and one mark per field

Prompt 19. Three changes, and they are all about the same thing: a reviewer
should be able to see what needs doing without hunting for it.

### The content column

`.container` stays at 1200px — a measure for reading prose, which is what most
administration screens are. The document-facing screens opt into
`.container-wide` at **1760px** by passing `'wide' => true` to the view:

| Screen | Why |
|---|---|
| Dashboard | Six tables and a stat row |
| Document list, document record | A queue and a pipeline log |
| Review queue and detail | A scan beside a form with a six-column table in it |
| Existing invoices, and its detail | A queue, and a scan beside a checksum |
| Duplicates, and its detail | A queue, and a comparison of four signals |

1760px rather than "no limit": it leaves a margin on a 1920 monitor instead of
running edge to edge, and a line of table text stops being scannable somewhere
around there.

Two other pieces of the same change:

- **Tables get real padding above 1150px** — the same breakpoint the navigation
  bar uses, so a screen has either the desktop treatment throughout or none of
  it — and every queue states its **column widths** with the `.col-*` helpers.
  Left to itself, `table-layout: auto` gives the widest column whatever is left,
  which on the queues means the "what is outstanding" sentence takes two thirds
  of the page and the supplier wraps onto three lines.
- The review split is `1fr 1fr` from 1100px and **5fr / 7fr past 1500px**: a
  sheet of A4 stops getting easier to read somewhere around 700px and an
  editable line-item table does not, so the extra width goes to the form.

**Below about 1500px the line-item table scrolls sideways inside
`.table-wrap`.** Six columns, two of them `<select>`s, in a 650px pane is not a
layout problem with a solution — something has to give, and a horizontal scroll
on one table is better than truncated pickers or a description box three
characters wide. It is the behaviour that wrapper exists for. Everything stacks
below 1100px and the screen still works on a phone; it is simply no longer what
the layout is designed around.

### The scan viewer

`templates/partials/scan.php`, used by the review screen, the Existing Invoice
screen, the duplicate comparison and the document record — all four now show the
scan the same way.

**Page images by default, PDF on request.** The images are already on disk:
every document is rendered to one PNG per page by `PdfRenderer` before a model
is shown it (§17), so this costs nothing to serve and shows exactly what the
extraction was worked out from. An `<img>` paints straight away where the
`<object>` boots a whole PDF viewer, with its own toolbar and its own idea of
zoom, inside a box a third of the screen wide.

The bar underneath carries page arrows and a count, an **Actual size** toggle —
fit-the-width or the image's own pixels, which is what reading a handwritten
annotation needs, and nothing in between, because a zoom slider is a control
nobody uses twice — and **View PDF**, which reveals the `<object>` *beneath* the
images rather than replacing them.

**All of it degrades.** The pages are stacked in one scrolling box in document
order, the thumbnail strip (past two pages) is ordinary in-page anchors, and
View PDF is a link to `/documents/{id}/pdf` with `target="_blank"`. The two
controls that cannot work without a script — the arrows and the zoom toggle —
ship `hidden` and `app.js` reveals them, so the bar never offers a button that
does nothing. A modified click on View PDF (ctrl, meta, shift, middle) is left
alone to open a tab, because hijacking that is the thing people hate most about
an intercepted link.

### One mark per field

`src/Services/FieldIssues.php`. The screen used to carry one card saying "4
things to check" above forty inputs, and left the reviewer to work out which
four boxes were meant — the part of the job the machine can do.

Three signals go in, in descending order of how far they can be trusted:

| Signal | Source | Tone |
|---|---|---|
| An unresolved entity | An `entity_matches` row, which names its entity type and, for a line item, its line index. Structural — no guessing | `danger` |
| A confidence below 1.0 | A match settled by the looser name pass (0.9), or a score in `extractions.confidence` below 0.8 | `warn` |
| A review note | Prose a stage wrote. The only part that is a guess | `warn` |

Field keys are **the extraction's own column names** — `invoice_date`,
`gross_amount`, `supplier_name_raw` — plus `custom_<field_key>` and
`line.<index>.<column>` for a cell of the line table. A template asks for an
issue with the same string it uses for the input's `name`, so the two cannot
drift apart silently.

Attribution runs four passes, narrowest first. The first three parse a prefix
the *pipeline itself* writes and are therefore not guesses:

1. `Matching: Account code on line 2: …` → that cell. A note in this form is
   **dropped** when the row behind it has already marked the same field, because
   two indicators saying the same thing on one input reads as two problems. The
   test is the same regex, not the `Matching: ` prefix — that stage also writes
   notes with no row behind them (a stale cached supplier id, a credit document
   waiting to be agreed) and those say something the rows do not.
2. `Line 3: no account code was chosen.` → line 3's account-code cell. The notes
   count from 1 and the form's rows from 0.
3. `Document type:`, `Line items:`, `Setup:`, `Custom fields:`, `Supplier:` —
   each straight to its field, except `Setup:`, which is about the installation
   rather than about a box on this document.
4. Free text a model wrote, read for a phrase from a short list. Deliberately
   short: "due date" is on it, bare "date" is not. **The cost of a wrong mark is
   a reviewer editing a value that was right, and then trusting the next mark
   less.**

**A note that cannot be placed is not attributed by guesswork.** It goes to
`unplaced()` and is listed at the top of the form, where the banner used to be.
That list plus an index of links to the marked fields is all that is left up
there.

`extractions.confidence` is read even though nothing writes it. The column is
defined as exactly this — migration 001, "per-field confidence, keyed the same
way as the columns above" — and a screen that ignores a column until somebody
remembers to wire it up is a screen that stays wrong for a release.

### Where a resolution happens now

Prompt 19 moved the resolution controls to the field, which changed what the
"Not resolved yet" cards are for:

- **An account code, a VAT rate or the VAT treatment** is a `<select>` in the
  review form already, and saving re-runs the match — so picking the right one
  there *is* the resolution. The marked cell is the control that fixes it, and
  the separate picker card for those three has gone. `POST
  /review/{id}/entity/{matchId}/pick` still exists and still does the same
  thing; nothing on the screen needs it for those types.
- **The supplier** keeps its card, beside the field. The supplier box holds the
  name read off the letterhead, so typing in it changes what the document says
  rather than what it points at — the two controls that do point it somewhere
  (pick one on file, create one in Clear Books) have to be somewhere else, and
  the field's own mark links to them.

### The read-only twin

`templates/partials/extraction.php` on the document record marks the same values
the same way, from the same `FieldIssues` object. It cannot be edited, so the
marks are all it offers — but "which of these forty values is the doubtful one"
is the same question on both screens and must not have two answers.

The three view helpers that draw a mark — `flag_class()`, `flag_tag()`,
`flag_notes()` — are in `src/helpers.php` rather than as closures in a template,
for that reason: three copies of "which class means danger" is three chances for
one screen to start saying something different from the others. `flag_tag()`
prints a **word** and not only a colour, because colour alone is not a signal
every reader receives and telling people which fields to look at is the entire
point of the mark.

---

