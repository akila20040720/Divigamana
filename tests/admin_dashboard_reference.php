<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
date_default_timezone_set('Asia/Colombo');
require dirname(__DIR__).'/app/Services/AdminDashboard.php';
require dirname(__DIR__).'/app/Services/DashboardMetrics.php';

use Divigamana\Services\AdminDashboard;
use Divigamana\Services\DashboardMetrics;

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, mobile_e164 TEXT, account_status TEXT, phone_verified_real INTEGER, ideamart_subscriber_id TEXT, mspace_subscriber_id TEXT, created_at TEXT, last_login_at TEXT)');
$db->exec('CREATE TABLE user_subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER, provider TEXT, status TEXT, provider_status TEXT, starts_at TEXT, ends_at TEXT, accepted_at TEXT)');
$db->exec('CREATE TABLE audit_events (id INTEGER PRIMARY KEY, user_id INTEGER, event_type TEXT, metadata_json TEXT, created_at TEXT)');
$db->exec('CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY, user_id INTEGER, amount DECIMAL, currency TEXT, status TEXT, is_simulated INTEGER, created_at TEXT)');
$before=date('Y-m-d H:i:s',time()-3600);
$after=date('Y-m-d H:i:s',time()+3600);
$old=date('Y-m-d H:i:s',time()-172800);
$user=$db->prepare('INSERT INTO users VALUES (?,?,?,?,?,?,?,?)');
foreach ([
    [1,'+94770000001','active',1,'idea-1',null,$before,$before],
    [2,'+94770000002','active',0,null,null,$before,null],
    [3,'+94770000003','active',1,'idea-3',null,$before,$before],
    [4,'+94750000004','active',1,'idea-4',null,$before,$before],
    [5,'+94710000005','active',1,null,'mspace-5',$before,$before],
    [6,'+94770000006','blocked',1,'idea-6',null,$before,$before],
    [7,'+94720000007','active',1,'idea-7',null,$before,$before],
    [8,'+94710000008','active',0,null,null,$before,null],
] as $row) $user->execute($row);
$db->exec('ALTER TABLE users ADD COLUMN first_login_at TEXT');
// Fixed dates exercise inclusive start and exclusive end, independent of when the test runs.
$db->exec("UPDATE users SET created_at='2026-03-10 00:00:00' WHERE id=1");
$db->exec("UPDATE users SET created_at='2026-03-10 23:59:59' WHERE id=2");
$db->exec("UPDATE users SET created_at='2026-03-11 00:00:00' WHERE id=3");
$event=$db->prepare('INSERT INTO audit_events VALUES (?,?,?,?,?)');
foreach ([
    [1,1,'login_success',null,'2026-03-10 00:00:00'],
    [2,1,'login_success',null,'2026-03-10 23:59:59'],
    [3,2,'login_success',null,'2026-03-11 00:00:00'],
    [4,1,'other_event',null,'2026-03-10 12:00:00'],
] as $row) $event->execute($row);
$subscription=$db->prepare('INSERT INTO user_subscriptions VALUES (?,?,?,?,?,?,?,?)');
foreach ([
    [1,1,'ideamart','active','REGISTERED',$before,$after,$before],
    [2,3,'ideamart','active','REGISTERED',$before,$after,$before],
    [3,3,'ideamart','cancelled','UNREGISTERED',$before,$after,$before],
    [4,4,'ideamart','active','REGISTERED',$old,$old,$old],
    [5,5,'mspace','active','REGISTERED',$old,$old,$before],
    [6,6,'ideamart','active','REGISTERED',$before,$after,$before],
    [7,7,'ideamart','cancelled','UNREGISTERED',$before,$after,$before],
    [8,7,'ideamart','active','REGISTERED',$before,$after,$before],
    [9,8,'mspace','pending','PENDING',$before,$after,$before],
] as $row) $subscription->execute($row);
$payment=$db->prepare('INSERT INTO payment_transactions VALUES (?,?,?,?,?,?,?)');
foreach ([
    [1,1,20,'LKR','paid',0,$before],
    [2,1,30,'LKR','paid',0,$old],
    [3,3,20,'LKR','failed',0,$before],
    [4,3,20,'LKR','authorized',0,$before],
    [5,1,900,'LKR','simulated_confirmed',1,$before],
] as $row) $payment->execute($row);
function expectReference(bool $condition,string $label): void {
    if (!$condition) { fwrite(STDERR,"FAIL $label\n"); exit(1); }
    echo "PASS $label\n";
}
$filters=['from'=>'','to'=>'','provider'=>'','verification'=>'','search'=>'','status'=>''];
$directory=new AdminDashboard($db);
$metrics=new DashboardMetrics($db);
$report=$directory->report($filters);
$overview=$metrics->report();
$daily=$directory->report(array_merge($filters,['day'=>'2026-03-10']));
expectReference($daily['dailyNew']===2 && $daily['dailyLogins']===2,'selected day counts new users and login events within its date boundaries');
$states=[];
foreach ($report['users'] as $row) $states[$row['mobile_e164']]=$row['display_status'];
expectReference($states['+94770000001']==='active' && $states['+94770000002']==='number_only' && $states['+94770000003']==='inactive' && $states['+94750000004']==='inactive' && $states['+94710000005']==='active' && $states['+94770000006']==='blocked' && $states['+94720000007']==='active' && $states['+94710000008']==='number_only','directory uses latest subscription, expiry, mSpace provider status and blocked precedence');
$s=$overview['summary'];
expectReference((int)$s['total']===8 && (int)$s['active']===3 && (int)$s['inactive']===4 && (int)$s['unsubscribed']===2 && (int)$s['pending']===2 && (int)$s['blocked']===1,'overview states match directory and form a partition');
foreach (['active'=>3,'inactive'=>2,'number_only'=>2,'blocked'=>1] as $status=>$expected) {
    $filtered=$directory->report(array_merge($filters,['status'=>$status]));
    expectReference($filtered['count']===$expected && count($filtered['users'])===$expected,"$status filter matches directory state");
}
expectReference((int)$overview['pin']['entered']===6 && (int)$overview['pin']['pending']===2,'PIN totals use current verification independently of block state');
expectReference((int)$overview['funnel']['mobitel']['total']===2 && (int)$overview['funnel']['mobitel']['active']===1 && (int)$overview['funnel']['mobitel']['inactive']===1,'provider funnel agrees with directory states');
$all=$overview['allCharges'];
expectReference((int)$all['attempts']===4 && (int)$all['successful']===2 && (int)$all['failed']===1 && (float)$all['revenue']===50.0,'all-time charges exclude simulated transactions and do not treat authorized as failed');
// Use the same calendar day as the PHP reporting clock, even around midnight.
$expectedToday=substr($before,0,10)===date('Y-m-d') ? [3,1,1,20.0] : [0,0,0,0.0];
$today=$overview['todayCharges'];
expectReference((int)$today['attempts']===$expectedToday[0] && (int)$today['successful']===$expectedToday[1] && (int)$today['failed']===$expectedToday[2] && (float)$today['revenue']===$expectedToday[3],'today charges use Colombo day and actual transaction statuses');
$db->exec("UPDATE user_subscriptions SET status='cancelled', provider_status='UNREGISTERED' WHERE id=1");
$changed=$directory->report($filters); $changedOverview=$metrics->report();
$changedStates=[]; foreach ($changed['users'] as $row) $changedStates[$row['mobile_e164']]=$row['display_status'];
expectReference($changedStates['+94770000001']==='inactive' && (int)$changedOverview['summary']['active']===2 && (int)$changedOverview['summary']['unsubscribed']===3,'subscription update immediately changes directory and overview together');
