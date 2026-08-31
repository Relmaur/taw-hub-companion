<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap. No real WordPress — Brain Monkey mocks WP functions
 * per-test. ABSPATH must be defined before any plugin class autoloads
 * (every src/ file guards with `if (!defined('ABSPATH')) exit;`).
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}

require __DIR__ . '/../vendor/autoload.php';
