# InvoGrid

**InvoGrid by Junction** — a self-hosted web application that turns scanned purchase
documents into Clear Books records.

A Paperless-ngx workflow tells InvoGrid a document has arrived. InvoGrid pulls the
PDF, renders each page to an image, transcribes it with a vision-capable LLM,
extracts the header, supplier and line items in several focused calls, matches
everything it can against the current Clear Books lists, and puts anything it is
not certain about in front of a human. Once a person has resolved the
uncertainties, the document is submitted to Clear Books and the result is written
back to Paperless.

Nothing is created in Clear Books automatically. Every entity that did not match
with full confidence is a decision a person makes, pre-filled from the extraction
and editable before it is sent.

It is a sibling of [Kitwell](https://github.com/maeterlinckle/kitwell) and shares
its visual language, but no code, database or dependency: InvoGrid is a
standalone application with its own copy of everything.

---

## Status

The pipeline is complete, end to end. A scanned document arrives from a
Paperless workflow, has its pages rendered and transcribed by a vision model,
is read into structured fields by three focused extraction calls, is matched
against cached Clear Books suppliers, account codes and VAT rates, and either
reaches **Ready to submit** on its own or stops at **Needs review** with the
specific reason attached. A person resolves what is left on the review screen —
editing anything the model got wrong, and creating a missing supplier in Clear
Books from a form they confirm — then submits it. The bill or credit note is
created in Clear Books with the PDF attached, and the Paperless document is
updated to match.

Custom fields and the prompts themselves are managed in the application:
**Settings → Custom fields** defines what to look for on a page and where the
value goes in Paperless, and **Settings → Prompts** edits what the models are
actually asked, versioned so any change can be undone. Neither needs a deploy.

**Settings → Users** creates and manages accounts. There is no sign-up page:
every account is made by an administrator, who sets a first password the account
is then made to replace before it can do anything else.

The dashboard shows the counts, what the machine tripped over, who did what, and
— the one nothing else reports — anything that has **stopped moving**: a
document that ran out of retries is not marked *failed* and appears in no count,
so without that list it simply rots. `/documents` filters by stage, type,
correspondent and date range, and its search reaches into what was read off the
page, not only what Paperless says.

When something does fail, the document page says which stage, which call, which
model, and what the far end actually answered — without a server log.

**Settings → Branding** takes the light-mode and dark-mode logos. They appear in
the header, on the sign-in page and at the top of a printed document summary,
scaled to fit without distortion; with none uploaded the header falls back to a
monogram. Any submitted document has a **printable summary** — what was read off
the page and what Clear Books did with it, on one sheet, on its own layout
rather than the ordinary page with its menus hidden.

What is left is the Settings screen (which would replace
`bin/console.php settings:set`) and the full activity log view. The navigation
shows those two destinations marked *soon* rather than linking to pages that do
not exist yet.

---

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer (developed against 8.4) |
| Extensions | `pdo_mysql`, `mbstring`, `openssl`, `curl`, `json`, `fileinfo` |
| Database | MariaDB 10.6+ (MySQL 8 also works; the DSN is `mysql:` either way) |
| Web server | Apache with `mod_rewrite`, or nginx — document root must be `public/` |
| PDF rendering | `pdftoppm` from **poppler-utils** |
| LLM access | An OpenAI **or** Anthropic API key, per pipeline stage |
| Clear Books | Application credentials for the v1 API, and a business to authorise against |
| Database privileges | `GET_LOCK` / `RELEASE_LOCK`, which the OAuth refresh serialises on |

There is no Composer, no `vendor/` directory and no build step. Every external
integration is a plain cURL call; there are no vendor SDKs to install.

### PDF page rendering

Each page of a source PDF is rendered to an image before it goes to the vision
model. InvoGrid shells out to `pdftoppm` to do it:

```bash
sudo apt install poppler-utils
```

Imagick with Ghostscript would do the same job and is deliberately **not** used:
one route, used consistently, beats a fallback chain nobody exercises. On
Windows, `winget install oschwartz10612.Poppler`.

If `pdftoppm` is not on `PATH` — or the shell was started before it was
installed — set `PDFTOPPM_PATH` in `.env` to its absolute path.

---

## Installing

```bash
git clone https://github.com/maeterlinckle/invogrid.git
cd invogrid
sudo ./install.sh
```

It asks a dozen questions and does the rest: packages, database and grant,
`.env` with a generated `APP_KEY`, file ownership, the Apache or nginx site,
the firewall, the migrations, the first administrator, the Paperless webhook
secret, and the cron entries. It is safe to run twice — an existing database is
left alone and its credentials refreshed, and **an existing `APP_KEY` is never
replaced**, because every stored credential is encrypted with it.

```bash
sudo ./install.sh --dry-run          # the plan, changing nothing (no root needed)
sudo ./install.sh --help             # every option
```

Unattended, for a rebuild or a second site:

```bash
sudo ./install.sh --answers=/root/invogrid.answers --non-interactive --cron
```

The answers file holds a database password and an administrator password. Create
it `chmod 600` and delete it afterwards — the installer says so at the end.

### What it will not do

Obtain a TLS certificate, or configure a reverse proxy. Both are site decisions
with better tools than a shell script. Tell it which of the three situations it
is in with `--tls=`:

| `--tls=` | Means |
|---|---|
| `proxy` | Something in front terminates TLS. Sets `TRUST_PROXY=true`, so `X-Forwarded-Proto` is honoured. |
| `direct-https` | This machine holds the certificate. Writes the vhost and the port-80 redirect. |
| `plain-http` | No TLS at all. Sets `FORCE_HTTPS=false`, and says plainly that passwords cross the network in the clear. |

---

## Managing it

`manage.sh` is the administration surface. The installer symlinks it, so after
an install this works from anywhere:

```bash
sudo invogrid status
sudo invogrid help
```

Anything that touches the database goes through `bin/console.php`, so it uses
the application's own models — the same prepared statements, the same
validation, the same guard rails. Changing a role with the database client would
walk straight past the rule that stops you stranding the site with no
administrator; going through the model means that rule holds on the command line
exactly as it does on the web.

### The ones worth knowing

```bash
sudo invogrid status            # services, versions, disk, the pipeline, cron
sudo invogrid doctor            # every check, each with what to do about it
sudo invogrid test              # the three verification harnesses
sudo invogrid queue             # run one pass of the worker by hand, verbosely
sudo invogrid refresh           # refresh the Clear Books cache now
sudo invogrid backup            # database + PDFs + .env, rotated
sudo invogrid update            # pull the latest version and migrate
```

`doctor` is the first thing to run on a server that is misbehaving. Every row
that is not `ok` carries a line saying what to do, because a check that reports
`FAIL: storage` and stops has told you nothing you did not already suspect. It
exits non-zero only on a failure — an install that has not been pointed at Clear
Books yet is incomplete, not broken, so that is a warning.

### Updating

```bash
sudo invogrid backup
sudo invogrid update
```

With no argument it clones the repository into a temp directory, copies the new
version over the install, re-applies permissions, runs the migrations and
reloads the web server. `.env`, `storage/` and the database are left alone.

Give it a directory instead when the machine cannot reach GitHub — which is the
normal case for a private repository on a server with no deploy key:

```bash
sudo invogrid update /path/to/new/version
```

### Backups

`backup` writes three files and **all three are needed**:

| | |
|---|---|
| `invogrid-*.sql.gz` | The database. |
| `files-*.tar.gz` | The source PDFs and the uploaded logos. |
| `env-*.bak` | `.env`, and therefore `APP_KEY`. |

Without the matching `APP_KEY` a restored database has credentials nobody can
read — every API token in it is an unreadable blob. Copy all three off the
machine; the nightly cron entry keeps the last fourteen sets locally, which is
protection against a mistake and not against the building burning down.

`storage/pages` is deliberately **not** backed up. A page image is re-rendered
from the PDF beside it in seconds, so including them would double the size of
every backup to save a step that costs nothing.

### The destructive ones

`reset-database` and `reset-storage` ask twice and **ignore `--yes`**, because
there is no undo and a scripted `--yes` is exactly how somebody empties the
wrong database. `reset-database` also makes you type the database name.

Neither can withdraw anything from Clear Books. What is submitted is submitted;
what is lost is InvoGrid's record of it — including which Paperless documents
have already been processed, so anything a workflow re-sends would be read and
submitted a second time.

---

## Installing by hand

What `install.sh` does, in case you would rather do it yourself — or need
to know what it did.

```bash
git clone https://github.com/maeterlinckle/invogrid.git
cd invogrid
cp .env.example .env
```

### 1. Create the database


```bash
sudo mariadb -e "CREATE DATABASE invogrid CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
sudo mariadb -e "CREATE USER 'invogrid'@'localhost' IDENTIFIED BY 'a-strong-password';"
sudo mariadb -e "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, ALTER, INDEX, REFERENCES ON invogrid.* TO 'invogrid'@'localhost'; FLUSH PRIVILEGES;"
```

Put the same credentials in `.env` under `DB_DATABASE`, `DB_USERNAME` and
`DB_PASSWORD`.

### 2. Generate the application key

```bash
php bin/console.php key:generate
```

Copy the printed line into `.env` as `APP_KEY`.

This key encrypts every credential stored in the database — the Paperless token,
the Clear Books OAuth2 secret and tokens, the LLM API keys, the webhook secret —
and binds session fingerprints. **Back it up with the database, not instead of
it**: a database restored onto a machine without the matching key has secrets
that cannot be read, and they would all have to be re-entered. Without a key set,
InvoGrid refuses to save a secret at all rather than storing it in the clear.

### 3. Set `APP_URL` and the rest of `.env`

At minimum: `APP_URL`, the database credentials and `APP_KEY`. The integration
credentials can be filled in here or, more usually, entered in Settings once the
application is running — see [Configuration](#configuration).

### 4. Run the migrations

```bash
php bin/migrate.php
```

`php bin/migrate.php --status` lists what has and has not been applied.

### 5. Create the first administrator

```bash
php bin/create-admin.php
```

It asks for a username and a password (at least 12 characters, using three of:
lower case, upper case, numbers, symbols). **The first account created is always
an administrator**, because there would otherwise be nobody able to configure the
integrations. Later accounts default to `reviewer` unless `--role` says
otherwise:

```bash
php bin/create-admin.php --username=jo --name="Jo Bloggs" --role=reviewer
```

Running it again for an existing username offers to reset that account's
password. There is no self-service password reset in the application: InvoGrid
sends no email of its own.

### 6. Check it over

```bash
php bin/console.php db:check
php bin/console.php settings:list
php tests/smoke.php
```

`tests/smoke.php` runs 281 plain assertions: config loading, the `APP_KEY`
encryption round trip, the validator, the pipeline state machine's internal
consistency, company-name normalisation, the totals arithmetic, the amount-sign
rules, provider selection, the template helpers and the route table. It also
renders a generated two-page PDF through `pdftoppm` and checks the result is the
expected size — then, against the database, that every ENUM still matches the
constants in PHP, that a secret setting really is stored as ciphertext, that the
seeded prompts still carry their field rules, and that a supplier's recorded
settings survive a cache refresh.

The database half is skipped, loudly, when there is no database to talk to, and
so is the rendering half when poppler is not installed. It exits non-zero on
failure, so it is safe in a deploy hook.

---

## Development

```bash
php -S 127.0.0.1:8484 -t public bin/serve.php
```

`bin/serve.php` is a router for PHP's built-in server, which has no rewrite
rules of its own; it is not used in production. Set `FORCE_HTTPS=false` and
`APP_DEBUG=true` in `.env` first — otherwise every request is redirected to an
`https://` URL nothing is listening on.

---

## Web server

The document root **must** be `public/`. Everything else — the source, `.env`,
`storage/` with its downloaded PDFs — sits above it and is not web-reachable.

### Apache

`public/.htaccess` is already in place: it redirects HTTP to HTTPS, sends
non-file requests to the front controller and refuses dotfiles. `mod_rewrite` and
`AllowOverride All` are needed.

```apache
<VirtualHost *:443>
    ServerName invogrid.example.com
    DocumentRoot /var/www/invogrid/public

    <Directory /var/www/invogrid/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### nginx

```nginx
server {
    listen 443 ssl http2;
    server_name invogrid.example.com;
    root /var/www/invogrid/public;
    index index.php;

    client_max_body_size 32m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    # Never serve dotfiles.
    location ~ /\. { deny all; }
}
```

### Permissions

The web server user needs to write to `storage/`:

```bash
sudo chown -R www-data:www-data storage
sudo chmod -R 775 storage
```

`.env` should be readable by the web server user and nobody else:

```bash
sudo chown root:www-data .env && sudo chmod 640 .env
```

---

## Configuration

Configuration lives in two places, and the split is deliberate.

**`.env`** holds what a server needs before it can read the database at all:
`APP_URL`, `APP_KEY`, the database credentials, session and HTTPS behaviour, and
the storage path. It also carries an optional fallback for each integration
credential, for a site that would rather keep secrets out of the database
entirely.

**The `settings` table**, edited from Settings in the application, holds
everything an administrator changes day to day: the Paperless address and token,
the Clear Books OAuth2 credentials and business id, the OpenAI and Anthropic API
keys, the webhook shared secret, and which provider and model each pipeline stage
uses.

Where both exist, **a non-empty setting wins** and the `.env` value is the
fallback. Secrets are stored encrypted under `APP_KEY` and are never rendered
back to a browser — the Settings screen shows whether a credential is set, never
what it is.

`INVOGRID_WEBHOOK_SECRET` (or the `paperless_webhook_secret` setting) is what the
Paperless workflow must present to the webhook receiver. Anything without it is
rejected.

Two settings have no `.env` fallback and govern how far the correspondent sync
may go: `clearbooks_sync_correspondents` turns it off entirely, and
`clearbooks_delete_correspondents` turns off deletion alone. Both default on.

Three more govern the submission: `paperless_processed_tag_id` (the tag put on a
document once it reaches Clear Books; empty means do not tag),
`paperless_replace_content` (whether InvoGrid's transcription replaces
Paperless's own OCR text, on) and `clearbooks_attach_pdf` (whether the scan is
attached to the created record, on).

---

## Paperless setup

InvoGrid is told about a document by a Paperless **workflow** with a single
webhook action. Everything else — the metadata, the PDF — InvoGrid fetches from
the API itself, because a webhook body is only ever what somebody typed into a
form and cannot be trusted to be complete or current.

### 1. Generate the shared secret

```bash
php bin/console.php secret:generate
php bin/console.php settings:set paperless_webhook_secret
```

The second command reads the value from standard input, so it never reaches your
shell history. Keep a copy — you are about to paste it into Paperless.

### 2. Create the workflow

In Paperless: **Manage → Workflows → Create**.

**Trigger type: `Document Added`.**

Not `Consumption Started`. This matters and is not a matter of taste: the
document row does not exist yet at consumption time, so Paperless renders
`{{doc_id}}` to an **empty string** — the placeholder is populated from
`document.pk`, and there is no document to have a pk. A workflow on that trigger
delivers a webhook with no id in it, which InvoGrid rejects with a 400 saying so.
`Document Updated` also works if you want InvoGrid re-notified on later edits.

Under **Filters**, scope it to whichever sources are actually purchase paperwork
— typically Consume Folder, API Upload and Mail Fetch — and, if invoices are
tagged on the way in, to that tag. A workflow that fires on everything means
InvoGrid registering delivery notes and payslips and somebody having to mark them
ignored.

### 3. Add the webhook action

| Field | Value |
|---|---|
| Action type | `Webhook` |
| URL | `https://invogrid.example.com/webhook/paperless` |
| Use parameters | **on** |
| Parameters | key `doc_id`, value `{{doc_id}}` |
| Send as JSON (`as_json`) | **on** |
| Headers | key `X-InvoGrid-Token`, value the shared secret |
| Include document | **off** |

**Use the final HTTPS URL.** Paperless posts with `follow_redirects=False`, so if
`FORCE_HTTPS` bounces an `http://` webhook to `https://`, the delivery simply
fails — and it fails looking like a network problem rather than a configuration
one.

*Include document* stays off deliberately: it would attach the file to every
webhook, and InvoGrid downloads the original from the API a moment later anyway.
Sending it twice buys nothing and makes the five-second budget tighter.

The secret may instead be sent as a `token` parameter or in the query string;
the header is simply the tidiest. `Authorization: Bearer <secret>` works too.

### 4. Confirm what actually arrives

Placeholder names change between Paperless versions, and the surest check is one
real delivery. Every request to the receiver — accepted or rejected — is
appended to:

```
storage/logs/webhook.log
```

with the content type, whether a secret header was present, and the body. The
secret itself is redacted. Trigger the workflow on a test document, read one
line, and you know exactly what your Paperless sends.

The receiver is deliberately liberal about where it looks: `doc_id`,
`document_id`, `id`, `pk`, `documentId` or `docId`, in a JSON body, a
form-encoded body, the query string, or a body containing nothing but the number.
`doc_id` is what the table above configures and what Paperless's own placeholder
is called.

### What the receiver does, and why it is so small

Paperless allows a webhook **five seconds** (`httpx` client timeout) and
**retries a non-2xx up to three times** with backoff. So the endpoint checks the
secret, writes one row, queues one job and returns — anything slower would time
out, and a timed-out webhook is a retried webhook.

Re-delivery is therefore normal rather than exceptional, and is handled by the
unique index on `paperless_doc_id`: the second delivery finds the document
already registered, answers `200 already-known`, and does not re-run anything. A
document already past `received` is never restarted by a webhook — it may
already have decisions on it that a human made.

| Response | Meaning |
|---|---|
| `202` | Registered and queued |
| `200` | Already known; nothing done |
| `400` | No usable document id — check the trigger type |
| `401` | Missing or wrong shared secret |
| `503` | No shared secret configured in InvoGrid yet |

### If deliveries never arrive

Paperless refuses to post to private addresses when
`PAPERLESS_WEBHOOKS_ALLOW_INTERNAL_REQUESTS` is `false` (it defaults to `true`).
`PAPERLESS_WEBHOOKS_ALLOWED_SCHEMES` and `PAPERLESS_WEBHOOKS_ALLOWED_PORTS`
can also block a URL before it is ever sent. A blocked webhook is logged on the
Paperless side, not this one, so an empty `webhook.log` points at that end.

---

## The queue

The webhook registers a document; a queue worker does the work. Run it from
cron, once a minute:

```
* * * * * www-data /usr/bin/php /var/www/invogrid/bin/process-queue.php >/dev/null 2>&1
```

```bash
php bin/process-queue.php --status     what is waiting, without doing any of it
php bin/process-queue.php --verbose    say what happened to each job
php bin/process-queue.php --limit=20   how many jobs one run may take
```

### Why cron and not a daemon

A long-running worker is the obvious design and is the wrong one here.

- **Nothing to supervise.** A daemon needs a systemd unit, a restart policy and
  somebody to notice when it has quietly stopped. The failure mode of a stopped
  daemon is a queue that silently stops moving. The failure mode of a missed
  cron tick is a document that is a minute late.
- **Nothing to restart on deploy.** Updating the code updates the next run. No
  step in the deployment can be forgotten, because there is no step.
- **Crashes are cheap.** A PHP process that dies takes one job with it; the job
  is released as stalled and picked up again. There is no accumulated state to
  lose and no memory to leak over weeks.
- **It is fast enough.** This is a handful of documents a day arriving from a
  scanner, not a stream. A minute of latency on a purchase invoice is not
  latency anybody can perceive, and stages chain within a single run rather than
  waiting a tick each.

The costs are real but small: up to a minute before a document is picked up, and
a PHP process started every minute doing nothing most of the time.

Overlapping runs are prevented by a lock file — an LLM call will sometimes take
longer than a minute, and two workers draining the same backlog would double the
API spend. Job claiming is independently safe (`SELECT … FOR UPDATE SKIP
LOCKED`), so the lock is about not piling up processes rather than about
correctness.

Failures back off — 1, 2, 4, 8 minutes — for up to four attempts, and a failure
that will never come right on its own (a document deleted in Paperless) is not
retried at all. After that the document sits in `Failed` with the reason on it,
and a person retries it from `/documents` once whatever broke is fixed.

### The other cron job: refreshing the Clear Books lists

Matching reads a **local cache** of the Clear Books suppliers, account codes,
VAT treatments and VAT rates — never Clear Books itself, mid-pipeline. That
cache has to be refilled, or a supplier added this morning is unmatchable this
afternoon.

```
17 * * * * www-data /usr/bin/php /var/www/invogrid/bin/refresh-clearbooks.php >/dev/null 2>&1
```

Hourly is ample; the lists change when somebody adds a supplier. The odd minute
is so it does not collide with everything else on the hour.

```bash
php bin/refresh-clearbooks.php --status     what is cached now, without fetching
php bin/refresh-clearbooks.php --dry-run    say what would change, change nothing
php bin/refresh-clearbooks.php --sync       also sync suppliers to Paperless correspondents
```

Both jobs are safe to run by hand at any time, and both take a lock so a slow
run and the next tick cannot overlap.

There is also a **"Refresh now"** button on *Settings → Clear Books* for the
case where somebody has just added the supplier they are standing in front of.

---

## The language models

InvoGrid makes two kinds of model call and lets you choose a provider for each,
because transcription and structured extraction have different strengths and
different costs.

| Setting | What it picks | Default |
|---|---|---|
| `llm_ocr_provider` | Who transcribes the page images | `anthropic` |
| `llm_ocr_model` | Which model | a current vision-capable one |
| `llm_extraction_provider` | Who reads the fields out of the transcription | `anthropic` |
| `llm_extraction_model` | Which model | as above |

Set them, and the corresponding key, from the command line:

```bash
php bin/console.php settings:set llm_ocr_provider anthropic
php bin/console.php settings:set llm_ocr_model claude-opus-5
php bin/console.php settings:set anthropic_api_key sk-ant-…
```

Or put them in `.env` instead — `ANTHROPIC_API_KEY`, `OPENAI_API_KEY` — which is
the right choice if you would rather keep secrets out of the database entirely.
A non-empty setting wins; `.env` is the fallback. Either way **the key is only
ever used from PHP**: the browser never talks to a model provider, and no key is
rendered into any page.

Only the provider you have actually chosen is complained about on the dashboard.
An OpenAI key is not "missing" on a site that has chosen Anthropic for both
stages.

### If you use a gateway

`anthropic_base_url` and `openai_base_url` point the client somewhere else — a
proxy, a gateway, an internal LLM service that speaks the same wire format. Set
the origin only; InvoGrid appends the path, so you do not have to know that one
provider's is `/v1/messages` and the other's is `/v1/chat/completions`.

---

---


## Reading a document

Once a document's PDF is stored, one stage renders it and reads it.

### Rendering

Every page goes to `pdftoppm` at **200 DPI**, which puts an A4 page at
1653 × 2339. That is inside the **2576-pixel long edge** current vision models
accept without downscaling, so the detail InvoGrid pays to send is detail the
model actually sees — and it is enough to read a biro annotation, which 150 DPI
is marginal for. A page that still comes out over the cap (a landscape scan, an
unusual page size) is re-rendered at the cap, and only that page.

JPEG at quality 90 rather than the default 75: the annotations being read are
thin pen strokes, and JPEG ringing around a thin red line on white is exactly
the artefact that turns a 3 into an 8.

All three are settings — `pdf_render_dpi`, `pdf_max_edge_px`, `pdf_render_format` —
because a site sending a model with a smaller image limit will want to lower
them.

### The model call

`App\Services\Llm` is the only part of the application that talks to a provider.
Everything else asks for "whatever runs the OCR stage" and gets an `LlmClient`.
Adding a third provider means one new class and one entry in `LlmFactory`; it
does not touch the pipeline.

Provider and model are chosen **per stage**, not globally: transcription and
structured extraction have different strengths and different costs.

| Setting | Default |
|---|---|
| `llm_ocr_provider` | `anthropic` |
| `llm_ocr_model` | `claude-opus-5` |
| `llm_extraction_provider` | `anthropic` |
| `llm_extraction_model` | `claude-opus-5` |

`anthropic_base_url` and `openai_base_url` are normally empty, meaning "go
straight to the provider". Set one to a gateway origin — the provider's path is
appended for you — if outbound API traffic has to be proxied.

Both clients turn a provider failure into a judgement the pipeline can act on:
a 429 or a 5xx is retryable and gets backed off, while a rejected key, an
unknown model or a refused request stops the document rather than being retried
every minute to no purpose. A transcription cut off at the token limit is
treated as a **failure**, not a short answer — half a document read as a whole
one is how confidently wrong totals get into the accounts.

### The prompt

The OCR prompt is a row in `prompt_templates`, not a string in the source.
Editing it writes a new version and deactivates the old one, so a change that
makes things worse is one click to revert and every `ocr_results` row can say
which version produced it.

It asks for a clean transcription in `ocrText` — no correcting, no normalising,
numbers exactly as printed, page boundaries marked — with a `### Notes` section
appended **inside** that string, so later stages can find the annotations by
reading the text alone. Alongside it come `handwrittenAnnotations[]`, a
best-guess `clearBooksNumber` and `projectCode`, and a `reviewNotes` list.

Two field rules matter more than the rest:

- A **Clear Books Number** is digits only, almost always in red pen, usually but
  not always preceded by `#`, occasionally circled. **A printed number is never
  substituted for a missing one** — not the supplier's invoice number, not a PO
  number, not an account reference. Null is a correct answer here; a wrong
  number is far worse than none.
- A **project code** is normally two letters and two digits, occasionally up to
  four letters, and may be handwritten, printed-and-circled, or plain printed
  text. Where there are several candidates the handwritten or circled one wins,
  and the ambiguity gets a review note.

> **Not yet reconciled with production.** This prompt implements the specified
> behaviour but is not a verbatim copy of the one running in the existing n8n
> flow, which was not available when it was written. Diff the two and keep
> whichever phrasing production has earned.

---

## Extracting the fields

A document that has been read goes to **three focused calls**, not one — the
split the n8n flow arrived at, and it holds up: each prompt is short enough to
reason about, and a model confused about VAT treatment does not therefore get
the invoice date wrong.

| Prompt key | What it decides |
|---|---|
| `extract_header` | Title, Clear Books description, invoice/due/paid dates, reference, currency |
| `extract_supplier` | Who issued it, and whether that matches a cached Clear Books supplier |
| `extract_lines` | Bill or credit note, VAT treatment, and each line with its account code and VAT rate |
| `extract_custom_fields` | Only the custom fields the fast path could not resolve |

All of them read the stored transcription. **None sees the page images again** —
that reading has been done and paid for, and sending the pages to three more
calls would triple the cost of every document for nothing.

### Prompt variables

Prompts are rows in `prompt_templates`, edited in the application. They
interpolate with `{{ name }}` placeholders:

| Variable | Is |
|---|---|
| `{{ ocrText }}` | The transcription, `### Notes` section included |
| `{{ today }}` | Today, as `YYYY-MM-DD` |
| `{{ suppliers }}` | Cached Clear Books suppliers, with both their Clear Books and Paperless ids |
| `{{ accountCodes }}` | Cached purchase account codes |
| `{{ vatRates }}` | Cached VAT rates, with their percentages |
| `{{ vatTreatments }}` | Cached VAT treatments |
| `{{ customFields }}` | The configured custom fields, with their hints |

A placeholder is a **name only** — it cannot run code, unlike the n8n
expressions it replaces. A name nothing provides is an **error at render time**,
before any request is made: a prompt that quietly posts the literal text
`{{ suppliers }}` gets a confident answer built on nothing, and the only symptom
is bad data much later.

Every prompt is offered every variable and takes what it names, so adding
`{{ accountCodes }}` to the header prompt is an edit, not a code change.

### Custom fields, cheapest route first

1. **From the OCR call**, which already reported the annotation fields it was
   asked for. `clearbooks_number` and `clearbooksNumber` are the same name once
   case and punctuation stop counting, so an operator can name a field however
   reads best.
2. **From the `### Notes` section**, which states them by label.
3. **A fourth call**, asking only about what is still unresolved.

On an ordinary document steps 1 and 2 answer everything and there is no fourth
call at all.

### Failing cleanly

Every call must return the JSON its prompt specified. A reply that will not
parse, or that is missing a key the prompt named, **stops the whole stage** —
it is never coerced into whatever fields happen to be present. The document
lands in `Failed` with a readable reason and **nothing is stored**.

That is deliberate. A document with two thirds of its fields and a silent gap in
the middle is worse than one that plainly stopped: the gap gets noticed after
somebody has already approved the rest.

Malformed output is **not** retried, unlike a rate limit. Four more identical
calls cost money and would very likely fail the same way; a person retries it
from the document page once the prompt or the scan is sorted out.

### What gets stored

One `extractions` row per run, merging all three calls, with the `reviewNotes`
from each concatenated and prefixed by which call raised them. `needs_review` is
set whenever that list is non-empty.

An unmatched supplier is deliberately **not** flagged here, though it was before
the matching stage existed. This stage only ever consults the cached list, and
the deterministic name pass that runs next resolves a good proportion of what it
leaves open. A note raised here would then be false, and would hold the document
in review for good — nothing later has the standing to withdraw an earlier
stage's judgement. The supplier's `entity_matches` row is the record instead.

Net is the sum of the line totals exactly as extracted. **No sign is applied
here for any document type**: which way the amounts go is a fact about the Clear
Books API rather than about reading a page, and it belongs with the submission —
`document_types.amount_sign` is where that decision lives, and it is not the
same answer for a credit note as for a refund. VAT and gross need each line's rate as a percentage
from the cached Clear Books rates; where any line's rate is unknown they are
left unset rather than guessed, because a wrong VAT figure is worse than none.

---

## Clear Books

Everything InvoGrid matches a document against — suppliers, purchase account
codes, VAT treatments and VAT rates — is read from a **local cache** of the
Clear Books lists, never from Clear Books mid-pipeline. Three extraction calls
per document, each needing the whole supplier list injected into its prompt,
would mean thousands of API calls against a service that starts throttling above
five a second, and would make every document wait on somebody else's uptime.

Everything below was read out of the published OpenAPI specification at
`https://api.clearbooks.co.uk/spec/v1.yaml`, not inferred.

### Connecting

Clear Books uses OAuth 2 with the **authorisation code** grant and confidential
clients only. There is no client-credentials or password grant: a person signs
in once, and the application then keeps itself signed in on refresh tokens.

1. Ask Clear Books for application credentials
   (<https://www.clearbooks.co.uk/support/api/>), giving them the redirect URI
   shown on **Settings → Clear Books**.
2. Set the credentials:

   ```
   php bin/console.php settings:set clearbooks_client_id
   php bin/console.php settings:set clearbooks_client_secret
   php bin/console.php settings:set clearbooks_business_id
   ```

3. Open **Settings → Clear Books** and press *Connect to Clear Books*.
4. Press *Refresh from Clear Books* to fill the cache.

InvoGrid asks for six scopes and no more — read access to the lists it caches,
write access to the two things it creates:

```
accounting.suppliers:read      accounting.suppliers:write
accounting.account_codes:read  accounting.purchases:read
accounting.vat:read            accounting.purchases:write
```

Nothing for sales, payments, journals or bank feeds. `tests/smoke.php` asserts
that list, so a scope added by accident fails a test rather than quietly
granting an integration the run of the ledger.

**Refresh tokens are single use.** Spending one issues a new pair and kills the
old, so two processes refreshing at the same moment lock the integration out
until somebody signs in again. The refresh therefore runs under a named database
lock (`GET_LOCK`) and re-reads the settings after taking it — the process that
waited usually finds the job already done and spends nothing. Completing the
consent flow a second time also revokes whatever this instance was holding, so
"just reconnect" is not free.

### Keeping the cache filled

```
0 0,12 * * *  www-data /usr/bin/php /var/www/invogrid/bin/refresh-clearbooks.php >/dev/null 2>&1
30 3 * * *    www-data /usr/bin/php /var/www/invogrid/bin/refresh-clearbooks.php --sync >/dev/null 2>&1
```

```
php bin/refresh-clearbooks.php                    refresh the cache
php bin/refresh-clearbooks.php --status           what is cached, without fetching
php bin/refresh-clearbooks.php --sync             refresh, then sync correspondents
php bin/refresh-clearbooks.php --sync --dry-run   say what the sync would do
```

Account codes are narrowed to the ones marked `purchases`: a sales-only code
offered to the extraction prompt is a wrong answer waiting to be picked. VAT
rates are fetched **once per treatment**, because which rates are legal depends
on the treatment.

Anything the refresh does not see is **deactivated, not deleted** — a document
already matched against a supplier keeps a resolvable record, and the row's
`paperless_correspondent_id` survives, which is the only link back to the
correspondent that then has to be dealt with. An *archived* supplier takes
exactly the same path as a deleted one; archiving is how a supplier is retired
in practice. A refresh that returns nothing at all deactivates nothing: that is
a failed fetch, not a business that deleted every supplier.

**There is no projects endpoint and no project scope.** Clear Books' project
codes are not reachable from this API, which is the reason a submitted document
offers an "Open in Clear Books" link for a person to set one by hand.

### Matching

The matching was largely done a stage earlier: the supplier call was handed the
cached supplier list and reported whether it found one, and the line-items call
chose an account code and a VAT rate per line from lists it was given. The
matching stage is not a second opinion on any of that. It does three things the
model cannot:

1. **Checks the ids are real.** An id a model returns is a claim. Every one is
   looked up in the current cache — a guess that does not survive that is worth
   less than no guess at all, because it is the one kind of error that reaches
   Clear Books looking correct.
2. **Runs the deterministic fallback** for a supplier the model could not place.
   Case, punctuation, `&`/`and` and legal suffixes (Ltd, Limited, PLC, LLP, Inc,
   Corp, Co) stop counting, on both sides, and trading names are tried as well as
   the legal name. That catches "ACME SUPPLIES LTD." against "Acme Supplies
   Limited", which is most of what a model is not needed for.
3. **Writes the outcome down per entity**, in `entity_matches`, so the review
   screen can say *which* thing is unresolved rather than "this needs review".

A second, looser pass removes word boundaries as well — settling "Clearbooks"
against "Clear Books" — and is recorded at a lower confidence. **An ambiguous
name resolves to nothing**: if two suppliers on file reduce to the same key,
picking one is a coin toss and the cost of losing it is a bill posted against
the wrong supplier.

A document reaches **Ready to submit** only when every required entity resolved
*and* the extraction flagged nothing. Anything else reaches **Needs review**
with the reason attached. Nothing is ever auto-created in Clear Books.

A row a person resolved by hand (`matched_via = manual`) **survives a
re-match**; everything automatic is rebuilt from scratch, because a stale
automatic guess is worse than none.

### Suppliers and Paperless correspondents

Clear Books is the source of truth. A supplier there is a correspondent here.
Nothing flows the other way — a correspondent invented in Paperless is left
alone, because that is somebody filing a document, not somebody deciding the
chart of suppliers.

| In Clear Books | In Paperless |
|---|---|
| New supplier | An existing correspondent of that name is **linked** if there is one; otherwise a correspondent is created. Names are unique in Paperless, and a duplicate splits a supplier's filing in two. |
| Renamed supplier | The correspondent is renamed. If another correspondent already holds that name the failure is logged and the two are left for a person — merging them silently is not this job's decision. |
| Supplier gone or archived | See below. |

Removal is the one that needs care, and the rule is absolute: **a correspondent
with documents pointing at it is never deleted.** The order is count, re-point,
count again, and only then delete. Each document is first sent to whichever
supplier Clear Books now considers correct —

- the supplier the matching stage settled on for that document, if it is still
  current and has a correspondent; failing that,
- an unambiguous name match of the retired supplier against the current list,
  which is how a delete-and-recreate rename resolves.

Anything else is **flagged** — a note on the Paperless document and an entry in
the activity log — and the correspondent stays, indefinitely if need be. An
unfiled document is a real loss to a person; a stale correspondent is
untidiness. The note is written at most once, so a nightly cron does not paste
the same sentence on for as long as the situation lasts.

Every create, rename, delete, re-point and flag goes to `audit_log`, because
this is the one part of InvoGrid that changes somebody else's system without a
person pressing anything. Run `--sync --dry-run` once before the first real run.

Two settings govern it: `clearbooks_sync_correspondents` turns the whole thing
off, and `clearbooks_delete_correspondents` turns off deletion alone for an
operator who would rather tidy up by hand. Both default on. The guard above
holds whatever they say.

---

## The review queue

`/review` is what a person uses this application for. Everything before it is
machinery.

The list shows documents needing a decision **and** documents ready to submit,
together: they are two halves of one job, and somebody who has just resolved a
document should not have to go and find it somewhere else to finish it. Each row
carries the supplier, type, amount, date and — the number that actually
matters — how many things are unresolved. A document with one unmatched supplier
is a minute's work; one with six unmatched account codes is not, and a queue
showing only a status makes you open both to find out.

### The detail screen

The scan on the left, the record on the right. The PDF is an ordinary
authenticated route on this same origin (`/documents/{id}/pdf`) rendered in an
`<object>`, so the browser's own viewer does the work.

**Every extracted value is an input** — title, description, type, reference,
supplier label, all three dates, every line item's description, quantity, unit
price, net, account code and VAT rate, the VAT treatment, all three totals, the
currency, and each custom field. A reviewer who can see that a date is wrong but
can only accept or reject the document is worse off than one with no machine at
all, because now they have to go somewhere else to fix it.

Clearing a line's description and amounts drops it — a scan that read the
remittance slip as a line item is the usual reason. A blank row at the bottom
adds one. The totals recalculate from the lines unless you type one in yourself,
in which case yours wins: somebody looking at the scan is the better authority
on a rounding settlement or a discount applied to the total rather than to a
line.

Saving re-runs the match immediately, so a corrected account code turns green
now rather than at the next cron tick.

### Resolving what could not be matched

Each unresolved entity gets its own card saying what was read off the document
and why it did not match. Two ways out:

| | |
|---|---|
| **Use one already on file** | Offered for every entity type, and first, because it is the common case: most unmatched suppliers *are* in Clear Books, under a name the matching could not see. |
| **Create in Clear Books** | Suppliers only. A form pre-filled from the extraction, which you check and edit before confirming. |

**Nothing is created automatically, ever.** `EntityCreator` is the only class
that creates anything in Clear Books, every entry point is a POST from this
screen, and there is no scheduled caller. What gets created is what is in the
boxes when the button is pressed — the pre-fill is a convenience, not the
decision.

Creating a supplier also creates its Paperless correspondent and records the
link, exactly as the nightly sync would — looking for an existing correspondent
of that name first, because Paperless names are unique and a duplicate splits a
supplier's filing in two. If Paperless is unreachable the supplier is still
created and the sync picks the correspondent up later; throwing there would
discard a successful creation over a filing detail.

Account codes, VAT rates and VAT treatments are **picked, not created**. VAT
rates and treatments have no POST endpoint in the Clear Books API at all. An
account code does, but needs a `heading` a reviewer has no basis to choose, and
a nominal code belongs to the chart of accounts rather than to an invoice. Both
say so on screen.

### Skipping a document

A duplicate, a statement rather than an invoice, a delivery note that came in on
the same scan run. **The reason is required**, checked on the server, and goes
to the activity log. Six months later somebody will ask why this one was
skipped, and a name and a timestamp will not answer them.

---

## Submitting to Clear Books

The only irreversible thing InvoGrid does, and the only step that is **not** a
pipeline stage. There is no `submit` in `Pipeline::STAGES` and there should not
be: nothing should be able to put a bill into somebody's accounts because a cron
job fired.

### What happens, in order

1. Refuse outright if this document already has a successful submission.
2. Build the payload from the **current** extraction values — the reviewer's, not
   the model's — and check it is complete. Before any call.
3. Create the bill or purchase credit note in Clear Books.
4. Write the `submissions` row and move the document to `submitted`.
5. Attach the source PDF to the created record.
6. Write back to Paperless.

Four comes before five and six deliberately. A crash between three and four
would leave a bill in the accounts that InvoGrid thinks it never sent, and the
next person to press submit would create a second one. A crash after four leaves
a document correctly marked submitted with an attachment missing — visible,
harmless and fixable. Of the two failure modes only one costs somebody a payment
run.

For the same reason steps five and six **do not fail the submission**. They
return warnings, shown on screen and written to the activity log. Refusing to
record a submission because a tag could not be written would be choosing the
worse failure on purpose.

A rejection by Clear Books is recorded too, with the payload that was sent —
that is exactly what somebody debugging one needs, and a failure leaving no
trace is what the table exists to prevent.

### Bills, credit notes and refunds

Three types, and the sign rule is Clear Books' rather than anything InvoGrid
decided:

```
bill             purchases/bills        +1   money spent; carries dateDue
credit_note      purchases/creditNotes  +1   an amount to set against an invoice
purchase_refund  purchases/bills        -1   money that actually came back
```

A **purchase document** is positive when it represents money spent and negative
when it represents money refunded — either way something actually moved. A
**credit note** is different in kind: it is an amount available to set against
an existing invoice, no money has moved, and Clear Books takes it **positive at
creation and inverts it internally**. Sending a credit note negative inverts an
inversion and puts the amount back where it started.

`dateDue` goes only on a document that is a bill *and* positive. It does not
exist on a credit note at all (sending it is a 400), and a refund has no due
date to speak of — the money has already moved, and a due date would show in
Clear Books as an outstanding payable nobody owes.

### Telling a credit note from a refund

This is the hard part, and it is why both types stop and ask.

The distinction is often not on the page. A document headed "Credit Note" that
goes on to describe a refund payment actually made **is a refund**; the title is
the weaker signal and what happened to the money is the stronger one. And
frequently neither is written down, because the arrangement was agreed on the
telephone.

So:

- The extraction prompt is told the difference, told the trap, and told to give
  its best answer — never to invent certainty. It must also return **one
  sentence saying what decided it**, quoting the wording where there is any
  (`extractions.doc_type_reason`). A reviewer given a conclusion and no evidence
  has to read the whole document again; given *"says 'refunded to card ending
  4412 on 14 August'"* they decide in seconds.
- `document_types.requires_confirmation` is set for both, and **a document of
  either kind cannot reach Ready to submit until a person agrees which it is.**
  The review screen puts the question at the top with the likeliest answer
  pre-selected; `SubmitStage` refuses one that has not been agreed, because a
  hidden button is not a guarantee.
- Agreement is recorded separately from an edit
  (`extractions.doc_type_confirmed_by`). Correcting a due date is not agreeing
  to a classification, and **changing the type in the ordinary Type box
  withdraws the agreement** rather than counting as one.
- **The model's guess is never pre-selected.** The box starts empty and will not
  submit until somebody chooses. Confirming a guess with one click on an
  already-filled form is the wave-through this step exists to prevent. The guess
  and its reasoning sit right beside the choice — what is withheld is the
  pre-filled answer, not the information.
- **A supplier default is pre-selected**, because that is established knowledge
  somebody recorded on purpose rather than a per-document guess, and
  re-answering a settled question every month is how the step decays into a
  habit. Set it either by ticking *remember this as the usual route* while
  reviewing, or on **Settings → Clear Books**, where somebody who already knows
  the pattern can write it down without waiting for a document
  (`clearbooks_cache.default_credit_route`).

### What goes back to Paperless

Six writes, each attempted independently so one failure does not cost the
others:

| What | Where the value comes from |
|---|---|
| Correspondent | the matched supplier's `paperless_correspondent_id` |
| Title | the extraction's generated title |
| Content | **InvoGrid's transcription, replacing Paperless's OCR** |
| Document type | `document_types.paperless_document_type_id` |
| Tags | the existing ones plus `paperless_processed_tag_id` |
| Custom fields | each field's `paperless_field_id`, merged not replaced |
| A note | Clear Books id and number, supplier, total, their reference |

Nothing is guessed: every target id is nullable, and an unset one means the
write is skipped and said so in the warnings, never that an id is invented.

Replacing `content` is on by default and switchable
(`paperless_replace_content`). On a scanned invoice the LLM reading beats
Paperless's own engine, and it is the only version carrying the handwritten
annotations — which is exactly what somebody searching the archive months later
is looking for. Overwriting a search index is still a decision an operator is
entitled to make differently.

Custom fields are **merged**. A PATCH of `custom_fields` replaces the whole
list, so writing the Clear Books id naively would wipe every field somebody had
set by hand.

Two of those fields are produced by the submission rather than read off the
page — the Clear Books id and the document number Clear Books assigned. They are
marked `source = 'submission'` and are never offered to the extraction prompt:
asking a vision model to find a Clear Books bill id on a supplier's invoice asks
it to invent a number that does not exist yet, and it will oblige.

### Open in Clear Books

Clear Books has **no API for a purchase line's project code**, so every
submitted document offers a link straight into the Clear Books web interface for
a person to set one. The link reuses a single named window
(`window.open(url, 'clearbooksWindow')`), so working through a queue of twenty
documents does not leave twenty tabs open — the second click lands in the tab
already sitting on the previous record.

It appears on the review screen, on the document record, and in the document
list, because it is the only route to that field.

### Submitting twice

Guarded three ways: `submitted` transitions only to `ignored`; the screens hide
the button once a submission exists; and `SubmitStage` itself refuses, naming
the record that already exists.

`/documents/{id}/resubmit` is the escape hatch — admin only, on no ordinary
path, behind a confirmation. It creates a **second** record and does not
withdraw the first, because InvoGrid has no business deleting from somebody's
ledger. It says so on the button and again afterwards.

---

## Custom fields

**Settings → Custom fields** (`/admin/fields`) defines the values to pull off a
page that are not part of an invoice's ordinary structure — a handwritten
reference, a circled project code.

Adding one is a row plus a sentence of hint. The extraction stage picks it up on
the **next document**: `{{ customFields }}` is filled from whatever is active at
the moment each document runs. Nothing is listed in a prompt by hand, and
nothing is deployed.

The hint is the part that does the work — it is passed to the model verbatim.
The Clearbooks Number hint is the pattern to copy, and the important half of it
is what it rules *out*:

> a handwritten number, almost always in RED pen, purely numeric… frequently
> absent — **never substitute a printed number** such as the supplier's own
> invoice number, an account number or a purchase-order number.

Without that last clause a model finds a candidate on every page.

### Two rules the screen enforces

**A field key never changes.** Values already read off documents are stored
under it in `extractions.custom_field_values`, so a rename orphans every one of
them. The form shows the key as read-only text once the field exists; deactivate
it and add another if the key is wrong.

**A field is deactivated, never deleted**, for the same reason — last month's
extraction still has to resolve what it stored. Deactivating stops it being
offered to the prompt and stops it being written back, and nothing else.

### Pairing with Paperless

On the same screen: pick an existing Paperless custom field — its type and, for
a select, its choices come across — or **create one** from what has already been
typed in, so setting a field up does not mean opening the Paperless admin in
another tab and coming back with an id.

The Paperless field is created **first**. A failure there leaves nothing behind;
the other order would give an InvoGrid field that silently never writes back,
which looks like it is working right up until somebody goes looking for the
value.

A Paperless field already paired with another InvoGrid field is shown but not
selectable — the write-back merges by Paperless field id, so two pointing at one
would overwrite each other on every document.

A field with no pairing is still read off the page; it just is not written back,
and the screen says so.

---

## Prompts

**Settings → Prompts** (`/admin/prompts`) is what the models are actually asked.

An edit writes a **new version** and makes it active — nothing is overwritten.
A change that turns out badly is one click to undo, and every result records
which version produced it, so "the extraction got worse last Tuesday" has an
answer.

**Reset to default** goes back to the newest version that shipped with the
application, re-activating it rather than writing a copy. Your edits stay in the
history.

### What the editor checks, and shows

**Variables are validated on save.** A `{{ name }}` nothing supplies would throw
at render time — correct, but with a document already in the pipeline and
whoever typed it long gone. The editor refuses it there and then, naming what is
available.

**The OCR prompt takes no variables at all**, and the editor refuses any. That
prompt goes to the model verbatim alongside the page images; a `{{ ocrText }}`
in it would be transmitted as those literal characters and the model would
answer confidently about nothing.

**Each variable shows what it holds right now** — the cached supplier list, the
account codes, the custom fields as they currently stand. Being able to see that
`{{ customFields }}` already contains the field you just added is the difference
between trusting the injection and hand-listing fields in the prompt "to be
safe".

> **Do not list custom fields in a prompt.** They are injected from the Custom
> fields screen every run. A copy written into the prompt goes out of step, and
> the copy in the prompt wins without saying so.

---

## Security

- HTTPS is enforced when `FORCE_HTTPS=true`: HTTP is redirected and the session
  cookie carries `Secure`. `/health` is exempt so a container probe still works.
- Session cookies are `HttpOnly` and `SameSite=Lax`, the session id is
  regenerated on sign-in, sessions are bound to a keyed fingerprint of the user
  agent, and an idle session expires after `SESSION_LIFETIME` minutes.
- Failed sign-ins are throttled per username and per client address.
- Every query is a prepared statement. No user input is concatenated into SQL
  anywhere.
- Every state-changing request carries a CSRF token, checked by middleware.
- Third-party credentials are only ever used from PHP. The browser never talks to
  Paperless, Clear Books, OpenAI or Anthropic directly.
- A Content-Security-Policy, `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy` and HSTS (over HTTPS) are set on every response.

---

## Accounts and roles

There is **no sign-up page**, and there is not going to be one: this application
is reachable before it has been configured, and a registration form on it would
be a way in rather than a convenience. The first account is made at the command
line (see *Create the first administrator*); every one after that is made on
**Settings → Users**.

| Role | Can |
|---|---|
| `viewer` | See documents, the dashboard and the review queue. Changes nothing. |
| `reviewer` | The above, plus correct a document, resolve its entities, create a supplier in Clear Books, submit, retry a failure. |
| `admin` | The above, plus settings, branding, prompts, custom fields, accounts. |

Roles are cumulative and enforced on the route, not in the template. Hiding a
button is a courtesy; the gate is `App\Core\Auth::can()` running before the
controller. `php tests/permissions.php` proves it — see *Verifying an install*.

### A password an administrator sets is a way in, not a password

Creating an account or resetting one sets a flag. That account can sign in, and
can then reach exactly two pages — the change-password page and sign-out — until
it has chosen its own. Otherwise "reset a password" quietly means "hold a
colleague's credentials indefinitely".

Everyone can change their own password from the avatar in the header. It asks
for the current one first, even when the change is a forced one: a session left
open on an unlocked screen should not be enough to change the credential that
outlives it.

### Three rules the users screen enforces

- **A username never changes.** Every line in the activity log refers to it.
  Deactivate the account and make another.
- **Accounts are deactivated, never deleted**, for the same reason.
  Deactivation takes effect on that account's *next request*, not at their next
  sign-in.
- **The last active administrator cannot be demoted or deactivated.** An
  application with no administrator can only be rescued from the server itself.

---

## Branding

**Settings → Branding** takes a light-mode and a dark-mode logo. Both are sent
to the browser and the stylesheet picks, because the theme can be switched
without a page load. Where only one is uploaded it stands in for both; with
neither, the header falls back to a monogram.

The logo appears in the site header, on the sign-in page (which is why that one
route is readable by a signed-out visitor — a logo is not a secret, and gating
it would mean an unbranded login), and at the top of a printed document summary,
where the **light** variant is used because paper is white.

Any shape works. It is scaled to fit by height with an automatic width, so a
wordmark and a square mark both land correctly.

**PNG, JPEG or WebP — not SVG.** An SVG is a document that can carry script, so
serving one from this origin would let anybody who can reach that screen run
code in everybody else's browser. A PNG at twice the display height is
indistinguishable in a 36-pixel-tall header.

Uploads are checked three ways: the extension, the real content type sniffed
from the file rather than believed from the browser, and whether it decodes as
an image at all. A script wearing a PNG header fails the third.

---

## Finding things

**The dashboard** answers three questions: how much work is waiting, what the
machine tripped over, and — the one nothing else reports — what has **stopped
moving**. A document that has exhausted its retries is not marked *failed* and
appears in no count; without that list it simply rots. Two thresholds, because
a document waiting on a machine should move in minutes and one waiting on a
person may sit over a weekend:

```bash
php bin/console.php settings:set stuck_pipeline_minutes 30
php bin/console.php settings:set stuck_review_days 7
```

**`/documents`** filters by stage, document type, correspondent and date range,
and its search reaches into what was read off the page — supplier name, invoice
number, title — not only what Paperless says. So a document whose correspondent
says "Acme" but whose invoice was read as "Totally Unknown Trading Co" is
findable under either.

The date range is the invoice date where one has been read, and the day it
arrived where one has not — otherwise the documents most worth hunting for would
be the ones the filter hides.

### When something fails

The document page says which stage, which call, which provider and model, and
**what the far end actually answered** — its own words, not a translation. That
is the difference between reading a page and reading a server log:

| | |
|---|---|
| Call | `extract_header` |
| Provider / model | anthropic / claude-opus-5 |
| Answered | Number of request tokens has exceeded your per-minute rate limit. |
| Http status | 429 · Retryable yes · Asked us to wait 45s |

**Retrying resumes from the stage that failed**, not from the beginning. A
document whose extraction broke is not downloaded and read again, and the page
says which stage it will resume at before you press it.

### Printing a summary

Any document that has been read has a **Printable summary** — what was extracted
and what Clear Books did with it, on one sheet, with the logo at the top. It is
its own layout rather than the ordinary page with the menus hidden: hiding the
navigation still ships it, and a printed record that quietly breaks when the
menu changes is one nobody notices until a supplier queries an invoice.

---

## Deploying from scratch

`sudo ./install.sh` does all of this. The list is here because somebody
eventually has to know what the script was doing, and because a machine that
was set up before the installer existed still has to be understood.

**On the server**

1. PHP 8.2 or newer with `pdo_mysql`, `mbstring`, `openssl`, `curl`, `json` and
   `fileinfo`. No Composer, no `vendor/`. **`openssl` is not optional** — it is
   what encrypts every stored API token.
2. MariaDB 10.6 or newer (or MySQL 8).
3. **poppler-utils**, for `pdftoppm`. Without it nothing can be read at all.
   `apt install poppler-utils`, and check with `pdftoppm -v`.
4. A web server with the document root at `public/` and everything else above
   it. Apache and nginx examples are above.
5. HTTPS. `FORCE_HTTPS=true` redirects and sets the `Secure` cookie flag;
   behind a reverse proxy also set `TRUST_PROXY=true` so
   `X-Forwarded-Proto` is honoured.

**The application**

6. `cp .env.example .env`, then `php bin/console.php key:generate`.
   **`APP_KEY` is not optional**: without it, no secret can be stored, and
   `Setting::put()` refuses rather than writing an API token to the database in
   the clear.
7. Set `APP_URL`, and the database credentials.
8. `php bin/migrate.php`.
9. `php bin/create-admin.php` — the first account is an administrator because
   there would otherwise be nobody to configure anything.
10. Storage: `storage/` must be writable by the web server user and **must not
    be reachable from the web**. It holds the PDFs, the page images and the
    uploaded logos.

**The external services**

11. **Paperless**: base URL and API token, then the workflow and its shared
    secret — see *Paperless setup*.
12. **Clear Books**: client id, secret and business id, then complete the
    consent flow on *Settings → Clear Books*. Until that is done every cached
    list is empty and every document lands in review saying so.
13. **A model provider**: a key for whichever of Anthropic or OpenAI you have
    chosen, per stage.
14. Pair the Paperless document types and the processed tag. These are still set
    by hand:

    ```bash
    php bin/console.php settings:set paperless_processed_tag_id 12
    ```

**The two cron jobs**

15. `sudo ./manage.sh cron-install` writes both, plus a nightly backup. By hand
    it is:

    ```
    * * * * * www-data /usr/bin/php /var/www/invogrid/bin/process-queue.php >/dev/null 2>&1
    17 * * * * www-data /usr/bin/php /var/www/invogrid/bin/refresh-clearbooks.php >/dev/null 2>&1
    ```

    **Without the first one nothing is ever processed.** It is the single most
    common way a working install stops working, so `doctor` reports a queue
    with overdue jobs and `status` says when there are no cron entries at all.

**Then check it**

16. The dashboard lists whatever is still unconfigured, and says what each thing
    is for. Work down it until it is empty.

---

## Verifying an install

Three scripts, none of which needs a browser. All three are safe to run against
a live install: the permission sweep creates and removes its own throwaway
accounts and never submits anything, and the pipeline audit only reads.

```bash
php tests/smoke.php                       everything that does not need a browser
php tests/pipeline.php                    is every workflow step really implemented?
php tests/permissions.php https://…       every route, every role, over real HTTP
```

`tests/permissions.php` is the one worth running after any change to the route
table. It signs in as a viewer, a reviewer and an administrator in turn and
requests every route, checking each against the capability the route itself
declares — so it compares the server with the route table rather than with a
second list somebody would have to keep in step.

State-changing routes are probed **without a CSRF token**, deliberately: a 403
means the capability gate refused, a 419 means the gate let it through and CSRF
stopped it. So the sweep can tell "denied" from "would have been allowed"
without any handler ever running. Nothing is created, submitted or deleted.

`tests/smoke.php` needs a readable `.env` with `APP_KEY` set. Everything up to
its "Database" section runs without one, and the database section is skipped
rather than failed on a machine that has neither.

---


## Project layout

```
bin/          command-line tools (migrate, create-admin, console, serve)
config/       configuration assembled from .env
database/     .sql migrations, applied in filename order
docs/         PROJECT-STATE.md — what currently exists, kept current
public/       the document root: front controller, CSS, JS, favicon
routes/       the route table
src/Core/     framework-ish pieces: router, database, session, auth, crypto
src/Models/   database-backed models
src/Services/ things that are neither: the API clients and the pipeline stages
src/Controllers/, src/Middleware/
storage/      PDFs, page images, uploaded logos, logs — never web-reachable
templates/    plain PHP templates
tests/        plain-assertion checks, no framework
```

---

## Licence

Internal software for Junction Inc Ltd.
