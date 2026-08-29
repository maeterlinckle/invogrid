-- Prompt 11: making a failure answerable without a terminal.
--
-- `documents.error_message` says what went wrong in one sentence, which is the
-- right thing for a list and not enough to act on. This is where the rest goes:
-- which provider was called, with which model, what it answered, and how long
-- it took before it gave up.
--
-- JSON rather than columns because the useful detail differs by stage — an LLM
-- failure and a Clear Books failure have almost nothing in common — and a table
-- with a column for every integration's every field is a table nobody adds to.

ALTER TABLE document_events
    ADD COLUMN context JSON NULL AFTER message;

-- How long a document may sit in one place before the dashboard calls it stuck.
--
-- Two numbers because there are two kinds of waiting. A document in `extracting`
-- is waiting on a machine and should move in minutes; one in `needs_review` is
-- waiting on a person and may legitimately sit over a weekend. One threshold
-- covering both would either cry wolf about the second or say nothing about the
-- first.
INSERT INTO settings (setting_key, setting_value, is_secret) VALUES
    ('stuck_pipeline_minutes', '30', 0),
    ('stuck_review_days',      '7',  0)
ON DUPLICATE KEY UPDATE setting_key = setting_key;
