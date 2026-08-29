-- ---------------------------------------------------------------------------
-- InvoGrid — what the extraction stage needs beyond the existing columns.
--
-- 1. Somewhere to keep the supplier call's answer.
--
--    The supplier prompt returns more than a name: whether it matched, the Clear
--    Books id and the Paperless correspondent id if it did, and — if it did not
--    — the legal name, trading names, address, VAT number and company number
--    needed to create the supplier. `supplier_name_raw` holds one string of
--    that; the rest had nowhere to go, and `tradingNames` in particular would
--    have been dropped on the floor.
--
--    It is deliberately the *whole* answer rather than a set of columns: the
--    matching stage is what turns it into `entity_matches` rows, and until then
--    it is a record of what the model said, not a decision.
--
-- 2. The two custom fields the pipeline has always read.
--
--    These are the "hard-coded high-value fields" the OCR prompt already pulls
--    out. Making them rows means the extraction stage treats them like any
--    other field, and Prompt 8's screen manages them without a special case.
--    `paperless_field_id` stays null until somebody pairs them up.
-- ---------------------------------------------------------------------------

ALTER TABLE extractions
    ADD COLUMN supplier_match JSON NULL AFTER vat_treatment;

INSERT INTO custom_fields (field_key, label, data_type, prompt_hint, sort_order, active) VALUES
    ('clearbooks_number', 'Clearbooks Number', 'string',
     'A handwritten number, almost always in RED pen, purely numeric (digits only). Usually preceded by "#" but not always; may or may not have a hand-drawn circle around it. Frequently absent — never substitute a printed number such as the supplier''s own invoice number, an account number or a purchase-order number. If it is not handwritten on the page, it is not there.',
     10, 1),

    ('project', 'Project', 'string',
     'A short code, normally 2 letters followed by 2 numbers (e.g. "AB24"), occasionally up to 4 letters before the numbers. May be handwritten, printed with a hand-drawn circle around it, or plain printed text. Prefer a handwritten or circled one over plain printed text where there is more than one candidate.',
     20, 1);
