-- ---------------------------------------------------------------------------
-- InvoGrid — the rows the application expects to exist.
--
-- Settings are seeded with their keys present and their values empty, so the
-- Settings screen has something to render and a missing key is a bug rather
-- than an ordinary state. `is_secret = 1` means the value is stored encrypted
-- and is never sent back to a browser.
--
-- Nothing here is a credential. Real values are entered in Settings, or come
-- from .env as a fallback.
-- ---------------------------------------------------------------------------

INSERT INTO settings (setting_key, setting_value, is_secret) VALUES
    -- Who this instance says it is, on screen and on printed output.
    ('organisation_name',            'Junction Inc Ltd', 0),

    -- Paperless-ngx v3.
    ('paperless_base_url',           '', 0),
    ('paperless_token',              '', 1),

    -- The shared secret a Paperless workflow must present to the webhook
    -- receiver. Anything without it is rejected.
    ('paperless_webhook_secret',     '', 1),

    -- Clear Books: OAuth2 plus a business id, not a static API key.
    ('clearbooks_base_url',          'https://api.clearbooks.co.uk', 0),
    ('clearbooks_client_id',         '', 0),
    ('clearbooks_client_secret',     '', 1),
    ('clearbooks_access_token',      '', 1),
    ('clearbooks_refresh_token',     '', 1),
    ('clearbooks_token_expires_at',  '', 0),
    ('clearbooks_business_id',       '', 0),

    -- The address of the Clear Books web interface, as distinct from the API.
    -- Used to build the "Open in Clear Books" link a human follows to set a
    -- project code by hand.
    ('clearbooks_web_url',           'https://secure.clearbooks.co.uk', 0),

    -- How long the cached supplier / account code / VAT lists stay fresh.
    ('clearbooks_cache_ttl_minutes', '720', 0),

    -- LLM credentials. Both providers are supported throughout; which one runs
    -- is a per-stage choice below.
    ('openai_api_key',               '', 1),
    ('anthropic_api_key',            '', 1),

    -- Per-stage provider and model. The vision transcription and the
    -- structured extraction are separate choices on purpose: they have
    -- different strengths and different costs.
    ('llm_ocr_provider',             'anthropic', 0),
    ('llm_ocr_model',                'claude-sonnet-5', 0),
    ('llm_extraction_provider',      'anthropic', 0),
    ('llm_extraction_model',         'claude-sonnet-5', 0),

    -- Seconds a success message stays on screen before fading. 0 keeps it until
    -- it is dismissed; errors and warnings never auto-hide whatever this says.
    ('flash_auto_hide_seconds',      '6', 0),

    -- Header logo. Both variants are optional and either stands in for the
    -- other; with neither, the header shows the IG monogram.
    ('logo_light_path',              '', 0),
    ('logo_light_mime',              '', 0),
    ('logo_dark_path',               '', 0),
    ('logo_dark_mime',               '', 0);

-- The two purchase document types InvoGrid starts with. A third is a row here
-- plus a prompt, not a schema change.
INSERT INTO document_types (type_key, label, clearbooks_resource, amount_sign, sort_order, active) VALUES
    ('bill',        'Purchase invoice / bill', 'purchases/bills',       1,  10, 1),
    ('credit_note', 'Purchase credit note',    'purchases/creditNotes', -1, 20, 1);
