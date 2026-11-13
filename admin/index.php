<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

use Divigamana\Support\Csrf;
use Divigamana\Support\Database;

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function n($value): string { global $databaseError; return !empty($databaseError)?'—':number_format((int)$value); }
function validDay(string $value): string {
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Asia/Colombo'));
    $errors=DateTimeImmutable::getLastErrors();
    if (!$date||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d')!==$value||$value>date('Y-m-d')) return date('Y-m-d');
    return $value;
}
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

// Preserve only dashboard filters when redirecting after an account action.
$allowedProviders = ['' => 'All providers', 'dialog' => 'Dialog', 'mobitel' => 'Mobitel', 'airtel' => 'Airtel', 'hutch' => 'Hutch', 'unknown' => 'Other'];
$allowedVerification = ['' => 'All verification states', 'ideamart' => 'IdeaMart verified', 'mspace' => 'mSpace verified', 'unknown' => 'Verified · platform unknown', 'unverified' => 'Not verified'];
$allowedStates = ['' => 'All account states', 'active' => 'Active account', 'blocked' => 'Blocked account'];
function filterParams(array $input): array {
    global $allowedProviders, $allowedVerification, $allowedStates;
    $rawDay = is_string($input['day'] ?? null) ? $input['day'] : '';
    $date = $rawDay !== '' && validDay($rawDay) === $rawDay ? $rawDay : '';
    $q = is_string($input['q'] ?? null) ? substr(trim($input['q']), 0, 40) : '';
    $provider = is_string($input['provider'] ?? null) ? $input['provider'] : '';
    $verification = is_string($input['verification'] ?? null) ? $input['verification'] : '';
    $state = is_string($input['state'] ?? null) ? $input['state'] : '';
    return ['day' => $date, 'q' => $q, 'provider' => isset($allowedProviders[$provider]) ? $provider : '',
        'verification' => isset($allowedVerification[$verification]) ? $verification : '',
        'state' => isset($allowedStates[$state]) ? $state : '',
        'page' => max(1, min(100000, is_scalar($input['page'] ?? null) ? (int)$input['page'] : 1))];
}

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
        $returnParams = [];
        parse_str(ltrim((string)($_POST['return_to'] ?? ''), '?'), $returnParams);
        $returnParams = filterParams($returnParams);
        try {
            $pdo = Database::pdo();
            $pdo->beginTransaction();
            $find = $pdo->prepare('SELECT role, account_status FROM users WHERE id=? FOR UPDATE');
            $find->execute([$id]);
            $target = $find->fetch();
            $blocked = $action === 'block';
            $newStatus = $blocked ? 'blocked' : 'active';
            if (!$target || $target['role'] === 'admin') {
                $pdo->rollBack();
                $_SESSION['admin_flash'] = 'That customer account could not be found.';
            } elseif ($target['account_status'] === $newStatus) {
                $pdo->rollBack();
                $_SESSION['admin_flash'] = 'The account already has that status.';
            } else {
                $pdo->prepare('UPDATE users SET account_status=?, blocked_at=? WHERE id=?')->execute([$newStatus, $blocked ? date('Y-m-d H:i:s') : null, $id]);
                $metadata = json_encode(['source' => 'admin_dashboard', 'admin_username' => $adminUsername], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $pdo->prepare('INSERT INTO audit_events (user_id,event_type,metadata_json) VALUES (?,?,?)')->execute([$id, $blocked ? 'admin_user_blocked' : 'admin_user_unblocked', $metadata]);
                $pdo->commit();
                $_SESSION['admin_flash'] = $blocked ? 'Account blocked.' : 'Account unblocked.';
            }
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            error_log('Divigamana admin user action error: ' . $exception->getMessage());
            $_SESSION['admin_flash'] = 'The account update could not be completed.';
        }
        header('Location: /admin' . qs($returnParams));
        exit;
    }
}

$filters = filterParams($_GET);
$reportDay = $filters['day'] ?: date('Y-m-d');
$range = [$reportDay . ' 00:00:00', (new DateTimeImmutable($reportDay))->modify('+1 day')->format('Y-m-d 00:00:00')];
$pageSize = 50;
$page = $filters['page'];
$offset = 0;
$totalUsers = 0;
$totalPages = 1;
$stats = ['total' => 0, 'active' => 0, 'inactive' => 0, 'blocked' => 0];
$funnel = [];
foreach (['dialog', 'mobitel', 'airtel', 'hutch', 'unknown'] as $provider) $funnel[$provider] = ['users' => 0, 'verified' => 0, 'active' => 0];
$verification = ['new_users' => 0, 'ideamart' => 0, 'mspace' => 0];
$subscriptionHours = [];
$subscriptionTotal = 0;
$users = [];
$databaseError = false;

