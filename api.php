<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

boot();

$action = (string)($_REQUEST['action'] ?? '');
$user = current_user();

switch ($action) {
    case 'setup':
        handle_setup();
        break;
    case 'login':
        handle_login();
        break;
    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        json_out(['ok' => true]);
        break;
    case 'me':
        require_user($user);
        json_out(['ok' => true, 'user' => public_user($user)]);
        break;
    case 'start':
        require_user($user);
        handle_start();
        break;
    case 'stop':
        require_user($user);
        handle_stop();
        break;
    case 'update':
        require_user($user);
        handle_update();
        break;
    case 'delete':
        require_user($user);
        handle_delete();
        break;
    case 'list':
        require_user($user);
        handle_list();
        break;
    case 'active':
        require_user($user);
        $active = find_active_entry($user['login']);
        json_out(['ok' => true, 'entry' => $active['entry'] ?? null]);
        break;
    case 'projects':
        require_user($user);
        json_out(['ok' => true, 'projects' => load_projects()]);
        break;
    case 'add_project':
        require_user($user);
        handle_add_project();
        break;
    case 'del_project':
        require_user($user);
        require_admin($user);
        handle_del_project();
        break;
    case 'users':
        require_user($user);
        require_admin($user);
        $list = array_map('public_user', load_users());
        json_out(['ok' => true, 'users' => $list]);
        break;
    case 'add_user':
        require_user($user);
        require_admin($user);
        handle_add_user();
        break;
    case 'del_user':
        require_user($user);
        require_admin($user);
        handle_del_user();
        break;
    case 'reset_password':
        require_user($user);
        require_admin($user);
        handle_reset_password();
        break;
    case 'set_admin':
        require_user($user);
        require_admin($user);
        handle_set_admin();
        break;
    default:
        json_out(['ok' => false, 'error' => 'Неизвестное действие']);
}

function public_user(array $user): array
{
    return [
        'login' => $user['login'],
        'name' => $user['name'] ?? '',
        'email' => $user['email'] ?? '',
        'is_admin' => !empty($user['is_admin']),
    ];
}

function require_user(?array $user): void
{
    if (!$user) {
        http_response_code(401);
        json_out(['ok' => false, 'error' => 'Требуется вход']);
    }
}

function require_admin(array $user): void
{
    if (empty($user['is_admin'])) {
        http_response_code(403);
        json_out(['ok' => false, 'error' => 'Недостаточно прав']);
    }
}

function current_period_string(): string
{
    return date('Y-m');
}

function intervals_overlap(array $a, array $b): bool
{
    $aStart = strtotime($a['start']);
    $aEnd = empty($a['end']) ? PHP_INT_MAX : strtotime($a['end']);
    $bStart = strtotime($b['start']);
    $bEnd = empty($b['end']) ? PHP_INT_MAX : strtotime($b['end']);
    return $aStart < $bEnd && $bStart < $aEnd;
}

function find_conflicts(array $others, array $candidate): array
{
    $conflicts = [];
    foreach ($others as $entry) {
        if ((int)$entry['id'] === (int)$candidate['id']) {
            continue;
        }
        if (intervals_overlap($candidate, $entry)) {
            $conflicts[] = (int)$entry['id'];
        }
    }
    if (count($conflicts) > 0) {
        $conflicts[] = (int)$candidate['id'];
    }
    return array_values(array_unique($conflicts));
}

function handle_setup(): void
{
    $error = setup_admin(
        (string)($_REQUEST['login'] ?? ''),
        (string)($_REQUEST['password'] ?? ''),
        (string)($_REQUEST['name'] ?? ''),
        (string)($_REQUEST['email'] ?? '')
    );
    if ($error !== null) {
        json_out(['ok' => false, 'error' => $error]);
    }
    json_out(['ok' => true]);
}

