<?php
declare(strict_types=1);

define('APP_NAME', 'Work Documentation');
define('APP_SUBTITLE', 'Private Local Documentation System');

define('BASE_PATH', __DIR__);
define('STORAGE_PATH', '/var/lib/workreport');
define('DB_PATH', STORAGE_PATH . DIRECTORY_SEPARATOR . 'reports.sqlite');
date_default_timezone_set('America/Kentucky/Louisville');

/*
 * WorkReport session security.
 *
 * Sessions are cookie-only, resistant to fixation, unavailable to
 * JavaScript, and transmitted only over HTTPS.
 */
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_start();
