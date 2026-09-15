<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Jakarta');

session_start([
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);

require_once __DIR__ . '/../vendor/autoload.php';

$envLocalFile = __DIR__ . '/env.local.php';
if (is_file($envLocalFile)) {
    require_once $envLocalFile;
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/app_settings.php';
require_once __DIR__ . '/../lib/costing.php';
require_once __DIR__ . '/../lib/inventory.php';
require_once __DIR__ . '/../lib/numbering.php';
require_once __DIR__ . '/../lib/pos.php';
require_once __DIR__ . '/../lib/cash.php';
require_once __DIR__ . '/../lib/backup.php';
require_once __DIR__ . '/../lib/reports.php';
require_once __DIR__ . '/../lib/units.php';
require_once __DIR__ . '/../lib/import.php';
require_once __DIR__ . '/../includes/icons.php';

const APP_NAME = 'PARTAMBUS';
const APP_BASE_PATH = '';
const APP_VERSION = '1.0.0';
