-- ---------------------------------------------------------------------------
-- InvoGrid — what the Clear Books connection and the correspondent sync need.
--
-- Nothing here is a credential. The authorisation URL and the scope list are
-- published constants of the Clear Books API; the redirect URI is derived from
-- APP_URL when it is left empty, and only needs setting when this instance is
-- reached at a different address from the one it thinks it has (behind a proxy,
-- or during a migration).
-- ---------------------------------------------------------------------------

INSERT INTO settings (setting_key, setting_value, is_secret) VALUES
    -- Where a person is sent to authorise InvoGrid. A different host from the
    -- API: consent happens in the Clear Books web interface, the token exchange
    -- against the API. Getting these two the same way round is the first thing
    -- to check if the connect button leads to a 404.
    ('clearbooks_authorise_url',
     'https://secure.clearbooks.co.uk/account/action/oauth/', 0),

    -- Where Clear Books sends the browser back to. Empty means "APP_URL plus
    -- /admin/clearbooks/callback", which is what should be registered with
    -- Clear Books when the application credentials are issued.
    ('clearbooks_redirect_uri', '', 0),

    -- Exactly what InvoGrid needs and no more. Read access to the reference
    -- lists it caches, write access to the two things it creates: a supplier a
    -- person has confirmed, and a purchase document. Notably absent: sales,
    -- payments, journals, bank feeds.
    ('clearbooks_scopes',
     'accounting.suppliers:read accounting.suppliers:write accounting.account_codes:read accounting.vat:read accounting.purchases:read accounting.purchases:write',
     0),

    -- Whether Clear Books suppliers are mirrored into Paperless correspondents
    -- at all. On by default; switching it off stops every write to Paperless's
    -- correspondent list without affecting matching or submission.
    ('clearbooks_sync_correspondents', '1', 0),

    -- Whether the sync may delete a correspondent whose supplier has gone.
    -- Separate from the switch above because deletion is the only irreversible
    -- thing this application does to somebody else's system. It is still
    -- guarded absolutely — a correspondent with documents pointing at it is
    -- never deleted, whatever this says — but an operator who would rather tidy
    -- up by hand can turn it off and keep the rest.
    ('clearbooks_delete_correspondents', '1', 0)
ON DUPLICATE KEY UPDATE setting_key = setting_key;