function handle_login(): void
{
    $error = attempt_login(
        (string)($_REQUEST['login'] ?? ''),
        (string)($_REQUEST['password'] ?? '')
    );
    if ($error !== null) {
        json_out(['ok' => false, 'error' => $error]);
    }
    json_out(['ok' => true, 'user' => public_user(current_user())]);
}

function handle_start(): void
{
    $user = current_user();
    $project = normalize_project((string)($_REQUEST['project'] ?? ''));
    $description = trim((string)($_REQUEST['description'] ?? ''));
    $description = mb_substr($description, 0, 500);
    if ($project === '') {
        json_out(['ok' => false, 'error' => 'Укажите проект']);
    }
    if (find_active_entry($user['login']) !== null) {
        json_out(['ok' => false, 'error' => 'Уже есть активная задача. Остановите её']);
    }
    $projects = load_projects();
    if (!in_array($project, $projects, true)) {
        $projects[] = $project;
        save_projects($projects);
    }
    $start = clean_dt($_REQUEST['start'] ?? null);
    if ($start === null) {
        $start = now_local();
    }
    $period = substr($start, 0, 7);
    $month = load_month($user['login'], $period);
    $nextId = 1;
    foreach ($month['entries'] as $entry) {
        if (isset($entry['id']) && (int)$entry['id'] >= $nextId) {
            $nextId = (int)$entry['id'] + 1;
        }
    }
    $entry = [
        'id' => $nextId,
        'project' => $project,
        'description' => $description,
        'start' => $start,
        'end' => null,
    ];
    $conflicts = find_conflicts($month['entries'], $entry);
    if (count($conflicts) > 0) {
        json_out(['ok' => false, 'error' => 'Пересекается время конца и начала', 'conflicts' => $conflicts]);
    }
    $month['entries'][] = $entry;
    if (!save_month($user['login'], $period, $month)) {
        json_out(['ok' => false, 'error' => 'Не удалось сохранить запись']);
    }
    json_out(['ok' => true, 'entry' => $entry, 'period' => $period]);
}

function handle_stop(): void
{
    $active = find_active_entry(current_user()['login']);
    if ($active === null) {
        json_out(['ok' => false, 'error' => 'Нет активной задачи']);
    }
    $end = clean_dt($_REQUEST['end'] ?? null);
    if ($end === null) {
        $end = now_local();
    }
    if (strtotime($end) < strtotime($active['entry']['start'])) {
        json_out(['ok' => false, 'error' => 'Время окончания раньше времени начала']);
    }
    $month = $active['month'];
    $month['entries'][$active['index']]['end'] = $end;
    if (!save_month(current_user()['login'], $active['period'], $month)) {
        json_out(['ok' => false, 'error' => 'Не удалось сохранить запись']);
    }
    $entry = $month['entries'][$active['index']];
    json_out(['ok' => true, 'entry' => $entry]);
}

