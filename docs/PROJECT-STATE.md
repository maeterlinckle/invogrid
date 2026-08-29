# InvoGrid — project state

A factual snapshot of what exists in the codebase **right now**, not a changelog.
Read it before starting work; rewrite the parts that changed when finishing.

**Last updated:** end of Prompt 13 (security review, polish, documentation).

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
| Paperless API client (`Http`, `PaperlessClient`) | done |
| Webhook receiver, idempotent registration | done |
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
| Supplier → Paperless correspondent sync | done |
| `/admin/clearbooks`: connect, cache state, refresh, sync | done |
| Review queue: triage list and the editable detail screen | done |
| Creating a supplier in Clear Books from a confirmed form | done |
| Submission to Clear Books, PDF attached to the record | done |
| Paperless write-back: correspondent, title, content, type, fields, tags, note | done |
| "Open in Clear Books" in one reused window | done |
| Credit note vs purchase refund, with a human confirming | done |
| Custom fields screen, with Paperless pairing and creation | done |
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
| Application settings / full activity log screens | not started |

A document now runs the whole way on its own when everything resolves, and a
person finishes the ones that do not. The pipeline itself still stops at
`ready_to_submit` — **submission is a human action, not a stage.** There is no
`submit` entry in `Pipeline::STAGES` and there should not be one: nothing should
be able to put a bill into somebody's accounts because a cron job fired.

Nav entries for the unbuilt destinations are rendered as muted text with a
`soon` badge (`nav-link is-pending`) rather than as links. Documents, Review
queue and Clear Books are real links now.

**What is left**: the Settings screen (which would replace
`bin/console.php settings:set`, and is where
`document_types.paperless_document_type_id`, the processed-tag id and the two
`stuck_*` thresholds would be edited — those are still set by hand in the
database) and the full activity log view. The dashboard carries the last
fifteen entries in the meantime.

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
`#a5b4fc`) instead of blue; desktop nav bar at `900px` instead of `1150px`
(three top-level items, not six). Everything else — spacing scale, 17px body
type, 44px tap targets, component vocabulary — is the same on purpose.

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
- `009_clearbooks_connection.sql` — the consent-flow settings and the two sync switches.
- `015_diagnostics.sql` — `document_events.context`, and the two `stuck_*`
  thresholds, so a failure can be read on the page and a stalled document
  is noticed at all.
- `014_user_management.sql` — `users.must_change_password` and
  `users.password_changed_at`, which is what makes an admin-set password a
  moment rather than a state.
- `013_prompt_origin.sql` — `prompt_templates.origin`, so "reset to default"
  knows which versions shipped.
- `012_classification_reasoning.sql` — `extractions.doc_type_reason` and
  `extract_lines` v3, which asks the model to say what decided its answer.
- `011_credit_notes_and_refunds.sql` — the corrected credit-note sign, the
  `purchase_refund` type, `requires_confirmation`, `default_credit_route`,
  the confirmation stamps, and `extract_lines` v2.
- `010_review_and_submission.sql` — `custom_fields.source`, the two write-back
  fields, `extractions.edited_at`/`edited_by`, and the write-back settings.

### Tables in use

