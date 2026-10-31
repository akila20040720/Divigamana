<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';

use Divigamana\Support\Csrf;
use Divigamana\Support\Database;

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function n($value): string { return number_format((int)$value); }
function money($value): string { return 'LKR ' . number_format((float)$value, 2); }
function carrierPlatform(string $mobile): string {
    if (preg_match('/^\\+947[01]\\d{7}$/', $mobile)) return 'mSpace';
    if (preg_match('/^\\+947[245678]\\d{7}$/', $mobile)) return 'IdeaMart';
    return 'Unknown';
}
function validDay(string $value): string { return preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $value) ? $value : date('Y-m-d'); }
function qs(array $values): string { return '?' . http_build_query(array_filter($values, static fn($v) => $v !== '' && $v !== null)); }

$adminConfig = APP_CONFIG['admin'] ?? [];
$adminUsername = (string)($adminConfig['username'] ?? '');
$adminPassword = (string)($adminConfig['password'] ?? '');
if ($adminUsername === '' || $adminPassword === '') {
    http_response_code(503);
    exit('Admin dashboard credentials are not configured.');
}
$adminFingerprint=hash('sha256',$adminUsername."\0".$adminPassword);
$authenticated=isset($_SESSION['admin_fingerprint'])&&hash_equals($adminFingerprint,(string)$_SESSION['admin_fingerprint']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$authenticated) {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        http_response_code(419);
        $error = 'Your session expired. Please try again.';
    } else {
        $attempts = $_SESSION['admin_login_attempts'] ?? ['count' => 0, 'started_at' => time()];
        if (!is_array($attempts) || time() - (int)($attempts['started_at'] ?? 0) > 900) $attempts = ['count' => 0, 'started_at' => time()];
        if ((int)$attempts['count'] >= 5) {
            $error = 'Too many sign-in attempts. Try again in 15 minutes.';
        } elseif (isset($_POST['username'], $_POST['password'])
            && hash_equals($adminUsername, (string)$_POST['username'])
            && hash_equals($adminPassword, (string)$_POST['password'])) {
            session_regenerate_id(true);
            $_SESSION['admin_fingerprint'] = $adminFingerprint;
            $_SESSION['admin_login_attempts'] = ['count' => 0, 'started_at' => time()];
            header('Location: /admin');
            exit;
        } else {
            $attempts['count'] = (int)$attempts['count'] + 1;
            $_SESSION['admin_login_attempts'] = $attempts;
            $error = 'Invalid username or password.';
        }
    }
}

