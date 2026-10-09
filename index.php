<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

boot();

$user = current_user();
$usersAll = load_users();
$needSetup = count($usersAll) === 0;
$myLogin = $user['login'] ?? '';
$dataWritable = is_writable(DATA_DIR);
$pageError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($needSetup && isset($_POST['form_setup'])) {
        $pageError = setup_admin(
            (string)($_POST['login'] ?? ''),
            (string)($_POST['password'] ?? ''),
            (string)($_POST['name'] ?? ''),
            (string)($_POST['email'] ?? '')
        );
        if ($pageError === null) {
            header('Location: index.php');
            exit;
        }
    } elseif (!$needSetup && isset($_POST['form_login'])) {
        $pageError = attempt_login(
            (string)($_POST['login'] ?? ''),
            (string)($_POST['password'] ?? '')
        );
        if ($pageError === null) {
            header('Location: index.php');
            exit;
        }
    }
    $user = current_user();
    $usersAll = load_users();
    $needSetup = count($usersAll) === 0;
}

function app_url(string $query): string
{
    $base = base_url();
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/toggl'));
    $dir = rtrim($dir, '/');
    return $base . $dir . $query;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo e(APP_TITLE); ?></title>
<style>
:root{--bg:#f3f4f6;--card:#fff;--line:#e2e5ea;--text:#1f2937;--muted:#6b7280;--accent:#2f6fed;--accent2:#1d4ed8;--ok:#0a7a3d;--warn:#b45309;--danger:#b91c1c;}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font:15px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.wrap{max-width:1000px;margin:0 auto;padding:16px}
.card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px}
h1{font-size:20px;margin:0}
h2{font-size:16px;margin:0 0 10px}
.muted{color:var(--muted);font-size:13px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.grow{flex:1}
label{display:block;font-size:13px;color:var(--muted);margin:8px 0 4px}
input,select,textarea{width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font:inherit;background:#fff;color:var(--text)}
textarea{resize:vertical}
input[type=checkbox]{width:auto}
button{cursor:pointer;border:0;border-radius:8px;padding:9px 14px;font:inherit;background:#e5e7eb;color:#111827}
button.primary{background:var(--accent);color:#fff}
button.primary:hover{background:var(--accent2)}
button.danger{background:#fde8e8;color:var(--danger)}
button.ghost{background:#fff;border:1px solid var(--line);color:var(--text)}
button:disabled{opacity:.5;cursor:not-allowed}
.btn-small{padding:4px 9px;font-size:13px;border-radius:6px}
table{width:100%;border-collapse:collapse;font-size:14px}
th,td{text-align:left;padding:8px 10px;border-bottom:1px solid var(--line);vertical-align:top}
th{color:var(--muted);font-weight:600;font-size:13px;white-space:nowrap}
td.proj{white-space:nowrap}
td.desc{white-space:pre-wrap;word-break:break-word}
.right{text-align:right}
.running{background:#eef6ff}
tr.conflict{background:#fde4e4}
tr.running.conflict{background:#fde4e4}
tr.conflict td{background:transparent}
.badge{display:inline-block;background:#eef6ff;color:var(--accent2);border-radius:99px;padding:2px 9px;font-size:12px}
.timer-big{font-size:30px;font-weight:700;font-variant-numeric:tabular-nums;color:var(--accent2)}
.total{font-weight:700}
.hidden{display:none}
.alert{padding:10px 12px;border-radius:8px;margin:10px 0;font-size:14px}
.alert.err{background:#fde8e8;color:var(--danger)}
.alert.info{background:#eef6ff;color:var(--accent2)}
a{color:var(--accent2)}
.topbar{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.mono{font-family:Consolas,Monaco,monospace;font-size:12px}
#msg{min-height:0;margin:10px 0 0}
table.entries td.dur{white-space:nowrap;font-variant-numeric:tabular-nums}
</style>
</head>
<body>
<div class="wrap">

<?php if ($needSetup): ?>
  <?php if (!$dataWritable): ?>
    <div class="alert err">Папка <b>data</b> не доступна для записи. Установите права: <b>chmod 775 data</b> (или 777), затем обновите страницу.</div>
  <?php endif; ?>
  <div class="card">
    <h1><?php echo e(APP_TITLE); ?></h1>
    <p class="muted">Первый запуск: создайте администратора.</p>
    <?php if ($pageError !== ''): ?><div class="alert err" id="setupError"><?php echo e($pageError); ?></div><?php endif; ?>
    <form id="setupForm" action="index.php" method="post" onsubmit="return doSetup(event)">
      <input type="hidden" name="form_setup" value="1">
      <label>Логин</label>
      <input type="text" id="s_login" name="login" required maxlength="32" autocomplete="username">
      <label>Пароль (не менее 6 символов)</label>
      <input type="password" id="s_pass" name="password" required minlength="6" autocomplete="new-password">
      <label>Имя</label>
      <input type="text" id="s_name" name="name" placeholder="Имя и фамилия">
      <label>Email</label>
      <input type="email" id="s_email" name="email">
      <div class="row" style="margin-top:14px">
        <button type="submit" class="primary">Создать администратора</button>
      </div>
      <div id="msg" class="alert hidden"></div>
    </form>
  </div>

<?php elseif ($user === null): ?>
  <?php if (!$dataWritable): ?>
    <div class="alert err">Папка <b>data</b> не доступна для записи. Установите права: <b>chmod 775 data</b> (или 777), затем обновите страницу.</div>
  <?php endif; ?>
  <div class="card">
    <h1><?php echo e(APP_TITLE); ?></h1>
    <div class="alert info">Доступ только по паролю. Войдите, чтобы продолжить.</div>
    <?php if ($pageError !== ''): ?><div class="alert err" id="loginError"><?php echo e($pageError); ?></div><?php endif; ?>
    <form id="loginForm" action="index.php" method="post" onsubmit="return doLogin(event)">
      <input type="hidden" name="form_login" value="1">
      <label>Логин</label>
      <input type="text" id="l_login" name="login" required autocomplete="username">
      <label>Пароль</label>
      <input type="password" id="l_pass" name="password" required autocomplete="current-password">
      <div class="row" style="margin-top:14px">
        <button type="submit" class="primary">Войти</button>
      </div>
      <div id="msg" class="alert hidden"></div>
    </form>
  </div>

<?php else: ?>
  <div class="card topbar">
    <h1 class="grow"><?php echo e(APP_TITLE); ?></h1>
    <span class="muted">Вы вошли как <b><?php echo e($user['name'] !== '' ? $user['name'] : $user['login']); ?></b><?php echo empty($user['is_admin']) ? '' : ' · админ'; ?></span>
    <button class="ghost btn-small" onclick="logout()">Выйти</button>
  </div>

  <div class="card" id="timerPanel">
    <div id="startForm">
      <h2>Новая задача</h2>
      <div class="row">
        <div class="grow" style="flex:1 1 260px">
          <label>Проект</label>
          <input type="text" id="f_project" list="projectsList" placeholder="Выберите или введите новый проект">
        </div>
        <div style="flex:1 1 200px">
          <label>Время начала</label>
          <input type="datetime-local" id="f_start">
        </div>
      </div>
      <label>Что делаете</label>
      <textarea id="f_description" rows="2" placeholder="Описание задачи…"></textarea>
      <div class="row" style="margin-top:12px">
        <button class="primary" id="startBtn" onclick="startEntry()">▶ Старт</button>
      </div>
    </div>
    <div id="activeCard" class="hidden">
      <div class="row">
        <div class="grow">
          <div class="muted">Текущая задача</div>
          <div><b id="a_project"></b> <span id="a_periodBadge" class="badge"></span></div>
          <div id="a_description" class="muted" style="white-space:pre-wrap"></div>
          <div class="muted">Начало: <span id="a_start"></span></div>
        </div>
        <div class="right">
          <div class="timer-big" id="a_duration">0:00:00</div>
          <button class="primary" style="margin-top:8px" onclick="stopEntry()">■ Стоп</button>
        </div>
      </div>
    </div>
  </div>

  <div class="card hidden" id="editCard">
    <h2 id="editTitle">Редактирование записи</h2>
    <input type="hidden" id="e_period">
    <input type="hidden" id="e_id">
    <label>Проект</label>
    <input type="text" id="e_project" list="projectsList">
    <label>Описание</label>
    <textarea id="e_description" rows="3"></textarea>
    <div class="row">
      <div class="grow">
        <label>Начало</label>
        <input type="datetime-local" id="e_start">
      </div>
      <div class="grow">
        <label>Конец</label>
        <input type="datetime-local" id="e_end">
      </div>
    </div>
    <div class="row" style="margin-top:12px">
      <button class="primary" onclick="saveEdit()">Сохранить</button>
      <button class="ghost" onclick="closeEdit()">Отмена</button>
      <span class="grow"></span>
      <button class="danger" onclick="deleteEdit()">Удалить</button>
    </div>
    <div id="editMsg" class="alert hidden"></div>
  </div>

  <div class="card">
    <h2>Записи за <span id="periodLabel"><?php echo e(date('Y-m')); ?></span></h2>
    <div id="list">
      <p class="muted">Загрузка…</p>
    </div>
  </div>

  <div class="card topbar">
    <button class="ghost" onclick="shiftMonth(-1)">← Пред. месяц</button>
    <input type="month" id="period" value="<?php echo e(date('Y-m')); ?>" onchange="setPeriod(this.value)">
    <button class="ghost" onclick="shiftMonth(1)">След. месяц →</button>
    <span class="grow"></span>
    <a class="btn-small primary" style="display:inline-block;text-decoration:none;padding:8px 12px" href="<?php echo e(app_url('/export.php?login=' . urlencode($user['login']) . '&period=' . date('Y-m'))); ?>" download="true">Скачать CSV</a>
    <button class="ghost" onclick="toggleManager()">Ссылка для руководителя</button>
    <?php if (!empty($user['is_admin'])): ?>
      <button class="ghost" onclick="toggleAdmin()">Управление</button>
    <?php endif; ?>
  </div>

  <div class="card hidden" id="managerCard">
    <h2>Ссылка на отчёт CSV (для руководителя)</h2>
    <div class="row">
      <input type="text" class="grow mono" id="managerLink" readonly onclick="this.select()">
      <button onclick="copyLink()">Копировать</button>
    </div>
    <p class="muted">Открыв ссылку без входа, руководитель скачает CSV-отчёт за выбранный месяц.</p>
  </div>

  <div class="card hidden" id="adminCard">
    <h2>Проекты</h2>
    <div id="adminProjects"></div>
    <h2>Пользователи</h2>
    <div id="adminUsers"></div>
    <h2>Добавить пользователя</h2>
    <form id="addUserForm" onsubmit="return addUser(event)">
      <div class="row">
        <input type="text" id="au_login" placeholder="Логин" required maxlength="32" class="grow" style="flex:1 1 180px">
        <input type="password" id="au_pass" placeholder="Пароль" required minlength="6" class="grow" style="flex:1 1 180px">
        <input type="text" id="au_name" placeholder="Имя" class="grow" style="flex:1 1 180px">
        <input type="email" id="au_email" placeholder="Email" class="grow" style="flex:1 1 180px">
        <button type="submit" class="primary">Добавить</button>
      </div>
    </form>
  </div>

  <datalist id="projectsList"></datalist>

<?php endif; ?>

</div>

<script>
const $ = id => document.getElementById(id);

function showMsg(text, isErr) {
  const msg = $('msg');
  if (!msg) return;
  msg.textContent = text || '';
  msg.classList.remove('hidden');
  msg.classList.toggle('err', !!isErr);
  msg.classList.toggle('info', !isErr);
}

function pad(n) { return String(n).padStart(2, '0'); }
function nowStr() {
  const d = new Date();
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
    + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
}
function toInput(dt) { return dt ? dt.replace(' ', 'T') : ''; }
function toLocal(v) {
  if (!v) return '';
  let s = v.replace('T', ' ');
  if (/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(s)) s += ':00';
  return s;
}
function fmtDur(ms) {
  return fmtHMS(ms);
}
function fmtHMS(ms) {
  const total = Math.max(0, Math.floor(ms / 1000));
  const h = Math.floor(total / 3600);
  const m = Math.floor((total % 3600) / 60);
  const s = total % 60;
  return h + ':' + pad(m) + ':' + pad(s);
}
function durMin(dt) { return new Date(dt.replace(' ', 'T')).getTime(); }

function api(action, params = {}) {
  params.action = action;
  const body = new URLSearchParams();
  for (const k in params) body.append(k, params[k]);
  return fetch('api.php', { method: 'POST', body, credentials: 'same-origin' })
    .then(r => r.json().catch(() => ({ ok: false, error: 'Ошибка сервера' })))
    .then(d => {
      if (!d.ok) {
        const err = new Error(d.error || 'Ошибка');
        err.conflicts = d.conflicts || [];
        throw err;
      }
      return d;
    });
}

let projects = [];
function refreshProjects() {
  api('projects').then(d => {
    projects = d.projects || [];
    const dl = $('projectsList');
    dl.innerHTML = projects.map(p => '<option value="' + escapeHtml(p) + '">').join('');
  });
}
function escapeHtml(s) {
  return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function logout() { api('logout').then(() => location.reload()); }

async function doLogin(ev) {
  ev.preventDefault();
  try {
    await api('login', { login: $('l_login').value.trim(), password: $('l_pass').value });
    location.reload();
  } catch (e) { showMsg(e.message, true); }
  return false;
}

async function doSetup(ev) {
  ev.preventDefault();
  try {
    await api('setup', {
      login: $('s_login').value.trim(),
      password: $('s_pass').value,
      name: $('s_name').value.trim(),
      email: $('s_email').value.trim(),
    });
    location.reload();
  } catch (e) { showMsg(e.message, true); }
  return false;
}

/* ---------------- Главный интерфейс ---------------- */

let period = '';
let entries = [];
let active = null;
let reloadTimer = null;
let startEdited = false;

function setPeriod(v) {
  if (!v) return;
  period = v;
  $('period').value = v;
  $('periodLabel').textContent = v;
  loadList();
  refreshManagerLink();
  const csv = document.querySelector('a[download="true"]');
  if (csv) csv.href = 'export.php?login=<?php echo urlencode($myLogin); ?>&period=' + encodeURIComponent(v);
}

function shiftMonth(delta) {
  const [y, m] = period.split('-').map(Number);
  const d = new Date(y, m - 1 + delta, 1);
  setPeriod(d.getFullYear() + '-' + pad(d.getMonth() + 1));
}

function refreshManagerLink() {
  const el = $('managerLink');
  if (el) el.value = <?php echo json_encode(app_url('/export.php?key=' . EXPORT_KEY . '&login=' . urlencode($myLogin) . '&period='), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?> + period;
}
function toggleManager() {
  refreshManagerLink();
  $('managerCard').classList.toggle('hidden');
}
function copyLink() {
  const el = $('managerLink');
  el.select();
  document.execCommand('copy');
}

function loadList() {
  api('list', { period }).then(d => {
    entries = d.entries || [];
    renderList();
  }).catch(e => { $('list').innerHTML = '<div class="alert err">' + escapeHtml(e.message) + '</div>'; });
  api('active').then(d => {
    active = d.entry || null;
    renderActive();
  });
}

function entryDur(entry) {
  const end = entry.end ? durMin(entry.end) : Date.now();
  return end - durMin(entry.start);
}

function renderList() {
  const host = $('list');
  if (!entries.length) {
    host.innerHTML = '<p class="muted">Записей пока нет. Запустите таймер выше.</p>';
    return;
  }
  let total = 0;
  const rows = entries.map(entry => {
    if (!entry.end) total += 0;
    else total += entryDur(entry);
    const live = entry.end ? '' : 'live';
    return '<tr class="' + (entry.end ? '' : 'running') + '" data-id="' + entry.id + '">'
      + '<td class="proj">' + escapeHtml(entry.project) + '</td>'
      + '<td class="desc">' + escapeHtml(entry.description || '') + '</td>'
      + '<td class="mono">' + escapeHtml(entry.start) + '</td>'
      + '<td class="mono">' + (entry.end ? escapeHtml(entry.end) : '—') + '</td>'
      + '<td class="dur right" data-start="' + escapeHtml(entry.start) + '" data-end="' + escapeHtml(entry.end || '') + '">' + fmtDur(entryDur(entry)) + '</td>'
      + '<td class="right"><button class="ghost btn-small" onclick="openEdit(' + entry.id + ')">Изменить</button></td>'
      + '</tr>';
  }).join('');
  host.innerHTML = '<table class="entries"><thead><tr>'
    + '<th>Проект</th><th>Описание</th><th>Начало</th><th>Конец</th>'
    + '<th class="right">Длительность</th><th></th>'
    + '</tr></thead><tbody>' + rows + '</tbody>'
    + '<tfoot><tr><td colspan="4" class="right total">Итого за месяц</td>'
    + '<td class="right total"><span id="monthTotal">' + fmtHMS(total) + '</span></td><td></td></tr></tfoot></table>';
  tick();
}

function tick() {
  clearTimeout(reloadTimer);
  document.querySelectorAll('.dur.live').forEach(el => {
    el.textContent = fmtDur(durMin(el.dataset.start) ? Math.max(0, Date.now() - durMin(el.dataset.start)) : 0);
  });
  const mt = $('monthTotal');
  if (mt) {
    let total = 0;
    for (const entry of entries) if (entry.end) total += entryDur(entry);
    const liveRow = document.querySelector('.dur.live');
    if (liveRow) total += Math.max(0, Date.now() - durMin(liveRow.dataset.start));
    mt.textContent = fmtHMS(total);
  }
  const ad = $('a_duration');
  if (ad && active) ad.textContent = fmtDur(Date.now() - durMin(active.start));
  reloadTimer = setTimeout(tick, 1000);
}

function renderActive() {
  const startF = $('startForm');
  const activeC = $('activeCard');
  if (!startF || !activeC) return;
  if (!active) {
    startF.classList.remove('hidden');
    activeC.classList.add('hidden');
    startEdited = false;
    $('f_start').value = toInput(nowStr());
  } else {
    startF.classList.add('hidden');
    activeC.classList.remove('hidden');
    $('a_project').textContent = active.project;
    $('a_description').textContent = active.description || '';
    $('a_start').textContent = active.start;
    $('a_periodBadge').textContent = active.start.slice(0, 7);
    $('a_duration').textContent = fmtDur(Date.now() - durMin(active.start));
  }
}

async function startEntry() {
  const project = $('f_project').value.trim();
  if (!project) { showMsg('Укажите проект', true); return; }
  const startVal = startEdited ? toLocal($('f_start').value) : nowStr();
  if (!startVal) { showMsg('Укажите время начала', true); return; }
  try {
    const d = await api('start', {
      project,
      description: $('f_description').value,
      start: startVal,
    });
    startEdited = false;
    refreshProjects();
    if (d.period !== period) { setPeriod(d.period); return; }
    active = d.entry;
    renderActive();
    loadList();
  } catch (e) {
    showMsg(e.message, true);
    applyConflicts(e.conflicts);
  }
}

async function stopEntry() {
  try {
    const d = await api('stop', { end: nowStr() });
    if (d.entry) {
      const p = d.entry.start.slice(0, 7);
      active = null;
      renderActive();
      if (p !== period) setPeriod(p); else loadList();
      showMsg('Задача остановлена', false);
      setTimeout(() => showMsg('', false), 3000);
    }
  } catch (e) { showMsg(e.message, true); }
}

function openEdit(id) {
  const entry = entries.find(x => x.id === id);
  if (!entry) return;
  $('e_period').value = period;
  $('e_id').value = entry.id;
  $('e_project').value = entry.project;
  $('e_description').value = entry.description || '';
  $('e_start').value = toInput(entry.start);
  $('e_end').value = toInput(entry.end || '');
  $('editTitle').textContent = 'Редактирование записи · ' + period;
  $('editCard').classList.remove('hidden');
  $('editCard').scrollIntoView({ behavior: 'smooth' });
  $('editMsg').classList.add('hidden');
}
function closeEdit() { $('editCard').classList.add('hidden'); }

function editMsg(text, isErr) {
  const el = $('editMsg');
  el.textContent = text || '';
  el.classList.remove('hidden');
  el.classList.toggle('err', !!isErr);
  el.classList.toggle('info', !isErr);
}

async function saveEdit() {
  const params = {
    period: $('e_period').value,
    id: $('e_id').value,
    project: $('e_project').value.trim(),
    description: $('e_description').value,
    start: toLocal($('e_start').value),
    end: toLocal($('e_end').value),
  };
  if (!params.project) { editMsg('Укажите проект', true); return; }
  if (!params.start) { editMsg('Укажите время начала', true); return; }
  try {
    await api('update', params);
    closeEdit();
    loadList();
    api('active').then(d => { active = d.entry; renderActive(); });
  } catch (e) {
    editMsg(e.message, true);
    applyConflicts(e.conflicts);
  }
  return false;
}

function applyConflicts(ids) {
  document.querySelectorAll('#list tbody tr').forEach(tr => tr.classList.remove('conflict'));
  (ids || []).forEach(id => {
    const tr = document.querySelector('#list tbody tr[data-id="' + id + '"]');
    if (tr) tr.classList.add('conflict');
  });
}

async function deleteEdit() {
  if (!confirm('Удалить запись?')) return;
  try {
    await api('delete', { period: $('e_period').value, id: $('e_id').value });
    closeEdit();
    loadList();
  } catch (e) { editMsg(e.message, true); }
}

/* ---------------- Админ-панель ---------------- */

function toggleAdmin() { $('adminCard').classList.toggle('hidden'); loadAdmin(); }

function loadAdmin() {
  api('users').then(d => {
    const users = d.users || [];
    $('adminUsers').innerHTML = '<table><thead><tr><th>Логин</th><th>Имя</th><th>Email</th><th>Админ</th><th></th></tr></thead><tbody>'
      + users.map(u =>
        '<tr>'
        + '<td>' + escapeHtml(u.login) + '</td>'
        + '<td>' + escapeHtml(u.name) + '</td>'
        + '<td>' + escapeHtml(u.email) + '</td>'
        + '<td><input type="checkbox" ' + (u.is_admin ? 'checked' : '') + ' onclick="setAdmin(\'' + escapeHtml(u.login) + '\', this.checked)"></td>'
        + '<td class="right"><button class="ghost btn-small" onclick="resetPw(\'' + escapeHtml(u.login) + '\')">Пароль</button> '
        + '<button class="danger btn-small" onclick="delUser(\'' + escapeHtml(u.login) + '\')">Удалить</button></td>'
        + '</tr>'
      ).join('') + '</tbody></table>';
  });
  api('projects').then(d => {
    const pjs = d.projects || [];
    $('adminProjects').innerHTML = pjs.length
      ? '<table><tbody>' + pjs.map(p =>
        '<tr><td>' + escapeHtml(p) + '</td><td class="right"><button class="danger btn-small" onclick="delProject(\'' + escapeHtml(p).replace(/'/g, "\\'") + '\')">Удалить</button></td></tr>'
      ).join('') + '</tbody></table>'
      : '<p class="muted">Проектов пока нет.</p>';
  });
}

async function addUser(ev) {
  ev.preventDefault();
  try {
    await api('add_user', {
      login: $('au_login').value.trim(),
      password: $('au_pass').value,
      name: $('au_name').value.trim(),
      email: $('au_email').value.trim(),
    });
    $('au_login').value = ''; $('au_pass').value = ''; $('au_name').value = ''; $('au_email').value = '';
    loadAdmin();
  } catch (e) { alert(e.message); }
  return false;
}
function delUser(login) {
  if (!confirm('Удалить пользователя «' + login + '»?')) return;
  api('del_user', { login }).then(() => loadAdmin()).catch(e => alert(e.message));
}
function resetPw(login) {
  const pass = prompt('Новый пароль для пользователя «' + login + '» (минимум 6 символов):');
  if (pass === null || pass === '') return;
  api('reset_password', { login, password: pass }).then(() => alert('Пароль изменён')).catch(e => alert(e.message));
}
function setAdmin(login, isAdmin) {
  api('set_admin', { login, is_admin: isAdmin ? 1 : 0 }).then(() => loadAdmin()).catch(e => { alert(e.message); loadAdmin(); });
}
function delProject(project) {
  if (!confirm('Удалить проект «' + project + '»?')) return;
  api('del_project', { project }).then(() => { refreshProjects(); loadAdmin(); }).catch(e => alert(e.message));
}

/* ---------------- Инициализация ---------------- */
(function init() {
  if (!$('period')) return;
  const p = new Date();
  period = p.getFullYear() + '-' + pad(p.getMonth() + 1);
  $('period').value = period;
  clearTimeout(reloadTimer);
  setPeriod(period);
  refreshProjects();
  const fs = $('f_start');
  if (fs) fs.addEventListener('input', () => { startEdited = true; });
})();
</script>
</body>
</html>