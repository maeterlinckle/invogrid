-- ---------------------------------------------------------------------------
-- InvoGrid — the Existing / New Invoice branch, and the annotation fields as
-- columns.
--
-- Prompt 16. Three changes, and the first two are the same change seen from
-- opposite ends.
--
-- **The `### Notes` section goes.** It was the n8n flow's only way of carrying
-- what the model found by hand: with nowhere to put structure, every annotation
-- had to be flattened into prose and appended to the transcription. InvoGrid
-- has stored the structured half since migration 006 and the section has been a
-- second, lossier copy of it ever since — one that downstream prompts then had
-- to be told to ignore, and that put text into the permanent record of a page
-- which is not printed on that page. `ocrText` is now the transcription and
-- nothing else.
--
-- **The annotation fields become columns.** `structured_json` already holds
-- them, which is enough for a template to render but not for a stage to route
-- on: routing wants one indexed value it can test without decoding a blob per
-- document. So `clearbooks_number`, `project_code` and `annotations_json` are
-- promoted beside `notes_present`, which was promoted for the same reason.
-- `structured_json` stays as the whole object — a prompt that starts reporting
-- confidence scores still has somewhere to put them without a migration.
--
-- **The branch.** The Clearbooks Number is a handwritten reference to an
-- invoice already in Clear Books, so a document carrying one is not a new bill
-- to post — it is a scan belonging to a record that exists. That is a different
-- job from the extraction pipeline, and it is decided the moment the
-- transcription lands rather than four stages later.
--
-- Modelled with two columns, because two different questions are being asked:
--
--   documents.status   where the document is *now*. Gains `existing_invoice`,
--                      the head of the flow Prompt 17 builds. Nothing runs that
--                      status yet, so a document reaching it waits there
--                      visibly, which is the honest thing for it to do.
--   documents.route    which flow it is *on*, decided once and kept. The status
--                      alone cannot answer this: `ocr_done` is the new-invoice
--                      path's head, and once the existing-invoice flow rejoins
--                      the ordinary statuses further down, a document that took
--                      the branch would be indistinguishable from one that did
--                      not. NULL until OCR decides — a document that has not
--                      been read is not on either flow, and saying it is on the
--                      ordinary one would be a guess.
-- ---------------------------------------------------------------------------

-- --- documents ---------------------------------------------------------------

ALTER TABLE documents
    MODIFY COLUMN status ENUM('received', 'ocr_pending', 'ocr_done', 'existing_invoice',
                              'extracting', 'extracted', 'matching', 'needs_review',
                              'ready_to_submit', 'submitted', 'failed', 'ignored')
           NOT NULL DEFAULT 'received',
    ADD COLUMN route ENUM('new_invoice', 'existing_invoice') NULL
        COMMENT 'Which flow the OCR stage sent this down. NULL until it has been read.' AFTER status,
    ADD KEY ix_documents_route (route, status);

-- Every document that has been read went down the only flow there was, and that
-- flow is the new-invoice one. Saying so is a statement of what happened, not a
-- re-decision: a document that was submitted as a new bill last week is not
-- retrospectively an existing-invoice document because it turns out to have a
-- number pencilled on it.
UPDATE documents d
   SET d.route = 'new_invoice'
 WHERE d.route IS NULL
   AND EXISTS (SELECT 1 FROM ocr_results o WHERE o.document_id = d.id);

-- --- ocr_results -------------------------------------------------------------
--
-- `clearbooks_number` is VARCHAR rather than an integer even though the prompt
-- asks for digits only. What the model returns is what it read off a scan, and
-- a column that refuses "8042I" cannot store the thing that has to be shown to
-- a person so they can see why it did not route. Whether it is usable as a
-- number is a question the stage asks, not one the column answers.