function handle_update(): void
{
    $user = current_user();
    $period = (string)($_REQUEST['period'] ?? '');
    if (!valid_period_value($period)) {
        json_out(['ok' => false, 'error' => 'Недопустимый период']);
    }
    $id = (int)($_REQUEST['id'] ?? 0);
    $month = load_month($user['login'], $period);
    $found = null;
    foreach ($month['entries'] as $index => $entry) {
        if ((int)$entry['id'] === $id) {
            $found = $index;
            break;
        }
    }
    if ($found === null) {
        json_out(['ok' => false, 'error' => 'Запись не найдена']);
    }
    $start = clean_dt($_REQUEST['start'] ?? null);
    if ($start === null) {
        json_out(['ok' => false, 'error' => 'Недопустимое время начала']);
    }
    $endRaw = (string)($_REQUEST['end'] ?? '');
    $wasRunning = empty($month['entries'][$found]['end']);
    if (trim($endRaw) === '') {
        $end = $wasRunning ? null : $month['entries'][$found]['end'];
    } else {
        $end = clean_dt($endRaw);
        if ($end === null) {
            json_out(['ok' => false, 'error' => 'Недопустимое время окончания']);
        }
        if (strtotime($end) < strtotime($start)) {
            json_out(['ok' => false, 'error' => 'Время окончания раньше времени начала']);
        }
    }
    $project = normalize_project((string)($_REQUEST['project'] ?? $month['entries'][$found]['project']));
    if ($project === '') {
        json_out(['ok' => false, 'error' => 'Укажите проект']);
    }
    $projects = load_projects();
    if (!in_array($project, $projects, true)) {
        $projects[] = $project;
        save_projects($projects);
    }
    $description = mb_substr(trim((string)($_REQUEST['description'] ?? $month['entries'][$found]['description'])), 0, 500);
    $month['entries'][$found]['project'] = $project;
    $month['entries'][$found]['description'] = $description;
    $month['entries'][$found]['start'] = $start;
    $month['entries'][$found]['end'] = $end;
    $candidate = $month['entries'][$found];
    $others = [];
    foreach ($month['entries'] as $index => $entry) {
        if ($index !== $found) {
            $others[] = $entry;
        }
    }
    $active = find_active_entry($user['login']);
    if ($active !== null && (int)$active['entry']['id'] !== $id) {
        $others[] = $active['entry'];
    }
    $conflicts = find_conflicts($others, $candidate);
    if (count($conflicts) > 0) {
        json_out(['ok' => false, 'error' => 'Пересекается время конца и начала', 'conflicts' => $conflicts]);
    }
    if (!save_month($user['login'], $period, $month)) {
        json_out(['ok' => false, 'error' => 'Не удалось сохранить запись']);
    }
    json_out(['ok' => true, 'entry' => $month['entries'][$found]]);
}

function handle_delete(): void
{
    $user = current_user();
    $period = (string)($_REQUEST['period'] ?? '');
    if (!valid_period_value($period)) {
        json_out(['ok' => false, 'error' => 'Недопустимый период']);
    }
    $id = (int)($_REQUEST['id'] ?? 0);
    $month = load_month($user['login'], $period);
    $month['entries'] = array_values(array_filter($month['entries'], static function ($entry) use ($id) {
        return (int)$entry['id'] !== $id;
    }));
    save_month($user['login'], $period, $month);
    json_out(['ok' => true]);
}

function handle_list(): void
{
    $user = current_user();
    $period = (string)($_REQUEST['period'] ?? current_period_string());
    if (!valid_period_value($period)) {
        json_out(['ok' => false, 'error' => 'Недопустимый период']);
    }
    $month = load_month($user['login'], $period);
    $entries = $month['entries'];
    usort($entries, static function ($a, $b) {
        return strcmp($a['start'], $b['start']);
    });
    $page = max(1, (int)($_REQUEST['page'] ?? 1));
    $perPage = max(10, (int)($_REQUEST['per_page'] ?? 100));
    $total = count($entries);
    $slice = array_slice($entries, ($page - 1) * $perPage, $perPage);
    json_out([
        'ok' => true,
        'period' => $period,
        'total' => $total,
        'entries' => $slice,
        'projects_default' => load_projects(),
    ]);
}

function handle_add_project(): void
{
    $projects = load_projects();
    $project = normalize_project((string)($_REQUEST['project'] ?? ''));
    if ($project === '' || mb_strlen($project) > 100) {
        json_out(['ok' => false, 'error' => 'Недопустимое название проекта']);
    }
    if (in_array($project, $projects, true)) {
        json_out(['ok' => true, 'projects' => $projects]);
    }
    $projects[] = $project;
    save_projects($projects);
    json_out(['ok' => true, 'projects' => $projects]);
}

function handle_del_project(): void
{
    $projects = load_projects();
    $project = normalize_project((string)($_REQUEST['project'] ?? ''));
    $projects = array_values(array_filter($projects, static function ($p) use ($project) {
        return $p !== $project;
    }));
    save_projects($projects);
    json_out(['ok' => true, 'projects' => $projects]);
}

