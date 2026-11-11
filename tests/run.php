<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

header('Pragma: no-cache');



use Divigamana\Support\Csrf;

use Divigamana\Support\Database;



function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

function n($value): string { global $databaseError; return !empty($databaseError)?'—':number_format((int)$value); }

function money($value): string { global $databaseError; return !empty($databaseError)?'—':'LKR ' . number_format((float)$value, 2); }

function percent($value,$total): string { global $databaseError; return !empty($databaseError)?'—':number_format($total?(float)$value*100/(float)$total:0,2).'%'; }

function carrierPlatform(string $mobile): string {
    if (preg_match('/^\+947[01]\d{7}$/', $mobile)) return 'mSpace';
    if (preg_match('/^\+947[245678]\d{7}$/', $mobile)) return 'IdeaMart';

    return 'Unknown';

}

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

        $returnParams=[];

        parse_str(ltrim((string)($_POST['return_to']??''),'?'),$returnParams);

        $returnDay=validDay(is_string($returnParams['day']??null)?$returnParams['day']:date('Y-m-d'));

        $returnQuery=mb_substr(trim(is_string($returnParams['q']??null)?$returnParams['q']:''),0,100);

        $returnStatus=is_string($returnParams['status']??null)?$returnParams['status']:'';

        if (!in_array($returnStatus,['','active','inactive','pending','blocked'],true)) $returnStatus='';

        $returnScope=($returnParams['scope']??'')==='all'?'all':'day';

        $returnPage=max(1,min(100000,(int)($returnParams['page']??1)));

        $safeReturn=qs(['day'=>$returnDay===date('Y-m-d')?'':$returnDay,'q'=>$returnQuery,'status'=>$returnStatus,'scope'=>$returnScope==='all'?'all':'','page'=>$returnPage>1?$returnPage:null]);

        try {

            $pdo = Database::pdo();

            $blocked = $action === 'block';

            $pdo->beginTransaction();

            $find=$pdo->prepare('SELECT role,account_status FROM users WHERE id=? FOR UPDATE');

            $find->execute([$id]);

            $target=$find->fetch();

            $newStatus=$blocked?'blocked':'active';

            if (!$target||$target['role']!=='user') {

                $pdo->rollBack();

                $_SESSION['admin_flash']='That customer account could not be found.';

            } elseif ($target['account_status']===$newStatus) {

                $pdo->rollBack();

                $_SESSION['admin_flash']='The account already has that status.';

            } else {

                $pdo->prepare('UPDATE users SET account_status=?,blocked_at=? WHERE id=?')->execute([$newStatus,$blocked?date('Y-m-d H:i:s'):null,$id]);

                $metadata=json_encode(['source'=>'admin_dashboard','admin_username'=>$adminUsername,'reason'=>mb_substr(trim((string)($_POST['reason']??'')),0,240)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

                $pdo->prepare('INSERT INTO audit_events (user_id,event_type,metadata_json) VALUES (?,?,?)')->execute([$id,$blocked?'admin_user_blocked':'admin_user_unblocked',$metadata]);

                $pdo->commit();

                $_SESSION['admin_flash']=$blocked?'Account blocked.':'Account unblocked.';

            }

        } catch (Throwable $exception) {

            if (isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack();

            error_log('Divigamana admin user action error: ' . $exception->getMessage());

            $_SESSION['admin_flash']='The account update could not be completed.';

        }

        header('Location: /admin' . $safeReturn);

        exit;

    }

}



$dayRaw=$_GET['day']??'';

$day = validDay(is_string($dayRaw)?$dayRaw:'');

$today = date('Y-m-d');

$prev = date('Y-m-d', strtotime($day . ' -1 day'));

$next = date('Y-m-d', strtotime($day . ' +1 day'));

$queryRaw=$_GET['q']??'';

$query = is_string($queryRaw)?trim($queryRaw):'';

$query=mb_substr($query,0,100);

$scope=($_GET['scope']??'')==='all'?'all':'day';

$page=max(1,min(100000,is_scalar($_GET['page']??null)?(int)$_GET['page']:1));

$pageSize=50;

$filter = is_string($_GET['status']??null)?$_GET['status']:'';

$filters = ['' => 'All users', 'active' => 'Active subscription', 'inactive' => 'Inactive / expired','pending'=>'Pending verification', 'blocked' => 'Blocked'];

if (!isset($filters[$filter])) $filter = '';

$returnTo = qs(['day' => $day === $today ? '' : $day, 'q' => $query, 'status' => $filter,'scope'=>$scope==='all'?'all':'','page'=>$page>1?$page:null]);



$providerExpr = "CASE WHEN u.mobile_e164 LIKE '+9470%' OR u.mobile_e164 LIKE '+9471%' THEN 'mSpace' WHEN u.mobile_e164 LIKE '+9472%' OR u.mobile_e164 LIKE '+9474%' OR u.mobile_e164 LIKE '+9475%' OR u.mobile_e164 LIKE '+9476%' OR u.mobile_e164 LIKE '+9477%' OR u.mobile_e164 LIKE '+9478%' THEN 'IdeaMart' ELSE 'Unknown' END";

$realUserExpr = "u.phone_verified_real=1";

// Carrier callbacks are authoritative. A registered IdeaMart subscriber remains active

// even when the locally estimated end date is stale; cancelled/pending callbacks do not.

$activeExpr = "u.account_status='active' AND COALESCE((SELECT CASE WHEN us.provider='ideamart' THEN us.provider_status='REGISTERED' ELSE us.status='active' AND us.ends_at>NOW() END FROM user_subscriptions us WHERE us.user_id=u.id ORDER BY us.id DESC LIMIT 1),0)";

$stats = ['all' => 0, 'active' => 0, 'inactive' => 0, 'blocked' => 0, 'new' => 0, 'logins' => 0];

$providers = [];

$providerBase = ['mSpace' => 0, 'IdeaMart' => 0, 'Unknown' => 0];

$platforms = ['IdeaMart' => ['new_users' => 0, 'active' => 0, 'inactive' => 0]];

$verification = ['total' => 0, 'verified' => 0, 'ideamart' => 0, 'pending' => 0];

$charges = ['day_count' => 0, 'day_success' => 0, 'day_failed' => 0, 'day_revenue' => 0, 'all_count' => 0, 'all_success' => 0, 'all_failed' => 0, 'all_revenue' => 0, 'average_success' => 0];

$dailyCharges = $subscriptions = $popular = $recent = $users = [];

$databaseError = false;

$totalUsers=0;$totalPages=1;$offset=0;$pageSize=50;



try {

    $pdo = Database::pdo();

    $sum = $pdo->query("SELECT COUNT(*) all_count, COALESCE(SUM($activeExpr),0) active_count, COALESCE(SUM(u.account_status='blocked'),0) blocked_count FROM users u WHERE u.role='user'")->fetch() ?: [];

    $stats['all'] = (int)($sum['all_count'] ?? 0);

    $stats['active'] = (int)($sum['active_count'] ?? 0);

    $stats['blocked'] = (int)($sum['blocked_count'] ?? 0);

    $stats['inactive'] = max(0, $stats['all'] - $stats['active'] - $stats['blocked']);

    $range = [$day . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($day . ' +1 day'))];

    $st = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE u.role='user' AND u.created_at>=? AND u.created_at<?");

    $st->execute($range);

    $stats['new'] = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='user' AND last_login_at>=? AND last_login_at<?");

    $st->execute($range);

    $stats['logins'] = (int)$st->fetchColumn();



    $providersByName=['mSpace'=>['provider'=>'mSpace','users'=>0,'active'=>0,'inactive'=>0,'real_verified'=>0,'charges'=>0,'successful'=>0,'failed'=>0,'revenue'=>0],'IdeaMart'=>['provider'=>'IdeaMart','users'=>0,'active'=>0,'inactive'=>0,'real_verified'=>0,'charges'=>0,'successful'=>0,'failed'=>0,'revenue'=>0],'Unknown'=>['provider'=>'Unknown','users'=>0,'active'=>0,'inactive'=>0,'real_verified'=>0,'charges'=>0,'successful'=>0,'failed'=>0,'revenue'=>0]];

    $st=$pdo->prepare("SELECT $providerExpr provider,COUNT(*) users,COALESCE(SUM($activeExpr),0) active,COALESCE(SUM(u.account_status='active' AND u.phone_verified_real=1 AND NOT ($activeExpr)),0) inactive,COALESCE(SUM($realUserExpr),0) real_verified FROM users u WHERE u.role='user' AND u.created_at>=? AND u.created_at<? GROUP BY provider");

    $st->execute($range);

    foreach($st->fetchAll() as $row) foreach(['users','active','inactive','real_verified'] as $metric) $providersByName[$row['provider']][$metric]=(int)$row[$metric];

    $dailyProviderSql="SELECT $providerExpr provider,COUNT(*) charges,COALESCE(SUM(p.status='paid'),0) successful,COALESCE(SUM(p.status='failed'),0) failed,COALESCE(SUM(CASE WHEN p.status='paid' THEN p.amount ELSE 0 END),0) revenue FROM payment_transactions p JOIN users u ON u.id=p.user_id AND u.role='user' WHERE p.provider IN ('ideamart','mspace') AND p.is_simulated=0 AND p.created_at>=? AND p.created_at<? GROUP BY $providerExpr";

    $st=$pdo->prepare($dailyProviderSql);$st->execute($range);

    foreach($st->fetchAll() as $row) foreach(['charges','successful','failed','revenue'] as $metric) $providersByName[$row['provider']][$metric]=$row[$metric];

    $providers=array_values($providersByName);

    $baseRows=$pdo->query("SELECT $providerExpr provider,COUNT(*) n FROM users u WHERE u.role='user' AND $activeExpr GROUP BY provider")->fetchAll();

    foreach($baseRows as $row) $providerBase[$row['provider']]=(int)$row['n'];

    $platformSql = "SELECT $providerExpr provider,COUNT(*) new_users,COALESCE(SUM($activeExpr),0) active,COALESCE(SUM(u.account_status='active' AND NOT ($activeExpr)),0) inactive FROM users u WHERE u.role='user' AND u.phone_verified_real=1 AND u.created_at>=? AND u.created_at<? AND $providerExpr='IdeaMart' GROUP BY $providerExpr";

    $st = $pdo->prepare($platformSql);

    $st->execute($range);

    foreach($st->fetchAll() as $row) $platforms[$row['provider']]=['new_users'=>(int)$row['new_users'],'active'=>(int)$row['active'],'inactive'=>(int)$row['inactive']];

    $st = $pdo->prepare("SELECT COUNT(*) total, COALESCE(SUM(u.phone_verified_real=1),0) verified, COALESCE(SUM(u.phone_verified_real=1 AND ($providerExpr)='IdeaMart'),0) ideamart, COALESCE(SUM(u.phone_verified_real=0),0) pending FROM users u WHERE u.role='user' AND u.created_at>=? AND u.created_at<?");

    $st->execute($range); $verification = array_map('intval', $st->fetch() ?: $verification);



    $st=$pdo->prepare("SELECT COUNT(*) day_count,COALESCE(SUM(status='paid'),0) day_success,COALESCE(SUM(status='failed'),0) day_failed,COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) day_revenue FROM payment_transactions WHERE provider IN ('ideamart','mspace') AND is_simulated=0 AND user_id IN (SELECT id FROM users WHERE role='user') AND created_at>=? AND created_at<?");

    $st->execute($range);$charges=array_merge($charges,$st->fetch()?:[]);

    $all=$pdo->query("SELECT COUNT(*) all_count,COALESCE(SUM(status='paid'),0) all_success,COALESCE(SUM(status='failed'),0) all_failed,COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) all_revenue,COALESCE(AVG(CASE WHEN status='paid' THEN amount END),0) average_success FROM payment_transactions WHERE provider IN ('ideamart','mspace') AND is_simulated=0 AND user_id IN (SELECT id FROM users WHERE role='user')")->fetch()?:[];

    $charges=array_merge($charges,$all);

    $dailyStart=date('Y-m-d 00:00:00',strtotime($day.' -13 days'));

    $st=$pdo->prepare("SELECT DATE(created_at) day,COUNT(*) attempts,COALESCE(SUM(status='paid'),0) successful,COALESCE(SUM(CASE WHEN status='paid' THEN amount ELSE 0 END),0) revenue FROM payment_transactions WHERE provider IN ('ideamart','mspace') AND is_simulated=0 AND user_id IN (SELECT id FROM users WHERE role='user') AND created_at>=? AND created_at<? GROUP BY DATE(created_at)");

    $st->execute([$dailyStart,$range[1]]);$dailyByDate=[];foreach($st->fetchAll() as $row)$dailyByDate[$row['day']]=$row;

    for($offset=13;$offset>=0;$offset--){$date=date('Y-m-d',strtotime($day.' -'.$offset.' days'));$dailyCharges[]=$dailyByDate[$date]??['day'=>$date,'attempts'=>0,'successful'=>0,'revenue'=>0];}

    $hourStart=new DateTimeImmutable(date('Y-m-d H:00:00'),new DateTimeZone('Asia/Colombo'));

    $hourRangeStart=$hourStart->modify('-23 hours');

    $st=$pdo->prepare("SELECT DATE_FORMAT(accepted_at,'%Y-%m-%d %H:00') hour_label,COUNT(*) n FROM user_subscriptions WHERE is_simulated=0 AND provider IN ('ideamart','mspace') AND user_id IN (SELECT id FROM users WHERE role='user') AND accepted_at>=? AND accepted_at<=NOW() GROUP BY hour_label");

    $st->execute([$hourRangeStart->format('Y-m-d H:i:s')]);$subscriptionHours=[];foreach($st->fetchAll() as $row)$subscriptionHours[$row['hour_label']]=(int)$row['n'];

    for($offset=0;$offset<24;$offset++){$hour=$hourStart->modify('-'.$offset.' hours')->format('Y-m-d H:00');$subscriptions[]=['hour_label'=>$hour,'n'=>$subscriptionHours[$hour]??0];}

    $popular = $pdo->query("SELECT s.slug, COUNT(q.id) n FROM service_catalog s LEFT JOIN horoscope_requests q ON q.service_id=s.id AND q.user_id IN (SELECT id FROM users WHERE role='user') GROUP BY s.id,s.slug ORDER BY n DESC,s.id ASC LIMIT 8")->fetchAll();



    $where=["u.role='user'"];$params=[];

    if($query!==''){$digits=preg_replace('/\D/','',$query);if($digits==='')$where[]='1=0';else{$where[]='(u.mobile_e164 LIKE ? OR u.id=?)';$params[]='%'.$digits.'%';$params[]=(int)$digits;}}

    elseif($scope!=='all'){$where[]='u.created_at>=? AND u.created_at<?';$params=$range;}

    if($filter==='active')$where[]=$activeExpr;

    if($filter==='inactive')$where[]="u.account_status='active' AND u.phone_verified_real=1 AND NOT ($activeExpr)";

    if($filter==='pending')$where[]="u.account_status='active' AND u.phone_verified_real=0";

    if($filter==='blocked')$where[]="u.account_status='blocked'";

    $whereSql=' FROM users u WHERE '.implode(' AND ',$where);

    $st=$pdo->prepare('SELECT COUNT(*)'.$whereSql);$st->execute($params);$totalUsers=(int)$st->fetchColumn();

    $totalPages=max(1,(int)ceil($totalUsers/$pageSize));$page=min($page,$totalPages);$offset=($page-1)*$pageSize;

    $sql="SELECT u.*,CASE WHEN u.account_status='blocked' THEN 'blocked' WHEN u.phone_verified_real=0 THEN 'pending' WHEN $activeExpr THEN 'active' ELSE 'inactive' END dashboard_status,(SELECT MAX(ends_at) FROM user_subscriptions ux WHERE ux.user_id=u.id) subscription_ends_at,(SELECT provider_status FROM user_subscriptions ux WHERE ux.user_id=u.id ORDER BY ux.id DESC LIMIT 1) provider_subscription_status,(SELECT frequency FROM user_subscriptions ux WHERE ux.user_id=u.id ORDER BY ux.id DESC LIMIT 1) subscription_frequency,(SELECT notified_at FROM user_subscriptions ux WHERE ux.user_id=u.id ORDER BY ux.id DESC LIMIT 1) subscription_notified_at FROM users u WHERE ".implode(' AND ',$where)." ORDER BY u.created_at DESC,u.id DESC LIMIT $pageSize OFFSET $offset";

    $st=$pdo->prepare($sql);$st->execute($params);$users=$st->fetchAll();

    $recent = $pdo->query("SELECT q.status,q.locale,q.created_at,s.slug FROM horoscope_requests q JOIN service_catalog s ON s.id=q.service_id JOIN users u ON u.id=q.user_id AND u.role='user' ORDER BY q.id DESC LIMIT 8")->fetchAll();

} catch (Throwable $exception) {

    error_log('Divigamana admin dashboard database error: ' . $exception->getMessage());

    $databaseError = true;

}

