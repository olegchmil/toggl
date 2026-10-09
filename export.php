<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

boot();

$key = (string)($_REQUEST['key'] ?? '');
$login = normalize_login((string)($_REQUEST['login'] ?? ''));
$period = (string)($_REQUEST['period'] ?? '');

$allowed = false;
if ($key !== '' && hash_equals(EXPORT_KEY, $key)) {
    $allowed = true;
} else {
    $user = current_user();
    if ($user !== null && (strcasecmp($user['login'], $login) === 0 || !empty($user['is_admin']))) {
        $allowed = true;
    }
}

if (!$allowed) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Доступ запрещён');
}

if (!valid_login_value($login) || !valid_period_value($period)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Недопустимые параметры');
}

$knownLogin = false;
foreach (load_users() as $u) {
    if (strcasecmp($u['login'], $login) === 0) {
        $knownLogin = true;
        $displayName = $u['name'] ?? $u['login'];
        $email = $u['email'] ?? '';
        break;
    }
}
if (!$knownLogin) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Пользователь не найден');
}

$month = load_month($login, $period);
$entries = $month['entries'];
usort($entries, static function ($a, $b) {
    return strcmp($a['start'], $b['start']);
});

$lines = [];
$line = static function (array $fields): string {
    $quoted = array_map(static function ($value) {
        return '"' . str_replace('"', '""', (string)$value) . '"';
    }, $fields);
    return implode(',', $quoted);
};

$lines[] = $line([
    'User', 'Email', 'Client', 'Project', 'Task', 'Description', 'Billable',
    'Start date', 'Start time', 'End date', 'End time', 'Duration', 'Tags',
]);

$now = now_local();
foreach ($entries as $entry) {
    $start = clean_dt((string)$entry['start']) ?? (string)$entry['start'];
    $end = empty($entry['end']) ? $now : (clean_dt((string)$entry['end']) ?? (string)$entry['end']);
    $duration = fmt_duration($start, $end);
    $lines[] = $line([
        $displayName,
        $email,
        '',
        $entry['project'],
        '',
        $entry['description'],
        'No',
        substr($start, 0, 10),
        substr($start, 11, 8),
        substr($end, 0, 10),
        substr($end, 11, 8),
        $duration,
        '',
    ]);
}

$csv = implode("\r\n", $lines) . "\r\n";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $login . '_' . $period . '.csv"');
header('Content-Length: ' . strlen($csv));
echo $csv;