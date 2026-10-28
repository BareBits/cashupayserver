# BareBits fee-branch endpoint (`getbarebits.com/xpub/xpub.php`)

Hands every BareBits payserver install a unique, sequential BIP32 branch
number so on-chain dev-fee addresses never collide across installs. See the
header comment in `xpub.php` for the protocol and `includes/fee_branch.php`
in the payserver for the client side.

## Deploy

1. Upload `xpub.php` and `.htaccess` to the `xpub/` directory of getbarebits.com (merge
   the `.htaccess` rules into the existing file if one is present).
2. Make the directory writable by the PHP user: the script creates
   `xpub-branches.sqlite` (plus `-wal` / `-shm` files) beside itself on first
   request.
3. Confirm `https://getbarebits.com/xpub/xpub-branches.sqlite` returns 403 once the
   file exists, and that `https://getbarebits.com/xpub/xpub.php` prints `0`.
4. Open `https://getbarebits.com/xpub/xpub.php?whoami=1` from a machine whose
   public IP you know: it must print that IP. If it prints a loopback or
   proxy address, the site sits behind a reverse proxy (nginx in front of
   Apache, Cloudflare, ...) and every install would share one 20/hour
   rate-limit bucket. Set `XPUB_TRUSTED_PROXY_IP_HEADER` at the top of
   `xpub.php` to the header the proxy fills in (e.g. `'HTTP_X_FORWARDED_FOR'`
   or `'HTTP_CF_CONNECTING_IP'`) and re-check.

## Sweeping fee payments

`php scripts/fee-branch-descriptors.php --fetch` (run from a payserver
checkout) prints a Bitcoin Core `importdescriptors` list covering the legacy
`0/*` chain plus branches `1..N`, where `N` is what the endpoint reports.
