#!/bin/sh
# Entrypoint for the nginx test image: php-fpm in the background, nginx in the
# foreground as pid-1-adjacent so `docker stop` (SIGTERM) shuts the instance
# down inside the fixture's grace period. A crashed FPM shows up as 502s in
# the instance log rather than killing the container — acceptable for tests.
set -e
mkdir -p /tmp/nginx /tmp/fpm
php-fpm -F &
# Don't let nginx answer before FPM's socket exists: the fixtures' readiness
# poll treats ANY HTTP response as "up", so an early 502 leaks into tests.
i=0
while [ ! -S /tmp/fpm/fpm.sock ] && [ "$i" -lt 100 ]; do
    sleep 0.1
    i=$((i + 1))
done
exec nginx -g 'daemon off;'
