-- ---------------------------------------------------------------------------
-- InvoGrid — store what the OCR call returned as data, not as a blob.
--
-- The n8n flow had nowhere to put structured output, so everything the model
-- found had to be flattened into a `### Notes` section appended to the
-- transcription text. That is a workaround for having no database, and InvoGrid
-- has one.
--
-- So: parse the response once, when it arrives, and keep the parts separately.
--
--   raw_text        exactly what the provider returned, untouched. The audit
--                   record — never read by a stage, only by a human asking what
--                   actually came back.
--   ocr_text        the transcription alone. What every downstream prompt is
--                   given as {{ ocrText }}, so nothing has to re-parse to get it.
--   structured_json the rest of the object: notesPresent, the annotations, the
--                   custom fields, and whatever a future prompt version adds —
--                   including confidence scores, which the current prompt does
--                   not ask for but easily could.
--   notes_present   promoted to a column because it is the one field worth
--                   filtering a list by: "which documents have handwriting on
--                   them".
--
-- The `### Notes` section stays inside ocr_text, but it is now a *rendering* for
-- Paperless and for a human comparing the scan against the record — not the
-- carrier the data has to be recovered from.
-- ---------------------------------------------------------------------------

ALTER TABLE ocr_results
    ADD COLUMN ocr_text        LONGTEXT   NULL AFTER raw_text,
    ADD COLUMN structured_json JSON       NULL AFTER ocr_text,
    ADD COLUMN notes_present   TINYINT(1) NULL AFTER structured_json,
    ADD KEY ix_ocr_results_notes (notes_present);

-- Backfill from the rows already stored. JSON_VALUE returns NULL rather than
-- erroring on a response that was not JSON, which is the behaviour wanted here:
-- a prose transcription keeps working, it simply has no structure to promote.
--
-- The CASE accepts both spellings of a JSON boolean because the engines do not
-- agree: MariaDB's JSON_VALUE unwraps `true` to the string '1', while
-- JSON_EXTRACT gives 'true'. Matching only on 'true'/'false' silently leaves
-- every row NULL — which is exactly what the first version of this did.
UPDATE ocr_results
   SET ocr_text      = COALESCE(JSON_VALUE(raw_text, '$.ocrText'), raw_text),
       notes_present = CASE LOWER(COALESCE(JSON_VALUE(raw_text, '$.notesPresent'), ''))
                           WHEN 'true'  THEN 1
                           WHEN '1'     THEN 1
                           WHEN 'false' THEN 0
                           WHEN '0'     THEN 0
                           ELSE NULL
                       END
 WHERE ocr_text IS NULL;

UPDATE ocr_results
   SET structured_json = raw_text
 WHERE structured_json IS NULL
   AND JSON_VALID(raw_text)
   AND JSON_VALUE(raw_text, '$.ocrText') IS NOT NULL;