// Numbers are normally stored as +94, but older local-format records remain visible.
$digits = "REPLACE(u.mobile_e164,'+','')";
$prefix = "CASE WHEN $digits LIKE '94%' THEN SUBSTRING($digits,3,2) WHEN $digits LIKE '0%' THEN SUBSTRING($digits,2,2) ELSE LEFT($digits,2) END";
$providerExpr = "CASE WHEN ($prefix) IN ('74','76','77') THEN 'dialog' WHEN ($prefix) IN ('70','71') THEN 'mobitel' WHEN ($prefix)='75' THEN 'airtel' WHEN ($prefix) IN ('72','78') THEN 'hutch' ELSE 'unknown' END";
// Legacy rows can lack subscriber IDs. For those (and old rows with both IDs), infer
// the platform from the number. Do not report demo verification as real verification.
$verificationExpr = "CASE WHEN COALESCE(u.phone_verified_real,0)<>1 THEN 'unverified' WHEN u.ideamart_subscriber_id IS NOT NULL AND u.mspace_subscriber_id IS NULL THEN 'ideamart' WHEN u.mspace_subscriber_id IS NOT NULL AND u.ideamart_subscriber_id IS NULL THEN 'mspace' WHEN ($providerExpr)='mobitel' THEN 'mspace' WHEN ($providerExpr) IN ('dialog','airtel','hutch') THEN 'ideamart' ELSE 'unknown' END";
$customerExpr = "COALESCE(u.role,'user')<>'admin'";
// Use the latest subscription record. IdeaMart's REGISTERED status is authoritative;
// other subscriptions also require an unexpired end time.
$activeExpr = "COALESCE((SELECT CASE WHEN us.provider='ideamart' THEN us.provider_status='REGISTERED' ELSE us.status='active' AND us.ends_at>NOW() END FROM user_subscriptions us WHERE us.user_id=u.id ORDER BY us.id DESC LIMIT 1),0)";
try {
    $pdo = Database::pdo();
    $row = $pdo->query("SELECT COUNT(*) total, COALESCE(SUM(u.account_status<>'blocked' AND ($activeExpr)),0) active, COALESCE(SUM(u.account_status<>'blocked' AND NOT ($activeExpr)),0) inactive, COALESCE(SUM(u.account_status='blocked'),0) blocked FROM users u WHERE $customerExpr")->fetch();
    $stats = array_map('intval', $row);
    $rows = $pdo->query("SELECT $providerExpr provider, COUNT(*) users, COALESCE(SUM(u.phone_verified_real=1),0) verified, COALESCE(SUM(u.account_status<>'blocked' AND ($activeExpr)),0) active FROM users u WHERE $customerExpr GROUP BY provider")->fetchAll();
    foreach ($rows as $row) $funnel[$row['provider']] = array_map('intval', array_intersect_key($row, $funnel[$row['provider']]));
    $st = $pdo->prepare("SELECT COUNT(*) new_users, COALESCE(SUM(($verificationExpr)='ideamart'),0) ideamart, COALESCE(SUM(($verificationExpr)='mspace'),0) mspace FROM users u WHERE $customerExpr AND u.created_at>=? AND u.created_at<?");
    $st->execute($range);
    $verification = array_map('intval', $st->fetch());
    $st = $pdo->query("SELECT DATE_FORMAT(s.accepted_at,'%Y-%m-%d %H:00') hour_label, COUNT(*) n FROM user_subscriptions s JOIN users u ON u.id=s.user_id WHERE $customerExpr AND s.is_simulated=0 AND s.provider IN ('ideamart','mspace') AND s.status='active' AND s.accepted_at >= NOW() - INTERVAL 24 HOUR AND s.accepted_at <= NOW() GROUP BY hour_label ORDER BY hour_label DESC");
    $subscriptionHours = $st->fetchAll();
    foreach ($subscriptionHours as $row) $subscriptionTotal += (int)$row['n'];

    $where = [$customerExpr];
    $params = [];
    if ($filters['day'] !== '') { $where[] = 'u.created_at>=? AND u.created_at<?'; array_push($params, ...$range); }
    if ($filters['provider'] !== '') { $where[] = "($providerExpr)=?"; $params[] = $filters['provider']; }
    if ($filters['verification'] !== '') { $where[] = "($verificationExpr)=?"; $params[] = $filters['verification']; }
    if ($filters['state'] !== '') { $where[] = 'u.account_status=?'; $params[] = $filters['state']; }
    if ($filters['q'] !== '') {
        $searchDigits = preg_replace('/\D/', '', $filters['q']);
        if ($searchDigits === '') $where[] = '1=0';
        else {
            $where[] = "($digits LIKE ? OR $digits LIKE ?)";
            $params[] = '%' . $searchDigits . '%';
            $params[] = '%' . (str_starts_with($searchDigits, '0') ? '94' . substr($searchDigits, 1) : $searchDigits) . '%';
        }
    }
    $whereSql = ' FROM users u WHERE ' . implode(' AND ', $where);
    $st = $pdo->prepare('SELECT COUNT(*)' . $whereSql); $st->execute($params); $totalUsers = (int)$st->fetchColumn();
    $totalPages = max(1, (int)ceil($totalUsers / $pageSize));
    $page = min($page, $totalPages); $filters['page'] = $page; $offset = ($page - 1) * $pageSize;
    $st = $pdo->prepare("SELECT u.id,u.mobile_e164,u.account_status,u.created_at,u.last_login_at, $verificationExpr verification, $providerExpr provider" . $whereSql . " ORDER BY u.created_at DESC,u.id DESC LIMIT $pageSize OFFSET $offset");
    $st->execute($params); $users = $st->fetchAll();
} catch (Throwable $exception) {
    error_log('Divigamana admin dashboard database error: ' . $exception->getMessage());
    $databaseError = true;
    $users = [];
    $subscriptionHours = [];
}
$returnTo = qs($filters);
$providerNames = ['dialog' => 'Dialog', 'mobitel' => 'Mobitel', 'airtel' => 'Airtel', 'hutch' => 'Hutch', 'unknown' => 'Other'];
$prefixLabels = ['dialog' => '074 / 077 / 076', 'mobitel' => '070 / 071', 'airtel' => '075', 'hutch' => '072 / 078', 'unknown' => 'Other prefixes'];
$verificationNames = ['ideamart' => 'IdeaMart verified', 'mspace' => 'mSpace verified', 'unverified' => 'Not verified', 'unknown' => 'Verified · platform unknown'];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin dashboard | Divigamana</title>
<style>
:root{color-scheme:light;--ink:#172033;--muted:#65718a;--line:#e5eaf2;--accent:#5046c6;--bg:#f5f7fb}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.5 system-ui,-apple-system,sans-serif}.shell{max-width:1440px;margin:auto;padding:32px 28px 60px}.top,.heading,.panel-head,.metric,.pagination{display:flex;align-items:center;justify-content:space-between;gap:16px}.brand{display:flex;align-items:center;gap:12px}.mark{display:grid;place-items:center;width:44px;height:44px;border-radius:13px;background:#5148c7;color:white;font-size:23px;font-weight:800}h1,h2,h3,p{margin:0}h1{font-size:20px}h2{font-size:18px}h3{font-size:14px}.muted,small{color:var(--muted)}small{display:block}.top{margin-bottom:38px}.heading{margin-bottom:20px}.eyebrow{text-transform:uppercase;letter-spacing:.1em;font-size:11px;font-weight:700;color:var(--accent);margin-bottom:5px}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px}.card,.panel{background:white;border:1px solid var(--line);border-radius:14px;box-shadow:0 4px 18px #23345b08}.card{padding:20px}.card .value{display:block;font-size:30px;font-weight:750;line-height:1.2;margin:12px 0 7px}.card .label{font-weight:650}.section{margin-top:28px}.grid{display:grid;grid-template-columns:1.5fr 1fr;gap:16px}.panel{padding:22px;min-width:0}.panel-head{align-items:flex-start;margin-bottom:18px}.panel-head p{margin-top:4px}.base{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.base-item{background:#f7f8fd;border-radius:10px;padding:13px}.base-item b{display:block;font-size:22px;margin-top:10px}.base-item small{font-size:11px}.table-wrap{overflow-x:auto}.table{border-collapse:collapse;width:100%;min-width:650px}.table th,.table td{text-align:left;padding:12px 10px;border-bottom:1px solid var(--line)}.table th{font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--muted)}.table tr:last-child td{border-bottom:0}.metric{padding:12px 0;border-bottom:1px solid var(--line)}.metric:last-child{border:0}.metric b{font-size:17px}.toolbar{display:flex;align-items:end;flex-wrap:wrap;gap:12px;margin:18px 0}.toolbar label{display:grid;gap:5px;color:var(--muted);font-size:12px;font-weight:650}.toolbar input,.toolbar select{min-height:40px;min-width:145px;padding:8px 10px;border:1px solid #d6deeb;border-radius:8px;background:white;color:var(--ink);font:inherit}.toolbar input[type=search]{min-width:190px}button,.button{display:inline-block;min-height:40px;border:1px solid #d6deeb;border-radius:8px;background:white;color:var(--ink);padding:9px 13px;cursor:pointer;text-decoration:none;font:600 13px system-ui}.primary{background:var(--accent);color:white;border-color:var(--accent)}.danger{color:#b4233c}.pill{display:inline-block;padding:4px 8px;border-radius:20px;font-size:12px;font-weight:650;background:#f1f4f8}.pill.active{background:#e9f8ef;color:#167044}.pill.blocked{background:#fff0f1;color:#b4233c}.notice{padding:14px 16px;border-radius:10px;background:#fff5e9;color:#905300;margin-bottom:20px}.pagination{margin-top:16px;flex-wrap:wrap}.pagination div{display:flex;gap:8px}.hours{max-height:260px;overflow:auto}.footer{margin-top:25px;color:var(--muted);font-size:12px}.empty{text-align:center;color:var(--muted);padding:20px!important}@media(max-width:1000px){.stats,.base{grid-template-columns:repeat(2,1fr)}.grid{grid-template-columns:1fr}}@media(max-width:600px){.shell{padding:22px 14px 40px}.top{margin-bottom:28px}.heading{align-items:flex-start}.stats{gap:10px}.card,.panel{padding:16px}.card .value{font-size:25px}.toolbar label{flex:1 1 140px}.toolbar input,.toolbar select{width:100%;min-width:0}.base{gap:8px}}
</style></head><body><main class="shell">
<header class="top"><div class="brand"><div class="mark">දි</div><div><h1>Divigamana Admin</h1><small>Subscriber overview</small></div></div><form method="post"><input type="hidden" name="csrf" value="<?=e(Csrf::token())?>"><button name="logout" value="1">Sign out</button></form></header>
<?php if ($databaseError): ?><div class="notice" role="alert"><strong>Dashboard unavailable.</strong> The database could not be read. Check the database connection and migrations.</div><?php endif; ?>
<?php if (!empty($_SESSION['admin_flash'])): ?><div class="notice" role="status"><?=e($_SESSION['admin_flash'])?></div><?php unset($_SESSION['admin_flash']); endif; ?>
<div class="heading"><div><div class="eyebrow">Overview</div><h2>Subscriber dashboard</h2><p class="muted">Current totals across all registered mobile numbers.</p></div><a class="button" href="<?=e('/admin'.qs($filters))?>">Refresh</a></div>
<section class="stats" aria-label="Subscriber totals"><article class="card"><span class="label">Total users</span><span class="value"><?=n($stats['total'])?></span><small>All registered mobile numbers</small></article><article class="card"><span class="label">Active subscriptions</span><span class="value"><?=n($stats['active'])?></span><small>Enabled accounts with an active subscription</small></article><article class="card"><span class="label">Inactive</span><span class="value"><?=n($stats['inactive'])?></span><small>Enabled accounts without an active subscription</small></article><article class="card"><span class="label">Blocked users</span><span class="value"><?=n($stats['blocked'])?></span><small>Accounts restricted by an administrator</small></article></section>
<div class="grid section"><section class="panel"><div class="panel-head"><div><h2>Provider funnel</h2><p class="muted">Registered users, verified users and active subscriptions by mobile prefix.</p></div></div><div class="table-wrap"><table class="table"><thead><tr><th>Provider</th><th>Registered</th><th>Verified</th><th>Active subscriptions</th></tr></thead><tbody><?php foreach ($funnel as $key=>$row): ?><tr><td><b><?=e($providerNames[$key])?></b><small><?=e($prefixLabels[$key])?></small></td><td><?=n($row['users'])?></td><td><?=n($row['verified'])?></td><td><?=n($row['active'])?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="panel"><div class="panel-head"><div><h2>Phone verification · by date</h2><p class="muted">Current verification of users who joined on the selected date.</p></div></div><form method="get" class="toolbar"><label>Joined date<input type="date" name="day" value="<?=e($reportDay)?>" max="<?=e(date('Y-m-d'))?>"></label><?php foreach (['q','provider','verification','state'] as $key): ?><input type="hidden" name="<?=e($key)?>" value="<?=e($filters[$key])?>"><?php endforeach; ?><button class="primary">Apply date</button></form><div class="metric"><span>New users</span><b><?=n($verification['new_users'])?></b></div><div class="metric"><span>IdeaMart verified</span><b><?=n($verification['ideamart'])?></b></div><div class="metric"><span>mSpace verified</span><b><?=n($verification['mspace'])?></b></div></section></div>
<section class="panel section"><div class="panel-head"><div><h2>Provider base count</h2><p class="muted">All registered users, including inactive and blocked accounts. Provider is inferred from the number prefix.</p></div></div><div class="base"><?php foreach (['dialog','mobitel','airtel','hutch'] as $key): ?><div class="base-item"><strong><?=e($providerNames[$key])?></strong><small><?=e($prefixLabels[$key])?></small><b><?=n($funnel[$key]['users'])?></b></div><?php endforeach; ?></div><small style="margin-top:12px">Other prefixes: <?=n($funnel['unknown']['users'])?></small></section>
<section class="panel section"><div class="panel-head"><div><h2>Subscriptions · last 24 hours</h2><p class="muted">New real active subscription records, grouped by acceptance hour. Repeated callbacks for the same state do not create a new record.</p></div><strong><?=n($subscriptionTotal)?> total</strong></div><div class="hours"><?php foreach ($subscriptionHours as $row): ?><div class="metric"><span><?=e($row['hour_label'])?></span><b><?=n($row['n'])?></b></div><?php endforeach; ?><?php if (!$subscriptionHours): ?><p class="muted"><?=$databaseError?'Subscription data unavailable.':'No new subscription records in the last 24 hours.'?></p><?php endif; ?></div></section>
<section class="section"><div class="heading"><div><h2>User management</h2><p class="muted">Search and filter registered accounts. Date filters use the joined date.</p></div></div><div class="panel"><form method="get" class="toolbar"><label>Mobile number<input type="search" name="q" value="<?=e($filters['q'])?>" placeholder="e.g. 0771234567"></label><label>Joined date<input type="date" name="day" value="<?=e($filters['day'])?>" max="<?=e(date('Y-m-d'))?>"></label><label>Provider<select name="provider"><?php foreach ($allowedProviders as $key=>$label): ?><option value="<?=e($key)?>" <?=$filters['provider']===$key?'selected':''?>><?=e($label)?></option><?php endforeach; ?></select></label><label>Verification<select name="verification"><?php foreach ($allowedVerification as $key=>$label): ?><option value="<?=e($key)?>" <?=$filters['verification']===$key?'selected':''?>><?=e($label)?></option><?php endforeach; ?></select></label><label>Account state<select name="state"><?php foreach ($allowedStates as $key=>$label): ?><option value="<?=e($key)?>" <?=$filters['state']===$key?'selected':''?>><?=e($label)?></option><?php endforeach; ?></select></label><button class="primary">Apply filters</button><a class="button" href="/admin">Clear</a></form>
<div class="table-wrap"><table class="table"><thead><tr><th>Mobile</th><th>Account state</th><th>Verification</th><th>Joined</th><th>Last login</th><th>Action</th></tr></thead><tbody><?php foreach ($users as $user): ?><tr><td><b><?=e($user['mobile_e164'])?></b><small><?=e($providerNames[$user['provider']])?></small></td><td><span class="pill <?=e($user['account_status'])?>"><?=e(ucfirst($user['account_status']))?></span></td><td><?=e($verificationNames[$user['verification']])?></td><td><?=e($user['created_at'])?></td><td><?=e($user['last_login_at'] ?: '—')?></td><td><form method="post"><input type="hidden" name="csrf" value="<?=e(Csrf::token())?>"><input type="hidden" name="user_id" value="<?=(int)$user['id']?>"><input type="hidden" name="return_to" value="<?=e($returnTo)?>"><?php if ($user['account_status']==='blocked'): ?><button name="action" value="unblock">Unblock</button><?php else: ?><button class="danger" name="action" value="block" onclick="return confirm('Block this number from the site?')">Block</button><?php endif; ?></form></td></tr><?php endforeach; ?><?php if (!$users): ?><tr><td class="empty" colspan="6"><?=$databaseError?'User list unavailable.':'No users match these filters.'?></td></tr><?php endif; ?></tbody></table></div><?php if (!$databaseError): ?><div class="pagination"><span class="muted">Showing <?=n($totalUsers ? $offset+1 : 0)?>–<?=n(min($totalUsers,$offset+$pageSize))?> of <?=n($totalUsers)?> users</span><div><?php if ($page>1): ?><a class="button" href="<?=e(qs(array_merge($filters,['page'=>$page-1])))?>">Previous</a><?php endif; ?><?php if ($page<$totalPages): ?><a class="button" href="<?=e(qs(array_merge($filters,['page'=>$page+1])))?>">Next</a><?php endif; ?></div></div><?php endif; ?></div></section>
<p class="footer">All times are Asia/Colombo · <?=e(date('D, j M Y H:i'))?>. Verification platform is inferred from the prefix for older records without a clear subscriber ID.</p></main></body></html>