| Table | Purpose / notable columns |
|---|---|
| `users` | `username` (unique, lower-cased), `display_name`, `email`, `password_hash`, `role` ENUM(viewer,reviewer,admin), `active`, `last_login_at`, `last_login_ip` |
| `login_attempts` | throttling; `username`, `ip_address`, `successful`, `attempted_at` |
| `settings` | `setting_key` PK, `setting_value`, `is_secret`, `updated_by` |
| `document_types` | `type_key` (unique), `label`, `clearbooks_resource`, `amount_sign`, `paperless_document_type_id`, `sort_order`, `active` |
| `prompt_templates` | `template_key` + `version` unique, `content`, `is_active`, `updated_by` |
| `custom_fields` | `field_key` unique, `label`, `data_type`, `select_options`, `paperless_field_id`, `prompt_hint`, `sort_order`, `active` |
| `documents` | `paperless_doc_id` unique, `status`, `doc_type`, `correspondent_raw`, `correspondent_matched_supplier_id`, `pdf_path`, `page_count`, `failed_stage`, `error_message`, `attempts`, `locked_at` |
| `document_pages` | `document_id` + `page_number` unique, `image_path`, `width`, `height` |
| `ocr_results` | `document_id`, `llm_provider`, `llm_model`, `raw_text` (verbatim reply), `ocr_text` (transcription alone), `structured_json`, `notes_present`, `prompt_template_id`, token counts, `duration_ms` |
| `extractions` | one row per reading; header columns, `vat_treatment`/`supplier_match`/`line_items`/`custom_field_values`/`confidence`/`review_notes` as JSON, `needs_review` |
| `entity_matches` | `extraction_id`, `entity_type` ENUM(supplier, account_code, vat_rate, vat_treatment), `line_index`, `raw_value`, `matched_id`, `matched_name`, `matched_via` ENUM(llm, code_fallback, manual), `confidence`, `status` ENUM(matched, unmatched, created, rejected), `resolved_by`, `resolved_at` |
| `clearbooks_cache` | `entity_type` + `remote_id` unique, `name`, `normalised_name` (indexed), `raw_json`, `paperless_correspondent_id`, `active`, `cached_at` |
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
- **The Paperless document-type mapping lives in `document_types`**
  (`paperless_document_type_id`) rather than as a settings row. Prompt 1 listed
  it under settings; putting it beside the type keeps "add a document type" to
  one insert, which is what the "new type is a data change" requirement asks
  for. The Settings screen will still be where it is edited.
- **`entity_matches.line_index`** was added beyond the specified columns: account
  codes and VAT rates are per line item, so without it two lines guessing
  different codes cannot be told apart.
- **`document_events` and `pipeline_jobs`** were added beyond the specified
  tables. The queue exists because a webhook cannot be held open for an LLM call;
  the event log is what makes a failed stage retryable rather than lost. Both are
  here now to avoid a migration onto a live database later.
- `documents.correspondent_matched_supplier_id` holds a Clear Books remote id.

---

## 4. Pipeline status state machine

`documents.status`, defined in `App\Models\Document`:

```
received → ocr_pending → ocr_done → extracting → extracted
         → matching → needs_review → ready_to_submit → submitted
```

- `failed` is reachable from every working state, and a retry moves the document
  back to the head of the stage that failed.
- `ignored` is a human decision, reachable from anywhere; `ignored → received`
  puts a document back into the pipeline.
- `needs_review ⇄ matching` so a resolved entity can be re-matched.

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

`match` is the first stage whose handler can return something other than its
declared `to`: `ready_to_submit` when everything resolved. The registry records
the conservative outcome because that is the one `Document::retryStatusFor()`
and the smoke test's consistency check have to be right about. Both
destinations are legal from `matching`, and the test asserts it.

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
| POST | `/webhook/paperless` | `WebhookController::receive` | *none — shared secret* |
| GET | `/` | `DashboardController::index` | `auth` |
| GET | `/documents` | `DocumentController::index` | `can:documents.view` |
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

`/admin/fields/new` and `/admin/users/new` are declared **before** their
numeric forms, so "new" is never matched as an id.

Viewing the queue is `queue.view`, which a viewer has; everything that changes
something is `review.resolve` or higher, and **creating a record in somebody
else's accounts has its own capability** (`entities.create`) because it is a
different kind of act from correcting a date on a screen.

`/admin/clearbooks/callback` is the **one signed-in route without `csrf`**, and
deliberately: it is a redirect from Clear Books, which has no token to carry. A
`state` parameter generated in `connect()`, kept in the session and compared
with `hash_equals()` does the same job, and the pending request is discarded
whether the exchange succeeds or fails — an authorisation code is single use and
a stale verifier invites a replay.

Routes are declared in `routes/web.php`. Middleware is named on the route, never
checked inside a controller.

The webhook receiver is the one route with no middleware at all. It is not a
browser form, so CSRF is meaningless on it; the shared secret in
`paperless_webhook_secret` is what authenticates the caller. Do not put `csrf`
on it — Paperless has no session to carry a token in.

