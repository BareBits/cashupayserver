# Production deployment with Docker

The repo ships a production image for operators who'd rather run a single
container than wire up PHP/Apache by hand. It's the same code as the
shared-hosting deploy described in the README — just packaged for
`docker compose up`.

> **Heads up:** This is the BareBits fork of cashupayserver. The README's
> warnings about experimental software and "do not use with amounts you
> cannot afford to lose" apply here too.

## What's in the image

- **Base:** `php:8.3-apache` (Debian slim with mod_php).
- **Web server:** Apache 2.4 + mod_php. The in-repo `.htaccess` does all
  the routing and security-header work, so there's no separate nginx/Caddy
  config to maintain.
- **PHP extensions:** `gmp`, `pdo_sqlite`. Composer-installed at build
  time: `bitwasp/bitcoin`, `phpmailer/phpmailer`.
- **Persistent volume:** `/var/www/html`. The app source lives here so
  the in-app auto-updater can overlay files durably across restarts;
  the SQLite database, cache, and update backups live under
  `/var/www/html/data/`.
- **In-container cron:** A background loop runs `cron.php` every
  `CRON_INTERVAL_SECONDS` (default 60). Set to `0` if you'd rather run
  cron from an external scheduler.

## Non-goals

- **TLS termination.** The image serves HTTP on port 80 only. Put a
  reverse proxy in front (Caddy, Traefik, nginx, Cloudflare).
- **WordPress / WooCommerce integration testing.** That's what the other
  two `docker/Dockerfile.*` images are for — see [DOCKER.md](../DOCKER.md).
- **Multi-tenant orchestration.** This is one container per deployment.

## Quick start

From the repo root:

```bash
docker compose up -d --build
```

Then open `http://localhost:8080/setup.php` and complete the setup wizard.

To stop:

```bash
docker compose down
```

To wipe everything (including the database):

```bash
docker compose down -v
```

## Configuration

The image reads operator settings from environment variables. Anything
you don't set falls back to the in-app defaults or the admin UI.

| Variable | Default | Notes |
|----------|---------|-------|
| `TZ` | `UTC` | Standard Debian tz database name. |
| `CASHUPAY_BASE_URL` | (auto-detect) | Set when behind a reverse proxy so generated URLs use your public hostname. |
| `CASHUPAY_AUTO_UPDATE_ENABLED` | `1` | Set to `0` to pin to the image tag (immutable infra). |
| `CASHUPAY_UPDATE_CHANNEL` | `main` | `main` (stable) or `testing` (pre-release). |
| `CRON_INTERVAL_SECONDS` | `60` | `0` disables the in-container cron loop. |
| `CASHUPAY_SMTP_*` | unset | See `user_config.example.php` for the full SMTP set. |
| `CASHUPAY_FREE_TRIAL_*` | unset | Seeded once on first migration; see `user_config.example.php`. |

Any other constant documented in [`user_config.example.php`](../user_config.example.php)
can be set via the equivalent env var (same name).

### Reverse-proxy headers

The app reads `X-Forwarded-Proto` and `X-Forwarded-Host` from
`includes/auth.php` and `includes/security.php`. Make sure your proxy
forwards both — otherwise checkout-page URLs and HTTPS-only cookies
won't behave correctly.

Caddy example:

```caddyfile
pay.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Caddy sets `X-Forwarded-Proto` and `X-Forwarded-Host` automatically.

## Upgrading

There are two upgrade paths and they intentionally do not collide:

1. **In-app auto-updater (default).** With
   `CASHUPAY_AUTO_UPDATE_ENABLED=1`, every cron tick checks the
   `CASHUPAY_UPDATE_CHANNEL` channel on GitHub. New builds overlay onto
   `/var/www/html`, preserving `data/` and `user_config.php`. No
   container restart needed.
2. **Image-tag pinning.** Set `CASHUPAY_AUTO_UPDATE_ENABLED=0` and
   redeploy with a newer image tag when you want to upgrade. The
   entrypoint will **not** overwrite an existing `/var/www/html` volume,
   so you must either:
   - apply updates by hand: `docker compose down`, mount the volume into
     an alpine container, copy in the new sources, restart; or
   - wipe the volume (`docker compose down -v`) and lose your data.

Pick one strategy and stick with it. Mixing them — running auto-update
on top of a pinned image — works but makes debugging harder because
the running source no longer matches the image you deployed.

## Backups

Everything that needs backing up lives in the `cashupay_app` volume,
specifically under `data/`:

- `data/cashupay.sqlite` — the entire app database.
- `data/cache/` — regenerable; safe to skip.
- `data/updates/backup/` — auto-updater's rollback backups; large,
  skip if disk is tight.

Single-shot backup:

```bash
docker run --rm -v cashupay_app:/src -v "$PWD":/dest alpine \
    sh -c 'cp /src/data/cashupay.sqlite /dest/cashupay-$(date +%F).sqlite'
```

Restore is the inverse with the container stopped.

## Troubleshooting

### Container starts but `/setup.php` returns 500
Check Apache + cron output: `docker compose logs -f cashupayserver`.
The auto-updater and cron-driven workers write to the same stream.

### `pdo_sqlite` or `gmp` missing
Confirm the build succeeded: `docker compose exec cashupayserver php -m`
should list both. If not, rebuild without cache:
`docker compose build --no-cache`.

### Auto-updater not running
Verify the env var is set: `docker compose exec cashupayserver env | grep AUTO_UPDATE`.
Then check `data/updates/` for a `.lock` file — a stuck lock will skip
new ticks. Delete the lock and wait for the next cron interval.

### "Permission denied" on `data/`
Re-running the entrypoint fixes ownership:
`docker compose restart cashupayserver`. If the problem persists,
something else is writing to the volume as a non-`www-data` user.
