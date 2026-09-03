-- ---------------------------------------------------------------------------
-- InvoGrid — the local copy of what is already in Clear Books.
--
-- Prompt 14. This table answers one question, asked later by the matching and
-- deduplication work: *has this document already been posted?* Everything about
-- its shape follows from that.
--
-- **One table for both kinds.** Clear Books' `id` is unique across
-- `purchases/bills` and `purchases/creditNotes` — confirmed, not assumed — so
-- splitting them would buy nothing and would mean every "have I seen this id"
-- lookup asking two tables and hoping they agree. `purchase_type` records which
-- endpoint a row came from, because the two are submitted to differently and a
-- match against the wrong one is a wrong answer.
--
-- **Broken-out columns are the ones a lookup uses; everything else stays in
-- `raw_json`.** Guessing now which of Clear Books' forty-odd fields will matter
-- to a matcher nobody has written yet is how a schema acquires columns that are
-- always NULL. The whole record is kept, so a later prompt can promote a field
-- to a column with an UPDATE rather than a re-sync.
--
-- Attachment *content* is never fetched or stored. The list endpoints do not
-- return it, and a table of invoice metadata is not the place for somebody
-- else's PDFs.
--
-- **Rows are deleted, not deactivated** — the opposite of `clearbooks_cache`,
-- and for a reason that will stop being true later. A deactivated supplier is
-- kept because a document already matched against it still has to resolve; no
-- InvoGrid row points at a `clearbooks_invoices` row at all yet, so a document
-- deleted in Clear Books can simply go. If anything ever references these rows,
-- this decision has to be revisited.
-- ---------------------------------------------------------------------------

CREATE TABLE clearbooks_invoices (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Clear Books' own id, as a string: it is their identifier, not this
    -- database's, and `clearbooks_cache.remote_id` is VARCHAR(64) for the same
    -- reason. Everything downstream compares these as strings.
    clearbooks_id   VARCHAR(64) NOT NULL,

    purchase_type   ENUM('bill', 'creditNote') NOT NULL,

    -- What Clear Books calls the document — `formattedDocumentNumber`, e.g.
    -- PUR0001. Not the supplier's own invoice number; that is `reference`.
    document_number VARCHAR(100) NULL,

    -- `supplierId`, matching clearbooks_cache.remote_id for entity_type
    -- 'supplier' so the two can be joined without a cast.
    supplier_id     VARCHAR(64) NULL,

    -- Clear Books calls this field `date`. It is `document_date` here because
    -- `date` on its own reads as a type rather than a column in every query
    -- that follows, and because a credit note's date is not an invoice date.
    document_date   DATE NULL,
    due_date        DATE NULL,

    -- The supplier's own reference — their invoice number, and the field a
    -- duplicate check will lean on hardest.
    reference       VARCHAR(191) NULL,

    gross_amount    DECIMAL(14,2) NULL,

    -- The record exactly as the API returned it, minus nothing: the columns
    -- above are a fast index into this, not a replacement for it.
    raw_json        JSON NULL,

    -- When this row last matched what Clear Books said. Set explicitly by the
    -- sync rather than ON UPDATE, so a run that finds a record unchanged still
    -- stamps it — "last confirmed present" is the useful meaning, not "last
    -- altered".
    synced_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_clearbooks_invoices_remote (clearbooks_id),

    -- The lookups Prompts 17 and 18 will make: by the supplier's reference, by
    -- amount and date, by supplier, and by Clear Books' own number.
    KEY ix_clearbooks_invoices_reference (reference),
    KEY ix_clearbooks_invoices_number (document_number),
    KEY ix_clearbooks_invoices_supplier (supplier_id, document_date),
    KEY ix_clearbooks_invoices_amount (gross_amount, document_date),
    KEY ix_clearbooks_invoices_date (document_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How often the sync runs, and what happened last time.
--
-- The interval is minutes rather than a cron expression: cron already runs the
-- script every few minutes and the script decides whether it is due, which
-- means changing the schedule is a form field rather than root editing
-- /etc/cron.d. An administrator who wants a cron expression has cron.
--
-- The last run is a JSON blob rather than five columns because it is displayed
-- and never queried — and because a failed run needs to record a message, which
-- a counts table has nowhere to put.
INSERT INTO settings (setting_key, setting_value, is_secret) VALUES
    ('clearbooks_invoice_sync_interval_minutes', '60', 0),
    ('clearbooks_invoice_sync_last_run',         '',   0)
ON DUPLICATE KEY UPDATE setting_key = setting_key;
