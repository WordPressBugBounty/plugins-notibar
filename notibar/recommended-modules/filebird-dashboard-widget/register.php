<?php

defined( 'ABSPATH' ) || exit;

// Bump version on every init.php behavior change. Newest wins across consumers.
\YayRecommendedModules\Registry::register(
    'filebird-dashboard-widget',
    '1.2.0',
    __DIR__ . '/init.php'
);
