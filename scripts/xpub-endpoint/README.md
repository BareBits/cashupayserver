# BareBits fee-branch endpoint (`getbarebits.com/xpub.php`)

Hands every BareBits payserver install a unique, sequential BIP32 branch
number so on-chain dev-fee addresses never collide across installs. See the
header comment in `xpub.php` for the protocol and `includes/fee_branch.php`
in the payserver for the client side.

## Deploy

1. Upload `xpub.php` and `.htaccess` to the web root of getbarebits.com (merge
   the `.htaccess` rules into the existing file if one is present).
2. Make the directory writable by the PHP user: the script creates
   `xpub-branches.sqlite` (plus `-wal` / `-shm` files) beside itself on first
   request.
3. Confirm `https://getbarebits.com/xpub-branches.sqlite` returns 403 once the
   file exists, and that `https://getbarebits.com/xpub.php` prints `0`.
4. Behind Cloudflare or another proxy, set `XPUB_TRUSTED_PROXY_IP_HEADER` at the
   top of `xpub.php` (e.g. `'HTTP_CF_CONNECTING_IP'`) so the per-IP rate limit
   sees real client addresses rather than the proxy's.

## Sweeping fee payments

`php scripts/fee-branch-descriptors.php --fetch` (run from a payserver
checkout) prints a Bitcoin Core `importdescriptors` list covering the legacy
`0/*` chain plus branches `1..N`, where `N` is what the endpoint reports.
