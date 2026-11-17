<?php
/**
 * Keyed scheduler URLs.
 *
 * The operator-facing cron surfaces (admin Cron URL card, Auto-update card,
 * wizard cron screen) hand out a single URL with the cron key in the query
 * string, so a non-technical operator can paste it into a hosting panel or
 * scheduler service — no custom header to configure. Urls::withCronKey is
 * the one place that shape is built; this pins it.
 */
declare(strict_types=1);

require_once __DIR__ . '/harness.php';

fresh_db();
require_once dirname(__DIR__, 2) . '/app/includes/config.php';
require_once dirname(__DIR__, 2) . '/app/includes/urls.php';
require_once dirname(__DIR__, 2) . '/app/includes/setup_flow.php';

$BASE = 'https://pay.example.com';
Config::set('base_url', $BASE);
$key = str_repeat('cd', 32);

assert_eq(
    "$BASE/cron.php?key=$key",
    Urls::withCronKey(Urls::cron(), $key),
    'main cron URL: endpoint + ?key='
);
assert_eq(
    "$BASE/cron.php?only=swaps&key=$key",
    Urls::withCronKey(Urls::cron(), $key, ['only' => 'swaps']),
    'extra parameters come first, the key is always last'
);
assert_eq(
    "$BASE/cron.php?only=swaps&key=$key",
    Urls::withCronKey(Urls::cron() . '?only=swaps', $key),
    'an endpoint that already carries a query string is extended with &'
);
assert_eq(
    "$BASE/update.php?key=$key",
    Urls::withCronKey("$BASE/update.php", $key),
    'the updater endpoint gets the same shape'
);

// A key with URL-special characters is encoded rather than emitted raw —
// seeded keys are hex today, but the helper must not depend on that.
assert_eq(
    "$BASE/cron.php?key=a%26b%3Dc",
    Urls::withCronKey(Urls::cron(), 'a&b=c'),
    'the key is query-encoded'
);

// The wizard line builder reuses the helper, so the three surfaces can't
// drift apart.
$line = SetupFlow::cronScheduleLine('Linux', $key, Urls::cron());
assert_eq(Urls::withCronKey(Urls::cron(), $key), $line['url'], 'the wizard hands out the same keyed URL');
assert_true(str_contains($line['line'], "'" . $line['url'] . "'"), 'the crontab line quotes that URL for the shell');

echo "test_cron_url_key: OK\n";
