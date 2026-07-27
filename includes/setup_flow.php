<?php
/**
 * Screen sequencing for the onboarding wizard (setup.php).
 *
 * Screens are identified by slug rather than by number, so the sequence reads
 * in execution order and inserting one never requires a renumber. This lives
 * outside setup.php so the ordering rules — which screens appear in which
 * mode, what "next" and "back" resolve to — can be tested without rendering a
 * page.
 */

require_once __DIR__ . '/database.php';

final class SetupFlow {
    /** Every screen the wizard knows, in canonical order. */
    public const STEPS = [
        'security', 'password', 'store', 'onchain', 'zeroconf',
        'lightning', 'swaps', 'mints', 'cron', 'done',
    ];

    /** The screens add_store mode walks before returning to admin. */
    public const ADD_STORE_STEPS = [
        'store', 'onchain', 'zeroconf', 'lightning', 'swaps', 'mints',
    ];

    /**
     * Terminal screen for add_store, shown only when the mints answer minted a
     * fresh wallet seed. It is deliberately outside ADD_STORE_STEPS: it is a
     * hand-off panel, not a question, and counting it would make the progress
     * indicator jump for the operators who never see it. Its whole reason to
     * exist is that a silently generated seed must be displayed once.
     */
    public const ADD_STORE_COMPLETE = 'store_created';

    /**
     * Screens that must never be a "Back" target: the store already exists by
     * the time any of them could be reached, and re-submitting the password
     * screen trips its "an admin already exists" guard.
     */
    public const NO_BACK_TARGET = ['security', 'password'];

    /**
     * Screens that render after setup_complete has been set, and therefore
     * have to survive setup.php's redirect-if-set-up guard.
     */
    public const POST_COMPLETION = ['cron', 'done'];

    public static function isKnownStep(string $step): bool {
        return in_array($step, self::STEPS, true) || $step === self::ADD_STORE_COMPLETE;
    }

    /** The first screen for a given mode. */
    public static function firstStep(string $mode): string {
        return $mode === 'add_store' ? 'store' : 'security';
    }

    /**
     * The screens in display order for a given context.
     *
     * $includeZeroConf is false when the store has no on-chain destination —
     * the zero-conf question has nothing to apply to, so it is dropped from
     * the sequence entirely, which also keeps the "Step X of Y" counter honest
     * rather than advertising a screen that will never render.
     *
     * @return string[]
     */
    public static function stepSequence(string $mode, bool $isWordPress, bool $includeZeroConf): array {
        if ($mode === 'add_store') {
            $steps = self::ADD_STORE_STEPS;
        } else {
            $steps = self::STEPS;
            if ($isWordPress) {
                // WordPress supplies its own authentication.
                $steps = array_values(array_diff($steps, ['password']));
            }
        }
        if (!$includeZeroConf) {
            $steps = array_values(array_diff($steps, ['zeroconf']));
        }
        return array_values($steps);
    }

    /**
     * Next screen after $current, or null when $current is the last one (or
     * isn't in this sequence at all).
     *
     * @param string[] $steps
     */
    public static function nextStep(string $current, array $steps): ?string {
        $i = array_search($current, $steps, true);
        if ($i === false || $i + 1 >= count($steps)) {
            return null;
        }
        return $steps[$i + 1];
    }

    /**
     * Previous screen before $current, or null when $current is the first (or
     * isn't in this sequence at all).
     *
     * @param string[] $steps
     */
    public static function prevStep(string $current, array $steps): ?string {
        $i = array_search($current, $steps, true);
        if ($i === false || $i < 1) {
            return null;
        }
        return $steps[$i - 1];
    }

    /**
     * The screen a "Back" link on $current should point at, or null when there
     * is no safe target.
     *
     * @param string[] $steps
     */
    public static function backStep(string $current, array $steps): ?string {
        if (in_array($current, self::NO_BACK_TARGET, true)
            || in_array($current, self::POST_COMPLETION, true)
            || $current === self::ADD_STORE_COMPLETE) {
            return null;
        }
        $prev = self::prevStep($current, $steps);
        return in_array($prev, self::NO_BACK_TARGET, true) ? null : $prev;
    }