ALTER TABLE ocr_results
    ADD COLUMN clearbooks_number VARCHAR(32) NULL
        COMMENT 'The OCR response''s clearbooksNumber, trimmed. Its presence is what routes the document.'
        AFTER notes_present,
    ADD COLUMN project_code      VARCHAR(32) NULL
        COMMENT 'The OCR response''s project.'
        AFTER clearbooks_number,
    ADD COLUMN annotations_json  JSON        NULL
        COMMENT 'The OCR response''s handwrittenAnnotations array, on its own.'
        AFTER project_code,
    ADD KEY ix_ocr_results_clearbooks (clearbooks_number);

-- Backfill from the structure already stored, so a document read before this
-- migration reports its annotations the same way as one read after it. The
-- leading "#" goes here for the same reason it goes in the stage: the prompt
-- says the number is usually written with one, and "#80421" and "80421" are the
-- same reference.
UPDATE ocr_results
   SET clearbooks_number = NULLIF(TRIM(LEADING '#' FROM TRIM(JSON_VALUE(structured_json, '$.clearbooksNumber'))), ''),
       project_code      = NULLIF(TRIM(JSON_VALUE(structured_json, '$.project')), ''),
       annotations_json  = CASE
                               WHEN JSON_TYPE(JSON_EXTRACT(structured_json, '$.handwrittenAnnotations')) = 'ARRAY'
                                   THEN JSON_EXTRACT(structured_json, '$.handwrittenAnnotations')
                               ELSE NULL
                           END
 WHERE structured_json IS NOT NULL;

-- --- the OCR prompt, version 3 -----------------------------------------------
--
-- Step 4 asked for the notes section and is gone; a new Step 4 says plainly
-- that the transcription is the transcription, because a model given Steps 2
-- and 3 and no instruction about what to do with them will helpfully summarise
-- them at the end of the page anyway. Steps 1 to 3 are untouched, and so is the
-- output shape apart from `ocrText`'s own description.
--
-- Seeded from the text that was active when this migration was written rather
-- than retyped, which is why the wording of every surviving rule is identical
-- to version 2's to the character.

UPDATE prompt_templates SET is_active = 0 WHERE template_key = 'ocr';

INSERT INTO prompt_templates (template_key, version, label, origin, content, is_active)
SELECT 'ocr',
       COALESCE(MAX(version), 0) + 1,
       'Transcription only — the annotations come back as data',
       'seed',
       'You are performing OCR transcription and handwritten-annotation detection on a scanned invoice/bill document for Junction Inc Ltd. Your output will be used as the permanent text record of this document and as input to further automated processing, so accuracy and completeness are critical.

The images you are given are the pages of ONE document, in order. Transcribe across all of them as a single continuous record, and treat Steps 2 and 3 below as applying to the whole document rather than to any one page.

## Step 1 — Transcription

Transcribe all text visible in the image, in natural reading order, top to bottom, preserving structure with Markdown (headings, tables, line breaks, lists). Include footers, small print, stamps, and margin text, checking corners and margins carefully. Transcribe printed text exactly as shown — do not correct, paraphrase, or summarise. Do not annotate handwriting or ink colour inline within this main transcription — that''s handled separately below.

## Step 2 — Handwritten annotations

Identify every handwritten mark, circled item, underline, or other non-printed annotation anywhere on the page. For each, note:
- text: what it says, if legible
- inkColor: if identifiable, else null
- marksPrintedText: the printed text it circles/underlines/boxes, if any, else null
- location: rough position on the page (e.g. "top right margin", "next to invoice total")

Set `notesPresent` to `true` if any such annotation exists anywhere on the page, otherwise `false`. If `notesPresent` is `false`, `handwrittenAnnotations` must be an empty array.

## Step 3 — Custom fields

Independently of Step 2 (these may exist even without other handwritten marks, or be absent even when other marks are present):
- `clearbooksNumber`: a handwritten number, almost always in RED pen, purely numeric (digits only — a circled code containing letters is a Project, not this). Usually preceded by "#" but not always; may or may not have a hand-drawn circle around it. Frequently absent — do not guess or substitute a printed number for it. If not found, use null.
- `project`: a short code normally 2 letters + 2 numbers (e.g. "AB24"), occasionally up to 4 letters before the numbers. May be handwritten, printed with a hand-drawn circle around it, or plain printed text. If not found, use null.

