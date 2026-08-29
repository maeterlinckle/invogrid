-- ---------------------------------------------------------------------------
-- InvoGrid — which prompt versions came with the application.
--
-- "Reset to default" needs to know what the default *is*, and until now nothing
-- did. Version 1 is not a reliable answer: the OCR prompt's version 1 was
-- written to a specification before the real text was available, and version 2
-- — the production prompt from the n8n flow — is the one anybody resetting
-- would actually want back.
--
-- So each row records where it came from. Everything that exists now arrived in
-- a migration, so everything is `seed`; anything saved from the Prompts screen
-- afterwards is `edited`. A reset re-activates the newest `seed` version rather
-- than writing a copy of it: the history stays honest, and the reset is
-- reversible by the same mechanism as any other version switch.
-- ---------------------------------------------------------------------------

ALTER TABLE prompt_templates
    ADD COLUMN origin ENUM('seed', 'edited') NOT NULL DEFAULT 'edited' AFTER label;

-- Every version that exists today came from a migration.
UPDATE prompt_templates SET origin = 'seed';
