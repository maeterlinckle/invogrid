-- Prompt 10: the users screen, and what an administrator resetting somebody
-- else's password implies.
--
-- An admin-set password is known to the admin who set it. Without a forced
-- change it stays that way indefinitely, which turns "reset a password" into
-- "hold a colleague's credentials". The flag below is what closes that: the
-- account can sign in, and can then do nothing but choose its own password.

ALTER TABLE users
    ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER active,
    ADD COLUMN password_changed_at  DATETIME   NULL              AFTER must_change_password;

-- Existing accounts chose their own password at the command line, so none of
-- them is owed a change. Stamping the column rather than leaving it null keeps
-- "never changed" meaning "never changed" rather than "predates the column".
UPDATE users SET password_changed_at = created_at WHERE password_changed_at IS NULL;