**Paths reserved for later stages** (rendered as `soon` in the nav):
`/admin/settings`, `/admin/activity`. The review queue was
reserved as `/queue` and is built as `/review`; Prompt 9 named the two admin
screens `/settings/*` and they are built under `/admin/*`, which is the prefix
the rest of the application already uses.

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
| `reviewer` | `documents.retry`, `review.resolve`, `entities.create`, `documents.submit` |
| `admin` | `settings.manage`, `prompts.manage`, `fields.manage`, `users.manage`, `audit.view` |

Always check `Auth::can('x')` / the `can('x')` template helper, or
`Auth::atLeast('admin')` / the `role_at_least('admin')` helper for the two
places gated on seniority rather than on a capability. **Never compare `role`
strings at a call site** — `Auth::can()` is the only method that should have to
change if the model grows.

`Auth::capabilityMap()` returns the same table, cumulative and in role order,
and is what the users screen renders. A hand-written table in a template would
describe a permission model the application does not enforce within one release.

**Prompt 10 named the middle role "Processor"; it is `reviewer` here.** The DB
enum, `Auth::ROLES`, every `can:` string and a year of audit entries already say
reviewer, the screen it names is called the Review queue, and renaming it buys
a word. Judgement was explicitly delegated on the naming.

### The gates are asserted, not assumed

`tests/smoke.php` walks `Router::routes()` and fails if:

- any route lacks `auth`/`can`/`role` and is not on the five-item
  `$deliberatelyOpen` list (health, branding, the webhook, the two login
  routes);
- any non-GET route lacks `csrf`, other than the webhook and the Clear Books
  callback;
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
| Users | `src/Models/User.php` |
| Pipeline state machine, status counts | `src/Models/Document.php` |
| Document type registry | `src/Models/DocumentType.php` |
| Audit trail | `src/Models/AuditLog.php` |
| Logo resolution and safe path handling | `src/Services/Branding.php` |
| **cURL wrapper: timeouts, no redirects, error translation** | `src/Services/Http.php` |
| One HTTP response, `json()`, `errorSummary()` | `src/Services/HttpResponse.php` |
| DNS/connect/TLS/timeout failure (worth retrying) | `src/Services/HttpTransportException.php` |
| **Paperless-ngx v3 REST client** | `src/Services/PaperlessClient.php` |
| Document gone from Paperless (never worth retrying) | `src/Services/PaperlessNotFoundException.php` |
| **Stage registry and job runner** | `src/Services/Pipeline.php` |
| Stage 1: fetch metadata and the source PDF | `src/Services/IngestStage.php` |
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
| Webhook receiver | `src/Controllers/WebhookController.php` |
| Document list, detail, PDF, retry, ignore | `src/Controllers/DocumentController.php` |
| **Clear Books v1 REST client, OAuth2 and all** | `src/Services/ClearBooksClient.php` |
| Clear Books failure, carrying whether a retry helps | `src/Services/ClearBooksException.php` |
| Clear Books needs somebody to sign in again | `src/Services/ClearBooksAuthException.php` |
| **Company-name reduction: the deterministic fallback** | `src/Services/Normaliser.php` |
| Refill the cached reference lists | `src/Services/CacheRefresh.php` |
| **Stage 4: check every id, fall back on names** | `src/Services/MatchStage.php` |
| Suppliers → Paperless correspondents | `src/Services/SupplierSync.php` |
| One row per entity that has to resolve | `src/Models/EntityMatch.php` |
| Connect, cache state, refresh now, sync now | `src/Controllers/ClearBooksController.php` |
| **The queue, the editable detail, resolve, submit, skip** | `src/Controllers/ReviewController.php` |
| Accounts: create, edit, role, deactivate, reset a password | `src/Controllers/UserController.php` |
| Changing your own password | `src/Controllers/AccountController.php` |
| An exception that can explain itself | `src/Services/Diagnosable.php` |
| Files arriving from a browser, and the three checks on them | `src/Core/Upload.php` |
| Every route x every role, over real HTTP | `tests/permissions.php` |
| Is every workflow step really implemented? | `tests/pipeline.php` |
| The logo: serving it, and replacing it | `src/Controllers/BrandingController.php` |
| The shell for anything meant to end up on paper | `templates/layouts/print.php` |
| What counts as an acceptable password, in one place | `src/Core/PasswordPolicy.php` |
| Custom fields: define, pair with Paperless, take out of use | `src/Controllers/FieldController.php` |
| Prompts: edit, version, roll back, reset to default | `src/Controllers/PromptController.php` |
| Pick or create the Paperless field a value maps onto | `src/Services/PaperlessFields.php` |
| **The only class that creates anything in Clear Books** | `src/Services/EntityCreator.php` |
| **Build the payload, submit, attach, record** | `src/Services/SubmitStage.php` |
| Make Paperless agree with what was submitted | `src/Services/PaperlessWriteBack.php` |
| What was sent to Clear Books, and what came back | `src/Models/Submission.php` |

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
  `data-confirm`, and `data-clearbooks-window` (the reusable named
  `clearbooksWindow` for the "Open in Clear Books" action).
