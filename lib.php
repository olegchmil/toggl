<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (!function_exists('mb_strlen')) {
    function mb_strlen($s)
    {
        return strlen((string)$s);
    }
}

if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null)
    {
        if ($length === null) {
            return substr((string)$s, $start);
        }
        return substr((string)$s, $start, $length);
    }
}

function boot(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function json_out(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function now_local(): string
{
    return date('Y-m-d H:i:s');
}

function users_path(): string
{
    return DATA_DIR . '/users.json';
}

function projects_path(): string
{
    return DATA_DIR . '/projects.json';
}

function month_path(string $login, string $period): string
{
    return DATA_DIR . '/' . $login . '_' . $period . '.json';
}

function read_json(string $path, $default)
{
    if (!is_file($path)) {
        return $default;
    }
    $raw = file_get_contents($path);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : $default;
}

function write_json(string $path, $data): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        return false;
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    $tmp = $path . '.tmp';
    $fp = @fopen($tmp, 'wb');
    if (!$fp) {
        return false;
    }
    flock($fp, LOCK_EX);
    fwrite($fp, $json);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return @rename($tmp, $path);
}

function load_users(): array
{
    return read_json(users_path(), []);
}

function save_users(array $users): bool
{
    usort($users, static function ($a, $b) {
        return strcmp($a['login'], $b['login']);
    });
    return write_json(users_path(), $users);
}

function load_projects(): array
{
    $projects = read_json(projects_path(), []);
    return array_values(array_filter($projects, static function ($p) {
        return is_string($p) && $p !== '';
    }));
}

function save_projects(array $projects): bool
{
    $projects = array_values(array_unique(array_filter($projects, static function ($p) {
        return is_string($p) && trim($p) !== '';
    })));
    sort($projects, SORT_STRING);
    return write_json(projects_path(), $projects);
}

function load_month(string $login, string $period): array
{
    $data = read_json(month_path($login, $period), ['login' => $login, 'period' => $period, 'entries' => []]);
    if (!isset($data['entries']) || !is_array($data['entries'])) {
        $data['entries'] = [];
    }
    if (($data['login'] ?? '') !== $login) {
        $data['login'] = $login;
    }
    if (($data['period'] ?? '') !== $period) {
        $data['period'] = $period;
    }
    return $data;
}

function save_month(string $login, string $period, array $data): bool
{
    $data['login'] = $login;
    $data['period'] = $period;
    $data['entries'] = array_values($data['entries'] ?? []);
    return write_json(month_path($login, $period), $data);
}

function valid_login_value(string $value): bool
{
    return preg_match('/^[a-z0-9_-]{1,32}$/i', $value) === 1;
}

function normalize_login(string $value): string
{
    return strtolower(trim($value));
}

function valid_period_value(string $value): bool
{
    if (preg_match('/^(\d{4})-(\d{2})$/', $value, $m) !== 1) {
        return false;
    }
    return checkdate((int)$m[2], 1, (int)$m[1]);
}

function list_periods(string $login): array
{
    $files = glob(DATA_DIR . '/' . $login . '_*.json');
    $periods = [];
    foreach ($files ?: [] as $file) {
        if (preg_match('/_(\d{4}-\d{2})\.json$/', basename($file), $m)) {
            $periods[] = $m[1];
        }
    }
    sort($periods, SORT_STRING);
    return $periods;
}

function find_active_entry(string $login): ?array
{
    foreach (list_periods($login) as $period) {
        $month = load_month($login, $period);
        foreach ($month['entries'] as $index => $entry) {
            if (empty($entry['end'])) {
                return ['period' => $period, 'index' => $index, 'entry' => $entry, 'month' => $month];
            }
        }
    }
    return null;
}

function current_user(): ?array
{
    boot();
    $login = $_SESSION['login'] ?? null;
    if (!$login) {
        return null;
    }
    foreach (load_users() as $user) {
        if (strcasecmp($user['login'], $login) === 0) {
            return $user;
        }
    }
    return null;
}

function setup_admin(string $login, string $password, string $name, string $email): ?string
{
    $users = load_users();
    if (count($users) > 0) {
        return 'Система уже настроена';
    }
    $login = normalize_login($login);
    if (!valid_login_value($login)) {
        return 'Недопустимый логин. Разрешены буквы, цифры, _ и -';
    }
    if (strlen($password) < 6) {
        return 'Пароль слишком короткий (минимум 6 символов)';
    }
    $users[] = [
        'login' => $login,
        'pass_hash' => password_hash($password, PASSWORD_DEFAULT),
        'name' => $name !== '' ? $name : $login,
        'email' => trim($email),
        'is_admin' => true,
    ];
    if (!save_users($users)) {
        return 'Не удалось сохранить пользователей. Проверьте права на запись в папке data (chmod 775 или 777)';
    }
    $_SESSION['login'] = $login;
    session_regenerate_id(true);
    return null;
}

function attempt_login(string $login, string $password): ?string
{
    $login = normalize_login($login);
    foreach (load_users() as $user) {
        if (strcasecmp($user['login'], $login) === 0 && password_verify($password, $user['pass_hash'])) {
            $_SESSION['login'] = $user['login'];
            session_regenerate_id(true);
            return null;
        }
    }
    return 'Неверный логин или пароль';
}

function fmt_duration(?string $start, ?string $end): string
{
    if (!$start || !$end) {
        return '';
    }
    $seconds = strtotime($end) - strtotime($start);
    if ($seconds < 0) {
        $seconds = 0;
    }
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $secs = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
}

function base_url(): string
{
    if (defined('APP_BASE_URL') && APP_BASE_URL !== '') {
        return rtrim(APP_BASE_URL, '/');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host;
}

function clean_dt(?string $value): ?string
{
    if ($value === null || trim((string)$value) === '') {
        return null;
    }
    $value = trim($value);
    $value = str_replace('T', ' ', $value);
    $value = str_replace('t', ' ', $value);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})[ ](\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
        $h = str_pad($m[2], 2, '0', STR_PAD_LEFT);
        $s = isset($m[4]) ? $m[4] : '00';
        $clean = $m[1] . ' ' . $h . ':' . $m[3] . ':' . $s;
        if (strtotime($clean) !== false) {
            return $clean;
        }
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

function normalize_project(?string $value): string
{
    return trim((string)$value);
}