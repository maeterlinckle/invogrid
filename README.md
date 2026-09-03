# InvoGrid

**InvoGrid by Junction** — a self-hosted web application that turns scanned purchase
documents into Clear Books records.

Upload a PDF and InvoGrid renders each page to an image, transcribes it with a
vision-capable LLM, extracts the header, supplier and line items in several
focused calls, matches everything it can against the current Clear Books lists,
and puts anything it is not certain about in front of a human. Once a person has
resolved the uncertainties, the document is submitted to Clear Books with the
scan attached.

Nothing is created in Clear Books automatically. Every entity that did not match
with full confidence is a decision a person makes, pre-filled from the extraction
and editable before it is sent.

It is a sibling of [Kitwell](https://github.com/maeterlinckle/kitwell) and shares
its visual language, but no code, database or dependency: InvoGrid is a
standalone application with its own copy of everything.

---

## Status

The pipeline is complete, end to end. A scanned document is uploaded, has its
pages rendered and transcribed by a vision model,
is read into structured fields by three focused extraction calls, is matched
against cached Clear Books suppliers, account codes and VAT rates, and either
reaches **Ready to submit** on its own or stops at **Needs review** with the
specific reason attached. A person resolves what is left on the review screen —
editing anything the model got wrong, and creating a missing supplier in Clear
Books from a form they confirm — then submits it. The bill or credit note is
created in Clear Books with the PDF attached.

A document with a **Clearbooks Number** written on it takes the other fork, and
it runs the identical pipeline: read, extracted, matched, everything stored the
same way. Only the last step differs. That number refers to an invoice already
in Clear Books, so nothing is posted — the record is found in InvoGrid's synced
copy of Clear Books, its date and total are checked against the extraction
exactly, and the PDF is attached to it. Nothing on the Clear Books record is
changed. What does not match exactly waits on the **Existing invoices** screen,
where it is linked by hand, posted as a new invoice instead, or deleted — and
nothing there resolves itself.

A document with **no** such number is checked against that same synced copy
before it can be submitted, because the annotation is a fact about the page
rather than about the ledger: an invoice keyed into Clear Books by hand months
ago carries no number, and neither does a second scan of one already filed.
Where the supplier, their reference, the date and the total agree closely enough
to be worth a look, the document stops on the **Duplicates** screen and shows
InvoGrid's reading beside the Clear Books record, field by field. A person
either deletes the InvoGrid copy or confirms it is genuinely new, at which point
it carries on to be reviewed and submitted like any other and is never stopped
for this again. Nothing in Clear Books is touched either way.

Custom fields and the prompts themselves are managed in the application:
**Settings → Custom fields** defines what to look for on a page, and
**Settings → Prompts** edits what the models are actually asked, versioned so any
change can be undone. Neither needs a deploy.

**Settings → Users** creates and manages accounts. There is no sign-up page:
every account is made by an administrator, who sets a first password the account
is then made to replace before it can do anything else.

The dashboard shows the counts, what the machine tripped over, who did what, and
— the one nothing else reports — anything that has **stopped moving**: a
document that ran out of retries is not marked *failed* and appears in no count,
so without that list it simply rots. `/documents` filters by stage, type,
supplier and date range, and its search reaches into what was read off the page
as well as the filename it arrived under.

When something does fail, the document page says which stage, which call, which
model, and what the far end actually answered — without a server log.

**Settings → Branding** takes the light-mode and dark-mode logos. They appear in
the header, on the sign-in page and at the top of a printed document summary,
scaled to fit without distortion; with none uploaded the header falls back to a
monogram. Any submitted document has a **printable summary** — what was read off
the page and what Clear Books did with it, on one sheet, on its own layout
rather than the ordinary page with its menus hidden.

**Settings → Application settings** is where the rest lives: the Clear Books
address and credentials, the API keys, the largest document that may be
uploaded, which model runs each stage, how a page is rendered for the vision
call, and the thresholds behind the dashboard's "not moving" list. A credential shows as *set* or *not set* and never
as itself; an empty box means leave it alone, and there is a separate tick to
clear one. Buttons beside the model cards make a credential prove itself with a
real call, rather than confirming only that a box is not empty.

**Settings → Activity log** is the full record of what people did — filterable by
action, person, date and free text, and paged. Nothing on it writes. The
dashboard keeps the last fifteen entries for the "what just happened" question.

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
the firewall, the migrations, the first administrator, the
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
what is lost is InvoGrid's record of it — including which documents
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

This key encrypts every credential stored in the database — the Clear Books
OAuth2 secret and tokens, and the LLM API keys —
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

**The `settings` table**, edited on **Settings → Application settings**, holds
everything an administrator changes day to day: the Clear Books OAuth2
credentials and business id, the OpenAI and Anthropic API keys, the upload size
limit, and which provider and model each pipeline stage uses. `php bin/console.php settings:set <key>` does the same job from a terminal
and is what an unattended install uses — a credential has to be settable before
anybody can sign in to change one.

Where both exist, **a non-empty setting wins** and the `.env` value is the
fallback. Secrets are stored encrypted under `APP_KEY` and are never rendered
back to a browser — the Settings screen shows whether a credential is set, never
what it is.

`ingest_max_upload_mb` (default 25) is the largest document an ingest route will
accept. It has no `.env` fallback, and PHP's own `upload_max_filesize` and
`post_max_size` still outrank it — the upload page quotes whichever is actually
smallest.

`clearbooks_attach_pdf` (on) decides whether the scan is attached to the record
created in Clear Books.

---

## Getting documents in

A document reaches InvoGrid through an **ingest route**. One exists today — the
upload page — and the design assumes there will be more.

An ingest route has exactly one job: put the PDF on disk and create a
`documents` row at `received`, recording where the file came from. Everything
after that is the pipeline, which has no idea routes exist. That boundary is the
whole point: adding a route is a new caller of one method, not a change to OCR,
extraction, matching or the queue.

```
src/Services/Ingest/
  IngestCandidate.php   a file being offered, and how to move it
  IngestSource.php      the routes that exist, and what to call them on screen
  Ingestor.php          the one entry point: check, insert, store, queue
  IngestException.php   a candidate that was refused
```

### Uploading

**Documents → Upload** (`/documents/upload`). PDFs only, several at a time, each
accepted or refused on its own so one bad file does not discard the rest of the
batch.

It needs the `documents.upload` capability, which **reviewers and administrators
hold and viewers do not**. Accepting a file starts a pipeline that spends money
on every page of it, so it sits with retrying rather than with viewing.

The page quotes the limit that will actually apply, which is the smallest of:

| Limit | Where it is set |
|---|---|
| `ingest_max_upload_mb` | **Settings → Incoming documents**, default 25 |
| `upload_max_filesize` | php.ini |
| `post_max_size` | php.ini |

The last two cannot be raised from inside the application. A form promising 25MB
while PHP silently drops anything over 2MB produces the worst kind of bug
report — *"it just goes back to the list"* — so the screen says what is really
true rather than what the setting claims.

### What is checked, and where

Twice, deliberately, and they are not the same check.

**`Ingestor::accept()`**, before anything is written: the file is readable, is
not empty or truncated, is within the limit, and begins `%PDF-`. The header is
read rather than the extension trusted and rather than the browser's
`Content-Type` believed — a JPEG renamed to `.pdf` fails here. A refused
candidate leaves nothing behind at all.

**The `ingest` pipeline stage**, a moment later from the queue: the *stored* file
still exists, is still a PDF, and `pdfinfo` can find pages in it. This is the
gate in front of the expensive part — OCR renders every page and sends each one
to a vision model, and a truncated PDF should be caught for the cost of a stat
rather than discovered by a model that has already been paid for three pages of
nothing.

The second check exists because a future watched-directory route will hand over
files another process is still writing. That failure is **retryable**, not
permanent: the next attempt a minute later usually finds a whole document.

### Where the PDF goes

`storage/pdf/{document_id}/source.pdf`, outside the webroot, with the rendered
page images beside it. `documents.pdf_path` is stored relative to the storage
root, so the storage directory can be moved or restored onto another machine
without invalidating every row. The PDF is served back only through
`/documents/{id}/pdf`, behind `documents.view`.

> **InvoGrid holds the only copy.** A document was uploaded to it, not fetched
> from a system that still has it. `manage.sh backup` includes `storage/pdf` for
> exactly this reason, and `manage.sh reset-storage` is destructive in a way it
> was not when documents came from elsewhere.

### What is recorded about arrival

Four columns on `documents`, and nothing downstream reads any of them:

| Column | What it holds |
|---|---|
| `ingest_source` | the route: `upload`, or `legacy` for anything that predates native ingest |
| `original_filename` | what the file was called, for display and for searching |
| `ingested_by` | the user, where a person was involved; null for a robot |
| `ingested_at` | when |

They exist to answer *"where did this come from?"*, which is the first question
asked about a document that looks wrong. The document page prints them under the
heading — *Uploaded as acme-invoice-4471.pdf* — and `/documents` searches the
filename alongside the supplier and the invoice number.

### Adding a route later

A watched directory is the likely next one. It would be a script that finds
files, and for each one:

```php
Ingestor::accept(IngestCandidate::fromFile(
    $path,
    basename($path),
    IngestSource::WATCHED_FOLDER,
));
```

plus a constant and a label in `IngestSource`. The checks, the storage layout,
the `documents` row and the queued first stage all come with it. `moveTo()` is
the reason `IngestCandidate` is a class rather than four arguments: a browser
upload must be moved with `move_uploaded_file()`, which refuses any path PHP did
not itself receive as an upload, and a file found on disk is the opposite case —
`move_uploaded_file()` would refuse *it*. Each route knows which it is; nothing
downstream needs to.

---

## The queue

An ingest route registers a document; a queue worker does the work. Run it from
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
that will never come right on its own (a revoked API key, a model id that does
not exist) is not retried at all. After that the document sits in `Failed` with the reason on it,
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
```

Both jobs are safe to run by hand at any time, and both take a lock so a slow
run and the next tick cannot overlap.

There is also a **"Refresh now"** button on *Settings → Clear Books* for the
case where somebody has just added the supplier they are standing in front of.

### The third cron job: the invoice sync

A local copy of every bill and credit note **already in Clear Books**, so that
InvoGrid can tell whether a document that has just been uploaded has been posted
before. Clear Books has no search endpoint and throttles above five requests a
second, so the question is answered from a copy rather than asked of them once
per document.

```
*/5 * * * * www-data /usr/bin/php /var/www/invogrid/bin/sync-invoices.php >/dev/null 2>&1
```

Every five minutes, but **the schedule lives in the database, not in cron**: the
script only fetches when the interval on *Settings → Clear Books* says it is
due. Hourly is the default and suits most businesses. Changing it is a form
field rather than a root edit of `/etc/cron.d`, and it means the **"Sync now"**
button on that page runs exactly the code cron runs.

```bash
php bin/sync-invoices.php            sync if the schedule says it is due
php bin/sync-invoices.php --force    sync now, whatever the schedule says
php bin/sync-invoices.php --status   what is stored, without fetching
```

Clear Books is the source of truth here as it is for suppliers: a document
deleted there disappears locally on the next run. Nothing is ever written back —
this only reads. The screen shows the last run, what it fetched, and what it
deleted.

Nothing consumes these rows yet; matching an arriving document against them is
the next piece of work.

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
numbers exactly as printed, page boundaries marked — **and nothing else in that
string**. Everything the model found beyond the transcription comes back beside
it, as data: `handwrittenAnnotations[]`, `notesPresent`, and a best-guess
`clearbooksNumber` and `project`.

It used to append a `### Notes` section inside `ocrText` restating those fields
in prose, because the n8n flow it came from had no database and nowhere else to
put them. That is gone. The section was a second, lossier copy of data already
stored, every extraction prompt had to be told to skip past it, and it put text
into the permanent record of a page that is not printed on that page.

Two field rules matter more than the rest:

- A **Clearbooks Number** is digits only, almost always in red pen, usually but
  not always preceded by `#`, occasionally circled. **A printed number is never
  substituted for a missing one** — not the supplier's invoice number, not a PO
  number, not an account reference. Null is a correct answer here; a wrong
  number is far worse than none. This one decides which flow the document takes
  (below), so the rule is load-bearing rather than cosmetic.
- A **project code** is normally two letters and two digits, occasionally up to
  four letters, and may be handwritten, printed-and-circled, or plain printed
  text. Where there are several candidates the handwritten or circled one wins,
  and the ambiguity gets a review note.

---

## Existing invoice, or new one

Every document is put on one of two routes the moment the transcription lands.
**Both routes then run the same pipeline** — extraction, matching, all of it —
and part company only at the very end.

A Clearbooks Number written on the page is a reference to an invoice **already
in Clear Books**, so the document is a scan belonging to a record that exists,
not a bill to post.

| On the page | Route | What is different about it |
|---|---|---|
| A Clearbooks Number, digits only | **Existing invoice** | At the end, it is matched to the Clear Books record it names and the PDF is attached to it |
| Nothing, or a number that is not digits | **New invoice** | At the end, a bill or credit note is created in Clear Books |

A number that came back with letters in it is a misread, not a reference: the
prompt is explicit that a circled code containing letters is a Project, which is
a different field. It does not route, but it is stored and the document's
history says what it was and why it was not used.

Each document carries both the **route** it was sent down and the **status** it
has reached, because those are different questions — the route is decided once
and kept, and it stays readable after the two flows rejoin further down.

The routing is reversible in both directions, and reversing it costs nothing:
because both routes run the same pipeline, a document that changes route keeps
its transcription, its extraction and everything else already paid for. The
document page can put one onto the Existing invoice route; the Existing invoices
queue can send one back the other way.

**The route says what is written on the page, not what is in Clear Books**, and
those are different facts. A document with no number can still be an invoice
Clear Books already holds — see *Is this one Clear Books already has?* below,
which is the check on the New invoice arm.

---

## Linking an existing invoice

A document on the **Existing invoice** route is a scan of something already in
Clear Books. Nothing is posted for it. The job is to find the record its
handwritten number names and put the PDF on that record, so the accounts hold
the evidence rather than a reference to a filing cabinet.

InvoGrid does that on its own when it can, and asks a person when it cannot.

### It is read and extracted like everything else

**Both routes run exactly the same pipeline** — rendered, transcribed,
extracted, matched. A scan of an existing invoice is still a document you will
search for, filter by supplier and report on next year, so it gets the same
supplier, dates, line items and custom fields as any other, stored in the same
places.

The only difference is the very last step, where the New Invoice route creates a
record in Clear Books and this one matches one that is already there. That is
also why every later change to extraction applies to both routes without anybody
having to remember.

### How the number is matched

Against **InvoGrid's own copy of Clear Books' purchase documents**, which the
invoice sync keeps up to date — see *The third cron job* above. Clear Books has
no search endpoint for purchase documents, so a lookup against the API would
mean walking the whole ledger once per scan.

The number written in red pen is digits; Clear Books' own document number may be
`PUR0080421`. So the comparison is made twice — exactly as written, then on the
digits alone with leading zeros dropped — and `80421` finds `PUR0080421`. That
is a difference in spelling, not a loosening: `80421` and `80422` remain two
different numbers.

**If two records answer to the same number, nothing is linked.** Picking one
would be a coin toss, and losing it means this document's PDF ends up on
somebody else's invoice.

### The checksum, which is exact

Finding a record is not enough on its own: a single misread digit lands on a
real invoice belonging to a different supplier in a different month. So the
extraction is checked against the record, on two values:

| | Must be |
|---|---|
| **Invoice date** | the same day as the record's date |
| **Gross total** | the same figure as the record's total, to the penny |

**Both have to agree exactly, and there is no tolerance on either.** If they do,
the PDF is attached and nobody is asked. If either does not — even by a day, or
by a penny — the document goes to the **Existing invoices** queue for a person
to look at.

That is a deliberate trade, and it is worth being clear about which way it cuts.
Clear Books records are typed in by hand, so a date really is often the day the
bill was keyed in rather than the day it was issued, and a total really is
sometimes rounded. Those documents will land in the queue, and clearing one
takes about ten seconds. The alternative — a tolerance wide enough to absorb
them — is a licence to file a scan against the wrong invoice with nobody
watching, and that mistake is found during an audit, if at all.

Two things that look like tolerances and are not:

- the total is compared **without its sign**, because Clear Books stores a credit
  note negative and a page never prints a minus sign. The figure itself still
  has to be identical;
- amounts are compared as whole pence rather than as decimals, so floating-point
  noise cannot decide a match.

### Nothing on the Clear Books record is changed

The **only** call this route makes to Clear Books is the attachment. The record
was entered by a person and is not InvoGrid's to edit — if the page and the
ledger disagree about something, that is for a person to settle in Clear Books.
Everything InvoGrid extracted is stored in InvoGrid.

### What a linked document looks like

The PDF is attached to the Clear Books record, the document reaches
**Submitted**, and its **Flow** still reads *Existing invoice* — which is how
you tell one apart from a bill InvoGrid created. It carries the same **Open in
Clear Books** link a submitted document does, which is where a project code is
set by hand, and the Clear Books id and document number appear in its custom
fields exactly as they do after a submission.

The supplier and the document type are taken from the matched record. That is
the accounts speaking rather than a model reading a scan, so it is the better
answer of the two.

The number read off the page is **never overwritten**, including when somebody
corrects it. It is the record of what the model saw, and a value quietly
replaced is a misreading nobody would ever notice.

### When it cannot decide: the Existing invoices queue

Anything the match does not settle waits at **Needs linking**, on its own screen
at **Existing invoices** in the menu. Nothing there resolves itself. Each row
shows the Clearbooks Number, the extracted date and total, and why it stopped —
usually one of:

- **matches nothing** — very often the record was entered in Clear Books after
  the last invoice sync ran;
- **matches two records** — both are listed;
- **the date or the total does not agree** — the record's value and the
  extracted value are shown side by side.

Three things can be done about it, and no more:

1. **Link it.** The field arrives holding the number read off the page. Correct
   a misread digit, or leave it alone and press the button to look the same
   number up again — the sync may have caught up since. If the record is right
   but the checksum does not hold, you may link it anyway: you have the scan and
   the record in front of you, which is more than the checksum has, and what you
   overrode is recorded against the document.
2. **Post it as a new invoice.** The number was not a Clear Books reference — a
   stock code, a purchase order, somebody else's number. Nothing is re-read or
   re-extracted: the document keeps everything it has and goes straight to the
   ordinary review queue to be posted as a new bill.
3. **Delete it.** For a duplicate scan or a page that is nobody's. **This cannot
   be undone** — the document, its transcription, everything extracted from it,
   its page images and the stored PDF are all removed. A reason is required, and
   the activity log keeps it after the document itself has gone.

Reviewers and administrators can do all three. If you would rather deleting were
an administrator's job, move `documents.delete` from the reviewer list to the
admin list in `src/Core/Auth.php`; nothing else has to change.

---


---

## Is this one Clear Books already has?

The fork above turns on somebody having written a number on the page. That is a
fact about the page, not about the ledger — and an invoice already in Clear
Books very often carries no number at all. It was keyed in by hand months ago
and nobody printed it; or a colleague scanned it once before, under a different
image, and this is the second copy.

Such a document takes the New Invoice route from end to end, is extracted and
matched perfectly well, and arrives at the review queue looking exactly like a
bill nobody has posted — with a submit button on it. Submitting it puts the same
purchase into the accounts twice, and that is found by a payment run rather than
by anything in here.

So every New Invoice document is compared against InvoGrid's synced copy of
Clear Books before it can be submitted.

### What is compared

Four things, at the end of matching — which is where the supplier has just been
resolved, and the supplier is one of the four:

| | Compared with |
|---|---|
| Supplier | the Clear Books supplier the matching stage settled on |
| Their reference | the supplier's own invoice number, case and punctuation ignored |
| Invoice date | the same day |
| Gross total | the same figure to the penny, sign ignored |

The comparisons are the same code the Clearbooks Number checksum uses, so the
two screens can never disagree about the same pair of records. **There are no
tolerances**: the same day means the same day, and the same figure means to the
penny. A value missing on either side is not an agreement — it cannot be
confirmed, so it is not confirmed.

The reference ignores case and punctuation and nothing else: `INV-2026/0042`,
`inv 2026 0042` and `INV20260042` are one reference typed three ways, while
`0042` and `42` stay two different references. (That last is the one place this
differs from the Clearbooks Number, which *does* drop leading zeros — Clear
Books writes its own numbers to a fixed width, and a supplier's reference has no
such convention behind it.)

### When a document stops

**Two of the four must agree, and one of the two must be the gross total or the
supplier's reference.**

The second half is what stops the queue crying wolf. A business buys from the
same supplier every week and receives invoices dated the same day all the time,
so supplier-and-date is two agreements that would stop a delivery note every
week without once being right. A recurring monthly figure on its own is one
agreement, which is a coincidence rather than evidence.

A genuine duplicate normally agrees on all four — it is literally the same
invoice — so two is generous on purpose. The slack goes towards catching an
extraction that misread one field, because the cost of stopping something
wrongly is ten seconds on a comparison screen, and the cost of missing one is
the same purchase in the accounts twice.

**There is nothing to configure**, deliberately, and no threshold to turn down.
If you do not want the check, do not run the invoice sync: a local copy nobody
has filled matches nothing, and every document flows through as it did before.
The Duplicates screen says so on its own face rather than reporting an empty
queue as reassurance.

### The Duplicates queue

A document that stops waits at **Possible duplicate**, on its own screen at
**Duplicates** in the menu. Nothing there resolves itself.

The detail screen is one gesture: compare, then decide. InvoGrid's reading in
one column, the Clear Books record in the next, each of the four marked agreed,
disagreed or missing with a sentence saying why, and the scan itself underneath.
Records that were fetched but do not clear the bar are shown below the ones that
do — a near neighbour ruled out by eye is worth the ten seconds, and hiding them
would leave a page headed "possible duplicate" with nothing visible to be a
duplicate of.

The comparison is re-run when the page is opened rather than read back off what
the pipeline decided, because the invoice sync runs on a schedule and the record
may have been edited or withdrawn since.

Two things can be done about it, and no more:

1. **It is genuinely new.** For a supplier who invoices the same amount every
   month, a reference two suppliers happen to share, or a record that only looks
   like this one. Nothing is re-read or re-extracted: the document keeps
   everything it has and goes to the ordinary review queue, or straight to
   **Ready to submit** if nothing else is outstanding. The decision is recorded
   against the document, so it is never stopped for this again — editing it
   later and having it re-matched will not send it back.
2. **It is the same invoice.** Delete it. **This cannot be undone** — the
   document, its transcription, everything extracted from it, its page images
   and the stored PDF are all removed. A reason is required, and the activity
   log keeps it, along with the Clear Books document it duplicated, after the
   document itself has gone.

**Nothing in Clear Books is touched either way.** The check makes no call at
all — it reads InvoGrid's local copy — and deleting removes InvoGrid's copy of
the scan, not the record. The record was entered by a person and is not
InvoGrid's to edit.

There is no third "attach the scan to that record instead", because there is
already a way to do it: confirm the document is new, then use **Reset to →
Finding the record** on the document page, which puts it on the Existing Invoice
route where linking belongs.

Reviewers and administrators can do both. Deleting is the same
`documents.delete` capability the Existing invoices queue uses, so moving it to
administrators is the same one-line change in `src/Core/Auth.php`.

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
| `{{ ocrText }}` | The transcription, and nothing but the transcription |
| `{{ today }}` | Today, as `YYYY-MM-DD` |
| `{{ suppliers }}` | Cached Clear Books suppliers, with their Clear Books ids |
| `{{ accountCodes }}` | Cached purchase account codes |
| `{{ vatRates }}` | Cached VAT rates, with their percentages |
| `{{ vatTreatments }}` | Cached VAT treatments |
| `{{ customFields }}` | The configured custom fields, with their hints |
| `{{ annotations }}` | The handwritten marks found on the page, with ink colour and location |

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
2. **A fourth call**, asking only about what is still unresolved — and given
   `{{ annotations }}` alongside, since a field the first step could not answer
   is usually one written on the page by hand.

On an ordinary document step 1 answers everything and there is no fourth call at
all.

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
2. Enter the credentials on **Settings → Application settings → Clear Books**:
   client id, client secret and business id. From a terminal instead:

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
php bin/refresh-clearbooks.php --sync --dry-run   say what the sync would do
```

Account codes are narrowed to the ones marked `purchases`: a sales-only code
offered to the extraction prompt is a wrong answer waiting to be picked. VAT
rates are fetched **once per treatment**, because which rates are legal depends
on the treatment.

Anything the refresh does not see is **deactivated, not deleted** — a document
already matched against a supplier keeps a resolvable record, and the row's
the local knowledge held against it survives — its usual credit route, and every
document already matched to it. An *archived* supplier takes
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

---

## The review queue

`/review` is what a person uses this application for. Everything before it is
machinery.

The list shows documents needing a decision **and** documents ready to submit,
together: they are two halves of one job, and somebody who has just resolved a
document should not have to go and find it somewhere else to finish it. Each row
carries the supplier, type, amount, date and — the numbers that actually
matter — how many things are unresolved, how many are flagged, and what the
first of them says. A document with one unmatched supplier is a minute's work;
one with six unmatched account codes is not, and a queue showing only a status
makes you open both to find out.

There are **three queues, and each asks a different question** — which is why
each has its own screen rather than being a tab on another:

| Queue | The question |
|---|---|
| Review queue | Is this extraction right, and does everything on it resolve? |
| Existing invoices | Which Clear Books record does this handwritten number point at? |
| Duplicates | Is this invoice one Clear Books already holds, though nobody wrote a number on it? |

Only the review queue ends in a submission. The other two end in a document
either joining it or going away.

### The detail screen

The scan on the left, the record on the right, on a layout built for a monitor:
the content column runs to 1760px on the document-facing screens rather than the
1200px that suits a page of prose, and past 1500px the extra width goes to the
form, because a sheet of A4 stops getting easier to read somewhere around 700px
and a six-column line table does not.

**The scan pane shows the rendered page images, not the PDF.** Every document is
rendered to one image per page before a model is ever shown it, so the images are
already on disk — and they are the very images the extraction was worked out
from, which makes them the right thing to check a doubtful reading against. An
`<img>` paints straight away where an `<object>` boots a whole PDF viewer, with
its own toolbar and its own idea of zoom, inside a box a third of the screen
wide.

Under the pages: arrows and a page count, an **Actual size** toggle that swaps
fit-the-width for the image's own pixels — which is what reading a handwritten
annotation needs — and a **View PDF** button that opens the PDF *beneath* the
images rather than instead of them. A thumbnail strip appears past two pages.

All of it works with JavaScript off: the pages are stacked in a scrolling box in
order, the strip is ordinary in-page anchors, and View PDF is a link to
`/documents/{id}/pdf`. The two controls that cannot work without a script — the
arrows and the zoom toggle — are hidden until it loads, so the bar never offers a
button that does nothing.

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

### What needs a look, marked on the field that needs it

The screen used to carry one card at the top saying "4 things to check", above
forty inputs, and left the reviewer to work out which four boxes were meant.
Now each thing is drawn on the field it is about: the label carries a word —
**must be resolved** or **check this** — the box carries a coloured edge, and
what was actually said sits under the box.

Three signals feed it, in descending order of how much they can be trusted:

| Signal | Where it comes from | Tone |
|---|---|---|
| An unresolved entity | An `entity_matches` row that names its entity type and, for a line, its line index. Structural, so there is no guessing | red — it stands between this document and Clear Books |
| A confidence below 1 | A match settled by the looser name pass (0.9), or a score in `extractions.confidence`, which is per-column | amber |
| A review note | Prose a stage wrote. The only part that is a guess | amber |

`App\Services\FieldIssues` does the attribution. The pipeline's own notes carry
prefixes it parses rather than interprets — `Matching: Account code on line 2:`,
`Line 3:`, `Document type:` — and only the free text a model wrote is read for a
phrase that names a field, from a deliberately short list of phrases a note would
have to go out of its way to mean something else by. **A note it cannot place is
not attributed by guesswork**: it is listed at the top of the form, where the old
banner was, because a reviewer sent to correct a value that was never wrong will
trust the next mark less. That list, and an index of links to the marked fields,
is all that is left up there.

The document record at `/documents/{id}` marks the same values the same way. It
is read-only, so the marks are all it offers — but "which of these forty values
is the doubtful one" is the same question on both screens and must not have two
answers, so both build the marks from the same object.

### Resolving what could not be matched

An account code, a VAT rate or the VAT treatment is a `<select>` in the form
itself, and saving re-runs the match — so **picking the right one there is the
resolution**, and the marked cell is the control that fixes it.

The supplier is the exception, and gets a card of its own beside the field: the
supplier box holds the name read off the letterhead, so typing in it changes what
the document says rather than what it points at. Two ways out:

| | |
|---|---|
| **Use one already on file** | First, because it is the common case: most unmatched suppliers *are* in Clear Books, under a name the matching could not see. |
| **Create in Clear Books** | A form pre-filled from the extraction, which you check and edit before confirming. |

**Nothing is created automatically, ever.** `EntityCreator` is the only class
that creates anything in Clear Books, every entry point is a POST from this
screen, and there is no scheduled caller. What gets created is what is in the
boxes when the button is pressed — the pre-fill is a convenience, not the
decision.

Creating a supplier caches it immediately, so the re-check that follows reads the
record rather than trusting this step to have said so. A supplier that exists in
Clear Books but not in the cache would leave the document unresolved with no
explanation a person could act on; throwing there would
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
6. Record what Clear Books called it.

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

**Creating the record and attaching the PDF are one action.** There is no
separate write-back step and nothing to run afterwards: pressing submit is the
last thing anybody does to a document. There used to be one, when the reference
Clear Books gave a bill had to be written back into Paperless; that went with
Paperless, and the two values it carried — the Clear Books id and document
number — now go into custom fields on the extraction, where every other field
value already is.

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

### What the submission records

Two custom fields are **produced** by submitting rather than read off the page —
the Clear Books id, and the document number Clear Books assigned. They are marked
`source = 'submission'` and are written onto the extraction once Clear Books has
answered, which is how they reach the document page and the printed summary.

They are never offered to the extraction prompt: asking a vision model to find a
Clear Books bill id on a supplier's invoice asks it to invent a number that does
not exist yet, and it will oblige.

This is best effort, like everything else past step four. The `submissions` row
is the record of what happened; these are a convenience laid over it, and a
failure here must not make a submitted document look unsubmitted. It is also
written straight to the column rather than through `Extraction::updateFields()`,
which stamps `edited_at` — that stamp means *a person changed this*, and a value
the submission produced by itself must not make an untouched extraction claim it
was edited by hand.

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
offered to the prompt, and nothing else.

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
  Clear Books, OpenAI or Anthropic directly.
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
| `reviewer` | The above, plus correct a document, resolve its entities, create a supplier in Clear Books, submit, retry a failure, link an existing invoice, settle a possible duplicate, and **delete a document**. |
| `admin` | The above, plus settings, branding, prompts, custom fields, accounts. |

Roles are cumulative and enforced on the route, not in the template. Hiding a
button is a courtesy; the gate is `App\Core\Auth::can()` running before the
controller. `php tests/permissions.php` proves it — see *Verifying an install*.

**Deleting is the one irreversible thing in here**, and it has its own
capability (`documents.delete`) so that it can be moved without touching
anything else. A reviewer holds it because it is one of the three answers the
Existing invoices queue offers and one of the two the Duplicates queue offers,
and a queue with a resolution its own audience cannot reach stops being worked;
the reason field and an audit row that outlives the document are the controls.
To make it an administrator's job, move that one string from the reviewer list
to the admin list in `src/Core/Auth.php`.

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
person may sit over a weekend. Both are on **Settings → Application settings**,
under *Noticing trouble*, or from a terminal:

```bash
php bin/console.php settings:set stuck_pipeline_minutes 30
php bin/console.php settings:set stuck_review_days 7
```

**`/documents`** filters by stage, document type, supplier and date range,
and its search reaches into what was read off the page — supplier name, invoice
number, title — as well as the filename it arrived under. So a document uploaded
as `acme-jan.pdf` whose invoice was read as "Totally Unknown Trading Co" is
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

11. **Clear Books**: client id, secret and business id, then complete the
    consent flow on *Settings → Clear Books*. Until that is done every cached
    list is empty and every document lands in review saying so.
12. **A model provider**: a key for whichever of Anthropic or OpenAI you have
    chosen, per stage.
13. Optionally raise or lower `ingest_max_upload_mb` on
    **Settings → Incoming documents** if 25MB is the wrong ceiling for your
    scanner. From a terminal instead:

    ```bash
    php bin/console.php settings:set ingest_max_upload_mb 40
    ```

**The three cron jobs**

14. `sudo ./manage.sh cron-install` writes all three, plus a nightly backup. By
    hand it is:

    ```
    * * * * * www-data /usr/bin/php /var/www/invogrid/bin/process-queue.php >/dev/null 2>&1
    17 * * * * www-data /usr/bin/php /var/www/invogrid/bin/refresh-clearbooks.php >/dev/null 2>&1
    */5 * * * * www-data /usr/bin/php /var/www/invogrid/bin/sync-invoices.php >/dev/null 2>&1
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