- Layouts: `templates/layouts/app.php` (signed in) and `auth.php` (signed out).
- Partials: `brand.php` (logo with light/dark variants, IG monogram fallback),
  `nav.php`, `footer.php`, `flash.php`.
- Layouts are three now: `app.php` (signed in), `auth.php` (signed out) and
  `print.php`, which includes neither the navigation nor the footer — see §28.
- Component classes available: `.card`, `.stat-grid`/`.stat-card`,
  `.table-wrap`/`.table`/`.table-compact`/`.amount`, `.badge-*`, `.btn-*`,
  `.field`/`.input`/`.field-error`/`.field-hint`, `.flash-*`, `.subnav`,
  `.filter-bar`, `.page-head`, `.section-title`, `.empty`.

---

## 9. Navigation structure

```
[IG logo] InvoGrid   Documents  Review queue  Settings ▾   [theme] [avatar →] [Sign out]
                                                                   └ links to
                                                                     /account/password
                                                          ├ Application settings (soon)
                                                          ├ Branding
                                                          ├ Clear Books
                                                          ├ Prompts
                                                          ├ Custom fields
                                                          ├ Users
                                                          └ Activity log (soon)
```

No Dashboard entry: the logo is the link home. Items are filtered by capability;
a group with nothing visible in it disappears entirely.

Footer, every page: `InvoGrid — by Junction Inc Ltd` (vendor linked), plus the
tagline and who is signed in.

---

## 10. Configuration split

- **`.env`** — what is needed before the database can be read: `APP_URL`,
  `APP_KEY`, DB credentials, session/HTTPS behaviour, `STORAGE_PATH`. Also holds
  an optional fallback for each integration credential.
- **`settings` table** — everything an administrator edits: Paperless address and
  token, Clear Books OAuth2 credentials and business id, LLM API keys, webhook
  secret, per-stage provider and model choices, logo paths.
- **Precedence: a non-empty setting wins; `.env` is the fallback.** Implemented
  in `Setting::ENV_FALLBACK`.
- Secrets (`is_secret = 1`) are encrypted with `APP_KEY`. `Setting::secret()` is
  the only reader. `Setting::put()` **returns `false` rather than writing a
  secret in the clear** when `APP_KEY` is missing. Nothing prints a secret back
  to a browser; `Setting::summary()` exposes only `configured: true|false`.

Seeded setting keys: `organisation_name`, `paperless_base_url`,
`paperless_token`*, `paperless_webhook_secret`*, `clearbooks_base_url`,
`clearbooks_client_id`, `clearbooks_client_secret`*, `clearbooks_access_token`*,
`clearbooks_refresh_token`*, `clearbooks_token_expires_at`,
`clearbooks_business_id`, `clearbooks_web_url`, `clearbooks_cache_ttl_minutes`,
`openai_api_key`*, `anthropic_api_key`*, `llm_ocr_provider`, `llm_ocr_model`,
`llm_extraction_provider`, `llm_extraction_model`, `flash_auto_hide_seconds`,
`logo_light_path`, `logo_light_mime`, `logo_dark_path`, `logo_dark_mime`,
`clearbooks_authorise_url`, `clearbooks_redirect_uri`, `clearbooks_scopes`,
`clearbooks_sync_correspondents`, `clearbooks_delete_correspondents`,
`paperless_processed_tag_id`, `paperless_replace_content`,
`clearbooks_attach_pdf`.
(`*` = secret.)