if (!$authenticated) :
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Admin sign in | Divigamana</title><style>:root{color-scheme:dark}*{box-sizing:border-box}body{min-height:100vh;margin:0;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at 20% 15%,#292158 0,transparent 34%),#0b1026;color:#f9f5ed;font-family:Inter,system-ui,sans-serif}.panel{width:min(100%,420px);padding:34px;border:1px solid #ffffff22;border-radius:18px;background:#171333;box-shadow:0 24px 70px #0007}.mark{display:grid;place-items:center;width:46px;height:46px;border-radius:14px;background:#d5ad63;color:#171333;font-size:24px;font-weight:800}h1{margin:20px 0 7px;font-size:1.7rem}p{color:#c8c2d8;line-height:1.5}label{display:block;margin-top:18px;color:#e8e0f2;font-size:.9rem;font-weight:600}input{width:100%;margin-top:7px;padding:13px;border:1px solid #ffffff26;border-radius:9px;background:#0e0b22;color:#fff;font:inherit}button{width:100%;margin-top:24px;padding:13px;border:0;border-radius:9px;background:#d5ad63;color:#171333;font:700 1rem inherit;cursor:pointer}.error{margin:18px 0 0;padding:11px;border-radius:8px;background:#56232d;color:#ffdce0}.back{display:block;margin-top:20px;text-align:center;color:#d5ad63;font-size:.9rem;text-decoration:none}</style></head><body><main class="panel"><div class="mark">දි</div><h1>Divigamana Admin</h1><p>Sign in to manage users and view platform activity.</p><?php if ($error): ?><div class="error" role="alert"><?= e($error) ?></div><?php endif; ?><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= e(Csrf::token()) ?>"><label>Username<input name="username" required autofocus autocomplete="username"></label><label>Password<input name="password" type="password" required autocomplete="current-password"></label><button>Sign in</button></form><a class="back" href="/">← Return to Divigamana.lk</a></main></body></html><?php exit; endif;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::verify($_POST['csrf'] ?? null)) {
        http_response_code(419);
        exit('Your session expired. Reload the dashboard and try again.');
    }
    if (isset($_POST['logout'])) {
        unset($_SESSION['admin_fingerprint'], $_SESSION['admin_login_attempts']);
        session_regenerate_id(true);
        header('Location: /admin');
        exit;
    }
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['user_id'] ?? 0);
    if ($id > 0 && in_array($action, ['block', 'unblock'], true)) {
        try {
            $pdo = Database::pdo();
            $blocked = $action === 'block';
            $pdo->prepare('UPDATE users SET account_status=?, blocked_at=? WHERE id=?')->execute([$blocked ? 'blocked' : 'active', $blocked ? date('Y-m-d H:i:s') : null, $id]);
            $pdo->prepare('INSERT INTO audit_events (user_id,event_type,metadata_json) VALUES (?,?,?)')->execute([$id, $blocked ? 'admin_user_blocked' : 'admin_user_unblocked', json_encode(['source' => 'admin_dashboard'])]);
        } catch (Throwable $exception) {
            error_log('Divigamana admin user action error: ' . $exception->getMessage());
        }
        header('Location: /admin' . (string)($_POST['return_to'] ?? ''));
        exit;
    }
}

$day = validDay((string)($_GET['day'] ?? ''));
$today = date('Y-m-d');
$prev = date('Y-m-d', strtotime($day . ' -1 day'));
$next = date('Y-m-d', strtotime($day . ' +1 day'));
$query = trim((string)($_GET['q'] ?? ''));
$filter = (string)($_GET['status'] ?? '');
$filters = ['' => 'All users', 'active' => 'Active subscription', 'inactive' => 'Inactive / expired', 'blocked' => 'Blocked'];
if (!isset($filters[$filter])) $filter = '';
$returnTo = qs(['day' => $day === $today ? '' : $day, 'q' => $query, 'status' => $filter]);

$providerExpr = "CASE WHEN u.mobile_e164 LIKE '+9470%' OR u.mobile_e164 LIKE '+9471%' THEN 'mSpace' WHEN u.mobile_e164 LIKE '+9472%' OR u.mobile_e164 LIKE '+9474%' OR u.mobile_e164 LIKE '+9475%' OR u.mobile_e164 LIKE '+9476%' OR u.mobile_e164 LIKE '+9477%' OR u.mobile_e164 LIKE '+9478%' THEN 'IdeaMart' ELSE 'Unknown' END";
$realUserExpr = "u.phone_verified_real=1";
// Carrier callbacks are authoritative. A registered IdeaMart subscriber remains active
// even when the locally estimated end date is stale; cancelled/pending callbacks do not.
$activeExpr = "u.account_status='active' AND COALESCE((SELECT CASE WHEN us.provider='ideamart' THEN us.provider_status='REGISTERED' ELSE us.status='active' AND us.ends_at>NOW() END FROM user_subscriptions us WHERE us.user_id=u.id ORDER BY us.id DESC LIMIT 1),0)";
$stats = ['all' => 0, 'active' => 0, 'inactive' => 0, 'blocked' => 0, 'new' => 0, 'logins' => 0];
$providers = [];
$providerBase = ['mSpace' => 0, 'IdeaMart' => 0];
$platforms = ['mSpace' => ['users' => 0, 'active' => 0, 'inactive' => 0], 'IdeaMart' => ['users' => 0, 'active' => 0, 'inactive' => 0]];
$verification = ['total' => 0, 'verified' => 0, 'ideamart' => 0, 'mspace' => 0, 'pending' => 0];
$charges = ['day_count' => 0, 'day_success' => 0, 'day_failed' => 0, 'day_revenue' => 0, 'all_count' => 0, 'all_success' => 0, 'all_failed' => 0, 'all_revenue' => 0, 'average_success' => 0];
$dailyCharges = $subscriptions = $popular = $recent = $users = [];
$databaseError = false;

