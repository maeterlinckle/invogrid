-- ---------------------------------------------------------------------------
-- InvoGrid — initial schema.
--
-- The whole schema lands in one migration on purpose: the pipeline's later
-- stages (OCR, extraction, matching, review, submission) already have their
-- tables here even though only sign-in and the dashboard read them yet. A table
-- that exists from the start is a table nobody has to migrate onto a live
-- database halfway through the project.
--
-- Target: MariaDB 10.6+. JSON is MariaDB's LONGTEXT alias with a validity
-- check, which is exactly what is wanted — the application does the encoding.
-- ---------------------------------------------------------------------------

-- --- People -----------------------------------------------------------------

CREATE TABLE users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username      VARCHAR(64)  NOT NULL,
    display_name  VARCHAR(120) NOT NULL DEFAULT '',
    email         VARCHAR(190) NULL,
    password_hash VARCHAR(255) NOT NULL,

    -- A simple ordered enum for now: viewer < reviewer < admin. The full
    -- permission model replaces this later; App\Core\Auth::can() is the only
    -- place that reads it, so that is the only place that has to change.
    role          ENUM('viewer', 'reviewer', 'admin') NOT NULL DEFAULT 'reviewer',

    active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login_at DATETIME     NULL,
    last_login_ip VARCHAR(45)  NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    KEY ix_users_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Failed sign-ins, for throttling. Keyed on both the username and the client