The last five have **no `.env` fallback** and are rows only. `clearbooks_scopes`
is asserted in `tests/smoke.php`: a scope added by accident fails a test rather
than quietly granting the integration the run of the ledger.

---

## 11. Command-line tools

```bash
php bin/migrate.php [--status]      apply pending migrations
php bin/create-admin.php            create or reset an account (first one is admin)
php bin/process-queue.php           work the queue; --status, --verbose, --limit=N
php bin/refresh-clearbooks.php      refill the cache; --status, --sync, --dry-run
php bin/console.php secret:generate      a random shared secret
php bin/console.php settings:set <key>   set one, value read from stdin
php bin/console.php key:generate    print a new APP_KEY
php bin/console.php db:check        database reachable, schema current, key present
php bin/console.php settings:list   which settings are configured (never values)
php tests/smoke.php                 273 assertions; exits non-zero on failure
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

## 12. Paperless: the facts, and where they came from

All of this was read out of the paperless-ngx source, not inferred. Where a
later prompt needs to touch the integration, start here.

**The webhook action** (`src/documents/workflows/actions.py`,
`workflows/webhooks.py`, `templating/workflows.py`):

- Placeholders available in a workflow action are `{{doc_id}}`, `{{doc_title}}`,
  `{{doc_url}}`, `{{correspondent}}`, `{{document_type}}`, `{{owner_username}}`,
  `{{added*}}`, `{{created*}}`, `{{original_filename}}`, `{{filename}}`.
- **`{{doc_id}}` renders to an empty string on a Consumption-Started trigger.**
  The context builder sets `"id": ""` in its overrides branch, because there is
  no Document row yet. The workflow must therefore use **Document Added** (or
  Document Updated). This is the single most important fact in this section.
- `use_params` on → each param *value* is rendered and the set is posted as a
  dict. `use_params` off with a `body` → the body string is rendered and posted
  as raw content with no content type. `as_json` decides `json=` versus `data=`
  / `content=`.
- **Timeout is 5.0 seconds**, redirects are **not** followed, and a non-2xx is
  **retried up to 3 times** with backoff. This is why the receiver does almost
  nothing and why idempotency is mandatory rather than tidy.
- A `Host` header set on the action is stripped before sending.
- `PAPERLESS_WEBHOOKS_ALLOW_INTERNAL_REQUESTS` (default `true`),
  `_ALLOWED_SCHEMES` (`http,https`) and `_ALLOWED_PORTS` (all) can block a URL
  before it leaves Paperless. A blocked delivery is logged on the Paperless
  side and never reaches `storage/logs/webhook.log`.

**The REST API** (`src/documents/views.py`):

- `GET /api/documents/{id}/download/?original=true` — the comparison is against
  the **literal string `true`**. `original=1` silently returns the *archive*
  copy, which is the re-rendered OCR version rather than the scan.
- `POST /api/documents/{id}/notes/` with `{"note": "..."}`. Paperless calls
  them notes; nothing in the API says comment.
- `PATCH /api/documents/{id}/` accepts correspondent, document_type,
  storage_path, title, content, tags, custom_fields, created, owner. An unknown
  key is ignored silently, so `PaperlessClient::updateDocument()` rejects one
  rather than let a write appear to work and change nothing.
- `custom_fields` is `[{"field": <id>, "value": <value>}, ...]` and a PATCH
  **replaces the whole list** — hence `setCustomFields()` merging by default.
- Auth is `Authorization: Token <token>`, not Bearer.

**Confirming a real payload:** every delivery, accepted or rejected, is appended
to `storage/logs/webhook.log` with the content type and body, secret redacted.
That file is how a placeholder question gets settled in seconds.

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
| `ocrText` | `OcrResult::text()`, `### Notes` included |
| `today` | `date('Y-m-d')` |
| `suppliers` | `ClearbooksCache::forPrompt('supplier')` — carries **both** `cbId` and `paperlessId` |
| `accountCodes` | `ClearbooksCache::forPrompt('account_code')` |
| `vatRates` | `ClearbooksCache::forPrompt('vat_rate')` — with the percentage, or VAT cannot be computed |
| `vatTreatments` | `ClearbooksCache::forPrompt('vat_treatment')` |
| `customFields` | `CustomField::forPrompt()` |

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