?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Admin dashboard | Divigamana</title><style>

:root{color-scheme:dark;--navy:#0b1026;--panel:#171333;--line:#ffffff1f;--gold:#d5ad63;--muted:#bdb5ca;--green:#71d0aa;--red:#fa98a8}*{box-sizing:border-box}body{margin:0;background:radial-gradient(circle at 8% 0,#292158 0,transparent 32%),var(--navy);color:#f9f5ed;font:14px Inter,system-ui,sans-serif}.shell{max-width:1400px;margin:auto;padding:28px 22px 55px}.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:30px}.brand{display:flex;align-items:center;gap:12px}.mark{display:grid;place-items:center;width:42px;height:42px;border-radius:12px;background:var(--gold);color:#171333;font-size:22px;font-weight:800}h1,h2,h3{margin:0}.muted,small{color:var(--muted)}button,.button{border:1px solid var(--line);border-radius:8px;padding:8px 11px;background:#ffffff0a;color:#f9f5ed;cursor:pointer;text-decoration:none;font:inherit}.danger{color:#ffbac4;border-color:#fa98a855}.notice{padding:13px 15px;background:#382a18;color:#ffdf98;border:1px solid #e5b75b55;border-radius:10px;margin-bottom:18px}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:22px 0}.card,.panel{background:var(--panel);border:1px solid var(--line);border-radius:12px}.card{padding:18px}.card b{display:block;color:var(--gold);font-size:1.65rem;margin-bottom:6px}.card span{display:block}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.panel{padding:18px;min-width:0}.panel h2{font-size:14px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);padding-bottom:12px;margin-bottom:3px;border-bottom:1px solid var(--line)}.metric{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px dashed var(--line)}.metric:last-child{border:0}.metric b{text-align:right}.section{margin-top:20px}.toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:9px;margin:18px 0}.toolbar form{display:flex;gap:8px}.toolbar input,.toolbar select{border:1px solid var(--line);border-radius:8px;background:#0e0b22;color:#fff;padding:8px;font:inherit}.tabs a{padding:8px 10px;border:1px solid var(--line);border-radius:20px;color:#f9f5ed;text-decoration:none}.tabs .on{background:var(--gold);color:#171333;border-color:var(--gold)}.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:720px}.table th{text-align:left;color:var(--muted);text-transform:uppercase;font-size:11px;letter-spacing:.04em}.table th,.table td{padding:11px 8px;border-bottom:1px solid var(--line)}.right{text-align:right}.status{font-weight:700;text-transform:capitalize}.active,.completed{color:var(--green)}.inactive,.processing{color:var(--gold)}.blocked,.failed{color:var(--red)}.two{display:grid;grid-template-columns:1fr 1.4fr;gap:16px}.footer{margin-top:22px;color:var(--muted);font-size:12px}@media(max-width:1050px){.grid{grid-template-columns:repeat(2,1fr)}.stats{grid-template-columns:repeat(2,1fr)}}@media(max-width:700px){.shell{padding:20px 14px}.grid,.two{grid-template-columns:1fr}.stats{grid-template-columns:1fr}.top{align-items:flex-start}.toolbar form{width:100%}.toolbar input{min-width:0;width:100%}}

:root{color-scheme:light;--navy:#f6f8fc;--panel:#fff;--line:#e2e8f0;--gold:#4338ca;--muted:#64748b;--green:#15803d;--red:#b91c1c}body{background:#f6f8fc;color:#172033}.card,.panel{background:#fff;border-color:#e2e8f0;box-shadow:0 4px 14px #1720330a}.card b{color:#4338ca}.metric{border-color:#edf0f5}.toolbar input,.toolbar select{background:#fff;color:#172033;border-color:#cbd5e1}.toolbar a,.button,button{background:#fff;color:#172033;border-color:#d8e0ea}.tabs .on{background:#eef2ff;color:#3730a3;border-color:#c7d2fe}.notice{background:#fff7ed;color:#9a3412;border-color:#fed7aa}.status.active,.completed{color:#15803d}.status.inactive,.status.pending,.processing{color:#a16207}.status.blocked,.failed{color:#b91c1c}

@media(max-width:700px){.table-wrap{overflow:visible}.table{min-width:0}.table thead{display:none}.table,.table tbody,.table tr,.table td{display:block;width:100%}.table tbody{display:grid;gap:10px}.table tr{display:block;margin:0 0 10px;padding:10px 12px;background:#fff;border:1px solid #e2e8f0;border-radius:10px}.table td{display:grid;grid-template-columns:minmax(100px,.75fr) minmax(0,1.25fr);gap:9px;padding:8px 4px;border-bottom:1px solid #edf0f5;text-align:right;overflow-wrap:anywhere}.table td:before{content:attr(data-label);color:#64748b;text-align:left;font-size:.78rem}.table td:last-child{border-bottom:0}.table td[colspan]{display:block}.table td[colspan]:before{content:none}}

</style></head><body><main class="shell">

<header class="top"><div class="brand"><div class="mark">දි</div><div><h1>Divigamana Admin</h1><small>User, subscription, payment and reading analytics</small></div></div><form method="post"><input type="hidden" name="csrf" value="<?= e(Csrf::token()) ?>"><button name="logout" value="1">Sign out</button></form></header>

<?php if ($databaseError): ?><div class="notice" role="alert"><strong>Analytics unavailable.</strong> The database could not be read. Confirm migrations and database access; counts are suppressed instead of shown as zero.</div><?php endif; ?><?php if (!empty($_SESSION['admin_flash'])): ?><div class="notice" role="status"><?=e($_SESSION['admin_flash'])?></div><?php unset($_SESSION['admin_flash']); endif; ?>

<section class="stats"><article class="card"><b><?= n($stats['all']) ?></b><span>Total users</span><small>All registered mobile numbers</small></article><article class="card"><b><?= n($stats['active']) ?></b><span>Active users</span><small>Enabled accounts with a current subscription</small></article><article class="card"><b><?= n($stats['inactive']) ?></b><span>Inactive / expired</span><small>Includes pending verification</small></article><article class="card"><b><?= n($stats['blocked']) ?></b><span>Blocked users</span><small>Restricted by an administrator</small></article></section>

<div class="grid"><section class="panel"><h2>Signup cohorts · <?= e($day) ?></h2><p class="muted">Unique users registered this day; charge metrics use transaction time on this date.</p><?php foreach ($providers as $row): ?><div class="metric"><span><b><?= e($row['provider']) ?></b><small><?= n($row['users']) ?> new · <?= n($row['real_verified']) ?> verified</small></span><b><?= n($row['active']) ?> active<br><small><?= n($row['inactive']) ?> inactive · <?= n($row['charges']) ?> charges</small></b></div><?php endforeach; ?><?php if (!$providers): ?><p class="muted">No new users for this day.</p><?php endif; ?><h2 style="margin-top:16px">Current provider base</h2><?php foreach ($providerBase as $name => $count): ?><div class="metric"><span><?= e($name) ?></span><b><?= n($count) ?></b></div><?php endforeach; ?></section>

<section class="panel"><h2>Verification · <?= e($day) ?></h2><div class="metric"><span>New users</span><b><?= n($verification['total']) ?></b></div><div class="metric"><span>IdeaMart verified</span><b><?= n($verification['ideamart']) ?></b></div><div class="metric"><span>Not verified</span><b><?= n($verification['pending']) ?></b></div><div class="metric"><span>Verification rate</span><b><?=percent($verification['verified'],$verification['total'])?></b></div><h2 style="margin-top:16px">Provider platform</h2><?php foreach (['IdeaMart'] as $name): $row = $platforms[$name]; ?><div class="metric"><span><?= e($name) ?><small><?= n($row['new_users']) ?> new users</small></span><b><?= n($row['active']) ?> active<br><small><?= n($row['inactive']) ?> inactive</small></b></div><?php endforeach; ?></section>

<section class="panel"><h2>Carrier charges · <?= e($day) ?></h2><p class="muted">Non-simulated transactions by event time; refunds excluded from collected revenue.</p><div class="metric"><span>Attempts</span><b><?= n($charges['day_count']) ?></b></div><div class="metric"><span>Successful / failed</span><b><?= n($charges['day_success']) ?> / <?= n($charges['day_failed']) ?></b></div><div class="metric"><span>Success rate</span><b><?=percent($charges['day_success'],$charges['day_count'])?></b></div><div class="metric"><span>Collected revenue</span><b><?= money($charges['day_revenue']) ?></b></div><h2 style="margin-top:16px">All-time</h2><div class="metric"><span>Attempts / success / failed</span><b><?= n($charges['all_count']) ?> / <?= n($charges['all_success']) ?> / <?= n($charges['all_failed']) ?></b></div><div class="metric"><span>Revenue / avg. success</span><b><?= money($charges['all_revenue']) ?><br><small><?= money($charges['average_success']) ?></small></b></div></section></div>

<div class="grid section"><section class="panel"><h2>Carrier charges · 14 days ending <?=e($day)?></h2><?php foreach ($dailyCharges as $row): ?><div class="metric"><span><?= e($row['day']) ?><small><?= n($row['attempts']) ?> attempts</small></span><b><?= n($row['successful']) ?> success<br><small><?= money($row['revenue']) ?></small></b></div><?php endforeach; ?><?php if ($databaseError): ?><p class="muted">Analytics unavailable.</p><?php elseif (!$dailyCharges): ?><p class="muted">No payments recorded.</p><?php endif; ?></section><section class="panel"><h2>Subscription records · rolling 24 hours</h2><?php foreach ($subscriptions as $row): ?><div class="metric"><span><?= e($row['hour_label']) ?></span><b><?= n($row['n']) ?></b></div><?php endforeach; ?><?php if ($databaseError): ?><p class="muted">Analytics unavailable.</p><?php elseif (!$subscriptions): ?><p class="muted">No subscription records in this window.</p><?php endif; ?></section><section class="panel"><h2>Popular services</h2><?php foreach ($popular as $row): ?><div class="metric"><span><?= e($row['slug']) ?></span><b><?= n($row['n']) ?></b></div><?php endforeach; ?><?php if($databaseError): ?><p class="muted">Analytics unavailable.</p><?php elseif(!$popular): ?><p class="muted">No service requests recorded.</p><?php endif; ?></section></div>

<section class="section"><h2>User management</h2><div class="toolbar"><a class="button" href="<?= e(qs(['day' => $prev, 'q' => $query, 'status' => $filter,'scope'=>$scope==='all'?'all':''])) ?>">‹ Prev</a><form method="get"><input type="date" name="day" value="<?= e($day) ?>" max="<?=e($today)?>" onchange="this.form.submit()"><input type="hidden" name="q" value="<?=e($query)?>"><input type="hidden" name="scope" value="<?=e($scope)?>"><?php if ($filter): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?></form><?php if ($day !== $today): ?><a class="button" href="<?= e(qs(['q' => $query, 'status' => $filter,'scope'=>$scope==='all'?'all':''])) ?>">Today</a><?php endif; ?><?php if ($day < $today): ?><a class="button" href="<?= e(qs(['day' => $next, 'q' => $query, 'status' => $filter,'scope'=>$scope==='all'?'all':''])) ?>">Next ›</a><?php endif; ?><a class="button <?= $scope==='day'?'on':'' ?>" href="<?=e(qs(['day'=>$day===$today?'':$day,'q'=>$query,'status'=>$filter]))?>">Users on selected day</a><a class="button <?= $scope==='all'?'on':'' ?>" href="<?=e(qs(['day'=>$day===$today?'':$day,'q'=>$query,'status'=>$filter,'scope'=>'all']))?>">All dates</a><a class="button" href="<?=e('/admin'.qs(['day'=>$day===$today?'':$day,'q'=>$query,'status'=>$filter,'scope'=>$scope==='all'?'all':'','page'=>$page>1?$page:null]))?>">Refresh</a><span class="muted"><?= n($stats['new']) ?> new · <?= n($stats['logins']) ?> users last logged in</span><form method="get"><input name="q" value="<?= e($query) ?>" maxlength="100" placeholder="Search mobile or user ID"><input type="hidden" name="day" value="<?=e($day===$today?'':$day)?>"><input type="hidden" name="scope" value="<?=e($scope)?>"><?php if ($filter): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?></form></div><div class="toolbar tabs"><?php foreach ($filters as $value => $label): ?><a class="<?= $filter === $value ? 'on' : '' ?>" href="<?= e(qs(['day' => $day === $today ? '' : $day, 'q' => $query, 'status' => $value,'scope'=>$scope==='all'?'all':''])) ?>"><?= e($label) ?></a><?php endforeach; ?></div><?php if ($query): ?><p class="muted">Search results for “<?= e($query) ?>” across all dates. <a href="<?=e('/admin'.qs(['day'=>$day===$today?'':$day,'status'=>$filter,'scope'=>$scope==='all'?'all':'']))?>">Clear</a></p><?php endif; ?><div class="panel table-wrap"><table class="table"><thead><tr><th>Mobile</th><th>Account state</th><th>Verification / platform</th><th>Joined</th><th>Last login</th><th>Carrier subscription</th><th>Subscription ends</th><th></th></tr></thead><tbody><?php foreach ($users as $user): ?><tr><td data-label="Mobile"><b><?= e($user['mobile_e164']) ?></b><small>ID <?=n($user['id'])?></small></td><td data-label="Account state"><span class="status <?= e($user['dashboard_status']) ?>"><?= e($user['dashboard_status']) ?></span></td><td data-label="Verification"><?= $user['phone_verified_real'] ? 'Verified: ' . e(carrierPlatform((string)$user['mobile_e164'])) : 'Pending' ?></td><td data-label="Registered"><?= e($user['created_at']) ?></td><td data-label="Last login"><?= e($user['last_login_at'] ?: '—') ?></td><td data-label="Carrier subscription"><?= e($user['provider_subscription_status'] ?: '—') ?><?php if ($user['subscription_frequency']): ?><small> · <?= e($user['subscription_frequency']) ?></small><?php endif; ?><?php if ($user['subscription_notified_at']): ?><small><br><?= e($user['subscription_notified_at']) ?></small><?php endif; ?></td><td data-label="Subscription ends"><?= e($user['subscription_ends_at'] ?: '—') ?></td><td data-label="Actions" class="right"><form method="post"><input type="hidden" name="csrf" value="<?= e(Csrf::token()) ?>"><input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>"><input type="hidden" name="return_to" value="<?= e($returnTo) ?>"><?php if ($user['dashboard_status'] === 'blocked'): ?><button name="action" value="unblock">Unblock</button><?php else: ?><button class="danger" name="action" value="block" onclick="return confirm('Block this number from the site?')">Block</button><?php endif; ?></form></td></tr><?php endforeach; ?><?php if (!$users): ?><tr><td colspan="8" class="muted"><?= $databaseError?'User list unavailable.':'No users for this view.' ?></td></tr><?php endif; ?></tbody></table></div><?php if(!$databaseError): ?><p class="muted">Showing <?=n($totalUsers?min($totalUsers,$offset+1):0)?>–<?=n(min($totalUsers,$offset+$pageSize))?> of <?=n($totalUsers)?> users · page <?=n($page)?> of <?=n($totalPages)?></p><div class="toolbar"><?php if($page>1): ?><a class="button" href="<?=e(qs(['day'=>$day===$today?'':$day,'q'=>$query,'status'=>$filter,'scope'=>$scope==='all'?'all':'','page'=>$page-1]))?>">‹ Previous page</a><?php endif; ?><?php if($page<$totalPages): ?><a class="button" href="<?=e(qs(['day'=>$day===$today?'':$day,'q'=>$query,'status'=>$filter,'scope'=>$scope==='all'?'all':'','page'=>$page+1]))?>">Next page ›</a><?php endif; ?></div><?php endif; ?></section><div class="two section"><section class="panel"><h2>Latest reading requests</h2><?php foreach ($recent as $row): ?><div class="metric"><span><?= e($row['slug']) ?><small><?= e($row['locale']) ?> · <?= e($row['created_at']) ?></small></span><b class="<?= e($row['status']) ?>"><?= e($row['status']) ?></b></div><?php endforeach; ?><?php if ($databaseError): ?><p class="muted">Analytics unavailable.</p><?php elseif (!$recent): ?><p class="muted">No reading requests yet.</p><?php endif; ?></section><section class="panel"><h2>Definitions</h2><p class="muted">Active means the account is not blocked and has a current active subscription. Carrier platform is inferred from the original mobile number prefix: Mobitel numbers use mSpace; Dialog, Hutch and Airtel ranges use IdeaMart.</p></section></div><p class="footer">Dashboard time zone: Asia/Colombo · <?= e(date('D, j M Y H:i')) ?></p></main></body></html>