function handle_add_user(): void
{
    $users = load_users();
    if (count($users) >= MAX_USERS) {
        json_out(['ok' => false, 'error' => 'Достигнут лимит пользователей (' . MAX_USERS . ')']);
    }
    $login = normalize_login((string)($_REQUEST['login'] ?? ''));
    $password = (string)($_REQUEST['password'] ?? '');
    $name = trim((string)($_REQUEST['name'] ?? ''));
    $email = trim((string)($_REQUEST['email'] ?? ''));
    if (!valid_login_value($login)) {
        json_out(['ok' => false, 'error' => 'Недопустимый логин']);
    }
    if (strlen($password) < 6) {
        json_out(['ok' => false, 'error' => 'Пароль слишком короткий (минимум 6 символов)']);
    }
    foreach ($users as $u) {
        if (strcasecmp($u['login'], $login) === 0) {
            json_out(['ok' => false, 'error' => 'Пользователь с таким логином уже существует']);
        }
    }
    $users[] = [
        'login' => $login,
        'pass_hash' => password_hash($password, PASSWORD_DEFAULT),
        'name' => $name !== '' ? $name : $login,
        'email' => $email,
        'is_admin' => false,
    ];
    if (!save_users($users)) {
        json_out(['ok' => false, 'error' => 'Не удалось сохранить пользователей']);
    }
    json_out(['ok' => true]);
}

function handle_del_user(): void
{
    $users = load_users();
    $login = normalize_login((string)($_REQUEST['login'] ?? ''));
    $current = current_user();
    if (strcasecmp($login, $current['login']) === 0) {
        json_out(['ok' => false, 'error' => 'Нельзя удалить самого себя']);
    }
    $remaining = array_values(array_filter($users, static function ($u) use ($login) {
        return strcasecmp($u['login'], $login) !== 0;
    }));
    $hadAdmin = false;
    foreach ($users as $u) {
        if (strcasecmp($u['login'], $login) === 0 && !empty($u['is_admin'])) {
            $hadAdmin = true;
        }
    }
    if ($hadAdmin) {
        $stillAdmin = false;
        foreach ($remaining as $u) {
            if (!empty($u['is_admin'])) {
                $stillAdmin = true;
            }
        }
        if (!$stillAdmin) {
            json_out(['ok' => false, 'error' => 'Нельзя удалить последнего администратора']);
        }
    }
    save_users($remaining);
    json_out(['ok' => true]);
}

function handle_reset_password(): void
{
    $users = load_users();
    $login = normalize_login((string)($_REQUEST['login'] ?? ''));
    $password = (string)($_REQUEST['password'] ?? '');
    if (strlen($password) < 6) {
        json_out(['ok' => false, 'error' => 'Пароль слишком короткий (минимум 6 символов)']);
    }
    foreach ($users as $index => $u) {
        if (strcasecmp($u['login'], $login) === 0) {
            $users[$index]['pass_hash'] = password_hash($password, PASSWORD_DEFAULT);
            save_users($users);
            json_out(['ok' => true]);
        }
    }
    json_out(['ok' => false, 'error' => 'Пользователь не найден']);
}

function handle_set_admin(): void
{
    $users = load_users();
    $login = normalize_login((string)($_REQUEST['login'] ?? ''));
    $isAdmin = !empty($_REQUEST['is_admin']);
    $current = current_user();
    foreach ($users as $index => $u) {
        if (strcasecmp($u['login'], $login) === 0) {
            if (strcasecmp($login, $current['login']) === 0 && !$isAdmin) {
                json_out(['ok' => false, 'error' => 'Нельзя снять права администратора с самого себя']);
            }
            $users[$index]['is_admin'] = $isAdmin;
            save_users($users);
            json_out(['ok' => true]);
        }
    }
    json_out(['ok' => false, 'error' => 'Пользователь не найден']);
}