---

## 15. The production prompts, as seeded

The four prompts from the existing n8n flow were supplied at the end of Prompt 3.
The OCR one is now seeded as `ocr` **version 2** and is active; version 1, which
was written to the specification before the real text was available, stays in the
table. The other three are **not yet seeded** — they belong with the extraction
stage.

### Structured output is data, not text

**The `### Notes` section is a rendering, not a carrier.** It exists because
n8n had no database: every field the model found had to be flattened into text
appended to the transcription, and confidence scores and extra fields became
impractical almost immediately. InvoGrid has a database, so:

- the response is parsed **once**, in `OcrResult::create()`, and stored as
  columns — `raw_text` (verbatim, for a human asking what actually came back),
  `ocr_text` (the transcription alone, which is what a downstream prompt is
  given), `structured_json` (everything else), `notes_present` (promoted
  because it is the one field worth filtering a list by);
- **nothing downstream re-parses the raw text.** The document template used to
  `json_decode` it on every render; that was the n8n habit and it is gone.
  `OcrResult::structured()` and `OcrResult::text()` are the readers;
- `### Notes` keeps its place inside `ocr_text` for two reasons only: it is
  what gets written into the Paperless document content, and it is what a human
  reads when checking the scan against the record between OCR and submission.

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

### The other three, as seeded

All three are now rows in `prompt_templates` (migration 008), verbatim apart
from the interpolation — see §14. Things about them worth keeping in mind:

- **The extraction prompts ignore everything from `### Notes` onward** in the
  OCR text. That is why the notes section is appended *inside* `ocrText`: it
  travels with the record for a human and is skipped by the machine.
- `accountCode` is **numeric**; `vatRateKey` is a string; `vatTreatment` is
  `{key, name}`. `documentType` is `bill` or `creditNote` — mapped onto
  `document_types.type_key` by reduction, not a lookup table.
- **`tradingNames[]` lives in `extractions.supplier_match`**, along with the
  address, VAT number and company number the supplier call returns when it
  finds no match. The matching stage turns that into `entity_matches` rows;
  until then it is a record of what the model said, not a decision.
- The supplier prompt returns `cbId` **and** `paperlessId`, which is why
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
  `paperless_correspondent_id` survives — which is the only link back to the
  correspondent the sync then has to deal with.
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

## 21. Supplier ↔ Paperless correspondent sync

`App\Services\SupplierSync`. **Clear Books is the source of truth**; nothing
flows the other way. A correspondent invented in Paperless is left alone.

| Case | What happens |
|---|---|
| Active supplier, no link | An existing correspondent with the same **normalised** name is linked; otherwise one is created. Names are unique in Paperless and a duplicate splits a supplier's filing. |
| Linked, name differs | The correspondent is renamed. A name collision is logged and left for a person — merging two correspondents is not this job's decision. |
| Linked to a correspondent that no longer exists | The dead link is cleared and the supplier re-links on the same run. |
| Deactivated supplier with a link | Count, re-point, count again, then delete. Never otherwise. |

**The safety property is the order.** A correspondent with documents pointing at
it is never deleted. Each document is first sent to whichever supplier Clear
Books now considers correct:

1. the supplier the matching stage settled on for that document
   (`documents.correspondent_matched_supplier_id`), if it is still active and
   has a correspondent; failing that,
