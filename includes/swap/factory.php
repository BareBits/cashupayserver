<?php
/**
 * Factory: build SwapProvider instances in the configured preference order.
 *
 * Adding a new provider means:
 *   1. Implement SwapProvider (typically by extending BoltzLikeProvider).
 *   2. Add the name → class entry to {@see REGISTRY} below.
 *   3. The site config swaps_provider_order can now include the new name.
 */

require_once __DIR__ . '/provider.php';
require_once __DIR__ . '/zeus_provider.php';
require_once __DIR__ . '/boltz_provider.php';
require_once __DIR__ . '/config.php';

final class SwapProviderFactory {
    private const REGISTRY = [
        'zeus'  => ZeusSwapProvider::class,
        'boltz' => BoltzSwapProvider::class,
    ];

    /**
     * Build all configured providers in preference order. Unknown names in
     * the configured order are silently skipped (so misconfiguration doesn't
     * brick invoice creation).
     *
     * @return SwapProvider[]
     */
    public static function orderedForSite(): array {
        $out = [];
        foreach (SwapsConfig::providerOrder() as $name) {
            $cls = self::REGISTRY[$name] ?? null;
            if ($cls) {
                $out[] = new $cls();
            }
        }
        return $out;
    }

    /**
     * Build a single provider by name. Returns null for unknown names.
     */
    public static function byName(string $name): ?SwapProvider {
        $cls = self::REGISTRY[strtolower($name)] ?? null;
        return $cls ? new $cls() : null;
    }

    /**
     * All names that can appear in swaps_provider_order. Stable list used by
     * the admin UI dropdowns.
     *
     * @return string[]
     */
    public static function knownProviderNames(): array {
        return array_keys(self::REGISTRY);
    }
}
