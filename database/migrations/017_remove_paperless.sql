-- ---------------------------------------------------------------------------
-- InvoGrid — remove Paperless, replace it with native ingest.
--
-- The pivot: InvoGrid no longer waits for a Paperless workflow to tell it a
-- document has arrived. A document is now handed to InvoGrid directly — a
-- manual upload today, a watched directory later — and the pipeline from OCR
-- onward is unchanged.
--
-- Every column that existed only to talk to Paperless is dropped rather than
-- left unused: a nullable column nobody ever populates again is exactly the
-- kind of thing a future reader assumes still works. Columns that hold real
-- data but were *named* after a Paperless concept are renamed instead, because
-- the data is still wanted and only the vocabulary has gone.
-- ---------------------------------------------------------------------------

-- --- documents ---------------------------------------------------------------
--
-- What replaces `paperless_doc_id` is `ingest_source` / `original_filename` /
-- `ingested_by` / `ingested_at` — enough to know where a document came from
-- without naming a system that no longer exists.
--
-- `correspondent_raw` and `correspondent_matched_supplier_id` are renamed, not
-- dropped: "correspondent" is Paperless's word for the party a document is
-- with, and the columns outlived it. What they hold — the issuer as printed on
-- the page, and the Clear Books supplier it resolved to — is exactly as useful
-- as it was yesterday.

ALTER TABLE documents
    DROP KEY uq_documents_paperless,
    DROP COLUMN paperless_doc_id,
    CHANGE COLUMN correspondent_raw supplier_raw VARCHAR(255) NULL
        COMMENT 'The issuer as it appears on the page, before matching.',
    CHANGE COLUMN correspondent_matched_supplier_id matched_supplier_id VARCHAR(64) NULL
        COMMENT 'The Clear Books supplier this resolved to. Their id, not ours.',
    ADD COLUMN ingest_source     VARCHAR(32)  NOT NULL DEFAULT 'upload' AFTER id,
    ADD COLUMN original_filename VARCHAR(255) NULL AFTER ingest_source,
    ADD COLUMN ingested_by       INT UNSIGNED NULL AFTER original_filename,
    ADD COLUMN ingested_at       DATETIME     NULL AFTER ingested_by,
    ADD KEY ix_documents_ingest (ingest_source, ingested_at),
    ADD KEY fk_documents_ingested_by (ingested_by),
    ADD CONSTRAINT fk_documents_ingested_by FOREIGN KEY (ingested_by)
        REFERENCES users (id) ON DELETE SET NULL;

-- Every row that already exists arrived by the Paperless webhook, which no
-- longer exists to attribute correctly — 'legacy' says so rather than claiming
-- somebody uploaded it. `ingested_at` backfills from created_at, which is the
-- closest true fact available for a document that predates the column.
UPDATE documents SET ingest_source = 'legacy' WHERE ingest_source = 'upload';
UPDATE documents SET ingested_at = created_at WHERE ingested_at IS NULL;

ALTER TABLE documents
    MODIFY COLUMN ingested_at DATETIME NOT NULL;

-- --- extractions -------------------------------------------------------------
--
-- `paperless_title` was the short "what was bought" line the header prompt
-- writes, so named because it was destined for the Paperless document title.
-- It is still the most useful one-line description InvoGrid has of a document —
-- it heads the review screen and the printed summary — so it is renamed rather
-- than dropped. The prompt that produces it is re-seeded below to match.
ALTER TABLE extractions
    CHANGE COLUMN paperless_title document_title VARCHAR(255) NULL
        COMMENT 'A short description of what was purchased, for display.';

-- --- clearbooks_cache ---------------------------------------------------------

-- No Paperless correspondents left to link a supplier to.
ALTER TABLE clearbooks_cache
    DROP KEY ix_clearbooks_cache_correspondent,
    DROP COLUMN paperless_correspondent_id;

-- --- custom_fields -------------------------------------------------------------

-- No Paperless custom field left to pair with. The field definitions
-- themselves — key, label, data type, prompt hint — are untouched: they still
-- drive extraction, which is the whole reason they exist.
ALTER TABLE custom_fields
    DROP COLUMN paperless_field_id;

-- --- document_types ------------------------------------------------------------

-- No Paperless document type left to write a submitted document back as.
ALTER TABLE document_types
    DROP COLUMN paperless_document_type_id;

-- --- settings --------------------------------------------------------------

-- Rows that existed only to configure the webhook receiver, the write-back, or
-- the correspondent sync — all gone. Everything else (Clear Books, the LLM
-- providers, rendering, thresholds) is untouched.
DELETE FROM settings WHERE setting_key IN (
    'paperless_base_url',
    'paperless_token',
    'paperless_webhook_secret',
    'paperless_processed_tag_id',
    'paperless_replace_content',
    'clearbooks_sync_correspondents',
    'clearbooks_delete_correspondents'
);

