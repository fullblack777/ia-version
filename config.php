<?php
define('BRAND', '@hipolitoofc');
define('SITE_NAME', 'HIPOLITO CTL');
define('SITE_VERSION', '1.0');
define('BASE_URL', getenv('BASE_URL') ?: 'https://hipolito-ctl.onrender.com');

define('DB_PATH', __DIR__ . '/data/hipolito.db');
define('API_DIR', __DIR__ . '/api');
define('LOGINS_DIR', __DIR__ . '/logins');

define('LIVE_COST', 1.00);
define('DIE_COST', 0.20);
define('DEPOSIT_MIN', 10.00);
define('DEPOSIT_MAX', 200.00);

define('ADMIN_USER', 'hipolito');
define('ADMIN_PASS', 'hipolitoofcadm');

define('BOT_TOKEN', '8952767112:AAGsG1yxraZXY0IkZ3QmIGu5_jfTXhhhGas');
define('ADMIN_TELEGRAM_ID', 8617449427);

define('ELITEPAY_CLIENT_ID', 'ep_421737e484563dee4971477ca0c87575');
define('ELITEPAY_CLIENT_SECRET', 'eps_43f74326495af4ef95c43bf9b501ef122e4410dc6a8eb48d9f19f972509e2945');
define('ELITEPAY_BASE_URL', 'https://api.elitepaybr.com');
define('ELITEPAY_WEBHOOK_SECRET', 'eps_43f74326495af4ef95c43bf9b501ef122e4410dc6a8eb48d9f19f972509e2945');

define('SESSION_LIFETIME', 86400);
define('PASSWORD_MIN_LEN', 6);

date_default_timezone_set('America/Sao_Paulo');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('HIPCTL_SESS');
    session_start();
}

if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0755, true);
if (!is_dir(API_DIR)) @mkdir(API_DIR, 0755, true);
if (!is_dir(LOGINS_DIR)) @mkdir(LOGINS_DIR, 0755, true);

@file_put_contents(__DIR__ . '/data/.htaccess', "Require all denied\nDeny from all\n");
@file_put_contents(__DIR__ . '/data/index.html', '');
@file_put_contents(API_DIR . '/index.html', '');
@file_put_contents(LOGINS_DIR . '/index.html', '');