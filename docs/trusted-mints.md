# Trusted Mints List

CashuPayServer can pull a curated list of Cashu mints from an HTTPS URL and
apply it to every store on the instance. Operators of multi-tenant deployments
use this to:

- **Pre-populate failover mints** for every existing and newly created store.
- **Globally disable mints** that have been compromised, gone offline, or are
  otherwise no longer trustworthy. Disabled mints are filtered out of new
  invoice creation on every store, regardless of per-store backup config.

The list is fetched periodically (default 24h), parsed, cached locally, and
applied to all stores. A failed refresh never wipes the prior cached list.

## Configuration

| Setting               | DB key                            | Environment variable                       | Default |
| --------------------- | --------------------------------- | ------------------------------------------ | ------- |
| Trusted list URL      | `trusted_mints_url`               | `CASHUPAY_TRUSTED_MINTS_URL`               | _unset_ |
| Refresh interval (min)| `trusted_mints_refresh_minutes`   | `CASHUPAY_TRUSTED_MINTS_REFRESH_MINUTES`   | `1440`  |

When the environment variable is set, it takes precedence over the
database-stored value and the admin UI shows the value as read-only.

## JSON schema

```json
{
  "version": 1,
  "mints": [
    { "url": "https://mint.example.com" },
    { "url": "https://other.example.com" },
    { "url": "https://compromised.example.com",
      "disabled": true,
      "reason": "free-form text shown in admin UI" }
  ]
}
```

Field rules:

- `version` — optional integer, currently always `1`. Reject the list if you
  publish a different version than the server knows about.
- `mints` — required array.
- `mints[].url` — required HTTPS URL. Trailing slashes are normalized.
- `mints[].disabled` — optional boolean. When `true`, the mint is flagged as
  trusted-list-disabled in `mint_reliability` for every store on the instance.
- `mints[].reason` — optional free-form string; surfaced in the diagnostic UI.

The server will not accept the list if any entry has a non-HTTPS URL or any
field has the wrong type.

A sample valid list lives at [trusted-mints-example.json](trusted-mints-example.json).

## How the list is applied

For each store, on every successful refresh:

1. **Add failovers.** Each non-disabled `mints[].url` that isn't already in
   the store's primary or backup-mint list is added as a backup, at a priority
   below the store's existing backups. Existing backups are not reordered.
2. **Set primary if unset.** If the store was created without a primary mint
   (`primary_mint_source = 'setup'` and `mint_url` empty), the first
   non-disabled trusted mint becomes the primary. Stores where the admin has
   manually picked a primary are never overridden by the trusted list.
3. **Disable globally.** Each `mints[].disabled: true` entry sets the
   `trusted_list_disabled` flag in `mint_reliability`. The mint is filtered
   out of new-invoice selection for every store until either it returns to
   the trusted list without `disabled: true` OR the admin clears it from the
   reliability UI.

Mints that previously appeared with `disabled: true` but are no longer present
on the list, or are now present without the flag, are automatically
re-enabled.

Removing a mint from the list entirely (no `disabled: true`, just gone) is a
no-op — the trusted list is additive/blacklist-only, never a sync.

## Hosting tips

- Serve the file over HTTPS with a valid certificate; the fetcher refuses
  non-HTTPS URLs and enforces peer verification.
- Send `Content-Type: application/json`. The fetcher requests JSON via the
  `Accept` header.
- Keep the list small — every entry is loaded into memory on each refresh.
- If you publish a new schema version in the future, bump `version` so older
  servers cleanly reject it instead of partially applying it.
