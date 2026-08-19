<?php
declare(strict_types=1);

define('DATA_DIR', __DIR__ . '/data');
define('APP_TITLE', 'Учёт рабочего времени');
define('MAX_USERS', 50);
define('SESSION_NAME', 'toggl_session');
define('APP_BASE_URL', '');

$exportKey = getenv('TOGGL_EXPORT_KEY');
if ($exportKey === false || $exportKey === '') {
    $exportKey = '';
    $localFile = __DIR__ . '/config.local.php';
    if (is_file($localFile)) {
        require $localFile;
    }
}
define('EXPORT_KEY', $exportKey);

date_default_timezone_set('Asia/Vladivostok');