-- address so neither one account nor one source can be hammered.
CREATE TABLE login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username     VARCHAR(190) NOT NULL,
    ip_address   VARCHAR(45)  NOT NULL,
    successful   TINYINT(1)   NOT NULL DEFAULT 0,
    user_agent   VARCHAR(255) NULL,
    attempted_at DATETIME     NOT NULL,

    PRIMARY KEY (id),
    KEY ix_login_attempts_username (username, attempted_at),
    KEY ix_login_attempts_ip (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Configuration ----------------------------------------------------------

-- Key/value application settings. `is_secret` marks a row whose value is stored
-- encrypted (App\Core\Crypto, AES-256-GCM under APP_KEY) and must never be
-- rendered back to a browser: the Paperless token, the Clear Books OAuth2
-- client secret and tokens, the LLM API keys and the webhook shared secret.
CREATE TABLE settings (
    setting_key   VARCHAR(100) NOT NULL,
    setting_value MEDIUMTEXT   NULL,
    is_secret     TINYINT(1)   NOT NULL DEFAULT 0,
    updated_by    INT UNSIGNED NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (setting_key),
    KEY fk_settings_user (updated_by),
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The document types InvoGrid knows how to process.
--
-- This is data, not structure: adding a supplier statement or an expense claim
-- later is a row here plus a prompt, not an ALTER TABLE. documents.doc_type and
-- extractions.doc_type hold type_key rather than an enum for the same reason.
--
-- paperless_document_type_id is the Paperless document-type this maps onto,
-- written back to the document after submission. It lives here rather than as a
-- settings row because it is per-document-type by nature, and keeping it beside
-- the type keeps "add a type" to one insert.
CREATE TABLE document_types (
    id                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    type_key                   VARCHAR(32)  NOT NULL,
    label                      VARCHAR(80)  NOT NULL,

    -- The Clear Books resource a document of this type is submitted to, e.g.
    -- purchases/bills or purchases/creditNotes.
    clearbooks_resource        VARCHAR(64)  NOT NULL,

    -- 1 for a bill, -1 for a credit note: which way the money goes, so totals
    -- and validation do not need a special case per type.
    amount_sign                TINYINT      NOT NULL DEFAULT 1,

    paperless_document_type_id INT UNSIGNED NULL,
    sort_order                 SMALLINT     NOT NULL DEFAULT 0,
    active                     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at                 TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_document_types_key (type_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Versioned LLM prompts, edited in the application rather than in the source.
-- A key has many versions and exactly one active one; an edit writes a new
-- version rather than overwriting, so a bad prompt can be rolled back and an
-- extraction can say which prompt produced it.
CREATE TABLE prompt_templates (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_key VARCHAR(64)  NOT NULL,
    version      INT UNSIGNED NOT NULL DEFAULT 1,
    label        VARCHAR(120) NOT NULL DEFAULT '',
    content      LONGTEXT     NOT NULL,
    is_active    TINYINT(1)   NOT NULL DEFAULT 0,
    updated_by   INT UNSIGNED NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_prompt_templates_version (template_key, version),
    KEY ix_prompt_templates_active (template_key, is_active),
    KEY fk_prompt_templates_user (updated_by),
    CONSTRAINT fk_prompt_templates_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hand-annotated and document-specific fields to pull out of the page — a
-- "Clearbooks Number" written in a corner, a circled project code.
--
-- data_type mirrors Paperless's own custom-field types so an extracted value
-- maps straight onto the Paperless field it is paired with. documentlink is
-- deliberately absent: it has no meaning for a value read off a page.
CREATE TABLE custom_fields (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    field_key          VARCHAR(64)  NOT NULL,
    label              VARCHAR(120) NOT NULL,
    data_type          ENUM('string', 'url', 'date', 'boolean', 'integer',
                            'float', 'monetary', 'select', 'longtext')
                       NOT NULL DEFAULT 'string',

    -- Only meaningful for data_type = select: the choices Paperless holds for
    -- that field, cached so a prompt can offer them.
    select_options     JSON         NULL,

    paperless_field_id INT UNSIGNED NULL,

    -- Told to the LLM verbatim: where on the page to look, what the annotation
    -- usually looks like, what to do when it is absent.
    prompt_hint        TEXT         NULL,

    sort_order         SMALLINT     NOT NULL DEFAULT 0,
    active             TINYINT(1)   NOT NULL DEFAULT 1,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_custom_fields_key (field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- Documents and the pipeline ---------------------------------------------

-- One row per Paperless document InvoGrid has been told about.
--
-- status is the pipeline state machine:
--   received -> ocr_pending -> ocr_done -> extracting -> extracted
--            -> matching -> needs_review -> ready_to_submit -> submitted
-- with failed reachable from any stage and retryable, and ignored for a
-- document a human has decided is not ours to process.
CREATE TABLE documents (
    id                                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    paperless_doc_id                  INT UNSIGNED NOT NULL,

    status ENUM('received', 'ocr_pending', 'ocr_done', 'extracting', 'extracted',
                'matching', 'needs_review', 'ready_to_submit', 'submitted',
                'failed', 'ignored')
           NOT NULL DEFAULT 'received',

    -- The document_types.type_key this document was classified as; null until
    -- extraction has decided.
    doc_type                          VARCHAR(32)  NULL,

    -- The issuer as it appears on the page or in Paperless, before matching.
    correspondent_raw                 VARCHAR(255) NULL,

    -- The Clear Books supplier this resolved to. A remote id, so VARCHAR rather
    -- than a foreign key — Clear Books owns that identifier, not this database.
    correspondent_matched_supplier_id VARCHAR(64)  NULL,

    -- Where the source PDF and its rendered page images live, relative to the
    -- storage path.
    pdf_path                          VARCHAR(255) NULL,
    page_count                        SMALLINT UNSIGNED NULL,

    -- Retry bookkeeping. A stage that fails records which one it was and leaves
    -- the document retryable rather than dropping it.
    failed_stage                      VARCHAR(32)  NULL,
    error_message                     TEXT         NULL,
    attempts                          SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- Held by whichever process is working the document, so two runs of the
    -- pipeline cannot process it at once.
    locked_at                         DATETIME     NULL,

    created_at                        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_documents_paperless (paperless_doc_id),
    KEY ix_documents_status (status, created_at),
    KEY ix_documents_doc_type (doc_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One rendered image per page, fed to the vision OCR stage.
CREATE TABLE document_pages (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id INT UNSIGNED NOT NULL,
    page_number SMALLINT UNSIGNED NOT NULL,
    image_path  VARCHAR(255) NOT NULL,
    width       SMALLINT UNSIGNED NULL,
    height      SMALLINT UNSIGNED NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_document_pages_page (document_id, page_number),
    CONSTRAINT fk_document_pages_document FOREIGN KEY (document_id)
        REFERENCES documents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The transcription of a document's pages. Kept per run rather than overwritten
-- so a re-OCR with a different model can be compared against the old one.
CREATE TABLE ocr_results (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id        INT UNSIGNED NOT NULL,
    llm_provider       VARCHAR(32)  NOT NULL,
    llm_model          VARCHAR(80)  NOT NULL,
    raw_text           LONGTEXT     NULL,
    prompt_template_id INT UNSIGNED NULL,
    prompt_tokens      INT UNSIGNED NULL,
    completion_tokens  INT UNSIGNED NULL,
    duration_ms        INT UNSIGNED NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_ocr_results_document (document_id, created_at),
    CONSTRAINT fk_ocr_results_document FOREIGN KEY (document_id)
        REFERENCES documents (id) ON DELETE CASCADE,
    CONSTRAINT fk_ocr_results_prompt FOREIGN KEY (prompt_template_id)
        REFERENCES prompt_templates (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the extraction stages made of one OCR result.
--
-- The several focused LLM calls (header, supplier match, line items, custom
-- fields) all write into one row: they are facets of a single reading of a
-- single document, and the review screen wants them together.
--
-- review_notes accumulates the short flags each call raises — an ambiguous due
-- date, an uncertain account code, totals that do not add up. A stage that is
-- unsure still returns its best guess and adds a note; it never returns a bare
-- null and never guesses silently. needs_review is true whenever that list is
-- non-empty or a required entity is unmatched.
CREATE TABLE extractions (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id         INT UNSIGNED NOT NULL,
    ocr_result_id       INT UNSIGNED NULL,
    doc_type            VARCHAR(32)  NULL,

    paperless_title     VARCHAR(255) NULL,
    cb_summary          VARCHAR(255) NULL,
    supplier_name_raw   VARCHAR(255) NULL,
    invoice_number      VARCHAR(100) NULL,
    invoice_date        DATE         NULL,
    due_date            DATE         NULL,
    paid_date           DATE         NULL,

    net_amount          DECIMAL(14,2) NULL,
    vat_amount          DECIMAL(14,2) NULL,
    gross_amount        DECIMAL(14,2) NULL,
    currency            CHAR(3)       NULL,

    -- The document-level VAT treatment as extracted, before matching against
    -- the Clear Books list.
    vat_treatment       JSON         NULL,

    -- One object per line: description, quantity, unit price, net, VAT rate,
    -- account code, and the best-guess matches for the last two.
    line_items          JSON         NULL,

    -- Keyed by custom_fields.field_key.
    custom_field_values JSON         NULL,

    -- Per-field confidence, keyed the same way as the columns above.
    confidence          JSON         NULL,

    -- Short human-readable flags, one per thing a stage was unsure of.
    review_notes        JSON         NULL,

    needs_review        TINYINT(1)   NOT NULL DEFAULT 1,

    llm_provider        VARCHAR(32)  NULL,
    llm_model           VARCHAR(80)  NULL,
    prompt_template_id  INT UNSIGNED NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_extractions_document (document_id, created_at),
    KEY ix_extractions_review (needs_review),
    CONSTRAINT fk_extractions_document FOREIGN KEY (document_id)
        REFERENCES documents (id) ON DELETE CASCADE,
    CONSTRAINT fk_extractions_ocr FOREIGN KEY (ocr_result_id)
        REFERENCES ocr_results (id) ON DELETE SET NULL,
    CONSTRAINT fk_extractions_prompt FOREIGN KEY (prompt_template_id)
        REFERENCES prompt_templates (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every entity an extraction had to resolve against Clear Books, matched or
-- not. This is the review queue's work list: a row with status unmatched is
-- something a human has to decide about.
--
-- line_index is the zero-based position in extractions.line_items the value
-- came from, and null for a document-level entity (the supplier, the document's
-- VAT treatment). Account codes and VAT rates are per line, so without it two
-- lines guessing different codes could not be told apart.
CREATE TABLE entity_matches (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    extraction_id INT UNSIGNED NOT NULL,
    entity_type   ENUM('supplier', 'account_code', 'vat_rate', 'vat_treatment') NOT NULL,
    line_index    SMALLINT UNSIGNED NULL,

    raw_value     VARCHAR(255) NOT NULL,

    -- Clear Books' own identifier for the matched record, or null when nothing
    -- matched. Nothing below full confidence is auto-created.
    matched_id    VARCHAR(64)  NULL,
    matched_name  VARCHAR(255) NULL,
    matched_via   ENUM('llm', 'code_fallback', 'manual') NULL,
    confidence    DECIMAL(4,3) NULL,
    status        ENUM('matched', 'unmatched', 'created', 'rejected') NOT NULL DEFAULT 'unmatched',

    note          VARCHAR(255) NULL,
    resolved_by   INT UNSIGNED NULL,
    resolved_at   DATETIME     NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_entity_matches_extraction (extraction_id, entity_type),
    KEY ix_entity_matches_status (status),
    KEY fk_entity_matches_user (resolved_by),
    CONSTRAINT fk_entity_matches_extraction FOREIGN KEY (extraction_id)
        REFERENCES extractions (id) ON DELETE CASCADE,
    CONSTRAINT fk_entity_matches_user FOREIGN KEY (resolved_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A local copy of the Clear Books lists the pipeline matches against:
-- suppliers, purchase account codes, purchase VAT rates and VAT treatments, and
-- projects. Refreshed periodically; every prompt and every fallback pass reads
-- from here rather than calling Clear Books mid-pipeline.
--
-- normalised_name is the deterministic fallback's comparison key: lower case,
-- punctuation and legal suffixes stripped, ampersand folded to "and". Indexed
-- because that pass looks up by it.
--
-- paperless_correspondent_id is populated only for entity_type = supplier and
-- drives the supplier-to-correspondent sync.
CREATE TABLE clearbooks_cache (
    id                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type                VARCHAR(32)  NOT NULL,
    remote_id                  VARCHAR(64)  NOT NULL,
    name                       VARCHAR(255) NOT NULL,
    normalised_name            VARCHAR(255) NOT NULL DEFAULT '',
    raw_json                   JSON         NULL,
    paperless_correspondent_id INT UNSIGNED NULL,
    active                     TINYINT(1)   NOT NULL DEFAULT 1,
    cached_at                  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_clearbooks_cache_entity (entity_type, remote_id),
    KEY ix_clearbooks_cache_normalised (entity_type, normalised_name),
    KEY ix_clearbooks_cache_correspondent (paperless_correspondent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What was sent to Clear Books, and what came back.
CREATE TABLE submissions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id     INT UNSIGNED NOT NULL,

    -- The Clear Books resource submitted to, e.g. purchases/bills.
    clearbooks_type VARCHAR(64)  NOT NULL,
    clearbooks_id   VARCHAR(64)  NULL,

    -- The record's address in the Clear Books web interface. Kept because Clear
    -- Books has no API for a purchase line's project code, so every submitted
    -- document offers an "Open in Clear Books" action for a human to set it by
    -- hand.
    clearbooks_url  VARCHAR(255) NULL,

    status          ENUM('success', 'failed') NOT NULL DEFAULT 'success',
    submitted_by    INT UNSIGNED NULL,
    submitted_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    response_json   JSON         NULL,

    PRIMARY KEY (id),
    KEY ix_submissions_document (document_id, submitted_at),
    KEY fk_submissions_user (submitted_by),
    CONSTRAINT fk_submissions_document FOREIGN KEY (document_id)
        REFERENCES documents (id) ON DELETE CASCADE,
    CONSTRAINT fk_submissions_user FOREIGN KEY (submitted_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every run of a pipeline stage, whether it worked or not.
--
-- Separate from audit_log, which records what people did: this records what the
-- machine did, and is what makes a failed stage retryable rather than lost.
CREATE TABLE document_events (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id INT UNSIGNED NOT NULL,
    stage       VARCHAR(32)  NOT NULL,
    status      ENUM('started', 'succeeded', 'failed', 'skipped') NOT NULL,
    message     TEXT         NULL,
    duration_ms INT UNSIGNED NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_document_events_document (document_id, created_at),
    CONSTRAINT fk_document_events_document FOREIGN KEY (document_id)
        REFERENCES documents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The work queue. The webhook receiver answers Paperless immediately and leaves
-- a job here; a worker picks it up. An LLM call takes tens of seconds, which is
-- far too long to hold a webhook open.
CREATE TABLE pipeline_jobs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id  INT UNSIGNED NOT NULL,
    stage        VARCHAR(32)  NOT NULL,
    status       ENUM('queued', 'running', 'done', 'failed') NOT NULL DEFAULT 'queued',
    attempts     SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    -- When the job becomes eligible to run: now for a fresh job, later for a
    -- retry backing off after a failure.
    available_at DATETIME     NOT NULL,
    started_at   DATETIME     NULL,
    finished_at  DATETIME     NULL,
    last_error   TEXT         NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_pipeline_jobs_claim (status, available_at),
    KEY ix_pipeline_jobs_document (document_id, created_at),
    CONSTRAINT fk_pipeline_jobs_document FOREIGN KEY (document_id)
        REFERENCES documents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What people did. Kept when the user or the document is deleted — an audit
-- trail that disappears with its subject is not an audit trail — hence the
-- nullable columns and ON DELETE SET NULL.
CREATE TABLE audit_log (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NULL,
    document_id INT UNSIGNED NULL,
    action      VARCHAR(64)  NOT NULL,
    details     TEXT         NULL,
    ip_address  VARCHAR(45)  NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY ix_audit_log_created (created_at),
    KEY ix_audit_log_document (document_id, created_at),
    KEY fk_audit_log_user (user_id),
    CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_log_document FOREIGN KEY (document_id)
        REFERENCES documents (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