2. an unambiguous `matchByName()` of the retired supplier's name against the
   current list — which is how a delete-and-recreate rename resolves.

Anything else is **flagged**: a note on the Paperless document plus an
`audit_log` entry, and the correspondent stays indefinitely. An unfiled document
is a real loss to a person; a stale correspondent is untidiness.

- The note is marked `[InvoGrid]` and written **at most once** — the existing
  notes are checked first, or a nightly cron pastes the same sentence on for as
  long as the situation lasts. The `audit_log` entry is written on the same
  condition, for the same reason.
- The per-run summary entries (`clearbooks.cache_refresh`,
  `clearbooks.correspondent_sync`) are only written when something actually
  moved. `flagged` and `skipped` are standing states, not changes.
- `clearbooks_sync_correspondents` turns the whole thing off;
  `clearbooks_delete_correspondents` turns off deletion alone. Both default on,
  and **the never-delete-with-documents guard holds whatever they say.**

Audit actions this writes: `clearbooks.cache_refresh`,
`clearbooks.correspondent_sync`, `clearbooks.connect_started`,
`clearbooks.connected`, `clearbooks.disconnected`,
`paperless.correspondent_created`, `_linked`, `_renamed`, `_deleted`, `_kept`,
`_failed`, `paperless.document_repointed`, `paperless.document_flagged`.

## 22. The review screen — the rules

`/review` and `/review/{id}`, `App\Controllers\ReviewController`. Everything
before it is machinery; this is what a person uses the application for.

- **Every extracted value is an input.** A reviewer who can see that a date is
  wrong but can only accept or reject the document is worse off than one with no
  machine at all, because now they have to go somewhere else to fix it. The
  model's reading is a first draft.
- **The PDF is an `<object>` pointing at `/documents/{id}/pdf`.** Same origin,
  ordinary authenticated route, browser's own viewer. The arrangement this
  replaces had the file on another domain and shipped it base64-encoded inside
  JSON to get round that; nothing here needs to, because InvoGrid stores the
  file.
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
found by submitting a document: the note written into Paperless said
`£250.00 net` where it should have said `£300.00`, because the totals were the
ones from before the rate was known. `Extraction::refreshTotals()` is called
after any line change outside the form.

A typed-in net wins over the calculation. Somebody looking at the scan is the
better authority on a rounding settlement or a discount applied to the total.

---

## 23. Submission and write-back

`App\Services\SubmitStage` and `App\Services\PaperlessWriteBack`. The only
irreversible thing InvoGrid does.

### The order, and why it is the order

