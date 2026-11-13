<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use Divigamana\Services\AdminDashboard;
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
    } elseif (($_POST['action']??'')==='login') {
        $valid=$username!=='' && $password!=='' && hash_equals($username,(string)($_POST['username']??'')) && hash_equals($password,(string)($_POST['password']??''));
        if (!loginAttempt($valid)) { http_response_code(429); $error='Sign-in is temporarily unavailable or too many attempts were made. Please try again in 15 minutes.'; }
        elseif ($valid) {
            session_regenerate_id(true); $_SESSION['admin_fingerprint']=$fingerprint; header('Location: ./',true,303); exit;
        } else { $error='Invalid username or password.'; }
    }
}
$filters=['from'=>(string)($_GET['from']??''),'to'=>(string)($_GET['to']??''),'provider'=>(string)($_GET['provider']??''),'verification'=>(string)($_GET['verification']??''),'search'=>(string)($_GET['search']??'')];
foreach (['from','to'] as $key) if ($filters[$key]!=='' && !validDate($filters[$key])) $filters[$key]='';
if ($filters['from']!=='' && $filters['to']!=='' && $filters['from']>$filters['to']) { $error='The start date must be before the end date.'; $filters['from']=''; $filters['to']=''; }
if (!in_array($filters['provider'],['','dialog','mobitel','airtel','hutch','other'],true)) $filters['provider']='';
if (!in_array($filters['verification'],['','ideamart','mspace','unverified'],true)) $filters['verification']='';
$search=preg_replace('/[^0-9+]/','',$filters['search']);
if (substr($search,0,1)==='0') $search='94'.substr($search,1);
$filters['search']=substr($search,0,16);
$data=null;
if ($authenticated) {
    try { $data=(new AdminDashboard(Database::pdo()))->report($filters,max(1,(int)($_GET['page']??1))); }
    catch (Throwable $e) { error_log('Admin dashboard: '.$e->getMessage()); http_response_code(503); $error='The dashboard could not load the database. Check the connection and apply the required migrations.'; }
}
$providers=['dialog'=>['Dialog','074 / 077 / 076'],'mobitel'=>['Mobitel','070 / 071'],'airtel'=>['Airtel','075'],'hutch'=>['Hutch','072 / 078'],'other'=>['Other','Other prefixes']];
function selected(string $a,string $b): string { return $a===$b?' selected':''; }
function pageLink(int $page,array $filters): string { return '?'.http_build_query(array_merge($filters,['page'=>$page])); }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Divigamana · Admin</title><link rel="stylesheet" href="../public/assets/css/admin.css"></head><body>
<?php if (!$authenticated): ?>
<main class="login-wrap"><div class="login-card"><div class="brand-mark">D</div><p class="eyebrow">DIVIGAMANA</p><h1>Welcome back</h1><p class="muted">Sign in to view your subscriber dashboard.</p><?php if ($error): ?><p class="error" role="alert"><?=h($error)?></p><?php endif ?><form method="post"><input type="hidden" name="csrf" value="<?=h(Csrf::token())?>"><input type="hidden" name="action" value="login"><label>Username<input name="username" autocomplete="username" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button type="submit">Sign in</button></form></div></main>
<?php else: ?>
<header class="topbar"><a class="brand" href="./"><span class="brand-mark small">D</span><span>Divigamana <small>ADMIN</small></span></a><form method="post"><input type="hidden" name="csrf" value="<?=h(Csrf::token())?>"><input type="hidden" name="action" value="logout"><button class="button-secondary" type="submit">Sign out</button></form></header>
<main class="container"><div class="page-heading"><div><p class="eyebrow">OVERVIEW</p><h1>Subscriber dashboard</h1><p class="muted">A live view of your registered users and subscriptions.</p></div><div class="live"><span></span> Live database · Colombo time</div></div>
<?php if ($error): ?><p class="error" role="alert"><?=h($error)?></p><?php endif ?>
<?php if ($data): $summary=$data['summary']; ?>
<section class="stats" aria-label="Overview"><article class="stat"><p>Total users</p><strong><?=number($summary['total'])?></strong><small>All registered mobile numbers</small></article><article class="stat"><p>Active subscriptions</p><strong><?=number($summary['active'])?></strong><small>Users with a current subscription</small></article><article class="stat"><p>Inactive</p><strong><?=number($summary['inactive'])?></strong><small>Users without a current subscription</small></article><article class="stat"><p>Blocked users</p><strong><?=number($summary['blocked'])?></strong><small>May overlap subscription counts</small></article></section>
<section class="panel"><div class="section-heading"><div><p class="eyebrow">REACH & CONVERSION</p><h2>Provider funnel</h2><p class="muted">Registered numbers by mobile prefix, with current verification and subscription state.</p></div></div><div class="provider-grid"><?php $byProvider=[]; foreach($data['funnel'] as $row) $byProvider[$row['provider']]=$row; foreach($providers as $key=>[$name,$prefix]): $row=$byProvider[$key]??['total'=>0,'verified'=>0,'active'=>0]; ?><article class="provider"><div class="provider-top"><span class="provider-icon <?=h($key)?>"><?=h(substr($name,0,1))?></span><span><b><?=h($name)?></b><small><?=h($prefix)?></small></span></div><strong><?=number($row['total'])?></strong><span class="muted">registered</span><div class="provider-foot"><span><?=number($row['verified'])?> verified</span><span><?=number($row['active'])?> active</span></div></article><?php endforeach ?></div></section>
<section class="panel"><div class="section-heading"><div><p class="eyebrow">EXPLORE YOUR USERS</p><h2>Phone verification · by join date</h2><p class="muted">Filters apply to these counts and user management. Verification reflects current status, not the date verified.</p></div></div><form class="filters" method="get"><label>From<input type="date" name="from" value="<?=h($filters['from'])?>"></label><label>To<input type="date" name="to" value="<?=h($filters['to'])?>"></label><label>Provider<select name="provider"><option value="">All providers</option><?php foreach($providers as $key=>[$name,$prefix]): ?><option value="<?=h($key)?>"<?=selected($filters['provider'],$key)?>><?=h($name)?></option><?php endforeach ?></select></label><label>Verification<select name="verification"><option value="">All verification</option><option value="ideamart"<?=selected($filters['verification'],'ideamart')?>>IdeaMart</option><option value="mspace"<?=selected($filters['verification'],'mspace')?>>mSpace</option><option value="unverified"<?=selected($filters['verification'],'unverified')?>>Not provider verified</option></select></label><label>Mobile number<input name="search" inputmode="tel" placeholder="Search number" value="<?=h($filters['search'])?>"></label><div class="filter-actions"><button>Apply filters</button><a href="./">Reset</a></div></form><div class="mini-stats"><div><span>New users</span><strong><?=number($data['verification']['total'])?></strong><small>Joined in selected range</small></div><div><span>IdeaMart verified</span><strong><?=number($data['verification']['ideamart'])?></strong><small>Current verification</small></div><div><span>mSpace verified</span><strong><?=number($data['verification']['mspace'])?></strong><small>Current verification</small></div></div></section>
<section class="panel"><div class="section-heading"><div><p class="eyebrow">RECENT ACTIVITY</p><h2>Subscriptions · last 24 hours</h2><p class="muted">Subscription records accepted in the last 24 hours, grouped by provider and recorded status. Repeated updates to the same record are not included.</p></div></div><?php if (!$data['subscriptions']): ?><p class="empty">No subscription records in the last 24 hours.</p><?php else: ?><div class="subscription-list"><?php foreach($data['subscriptions'] as $row): ?><div><span><?=h(ucfirst($row['provider']))?> <span class="badge"><?=h(ucfirst($row['status']))?></span></span><strong><?=number($row['total'])?></strong></div><?php endforeach ?></div><?php endif ?></section>
<section class="panel"><div class="section-heading"><div><p class="eyebrow">DIRECTORY</p><h2>User management</h2><p class="muted"><?=number($data['count'])?> matching users</p></div></div><div class="table-wrap"><table><thead><tr><th>Mobile</th><th>Account state</th><th>Verification</th><th>Joined</th><th>Last login</th></tr></thead><tbody><?php foreach($data['users'] as $user): $ver=[]; if ($user['phone_verified_real'] && $user['ideamart_subscriber_id']) $ver[]='IdeaMart'; if ($user['phone_verified_real'] && $user['mspace_subscriber_id']) $ver[]='mSpace'; ?><tr><td><b><?=h($user['mobile_e164'])?></b><small><?=h($providers[$user['provider']][0]??'Other')?></small></td><td><span class="badge <?=h($user['account_status'])?>"><?=h(ucfirst($user['account_status']))?></span></td><td><?=h($ver?implode(' + ',$ver):'Not provider verified')?></td><td><?=h(dateValue($user['created_at']))?></td><td><?=h(dateValue($user['last_login_at']))?></td></tr><?php endforeach ?><?php if (!$data['users']): ?><tr><td colspan="5" class="empty">No users match these filters.</td></tr><?php endif ?></tbody></table></div><div class="pagination"><span>Page <?=number($data['page'])?> of <?=number($data['pages'])?></span><div><?php if($data['page']>1): ?><a href="<?=h(pageLink($data['page']-1,$filters))?>">← Previous</a><?php endif ?><?php if($data['page']<$data['pages']): ?><a href="<?=h(pageLink($data['page']+1,$filters))?>">Next →</a><?php endif ?></div></div></section>
<?php endif ?></main><?php endif ?></body></html>
