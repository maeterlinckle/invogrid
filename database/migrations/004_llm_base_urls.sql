-- ---------------------------------------------------------------------------
-- InvoGrid — optional API base URLs for the LLM providers.
--
-- Empty means "use the provider's own endpoint", which is what almost every
-- install wants. They exist for the two cases where it is not:
--
--   * a site that routes outbound API traffic through a gateway or proxy rather
--     than letting the application reach the internet directly;
--   * running the pipeline against a local stand-in, which is how the OCR stage
--     is exercised without spending money on a real call.
--
-- Not secret: a URL is not a credential, and seeing it on the Settings screen is
-- the point.
-- ---------------------------------------------------------------------------

INSERT INTO settings (setting_key, setting_value, is_secret) VALUES
    ('anthropic_base_url', '', 0),
    ('openai_base_url',    '', 0);
