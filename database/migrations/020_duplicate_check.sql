-- ---------------------------------------------------------------------------
-- InvoGrid — the duplicate check on the New Invoice route.
--
-- Prompt 18. Migration 019 finished the Existing Invoice route: a document
-- carrying a handwritten Clearbooks Number is a scan of a record that already
-- exists, and it is matched to that record rather than posted. This migration
-- deals with the case that annotation is *missing*.
--
-- **The gap.** The whole Existing Invoice branch turns on somebody having
-- written a number on the page in red pen. An invoice already in Clear Books —
-- entered by hand months ago, or scanned once before under a different image —
-- carries no such number, so it runs the New Invoice route from end to end and
-- arrives at the review queue looking exactly like a bill nobody has posted.
-- Submitting it puts the same purchase into somebody's accounts twice, and that
-- is discovered by a payment run rather than by this application.
--
-- So a New Invoice document is checked against `clearbooks_invoices` — the
-- local copy migration 016 created and Prompt 14's sync fills — *before* it is
-- offered for submission.
--
-- **`possible_duplicate`.** A new status, because the answer is a judgement and
-- there is nowhere else honest to put a document waiting for one:
--
--   * not `needs_review`, which is about resolving entities so a record can be
--     created. Mixing the two would put "correct this account code" and "this
--     bill may already be in the accounts" in one list under one heading, and
--     the second is not a data-entry question;
--   * not `needs_link`, which is the Existing Invoice queue. That screen asks
--     which Clear Books record a *known* reference points at; this one asks
--     whether a document with no reference at all is nevertheless one Clear
--     Books already holds;
--   * not `ignored`, because nothing has been decided yet.
--
-- It sits between `matching` and `needs_review` in the ordered status list,
-- which is its real position: the gate a New Invoice document passes through on
-- its way to a disposition, not a step after one.
--
-- **Nothing auto-resolves out of it**, exactly as with `needs_link`. The two
-- answers are the two in the prompt: delete the InvoGrid document when it is
-- genuinely the same invoice, or confirm it is genuinely new and push it on.
-- There is no third machine outcome, because the machine has already had its
-- go — a comparison it could settle by itself would not have stopped here.
--
-- **`duplicate_cleared_at` / `duplicate_cleared_by`.** Confirming "this is not
-- a duplicate" has to be remembered, or the re-match that follows would fire
-- the same check, find the same candidates and put the document straight back
-- in the queue. Two columns rather than a table: there is one such decision per
-- document, it is made once, and the narrative of what was compared and by whom
-- is already written to `document_events` and `audit_log`.
--
-- **No settings row, and no tolerance.** The comparison is the one Prompt 17
-- built — the same day, the same pence, unsigned — applied to more fields, and
-- `tests/smoke.php` still asserts that no setting containing "tolerance"
-- exists. The natural off switch is the sync itself: a `clearbooks_invoices`
-- table nobody has filled matches nothing, and every document flows through
-- exactly as it did before this prompt.
--
-- No new table for the candidates, either. They are recomputed when the queue
-- screen is opened, for the reason §33 gives for re-running the Clearbooks
-- Number lookup: the invoice sync runs on a schedule, and a stored comparison
-- that was true an hour ago is exactly what would send somebody off to judge a
-- document against a record that has since changed.
-- ---------------------------------------------------------------------------

ALTER TABLE documents
    MODIFY COLUMN status ENUM('received', 'ocr_pending', 'ocr_done', 'extracting',
                              'extracted', 'matching', 'possible_duplicate',
                              'needs_review', 'ready_to_submit',
                              'existing_invoice', 'needs_link', 'submitted', 'failed',
                              'ignored')
           NOT NULL DEFAULT 'received';

-- When somebody said "this is genuinely new", and who.
--
-- NULL means nobody has been asked, which is not the same as "not a duplicate":
-- the overwhelming majority of documents are never plausible duplicates and are
-- never stopped, so they reach `submitted` with both columns still empty.
--
-- `duplicate_cleared_by` is ON DELETE SET NULL rather than cascading, for the
-- same reason `documents.ingested_by` is: deactivating and then deleting a
-- leaver's account must not take the decisions they made with it.
ALTER TABLE documents
    ADD COLUMN duplicate_cleared_at TIMESTAMP NULL DEFAULT NULL AFTER locked_at,
    ADD COLUMN duplicate_cleared_by INT UNSIGNED NULL DEFAULT NULL AFTER duplicate_cleared_at,
    ADD KEY fk_documents_duplicate_cleared_by (duplicate_cleared_by),
    ADD CONSTRAINT fk_documents_duplicate_cleared_by
        FOREIGN KEY (duplicate_cleared_by) REFERENCES users (id) ON DELETE SET NULL;