## Step 4 — Leave the transcription alone

`ocrText` is the transcription from Step 1 and nothing else. Do not append a notes section, a summary, or any restatement of Steps 2 and 3 to it. Those are returned separately below as structured fields, which is where anything downstream reads them from; repeating them inside `ocrText` would put text into the permanent record of the page that is not on the page.

## Output format

Return ONLY the following JSON object — no preamble, no explanation, no Markdown code fences:

{
  "ocrText": "string — the full transcription from Step 1, and nothing else",
  "notesPresent": true,
  "handwrittenAnnotations": [
    {
      "text": "string",
      "inkColor": null,
      "marksPrintedText": null,
      "location": "string"
    }
  ],
  "clearbooksNumber": null,
  "project": null
}',
       1
  FROM prompt_templates
 WHERE template_key = 'ocr';

-- --- extract_header ----------------------------------------------------------
--
-- One sentence, telling the model to ignore everything from "### Notes" onward.
-- There is no such section to ignore now, and an instruction about a landmark
-- that is not there is worse than no instruction: a model that goes looking for
-- it and finds a heading somewhere in the transcription will do as it was told.
--
-- `extract_supplier` and `extract_lines` never mentioned the section and are
-- untouched.

UPDATE prompt_templates SET is_active = 0 WHERE template_key = 'extract_header';

INSERT INTO prompt_templates (template_key, version, label, origin, content, is_active)
SELECT 'extract_header',
       COALESCE(MAX(version), 0) + 1,
       'Title, summary, dates, reference, currency',
       'seed',
       'You are an invoice-processing assistant for Junction Inc Ltd. You will be given the OCR text of a supplier invoice/bill. Extract the title, summary, and header details below.

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

-- --- extract_custom_fields ---------------------------------------------------
--
-- This one leaned on the notes section hardest: it was told to read it first,
-- because the fields it is asked about are usually the handwritten ones. Taking
-- the section away without replacing it would have made the fallback call
-- markedly worse at the job it exists to do.
--
-- So the annotations are handed over as themselves, in a new `{{ annotations }}`
-- variable, which is the same trade the rest of this migration makes: the model
-- gets a list of objects with ink colour and location intact instead of a
-- flattened bullet list, and nobody has to parse prose back into fields.

UPDATE prompt_templates SET is_active = 0 WHERE template_key = 'extract_custom_fields';

INSERT INTO prompt_templates (template_key, version, label, origin, content, is_active)
SELECT 'extract_custom_fields',
       COALESCE(MAX(version), 0) + 1,
       'Fields the annotation fast path did not resolve',
       'seed',
       'You are extracting specific named fields from the OCR text of a supplier invoice/bill for Junction Inc Ltd.

<ocr_text>
{{ ocrText }}
</ocr_text>

<fields>
{{ customFields }}
</fields>

<annotations>
{{ annotations }}
</annotations>

For each field in <fields>, find its value in the OCR text and return it under that field''s key.

- The "hint" on each field describes where the value usually sits and what it looks like. Follow it.
- <annotations> is every handwritten mark the transcription pass found, each with what it says, its ink colour, what printed text it marks and roughly where it sits. Check it first: these fields are most often handwritten. It lists what is on the page, not these fields — match an entry to a field yourself, and ignore the entries that answer none of them.
- Return the value in the form the "type" asks for: a date as YYYY-MM-DD, a number as a number, a boolean as true/false, anything else as a string.
- If a field has "options", the value must be one of them.
- **If a field is not on the document, return null for it.** Do not guess, and do not substitute a printed value that merely looks similar — for these fields a null is a correct and expected answer, and a wrong value is far worse than none.
- Add a short note to reviewNotes for any field you found but were not confident about, and for any field where more than one candidate appeared.

## Output format

Return ONLY the following JSON object — no preamble, no explanation, no Markdown code fences. `values` must contain one entry for every field in <fields>, keyed by its "key", using null where the field was not found:

{
  "values": {},
  "reviewNotes": []
}',
       1
  FROM prompt_templates
 WHERE template_key = 'extract_custom_fields';