-- What an upload is allowed to be. A setting rather than a constant because the
-- answer is a matter of local policy — the scanner one office uses produces
-- 30MB colour scans and another's produces 2MB — and because the alternative is
-- an administrator editing PHP to accept a large invoice.
--
-- The web server and PHP both cap this independently (`upload_max_filesize`,
-- `post_max_size`, `client_max_body_size`); this is the application's own limit
-- and cannot raise a lower one of theirs. The upload screen says what the
-- effective limit actually is rather than what this row claims.
INSERT INTO settings (setting_key, setting_value, is_secret) VALUES
    ('ingest_max_upload_mb', '25', 0)
ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- --- the extraction prompts --------------------------------------------------
--
-- Two of the three name Paperless in the contract they ask the model to honour,
-- so both are re-seeded at a new version rather than edited in place. A new
-- version is how every other prompt change in this application is made: the old
-- one stays readable beside it, and a site that had customised the previous
-- version still has that text to copy from.
--
-- `extract_lines` is untouched — it never mentioned Paperless.

-- Header: `paperlessTitle` becomes `documentTitle`. The instruction is
-- otherwise verbatim, worked examples included: what makes a good short
-- description of a purchase did not change when the destination did.
UPDATE prompt_templates SET is_active = 0 WHERE template_key = 'extract_header';

INSERT INTO prompt_templates (template_key, version, label, origin, content, is_active)
SELECT 'extract_header',
       COALESCE(MAX(version), 0) + 1,
       'Title, summary, dates, reference, currency',
       'seed',
       'You are an invoice-processing assistant for Junction Inc Ltd. You will be given the OCR text of a supplier invoice/bill. Extract the title, summary, and header details below. Ignore anything from "### Notes" onward in the input — that section contains handwritten annotations and custom fields, not invoice content.

<ocr_text>
{{ ocrText }}
</ocr_text>

<today>
{{ today }}
</today>

## documentTitle

Short summary of what was purchased, used as the document''s title on screen and on the printed summary.
- Maximum 10 words.
- Do not end with "purchase" or similar (e.g. "purchased", "bought").
- Do not include the supplier/vendor''s name.
- Focus only on the items/services purchased — ignore addresses, invoice numbers, dates, VAT details, bank details, payment terms.
- Use as few words as possible; brevity over filling the limit.

Examples:
- "Petzl Vertex Vent helmet, red"
- "USB to RS232 serial adapter cable"
- "Pyrotechnic system construction, control hire, propane cylinders"

## cbSummary

A very general, high-level summary of what the invoice is broadly for, for use as the Clear Books bill description.
- Maximum 5 words.
- General category-level, not itemized — describe the broad type of purchase (e.g. "Electronic components", "Office supplies", "Freight and delivery"), not a list of specific items or models.
- Do not include the supplier/vendor''s name.
- Ignore addresses, invoice numbers, dates, VAT details, bank details, payment terms.

All dates below must be returned as YYYY-MM-DD.

## dateInvoice

Look for a date stamp for the document — it might be labelled Invoice Date, Bill Date, Issue Date, or simply appear as a date with no label. If no day is shown, use the 1st of the month. If no month is shown, use January. If no date at all is found, use <today>.

## datePaid

Look for a paid date, and/or any text indicating the invoice/bill has already been paid (e.g. a "PAID" stamp, "Paid in full", a receipt confirmation, zero balance/amount due). If a specific paid date is shown, use it. If payment is indicated only by text with no date given, use dateInvoice. If there is no indication the invoice has been paid, use null.

## dateDue

Determine using this priority order:
1. Look for an explicit due date or due-date wording tied to a calculation from the invoice date — e.g. an explicit "Due Date" field, "Invoice due on <date>", "Invoices due month end", "NET 30", "Month following" — and resolve it to a specific date based on dateInvoice. For "month end"/"end of month" wording, resolve to the actual last calendar day of the relevant month; for "month following", resolve to the corresponding day in the next calendar month (or that month''s last day if the day does not exist in it).
2. If no due date or due-date wording is found at all, use datePaid if it was found.
3. If neither is found, use dateInvoice.

dateDue should always be populated — never null. If the calculation relied on ambiguous wording, or fell back to datePaid/dateInvoice because no due-date information was found, add a note to reviewNotes.

## reference

The issuer''s own document/invoice/bill number (labelled "Invoice No.", "Reference", "Document Number", or similar). Make a best-effort guess even if the label is ambiguous. If truly no candidate exists, use null.

## currency

Only include an ISO 4217 code (e.g. "EUR", "USD") if the invoice is NOT in GBP. If no currency is indicated, assume GBP and use null.

## reviewNotes

Array of short strings flagging anything uncertain above (ambiguous due-date wording, fallback due date used, uncertain reference, uncertain currency). Empty array if nothing to flag.

