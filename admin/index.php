<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use Divigamana\Services\AdminDashboard;
use Divigamana\Services\DashboardMetrics;
use Divigamana\Support\Csrf;
use Divigamana\Support\Database;

header('Cache-Control: no-store, private');
function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function number($value): string { return number_format((int)$value); }
function dateValue($value): string { return $value ? date('d M Y, H:i', strtotime((string)$value)) : '—'; }
function validDate(string $date): bool { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date); return $d!==false && $d->format('Y-m-d')===$date; }
// Shared per-address throttling survives new browser sessions.
function loginAttempt(bool $success): bool {
    $key=hash('sha256',ROOT_PATH."\0".(string)($_SERVER['REMOTE_ADDR']??'unknown'));
    $file=@fopen(sys_get_temp_dir().'/divigamana-admin-'.$key.'.rate','c+');
    if (!$file || !flock($file,LOCK_EX)) { if ($file) fclose($file); return false; }
    $attempts=json_decode(stream_get_contents($file),true);
    $attempts=is_array($attempts)?array_values(array_filter($attempts,static fn($time)=>is_int($time) && $time>time()-900)):[];
    $allowed=count($attempts)<5;
    if ($success && $allowed) $attempts=[];
    elseif (!$success && $allowed) $attempts[]=time();
    rewind($file); ftruncate($file,0); fwrite($file,json_encode($attempts)); fflush($file); flock($file,LOCK_UN); fclose($file);
    return $allowed;
}
$config=APP_CONFIG['admin']??[];
$username=(string)(getenv('ADMIN_USERNAME') ?: ($config['username']??''));
$password=(string)(getenv('ADMIN_PASSWORD') ?: ($config['password']??''));
$fingerprint=hash('sha256',$username."\0".$password);
$authenticated=$username!=='' && $password!=='' && isset($_SESSION['admin_fingerprint']) && hash_equals($fingerprint,(string)$_SESSION['admin_fingerprint']);
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!Csrf::verify($_POST['csrf']??null)) { http_response_code(419); $error='Your session expired. Refresh the page and try again.'; }
    elseif (($_POST['action']??'')==='logout' && $authenticated) {
        unset($_SESSION['admin_fingerprint']); session_regenerate_id(true); header('Location: ./',true,303); exit;
    } elseif (in_array(($_POST['action']??''),['block','unblock'],true) && $authenticated) {
        $id=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($id===false) { http_response_code(400); $error='Invalid user.'; }
        else {
            try {
                $blocked=$_POST['action']==='block';
                $stmt=Database::pdo()->prepare('UPDATE users SET account_status=?, blocked_at='.($blocked?'NOW()':'NULL').' WHERE id=?');
                $stmt->execute([$blocked?'blocked':'active',$id]);
                header('Location: ?'.http_build_query($_GET).'#users',true,303); exit;
            } catch (Throwable $e) { error_log('Admin account update: '.$e->getMessage()); http_response_code(503); $error='The account could not be updated.'; }
        }
    } elseif (($_POST['action']??'')==='login') {
        $valid=$username!=='' && $password!=='' && hash_equals($username,(string)($_POST['username']??'')) && hash_equals($password,(string)($_POST['password']??''));
        if (!loginAttempt($valid)) { http_response_code(429); $error='Sign-in is temporarily unavailable or too many attempts were made. Please try again in 15 minutes.'; }
        elseif ($valid) {
            session_regenerate_id(true); $_SESSION['admin_fingerprint']=$fingerprint; header('Location: ./',true,303); exit;
        } else { $error='Invalid username or password.'; }
    }
}
$day=(string)($_GET['day']??date('Y-m-d')); if (!validDate($day)) $day=date('Y-m-d');
$filters=['from'=>(string)($_GET['from']??''),'to'=>(string)($_GET['to']??''),'provider'=>(string)($_GET['provider']??''),'verification'=>(string)($_GET['verification']??''),'search'=>(string)($_GET['search']??''),'status'=>(string)($_GET['status']??''),'day'=>$day];
$verificationDates=['from'=>(string)($_GET['verification_from']??''),'to'=>(string)($_GET['verification_to']??'')];
foreach (['from','to'] as $key) if ($verificationDates[$key]!=='' && !validDate($verificationDates[$key])) $verificationDates[$key]='';
if ($verificationDates['from']!=='' && $verificationDates['to']!=='' && $verificationDates['from']>$verificationDates['to']) { $error='The verification report start date must be before the end date.'; $verificationDates=['from'=>'','to'=>'']; }
foreach (['from','to'] as $key) if ($filters[$key]!=='' && !validDate($filters[$key])) $filters[$key]='';
if ($filters['from']!=='' && $filters['to']!=='' && $filters['from']>$filters['to']) { $error='The start date must be before the end date.'; $filters['from']=''; $filters['to']=''; }
if (!in_array($filters['provider'],['','dialog','mobitel','airtel','hutch','other'],true)) $filters['provider']='';
if (!in_array($filters['verification'],['','ideamart','mspace','unverified'],true)) $filters['verification']='';
if (!in_array($filters['status'],['','active','inactive','number_only','blocked'],true)) $filters['status']='';
$search=preg_replace('/[^0-9+]/','',$filters['search']);
if (substr($search,0,1)==='0') $search='94'.substr($search,1);
$filters['search']=substr($search,0,16);
// A number search spans all dates; otherwise show the selected join date.
if ($filters['search']==='') { $filters['from']=$day; $filters['to']=$day; } else { $filters['from']=''; $filters['to']=''; }
$data=null;
if ($authenticated) {
    try { $data=(new AdminDashboard(Database::pdo()))->report($filters,max(1,(int)($_GET['page']??1)),$verificationDates,max(1,(int)($_GET['subscription_page']??1))); $overview=(new DashboardMetrics(Database::pdo()))->report(); }
    catch (Throwable $e) { error_log('Admin dashboard: '.$e->getMessage()); http_response_code(503); $error='The dashboard could not load the database. Check the connection and apply the required migrations.'; }
}
$providers=['dialog'=>['Dialog','074 / 077 / 076'],'mobitel'=>['Mobitel','070 / 071'],'airtel'=>['Airtel','075'],'hutch'=>['Hutch','072 / 078'],'other'=>['Other','Other prefixes']];
function selected(string $a,string $b): string { return $a===$b?' selected':''; }
function pageLink(array $params,string $anchor): string { return '?'.http_build_query($params).'#'.$anchor; }
function hiddenFields(array $params): void { foreach ($params as $name=>$value) echo '<input type="hidden" name="'.h($name).'" value="'.h($value).'">'; }
$query=array_merge($filters,['day'=>$day,'verification_from'=>$verificationDates['from'],'verification_to'=>$verificationDates['to'],'page'=>$data['page']??1,'subscription_page'=>$data['subscriptionPage']??1]);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Divigamana · Admin</title><link rel="stylesheet" href="../public/assets/css/admin.css"><link rel="stylesheet" href="../public/assets/css/dashboard-metrics.css"></head><body>
<?php if (!$authenticated): ?>
<main class="login-wrap"><div class="login-card"><div class="brand-mark">D</div><p class="eyebrow">DIVIGAMANA</p><h1>Welcome back</h1><p class="muted">Sign in to view your subscriber dashboard.</p><?php if ($error): ?><p class="error" role="alert"><?=h($error)?></p><?php endif ?><form method="post"><input type="hidden" name="csrf" value="<?=h(Csrf::token())?>"><input type="hidden" name="action" value="login"><label>Username<input name="username" autocomplete="username" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button type="submit">Sign in</button></form></div></main>
<?php else: ?>
<header class="topbar"><a class="brand" href="./"><span class="brand-mark small">D</span><span>Divigamana <small>ADMIN</small></span></a><form method="post"><input type="hidden" name="csrf" value="<?=h(Csrf::token())?>"><input type="hidden" name="action" value="logout"><button class="button-secondary" type="submit">Sign out</button></form></header>
<main class="container"><div class="page-heading"><div><p class="eyebrow">OVERVIEW</p><h1>Subscriber dashboard</h1><p class="muted">A live view of your registered users and subscriptions.</p></div><div class="live"><span></span> Live database · Colombo time</div></div>
<?php if ($error): ?><p class="error" role="alert"><?=h($error)?></p><?php endif ?>
<?php if ($data && isset($overview)): ?>
<div data-dashboard-metrics><?php require __DIR__.'/dashboard_metrics.php'; ?></div>
<section class="panel" id="users"><div class="section-heading"><div><p class="eyebrow">DIRECTORY</p><h2>User management</h2><p class="muted">Current subscription and account state. Phone search covers all dates.</p></div></div>
<form class="user-toolbar" method="get" action="#users">
<a class="user-pill" href="<?=h(pageLink(array_merge($query,['day'=>(new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d'),'search'=>'','page'=>1]),'users'))?>">‹ Prev</a>
<label><span class="sr-only">Join date</span><input type="date" name="day" value="<?=h($day)?>"></label>
<?php if ($day<date('Y-m-d')): ?><a class="user-pill" href="<?=h(pageLink(array_merge($query,['day'=>(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d'),'search'=>'','page'=>1]),'users'))?>">Next ›</a><?php endif ?>
<label><span class="sr-only">Search phone</span><input name="search" inputmode="tel" placeholder="Search phone (all dates)" value="<?=h($filters['search'])?>"></label>
<input type="hidden" name="status" value="<?=h($filters['status'])?>"><button>Search</button>
</form>
<nav class="user-status-filters" aria-label="Account state"><?php foreach([''=>'All','active'=>'Active','inactive'=>'Inactive (subscription not active)','number_only'=>'Inactive (PIN not entered)','blocked'=>'Blocked'] as $status=>$label): ?><a class="user-pill<?=$filters['status']===$status?' is-selected':''?>" href="<?=h(pageLink(array_merge($query,['status'=>$status,'page'=>1]),'users'))?>"><?=h($label)?></a><?php endforeach ?></nav>
<div data-user-results><p class="muted user-count"><?=h($day===date('Y-m-d')?'Today':$day)?> <?=number($data['dailyNew'])?> new · <?=$data['dailyLogins']===null?'Login count unavailable':number($data['dailyLogins']).' recorded logins'?> · <?=number($data['count'])?> matching users <small>(login tracking started with this update)</small></p><div class="table-wrap"><table><thead><tr><th>Phone</th><th>Status</th><th>First visit</th><th>First login</th><th>Last login</th><th><span class="sr-only">Action</span></th></tr></thead><tbody><?php foreach($data['users'] as $user): $state=$user['display_status']; $stateLabel=['active'=>'Active','inactive'=>'Inactive (subscription not active)','number_only'=>'Inactive (PIN not entered)','blocked'=>'Blocked'][$state]; ?><tr><td><b><?=h($user['mobile_e164'])?></b></td><td><span class="badge <?=h($state)?>"><?=h($stateLabel)?></span></td><td><?=h(dateValue($user['created_at']))?></td><td><?=h(dateValue($user['first_login_at']))?></td><td><?=h(dateValue($user['last_login_at']))?></td><td><form method="post" action="<?=h('?'.http_build_query($_GET).'#users')?>"><input type="hidden" name="csrf" value="<?=h(Csrf::token())?>"><input type="hidden" name="user_id" value="<?=h($user['id'])?>"><input type="hidden" name="action" value="<?=$state==='blocked'?'unblock':'block'?>"><button class="user-action" type="submit"><?=$state==='blocked'?'Unblock':'Block'?></button></form></td></tr><?php endforeach ?><?php if (!$data['users']): ?><tr><td colspan="6" class="empty">No users match these filters.</td></tr><?php endif ?></tbody></table></div><div class="pagination"><span>Page <?=number($data['page'])?> of <?=number($data['pages'])?></span><div><?php if($data['page']>1): ?><a href="<?=h(pageLink(array_merge($query,['page'=>$data['page']-1]),'users'))?>">← Previous</a><?php endif ?><?php if($data['page']<$data['pages']): ?><a href="<?=h(pageLink(array_merge($query,['page'=>$data['page']+1]),'users'))?>">Next →</a><?php endif ?></div></div></div></section>
<script src="../public/assets/js/admin-users.js" defer></script>
<?php endif ?></main><?php endif ?></body></html>
