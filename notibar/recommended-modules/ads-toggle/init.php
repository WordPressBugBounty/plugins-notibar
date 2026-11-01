<?php

declare(strict_types=1);

namespace YayRecommendedModules\AdsToggle;

defined( 'ABSPATH' ) || exit;

// Global ads-toggle helper — defined at module load (plugins_loaded:0) so ad modules can gate
// during their own plugins_loaded hooks, independent of boot(). See functions.php.
require_once __DIR__ . '/functions.php';

class Plugin
{
    public static function boot(): void
    {
        require __DIR__ . '/main.php';
        \NjtAdsToggle::get_instance()->init();
    }
}

if ( function_exists( 'add_action' ) ) {
    add_action( 'plugins_loaded', [ Plugin::class, 'boot' ] );
}
