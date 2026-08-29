-- ---------------------------------------------------------------------------
-- InvoGrid — what the review screen and the submission need.
--
-- 1. Custom fields have two very different origins.
--
--    Until now every row in `custom_fields` was something read off the scanned
--    page: the handwritten Clearbooks Number, the project code. The write-back
--    adds fields of the opposite kind — the Clear Books bill id, the document
--    number Clear Books assigned — which are *produced* by the submission and
--    written into Paperless afterwards.
--
--    They cannot share a code path. `CustomField::forPrompt()` feeds the
--    extraction prompt, and asking a vision model to find a Clear Books bill id
--    on a supplier's invoice is asking it to invent one: the number does not
--    exist until InvoGrid creates the record. Hence `source`.
--
-- 2. Where the write-back's other targets live.
--
--    The Paperless document type is already on `document_types`. The tag and
--    the storage path are one-per-instance, so they are settings.
-- ---------------------------------------------------------------------------

ALTER TABLE custom_fields
    ADD COLUMN source ENUM('extracted', 'submission') NOT NULL DEFAULT 'extracted' AFTER data_type;

-- 3. Whether a person has corrected the reading, and who.
--
--    An extraction is a record of what a model said at a moment, which is why
--    almost nothing may rewrite one. A reviewer's corrections are the exception,
--    and they have to be visible as such: an extraction that no longer matches
--    what the model returned must not look like one that does — a later reader
--    comparing two runs would otherwise be comparing a reading against a
--    reading-plus-edits and not know it.
ALTER TABLE extractions
    ADD COLUMN edited_at DATETIME     NULL AFTER needs_review,
    ADD COLUMN edited_by INT UNSIGNED NULL AFTER edited_at,
    ADD KEY fk_extractions_editor (edited_by),
    ADD CONSTRAINT fk_extractions_editor FOREIGN KEY (edited_by)
        REFERENCES users (id) ON DELETE SET NULL;

-- The two the write-back fills in. `paperless_field_id` stays null until
-- somebody pairs them with a real Paperless custom field, and the write-back
-- skips anything unpaired rather than guessing an id.
INSERT INTO custom_fields (field_key, label, data_type, source, prompt_hint, sort_order, active) VALUES
    ('clearbooks_bill_id', 'Clear Books ID', 'string', 'submission',
     'Written back after submission — the id Clear Books gave the created record. Never read off the page.',
     100, 1),
    ('clearbooks_document_number', 'Clear Books number', 'string', 'submission',
     'Written back after submission — the document number Clear Books assigned. Never read off the page.',
     101, 1)
ON DUPLICATE KEY UPDATE field_key = field_key;

INSERT INTO settings (setting_key, setting_value, is_secret) VALUES
    -- The tag put on a Paperless document once it has reached Clear Books.
    -- Empty means "do not tag", which is a legitimate way to run this.
    ('paperless_processed_tag_id', '', 0),

    -- Whether the write-back replaces the Paperless document's `content` with
    -- InvoGrid's own transcription. On, matching the flow this replaces: the
    -- LLM reading is better than Paperless's OCR engine on a scanned invoice,
    -- and it is what makes the handwritten annotations searchable. Off leaves
    -- Paperless's own text alone.
    ('paperless_replace_content', '1', 0),

    -- Whether a submitted document's PDF is attached to the Clear Books record.
    -- On: the accounts package should hold the evidence, not just a reference
    -- to it.
    ('clearbooks_attach_pdf', '1', 0)
ON DUPLICATE KEY UPDATE setting_key = setting_key;