## Output format

Return ONLY the following JSON object — no preamble, no explanation, no Markdown code fences:

{
  "documentTitle": "string",
  "cbSummary": "string",
  "dateInvoice": "YYYY-MM-DD",
  "datePaid": null,
  "dateDue": "YYYY-MM-DD",
  "reference": null,
  "currency": null,
  "reviewNotes": []
}',
       1
  FROM prompt_templates
 WHERE template_key = 'extract_header';

-- Supplier: the same matching rules, minus the `paperlessId` this call used to
-- be asked to echo back for the correspondent write-back. `cbId` is unaffected,
-- and so is every rule about name variants and normalisation — those are about
-- reading a letterhead, not about where the answer was sent.
UPDATE prompt_templates SET is_active = 0 WHERE template_key = 'extract_supplier';

INSERT INTO prompt_templates (template_key, version, label, origin, content, is_active)
SELECT 'extract_supplier',
       COALESCE(MAX(version), 0) + 1,
       'Identify the issuer and match it against Clear Books',
       'seed',
       'You are a supplier-matching assistant for Junction Inc Ltd''s accounting system. You will be given the OCR text of a supplier invoice/bill and a list of known suppliers. Identify the invoice issuer and match it against the known list.

<ocr_text>
{{ ocrText }}
</ocr_text>

<suppliers>
{{ suppliers }}
</suppliers>

## Step 1 — Identify name variants

Invoices are sometimes issued under more than one name for the same entity: a legal name plus a trading name joined by "t/a" or "trading as" (e.g. "AO Retail Limited t/a AO.com"), or two names separated by "/" where either could be the one already on file, often following a rebrand or merger (e.g. "Powerled (UK) Limited / Sunpower Group Holdings Ltd"). Identify every name variant shown for the issuer: the primary/legal name, and any trading name, "t/a" alias, or secondary name joined by "/".

## Step 2 — Normalise before comparing

Before comparing, normalise each variant (both from the invoice and from <suppliers>) so formatting differences do not cause a missed match:
- Treat common legal suffixes as equivalent regardless of abbreviation: "Ltd" = "Limited", "PLC"/"plc" = "Public Limited Company", "LLP" = "Limited Liability Partnership", "Inc"/"Inc." = "Incorporated", "Corp"/"Corp." = "Corporation", "Co"/"Co." = "Company".
- Treat the suffix as optional on either side — "Sunpower Group Holdings" should still match "Sunpower Group Holdings Ltd" even if one omits it entirely.
- Ignore case, full stops, commas, and extra whitespace.
- Treat "&" and "and" as equivalent.
- A match does not need to be byte-for-byte — an otherwise-identical core name is enough. For example, "Direct Plastics Ltd" MUST be matched against "Direct Plastics Limited" on file — these are the same entity.

## Step 3 — Match

Check EACH variant against <suppliers> — by normalised name, VAT number, or company registration number, in that order of reliability (a VAT/company number match is stronger evidence than a name match — prefer it if both are available and they disagree). A match on ANY single variant counts as a match on that supplier.

If matched: set supplierMatched: true and return that supplier''s cbId exactly as given in <suppliers>. Leave name/address/vatNumber/companyNumber null. If the invoice used a name variant that IS NOT the one currently on file, add it to tradingNames and note in reviewNotes that a new alias may be worth adding.

If not matched (even after normalisation): set supplierMatched: false and cbId to null, and populate name with whichever variant is the current legal entity name (the one carrying a company suffix, or the more prominent/first-listed one). Put any other variant(s) in tradingNames. Populate address, vatNumber, companyNumber from the document, using null for anything not found. Never invent a match to avoid creating a new supplier — a wrong match is worse than a new record.

## Output format

Return ONLY the following JSON object — no preamble, no explanation, no Markdown code fences:

{
  "supplierMatched": true,
  "cbId": null,
  "name": null,
  "tradingNames": [],
  "address": null,
  "vatNumber": null,
  "companyNumber": null,
  "reviewNotes": []
}',
       1
  FROM prompt_templates
 WHERE template_key = 'extract_supplier';

-- --- the two submission-produced custom fields ---------------------------------
--
-- Their hints said "written back after submission", which described where the
-- value went rather than what it is. It went to Paperless; now it is recorded
-- on the extraction itself and shown on the document. The fields, their keys and
-- their types are untouched — only the sentence describing them was wrong.
UPDATE custom_fields
   SET prompt_hint = 'Filled in by the submission — the id Clear Books gave the created record. Never read off the page.'
 WHERE field_key = 'clearbooks_bill_id';

UPDATE custom_fields
   SET prompt_hint = 'Filled in by the submission — the document number Clear Books assigned. Never read off the page.'
 WHERE field_key = 'clearbooks_document_number';