    /**
     * Settle the store's auto-cashout (threshold-melt) configuration once
     * every rail answer is in. This cannot be decided on the Lightning screen
     * alone: whether the mint balance is swept over Lightning or via a
     * submarine swap depends on the swaps and mints answers that come after.
     *
     * The rules, in priority order:
     *   1. Any Lightning destination (LNURL address or CLINK noffer) → sweep
     *      over Lightning. Cheapest and fastest, so it wins when available.
     *   2. Otherwise, a mint plus swaps plus an xpub → sweep via submarine
     *      swap to the on-chain wallet. This is the case the mints screen
     *      promises: "funds are automatically withdrawn to your on-chain
     *      wallet when a sufficient amount has accumulated".
     *   3. Otherwise there is nowhere to sweep to, so auto-cashout stays off.
     *
     * Writes go through Database::update because the auto_melt_* columns are
     * intentionally outside Config::updateStore's allowlist — the same path
     * admin.php's save_auto_melt handler uses.
     *
     * @return 'lightning'|'swap'|'off' What was configured, for logging/tests.
     */
    public static function resolveAutoCashout(string $storeId): string {
        require_once __DIR__ . '/store_ln_addresses.php';
        require_once __DIR__ . '/swap/config.php';
        require_once __DIR__ . '/swap/auto_melt.php';

        $store = Database::fetchOne(
            "SELECT id, mint_url, seed_phrase FROM stores WHERE id = ?",
            [$storeId]
        );
        if (!$store) {
            return 'off';
        }

        $hasLightning = StoreLnAddresses::addressesForStore($storeId) !== [];
        $hasMint = !empty($store['mint_url']) && !empty($store['seed_phrase']);
        // isEnabledForStore already requires an xpub, and SwapAutoMelt::modeForStore
        // re-checks that the address mode is xpub before it will pick 'swap'.
        $canSwap = $hasMint && SwapsConfig::isEnabledForStore($storeId)
            && self::onchainState($storeId)['hasXpub'];

        if ($hasLightning) {
            $enabled = 1;
            $useSwap = SwapAutoMelt::FORCE_LIGHTNING;
            $result = 'lightning';
        } elseif ($canSwap) {
            $enabled = 1;
            $useSwap = SwapAutoMelt::FORCE_SWAP;
            $result = 'swap';
        } else {
            $enabled = 0;
            $useSwap = SwapAutoMelt::FORCE_LIGHTNING;
            $result = 'off';
        }

        Database::update('stores', [
            'auto_melt_enabled' => $enabled,
            'auto_melt_use_swap' => $useSwap,
        ], 'id = ?', [$storeId]);

        return $result;
    }

    /**
     * Does this store have somewhere on-chain to send money? Drives whether
     * the zero-conf screen is shown and whether submarine swaps can be
     * enabled (swaps derive a fresh address per swap, so they need an xpub —
     * a single reused address will not do).
     *
     * @return array{configured: bool, hasXpub: bool}
     */
    public static function onchainState(?string $storeId): array {
        if ($storeId === null || $storeId === '') {
            return ['configured' => false, 'hasXpub' => false];
        }
        $row = Database::fetchOne(
            "SELECT onchain_address_mode, onchain_xpub, onchain_static_address
               FROM stores WHERE id = ?",
            [$storeId]
        );
        if (!$row) {
            return ['configured' => false, 'hasXpub' => false];
        }
        $hasXpub = trim((string)($row['onchain_xpub'] ?? '')) !== '';
        $hasStatic = trim((string)($row['onchain_static_address'] ?? '')) !== '';
        // A store can hold both columns if the operator switched modes; the
        // active one is whichever address_mode names.
        $mode = ($row['onchain_address_mode'] ?? 'xpub') === 'static' ? 'static' : 'xpub';
        $configured = $mode === 'static' ? $hasStatic : $hasXpub;
        return ['configured' => $configured, 'hasXpub' => $mode === 'xpub' && $hasXpub];
    }
}
