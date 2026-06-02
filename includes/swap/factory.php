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
    /**
     * Default registry. Tests may override via {@see setRegistry()} to inject
     * mock providers; pass `null` to reset.
     */
    private const DEFAULT_REGISTRY = [
        'zeus'  => ZeusSwapProvider::class,
        'boltz' => BoltzSwapProvider::class,
    ];
    private static ?array $registryOverride = null;

    /**
     * Test seam: override the provider registry. Each value may be either a
     * class name (instantiated on demand) or a pre-built SwapProvider instance.
     */
    public static function setRegistry(?array $registry): void {
        self::$registryOverride = $registry;
    }

    private static function registry(): array {
        return self::$registryOverride ?? self::DEFAULT_REGISTRY;
    }

    /**
     * Build all configured providers in preference order. Unknown names in
     * the configured order are silently skipped (so misconfiguration doesn't
     * brick invoice creation).
     *
     * @return SwapProvider[]
     */
    public static function orderedForSite(): array {
        $out = [];
        $reg = self::registry();
        foreach (SwapsConfig::providerOrder() as $name) {
            $entry = $reg[$name] ?? null;
            if ($entry === null) continue;
            $out[] = is_object($entry) ? $entry : new $entry();
        }
        return $out;
    }

    /**
     * Build a single provider by name. Returns null for unknown names.
     */
    public static function byName(string $name): ?SwapProvider {
        $entry = self::registry()[strtolower($name)] ?? null;
        if ($entry === null) return null;
        return is_object($entry) ? $entry : new $entry();
    }

    /**
     * All names that can appear in swaps_provider_order. Stable list used by
     * the admin UI dropdowns.
     *
     * @return string[]
     */
    public static function knownProviderNames(): array {
        return array_keys(self::registry());
    }
}