```
1. refuse if a successful submission already exists
2. build the payload and validate it          <- before any call
3. create the record in Clear Books
4. write `submissions`, move to `submitted`   <- the critical pair
5. attach the PDF
6. write back to Paperless
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

### What goes back to Paperless

Six writes, each attempted independently so one failure does not cost the
others. Nothing is guessed: every target id is nullable and an unset one means
the write is skipped and said so.

| What | From |
|---|---|
| `correspondent` | `clearbooks_cache.paperless_correspondent_id` for the matched supplier |
| `title` | `extractions.paperless_title` |
| `content` | InvoGrid's own transcription, **replacing Paperless's OCR** |
| `document_type` | `document_types.paperless_document_type_id` |
| `tags` | the existing tags plus `paperless_processed_tag_id` |
| `custom_fields` | each field's own `paperless_field_id`, merged not replaced |
| a note | Clear Books id, document number, supplier, total, their reference |

Replacing `content` is switchable (`paperless_replace_content`, on) because
overwriting somebody's search index is a decision an operator may make
differently. On a scanned invoice the LLM reading is better than Paperless's
engine and is the only version carrying the handwritten annotations — which is
exactly what somebody searching the archive is looking for.

`custom_fields` is **merged**: a PATCH replaces the whole list, so writing the
Clear Books id naively would wipe every field somebody had set by hand.

### Custom fields have two origins

`custom_fields.source` is `extracted` or `submission`, and the distinction is
not cosmetic. `CustomField::extracted()` feeds the extraction prompt;
`forSubmission()` is filled in by the write-back. Asking a vision model to find
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
`paperless_correspondent_id` does — `upsert()` writes only the columns it gets
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
- **A field is deactivated, never deleted.** Last month's extraction still has
  to resolve what it stored. Deactivating stops it being offered to the prompt
  and stops it being written back; nothing else changes.

The `source` split from Prompt 7 is shown as two sections: fields read off the
page, and the two the submission produces. The second group is never offered to
the extraction prompt, and the screen says why.

**Pairing with Paperless** happens on the same screen, via
`App\Services\PaperlessFields`. An existing Paperless field can be picked — its
type and, for a select, its choices are imported — or one can be created from
what is already typed in, so setting a field up does not mean opening the
Paperless admin in another tab. The creation is done **first**: a failure there
leaves nothing behind, where the other order would give an InvoGrid field that
silently never writes back.

A Paperless field already paired with another InvoGrid field is offered but
disabled. The write-back merges by Paperless field id, so two fields pointing at
one would overwrite each other on every document.

`longtext` is InvoGrid's own type and becomes a Paperless `string` when one is
created. That substitution lives in `PaperlessFields`, because it is a fact
about Paperless rather than about the field.

Select choices are stored as `[{id, label}]` — Paperless's own shape — so a
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
`paperless.custom_field_created`, `prompts.edited`, `prompts.activated`,
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

`/documents` filters on status, document type, correspondent, a date range and
free text. Free text reaches **into the extraction** — supplier name, invoice
number, title — not just the `documents` row, so a document whose Paperless
correspondent says "Acme" but whose invoice was actually read as "Totally
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

**The PDF download had no size limit.** `downloadOriginal()` checked the `%PDF-`
magic bytes and deleted a partial, but nothing capped the length — so a
misconfigured or hostile Paperless could stream until the volume filled, taking
down every other document, the page images and the log with it.

Fixed with `uploads.max_pdf_bytes` (100MB, `MAX_PDF_BYTES` in `.env`) passed
down to `Http::download()`. It aborts **mid-transfer** via a cURL progress
callback rather than measuring afterwards, because measuring afterwards is
measuring after the disk is full. `CURLOPT_MAXFILESIZE` would not do: it only
works when the far end sends a `Content-Length`, and a streamed response sends
none. Demonstrated against a server streaming an endless file: aborted
immediately, partial deleted, and a legible message.

**`openssl` was missing from the documented prerequisites**, and it is not
optional — it is what encrypts every stored API token. A deployment following
the old list would have had `Setting::put()` refusing to store credentials with
no obvious reason why.

### What held

- **CSRF**: all 24 POST forms carry `csrf_field()`; every non-GET route carries
  the middleware bar the webhook (not a browser form) and the Clear Books
  callback (a redirect with no token to carry, guarded by a `state` parameter).
- **Prepared statements**: no variable is interpolated into SQL anywhere. The
  only concatenation is `LIMIT`/`OFFSET`, every one int-cast and clamped, and
  the table alias in `filterClause()`, which is a literal.
- **Session cookies**: `httponly`, `samesite=Lax`, `secure` whenever the request
  is HTTPS or `FORCE_HTTPS` is on, `use_strict_mode`, and the id regenerated on
  sign-in and on password change.
- **Login throttling**: exercised live — four wrong passwords answered "not
  recognised", the fifth locked out for fifteen minutes. Counted per username
  *and* per address, at three times the limit for the address.
- **Webhook secret**: refused with 401 for no secret, a wrong secret, and a
  secret sharing a prefix. Compared with `hash_equals` on all three routes it
  can arrive by (header, query, body).
- **No secret reaches a browser**: templates read only non-secret settings, and
  every `Response::json()` passes a literal. Both are now assertions, because
  `Setting::get()` decrypts rather than returning ciphertext and so offers no
  type-level barrier of its own.

### Upload paths, precisely

There is exactly **one** browser upload path in the application: the logo, on
`/admin/branding`. PDFs are never uploaded — they are fetched from Paperless
over the API, which is why the size ceiling lives on the download rather than on
a form.

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