try {
    $pdo = Database::pdo();
    $sum = $pdo->query("SELECT COUNT(*) all_count, COALESCE(SUM($activeExpr),0) active_count, COALESCE(SUM(u.account_status='blocked'),0) blocked_count FROM users u")->fetch() ?: [];
    $stats['all'] = (int)($sum['all_count'] ?? 0);
    $stats['active'] = (int)($sum['active_count'] ?? 0);
    $stats['blocked'] = (int)($sum['blocked_count'] ?? 0);
    $stats['inactive'] = max(0, $stats['all'] - $stats['active'] - $stats['blocked']);
    $range = [$day . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($day . ' +1 day'))];
    $st = $pdo->prepare("SELECT COUNT(*) new_users, COALESCE(SUM(last_login_at>=? AND last_login_at<?),0) logins FROM users u WHERE created_at>=? AND created_at<?");
    $st->execute([$range[0], $range[1], $range[0], $range[1]]);
    $period = $st->fetch() ?: [];
    $stats['new'] = (int)($period['new_users'] ?? 0); $stats['logins'] = (int)($period['logins'] ?? 0);

    $providerPaymentJoin = "((($providerExpr)='mSpace' AND p.provider='mspace') OR (($providerExpr)='IdeaMart' AND p.provider='ideamart'))";
    $st = $pdo->prepare("SELECT $providerExpr provider, COUNT(u.id) users, COALESCE(SUM($activeExpr),0) active, COALESCE(SUM(u.account_status='active' AND NOT ($activeExpr)),0) inactive, COALESCE(SUM(u.account_status='blocked'),0) blocked, COALESCE(SUM($realUserExpr),0) real_verified, COALESCE(SUM(p.charges),0) charges, COALESCE(SUM(p.successful),0) successful, COALESCE(SUM(p.failed),0) failed, COALESCE(SUM(p.revenue),0) revenue FROM users u LEFT JOIN (SELECT user_id,provider,COUNT(*) charges,SUM(status='paid') successful,SUM(status='failed') failed,SUM(CASE WHEN status='paid' THEN amount ELSE 0 END) revenue FROM payment_transactions WHERE provider IN ('ideamart','mspace') GROUP BY user_id,provider) p ON p.user_id=u.id AND $providerPaymentJoin WHERE u.created_at>=? AND u.created_at<? AND $providerExpr IN ('mSpace','IdeaMart') GROUP BY provider ORDER BY FIELD(provider,'mSpace','IdeaMart')");
    $st->execute($range); $providers = $st->fetchAll();
    $baseRows = $pdo->query("SELECT $providerExpr provider, COUNT(*) n FROM users u WHERE $activeExpr AND $providerExpr IN ('mSpace','IdeaMart') GROUP BY provider")->fetchAll();
    foreach ($baseRows as $row) $providerBase[$row['provider']] = (int)$row['n'];
    $platformRows = $pdo->query("SELECT $providerExpr provider, COUNT(*) users, COALESCE(SUM($activeExpr),0) active, COALESCE(SUM(u.account_status='active' AND NOT ($activeExpr)),0) inactive FROM users u WHERE $providerExpr IN ('mSpace','IdeaMart') GROUP BY provider")->fetchAll();
    foreach ($platformRows as $platformRow) $platforms[$platformRow['provider']] = ['users'=>(int)$platformRow['users'],'active'=>(int)$platformRow['active'],'inactive'=>(int)$platformRow['inactive']];
    $st = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(u.phone_verified_real=1),0) verified, COALESCE(SUM(u.phone_verified_real=1 AND ($providerExpr)='IdeaMart'),0) ideamart, COALESCE(SUM(u.phone_verified_real=1 AND ($providerExpr)='mSpace'),0) mspace, COALESCE(SUM(u.phone_verified_real=0),0) pending FROM users u WHERE created_at>=? AND created_at<?");
    $st->execute($range); $verification = array_map('intval', $st->fetch() ?: $verification);

    $st = $pdo->prepare("SELECT COUNT(*) day_count, COALESCE(SUM(status IN ('paid','simulated_confirmed')),0) day_success, COALESCE(SUM(status='failed'),0) day_failed, COALESCE(SUM(CASE WHEN status IN ('paid','simulated_confirmed') THEN amount ELSE 0 END),0) day_revenue FROM payment_transactions WHERE created_at>=? AND created_at<?");
    $st->execute($range); $charges = array_merge($charges, $st->fetch() ?: []);
    $all = $pdo->query("SELECT COUNT(*) all_count, COALESCE(SUM(status IN ('paid','simulated_confirmed')),0) all_success, COALESCE(SUM(status='failed'),0) all_failed, COALESCE(SUM(CASE WHEN status IN ('paid','simulated_confirmed') THEN amount ELSE 0 END),0) all_revenue, COALESCE(AVG(CASE WHEN status IN ('paid','simulated_confirmed') THEN amount END),0) average_success FROM payment_transactions")->fetch() ?: [];
    $charges = array_merge($charges, $all);
    $dailyCharges = $pdo->query("SELECT DATE(created_at) day, COUNT(*) attempts, COALESCE(SUM(status IN ('paid','simulated_confirmed')),0) successful, COALESCE(SUM(CASE WHEN status IN ('paid','simulated_confirmed') THEN amount ELSE 0 END),0) revenue FROM payment_transactions WHERE created_at>=DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY DATE(created_at) ORDER BY day DESC")->fetchAll();
    $subscriptions = $pdo->query("SELECT DATE_FORMAT(accepted_at,'%Y-%m-%d %H:00') hour_label, COUNT(*) n FROM user_subscriptions WHERE accepted_at>=NOW()-INTERVAL 24 HOUR GROUP BY hour_label ORDER BY hour_label DESC")->fetchAll();
    $popular = $pdo->query('SELECT s.slug, COUNT(q.id) n FROM service_catalog s LEFT JOIN horoscope_requests q ON q.service_id=s.id GROUP BY s.id,s.slug ORDER BY n DESC,s.id ASC LIMIT 8')->fetchAll();

    $where = []; $params = [];
    if ($query !== '') { $where[] = 'u.mobile_e164 LIKE ?'; $params[] = '%' . preg_replace('/\\D/', '', $query) . '%'; }
    else { $where[] = 'u.created_at>=? AND u.created_at<?'; $params = $range; }
    if ($filter === 'active') $where[] = $activeExpr;
    if ($filter === 'inactive') $where[] = "u.account_status='active' AND NOT ($activeExpr)";
    if ($filter === 'blocked') $where[] = "u.account_status='blocked'";
    $sql = "SELECT u.*, CASE WHEN u.account_status='blocked' THEN 'blocked' WHEN $activeExpr THEN 'active' ELSE 'inactive' END dashboard_status, (SELECT MAX(ends_at) FROM user_subscriptions ux WHERE ux.user_id=u.id) subscription_ends_at, (SELECT provider_status FROM user_subscriptions ux WHERE ux.user_id=u.id ORDER BY ux.id DESC LIMIT 1) provider_subscription_status, (SELECT frequency FROM user_subscriptions ux WHERE ux.user_id=u.id ORDER BY ux.id DESC LIMIT 1) subscription_frequency, (SELECT notified_at FROM user_subscriptions ux WHERE ux.user_id=u.id ORDER BY ux.id DESC LIMIT 1) subscription_notified_at FROM users u WHERE " . implode(' AND ', $where) . ' ORDER BY u.created_at DESC LIMIT 500';
    $st = $pdo->prepare($sql); $st->execute($params); $users = $st->fetchAll();
    $recent = $pdo->query('SELECT q.status,q.locale,q.created_at,s.slug FROM horoscope_requests q JOIN service_catalog s ON s.id=q.service_id ORDER BY q.id DESC LIMIT 8')->fetchAll();
} catch (Throwable $exception) {
    error_log('Divigamana admin dashboard database error: ' . $exception->getMessage());
    $databaseError = true;
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin dashboard | Divigamana</title><style>
:root{color-scheme:dark;--navy:#0b1026;--panel:#171333;--line:#ffffff1f;--gold:#d5ad63;--muted:#bdb5ca;--green:#71d0aa;--red:#fa98a8}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 8% 0,#292158 0,transparent 32%),var(--navy);color:#f9f5ed;font:14px Inter,system-ui,sans-serif}.shell{max-width:1400px;margin:auto;padding:28px 22px 55px}.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:30px}.brand{display:flex;align-items:center;gap:12px}.mark{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;background:var(--gold);color:#171333;font-size:22px;font-weight:800}h1,h2,h3{margin:0}.muted,small{color:var(--muted)}button,.button{border:1px solid var(--line);border-radius:8px;padding:8px 11px;background:#ffffff0a;color:#f9f5ed;cursor:pointer;text-decoration:none;font:inherit}.danger{color:#ffbac4;border-color:#fa98a855}.notice{padding:13px 15px;background:#382a18;color:#ffdf98;border:1px solid #e5b75b55;border-radius:10px;margin-bottom:18px}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:22px 0}.card,.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px}.card{padding:18px}.card b{display:block;color:var(--gold);font-size:1.65rem;margin-bottom:6px}.card span{display:block}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.panel{padding:18px;min-width:0}.panel h2{font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);padding-bottom:12px;margin-bottom:3px;border-bottom:1px solid var(--line)}.metric{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px dashed var(--line)}.metric:last-child{border:0}.metric b{text-align:right}.section{margin-top:20px}.toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:9px;margin:18px 0}.toolbar form{display:flex;gap:8px}.toolbar input,.toolbar select{border:1px solid var(--line);border-radius:8px;background:#0e0b22;color:#fff;padding:8px;font:inherit}.tabs a{padding:8px 10px;border:1px solid var(--line);border-radius:20px;color:#f9f5ed;text-decoration:none}.tabs .on{background:var(--gold);color:#171333;border-color:var(--gold)}.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:720px}.table th{text-align:left;color:var(--muted);text-transform:uppercase;font-size:11px;letter-spacing:.04em}.table th,.table td{padding:11px 8px;border-bottom:1px solid var(--line)}.right{text-align:right}.status{font-weight:700;text-transform:capitalize}.active,.completed{color:var(--green)}.inactive,.processing{color:var(--gold)}.blocked,.failed{color:var(--red)}.two{display:grid;grid-template-columns:1fr 1.4fr;gap:16px}.footer{margin-top:22px;color:var(--muted);font-size:12px}@media(max-width:1050px){.grid{grid-template-columns:repeat(2,1fr)}.stats{grid-template-columns:repeat(2,1fr)}}@media(max-width:700px){.shell{padding:20px 14px}.grid,.two{grid-template-columns:1fr}.stats{grid-template-columns:1fr}.top{align-items:flex-start}.toolbar form{width:100%}.toolbar input{min-width:0;width:100%}}</style></head><body><main class="shell">
<header class="top"><div class="brand"><div class="mark">දි</div><div><h1>Divigamana Admin</h1><small>User, subscription, payment and reading analytics</small></div></div><form method="post"><input type="hidden" name="csrf" value="<?= e(Csrf::token()) ?>"><button name="logout" value="1">Sign out</button></form></header>
<?php if ($databaseError): ?><div class="notice">Dashboard data is unavailable because the database connection failed. Confirm the deployed server can reach the configured MySQL host and that the database user has access to this database.</div><?php endif; ?>
<section class="stats"><article class="card"><b><?= n($stats['all']) ?></b><span>Total users</span><small>All registered mobile numbers</small></article><article class="card"><b><?= n($stats['active']) ?></b><span>Active subscriptions</span><small>Active and not expired</small></article><article class="card"><b><?= n($stats['inactive']) ?></b><span>Inactive / expired</span><small>No current active subscription</small></article><article class="card"><b><?= n($stats['blocked']) ?></b><span>Blocked users</span><small>Restricted by an administrator</small></article></section>
<div class="grid"><section class="panel"><h2>Provider funnel · <?= e($day) ?></h2><?php foreach ($providers as $row): ?><div class="metric"><span><b><?= e($row['provider']) ?></b><small><?= n($row['users']) ?> new · <?= n($row['real_verified']) ?> verified</small></span><b><?= n($row['active']) ?> active<br><small><?= n($row['inactive']) ?> inactive · <?= n($row['charges']) ?> charges</small></b></div><?php endforeach; ?><?php if (!$providers): ?><p class="muted">No new users for this day.</p><?php endif; ?><h2 style="margin-top:16px">Current provider base</h2><?php foreach ($providerBase as $name => $count): ?><div class="metric"><span><?= e($name) ?></span><b><?= n($count) ?></b></div><?php endforeach; ?></section>
<section class="panel"><h2>Phone verification · <?= e($day) ?></h2><div class="metric"><span>New users</span><b><?= n($verification['total']) ?></b></div><div class="metric"><span>IdeaMart verified</span><b><?= n($verification['ideamart']) ?></b></div><div class="metric"><span>mSpace verified</span><b><?= n($verification['mspace']) ?></b></div><div class="metric"><span>Not verified</span><b><?= n($verification['pending']) ?></b></div><div class="metric"><span>Verification rate</span><b><?= $verification['total'] ? number_format($verification['verified'] * 100 / $verification['total'], 2) : '0.00' ?>%</b></div><h2 style="margin-top:16px">Provider platform</h2><?php foreach ($platforms as $name => $row): ?><div class="metric"><span><?= e($name) ?><small><?= n($row['users']) ?> users</small></span><b><?= n($row['active']) ?> active<br><small><?= n($row['inactive']) ?> inactive</small></b></div><?php endforeach; ?></section>
<section class="panel"><h2>Charge summary · <?= $day === $today ? 'today' : e($day) ?></h2><div class="metric"><span>Attempts</span><b><?= n($charges['day_count']) ?></b></div><div class="metric"><span>Successful / failed</span><b><?= n($charges['day_success']) ?> / <?= n($charges['day_failed']) ?></b></div><div class="metric"><span>Success rate</span><b><?= $charges['day_count'] ? number_format($charges['day_success'] * 100 / $charges['day_count'], 2) : '0.00' ?>%</b></div><div class="metric"><span>Collected revenue</span><b><?= money($charges['day_revenue']) ?></b></div><h2 style="margin-top:16px">All-time</h2><div class="metric"><span>Attempts / success / failed</span><b><?= n($charges['all_count']) ?> / <?= n($charges['all_success']) ?> / <?= n($charges['all_failed']) ?></b></div><div class="metric"><span>Revenue / avg. success</span><b><?= money($charges['all_revenue']) ?><br><small><?= money($charges['average_success']) ?></small></b></div></section></div>
<div class="grid section"><section class="panel"><h2>Daily charges · last 14 days</h2><?php foreach ($dailyCharges as $row): ?><div class="metric"><span><?= e($row['day']) ?><small><?= n($row['attempts']) ?> attempts</small></span><b><?= n($row['successful']) ?> success<br><small><?= money($row['revenue']) ?></small></b></div><?php endforeach; ?><?php if (!$dailyCharges): ?><p class="muted">No payments recorded.</p><?php endif; ?></section><section class="panel"><h2>Subscriptions · last 24 hours</h2><?php foreach ($subscriptions as $row): ?><div class="metric"><span><?= e($row['hour_label']) ?></span><b><?= n($row['n']) ?></b></div><?php endforeach; ?><?php if (!$subscriptions): ?><p class="muted">No subscription activity recorded.</p><?php endif; ?></section><section class="panel"><h2>Popular services</h2><?php foreach ($popular as $row): ?><div class="metric"><span><?= e($row['slug']) ?></span><b><?= n($row['n']) ?></b></div><?php endforeach; ?></section></div>
<section class="section"><h2>User management</h2><div class="toolbar"><a class="button" href="<?= e(qs(['day' => $prev, 'q' => $query, 'status' => $filter])) ?>">‹ Prev</a><form method="get"><input type="date" name="day" value="<?= e($day) ?>" onchange="this.form.submit()"><?php if ($filter): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?></form><?php if ($day !== $today): ?><a class="button" href="<?= e(qs(['q' => $query, 'status' => $filter])) ?>">Today</a><?php endif; ?><?php if ($day < $today): ?><a class="button" href="<?= e(qs(['day' => $next, 'q' => $query, 'status' => $filter])) ?>">Next ›</a><?php endif; ?><span class="muted"><?= n($stats['new']) ?> new · <?= n($stats['logins']) ?> logins</span><form method="get"><input name="q" value="<?= e($query) ?>" placeholder="Search mobile number"><?php if ($filter): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?></form></div><div class="toolbar tabs"><?php foreach ($filters as $value => $label): ?><a class="<?= $filter === $value ? 'on' : '' ?>" href="<?= e(qs(['day' => $query ? '' : ($day === $today ? '' : $day), 'q' => $query, 'status' => $value])) ?>"><?= e($label) ?></a><?php endforeach; ?></div><?php if ($query): ?><p class="muted">Search results for “<?= e($query) ?>” across all dates. <a href="/admin">Clear</a></p><?php endif; ?><div class="panel table-wrap"><table class="table"><thead><tr><th>Mobile</th><th>Account state</th><th>Verification / platform</th><th>Joined</th><th>Last login</th><th>Carrier subscription</th><th>Subscription ends</th><th></th></tr></thead><tbody><?php foreach ($users as $user): ?><tr><td><b><?= e($user['mobile_e164']) ?></b></td><td><span class="status <?= e($user['dashboard_status']) ?>"><?= e($user['dashboard_status']) ?></span></td><td><?= $user['phone_verified_real'] ? 'Verified: ' . e(carrierPlatform((string)$user['mobile_e164'])) : 'Pending' ?></td><td><?= e($user['created_at']) ?></td><td><?= e($user['last_login_at'] ?: '—') ?></td><td><?= e($user['provider_subscription_status'] ?: '—') ?><?php if ($user['subscription_frequency']): ?><small> · <?= e($user['subscription_frequency']) ?></small><?php endif; ?><?php if ($user['subscription_notified_at']): ?><small><br><?= e($user['subscription_notified_at']) ?></small><?php endif; ?></td><td><?= e($user['subscription_ends_at'] ?: '—') ?></td><td class="right"><form method="post"><input type="hidden" name="csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><input type="hidden" name="return_to" value="<?= e($returnTo) ?>"><?php if ($user['dashboard_status'] === 'blocked'): ?><button name="action" value="unblock">Unblock</button><?php else: ?><button class="danger" name="action" value="block" onclick="return confirm('Block this number from the site?')">Block</button><?php endif; ?></form></td></tr><?php endforeach; ?><?php if (!$users): ?><tr><td colspan="8" class="muted">No users for this view.</td></tr><?php endif; ?></tbody></table></div></section><div class="two section"><section class="panel"><h2>Latest reading requests</h2><?php foreach ($recent as $row): ?><div class="metric"><span><?= e($row['slug']) ?><small><?= e($row['locale']) ?> · <?= e($row['created_at']) ?></small></span><b class="<?= e($row['status']) ?>"><?= e($row['status']) ?></b></div><?php endforeach; ?><?php if (!$recent): ?><p class="muted">No reading requests yet.</p><?php endif; ?></section><section class="panel"><h2>Definitions</h2><p class="muted">Active means the account is not blocked and has a current active subscription. Carrier platform is inferred from the original mobile number prefix: Mobitel numbers use mSpace; Dialog, Hutch and Airtel ranges use IdeaMart.</p></section></div><p class="footer">Dashboard time zone: Asia/Colombo · <?= e(date('D, j M Y H:i')) ?></p></main></body></html>
