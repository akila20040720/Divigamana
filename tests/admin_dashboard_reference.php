<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
date_default_timezone_set('Asia/Colombo');
require dirname(__DIR__).'/app/Support/SchemaCapabilities.php';
require dirname(__DIR__).'/app/Services/AdminDashboard.php';
require dirname(__DIR__).'/app/Services/DashboardMetrics.php';

use Divigamana\Services\AdminDashboard;
use Divigamana\Services\DashboardMetrics;

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, mobile_e164 TEXT, account_status TEXT, phone_verified_real INTEGER, ideamart_subscriber_id TEXT, mspace_subscriber_id TEXT, created_at TEXT, last_login_at TEXT)');
$db->exec('CREATE TABLE user_subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER, provider TEXT, status TEXT, provider_status TEXT, starts_at TEXT, ends_at TEXT, accepted_at TEXT, is_simulated INTEGER NOT NULL DEFAULT 0)');
$db->exec('CREATE TABLE audit_events (id INTEGER PRIMARY KEY, user_id INTEGER, event_type TEXT, metadata_json TEXT, created_at TEXT)');
$db->exec('CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY, user_id INTEGER, amount DECIMAL, currency TEXT, status TEXT, is_simulated INTEGER, created_at TEXT, provider TEXT)');
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
$subscription=$db->prepare('INSERT INTO user_subscriptions (id,user_id,provider,status,provider_status,starts_at,ends_at,accepted_at) VALUES (?,?,?,?,?,?,?,?)');
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
$payment=$db->prepare('INSERT INTO payment_transactions (id,user_id,amount,currency,status,is_simulated,created_at) VALUES (?,?,?,?,?,?,?)');
foreach ([
    [1,1,20,'LKR','paid',0,$before],
    [2,1,30,'LKR','paid',0,$old],
    [3,3,20,'LKR','failed',0,$before],
    [4,3,20,'LKR','authorized',0,$before],
    [5,1,900,'LKR','simulated_confirmed',1,$before],
] as $row) $payment->execute($row);
$db->exec("UPDATE payment_transactions SET provider=CASE WHEN is_simulated=1 THEN 'simulated' ELSE 'ideamart' END");
function expectReference(bool $condition,string $label): void {
    if (!$condition) { fwrite(STDERR,"FAIL $label\n"); exit(1); }
    echo "PASS $label\n";
}
$filters=['from'=>'','to'=>'','provider'=>'','verification'=>'','search'=>'','status'=>''];
$directory=new AdminDashboard($db);
$metrics=new DashboardMetrics($db);
$report=$directory->report($filters);
$overview=$metrics->report();
expectReference(!$overview['caas']['reasonsAvailable'] && $overview['caas']['insufficient']===null && $overview['caas']['attempts']===4, 'CAAS counts remain available before failure-reason migration without inventing a balance count');
$daily=$directory->report(array_merge($filters,['day'=>'2026-03-10']));
expectReference($daily['dailyNew']===2 && $daily['dailyLogins']===2,'selected day counts new users and login events within its date boundaries');
$states=[];
foreach ($report['users'] as $row) $states[$row['mobile_e164']]=$row['display_status'];
expectReference($states['+94770000001']==='active' && $states['+94770000002']==='number_only' && $states['+94770000003']==='active' && $states['+94750000004']==='active' && $states['+94710000005']==='active' && $states['+94770000006']==='blocked' && $states['+94720000007']==='active' && $states['+94710000008']==='number_only','directory states use PIN verification before account status');
$s=$overview['summary'];
expectReference((int)$s['total']===8 && (int)$s['active']===5 && (int)$s['inactive']===2 && (int)$s['unsubscribed']===0 && (int)$s['pending']===0 && (int)$s['blocked']===1,'overview states classify missing PIN users as inactive');
foreach (['active'=>5,'inactive'=>0,'number_only'=>2,'blocked'=>1] as $status=>$expected) {
    $filtered=$directory->report(array_merge($filters,['status'=>$status]));
    expectReference($filtered['count']===$expected && count($filtered['users'])===$expected,"$status filter matches directory state");
}
expectReference((int)$overview['pin']['entered']===6 && (int)$overview['pin']['pending']===2,'PIN totals use current verification independently of block state');
expectReference((int)$overview['funnel']['mobitel']['total']===2 && (int)$overview['funnel']['mobitel']['active']===1 && (int)$overview['funnel']['mobitel']['inactive']===1 && (int)$overview['funnel']['mobitel']['blocked']===0,'provider funnel excludes PIN-missing users from active');
$all=$overview['allCharges'];
expectReference((int)$all['attempts']===4 && (int)$all['successful']===2 && (int)$all['failed']===1 && (float)$all['revenue']===50.0,'all-time charges exclude simulated transactions and do not treat authorized as failed');
// Use the same calendar day as the PHP reporting clock, even around midnight.
$expectedToday=substr($before,0,10)===date('Y-m-d') ? [3,1,1,20.0] : [0,0,0,0.0];
$today=$overview['todayCharges'];
expectReference((int)$today['attempts']===$expectedToday[0] && (int)$today['successful']===$expectedToday[1] && (int)$today['failed']===$expectedToday[2] && (float)$today['revenue']===$expectedToday[3],'today charges use Colombo day and actual transaction statuses');
$db->exec("UPDATE user_subscriptions SET status='cancelled', provider_status='UNREGISTERED' WHERE id=1");
$changed=$directory->report($filters); $changedOverview=$metrics->report();
$changedStates=[]; foreach ($changed['users'] as $row) $changedStates[$row['mobile_e164']]=$row['display_status'];
expectReference($changedStates['+94770000001']==='active' && (int)$changedOverview['summary']['active']===5 && (int)$changedOverview['summary']['unsubscribed']===0,'subscription updates do not change effective PIN/account status');
$subscription->execute([10,1,'ideamart','active','REGISTERED',$old,$old,$before]);
$subscription->execute([11,3,'mspace','active','REGISTERED',$old,$old,$before]);
$reactivated=$directory->report($filters); $reactivatedOverview=$metrics->report();
$reactivatedStates=[]; foreach ($reactivated['users'] as $row) $reactivatedStates[$row['mobile_e164']]=$row['display_status'];
expectReference($reactivatedStates['+94770000001']==='active' && $reactivatedStates['+94770000003']==='active' && (int)$reactivatedOverview['summary']['active']===5, 'subscription rows do not override effective account status');
$db->exec("UPDATE user_subscriptions SET status='inactive' WHERE id=10");
$inactiveFilter=$directory->report(array_merge($filters,['status'=>'inactive']));
expectReference($inactiveFilter['count']===0 && (int)$metrics->report()['summary']['active']===5,'subscription status does not change effective account status');
$db->exec("UPDATE user_subscriptions SET status='active' WHERE id=10");
$activeFilter=$directory->report(array_merge($filters,['status'=>'active']));
$activeMobiles=array_column($activeFilter['users'],'mobile_e164');
expectReference($activeFilter['count']===5 && in_array('+94770000001',$activeMobiles,true) && (int)$metrics->report()['summary']['active']===5,'active filter requires account status and PIN verification');
$db->exec('ALTER TABLE users DROP COLUMN first_login_at');
$db->exec('DROP TABLE audit_events');
$legacy=$directory->report($filters);
expectReference($legacy['users'][0]['first_login_at']===null && $legacy['dailyLogins']===null,'directory remains available on databases awaiting login tracking migration');
$db->exec('CREATE TABLE audit_events (id INTEGER PRIMARY KEY,user_id INTEGER,event_type TEXT,created_at TEXT)');
$db->prepare("INSERT INTO audit_events (user_id,event_type,created_at) VALUES (1,'login_success',?)")->execute([date('Y-m-d H:i:s')]);
$legacyWithAudit=$directory->report($filters);
expectReference($legacyWithAudit['users'][0]['first_login_at']===null && $legacyWithAudit['dailyLogins']===1,'existing login events are counted even when first login column is absent');
$db->exec('ALTER TABLE payment_transactions ADD COLUMN failure_reason TEXT');
$db->exec("UPDATE payment_transactions SET failure_reason='insufficient_balance' WHERE id=3");
$extraPayment=$db->prepare('INSERT INTO payment_transactions (id,user_id,amount,currency,status,is_simulated,created_at,provider,failure_reason) VALUES (?,?,?,?,?,?,?,?,?)');
foreach ([
    [6,5,20,'LKR','paid',0,$before,'mspace',null],
    [7,7,20,'LKR','failed',0,$before,'ideamart','insufficient_balance'],
    [8,4,20,'LKR','failed',0,$before,'ideamart',null],
    [9,5,20,'LKR','authorized',0,$before,'mspace',null],
    [10,7,20,'LKR','paid',0,$before,'simulated',null],
] as $row) $extraPayment->execute($row);
$caas=$metrics->report()['caas'];
expectReference($caas['reasonsAvailable'] && $caas['attempts']===8 && $caas['successful']===3 && $caas['insufficient']===2, 'CAAS totals include real provider records and confirmed balance failures only');
expectReference((int)$caas['operators']['dialog']['attempts']===4 && (int)$caas['operators']['dialog']['successful']===2 && (int)$caas['operators']['dialog']['insufficient']===1 && (int)$caas['operators']['mobitel']['attempts']===2 && (int)$caas['operators']['hutch']['insufficient']===1 && (int)$caas['operators']['airtel']['insufficient']===0, 'CAAS records group by number prefix and leave other failures unclassified');

// All stored active records count, including simulated or older records.
$newUser=$db->prepare('INSERT INTO users (id,mobile_e164,account_status,phone_verified_real,ideamart_subscriber_id,mspace_subscriber_id,created_at,last_login_at) VALUES (?,?,?,?,?,?,?,?)');
foreach ([9,10,11] as $id) $newUser->execute([$id,'+9477'.str_pad((string)$id,7,'0',STR_PAD_LEFT),'active',1,'idea-'.$id,null,$before,null]);
$subWithMode=$db->prepare('INSERT INTO user_subscriptions (id,user_id,provider,status,provider_status,starts_at,ends_at,accepted_at,is_simulated) VALUES (?,?,?,?,?,?,?,?,?)');
foreach ([
    [20,9,'simulated','active',null,$old,$old,$old,1],
    [21,10,'simulated','active',null,$old,$old,$old,1],
    [22,10,'ideamart','inactive','INITIAL CHARGING PENDING',$old,$old,$old,0],
    [23,11,'ideamart','active','REGISTERED',$old,$old,$old,0],
    [24,11,'simulated','cancelled',null,$old,$old,$old,1],
] as $row) $subWithMode->execute($row);
$mixed=$directory->report($filters); $mixedRows=[];
foreach ($mixed['users'] as $row) $mixedRows[$row['id']]=$row;
expectReference($mixedRows[9]['display_status']==='active' && $mixedRows[10]['display_status']==='active' && $mixedRows[11]['display_status']==='active','directory status remains sourced from users.account_status');
expectReference((int)$mixedRows[10]['directory_sub_id']===22 && $mixedRows[10]['sub_provider_status']==='INITIAL CHARGING PENDING' && (int)$mixedRows[11]['directory_sub_id']===24 && (int)$mixedRows[11]['sub_simulated']===1,'directory shows the latest stored subscription independently of effective state');
expectReference((int)$mixedRows[7]['payment_id']===10 && $mixedRows[7]['payment_status']==='paid' && (int)$mixedRows[7]['payment_simulated']===0 && $mixedRows[7]['payment_provider']==='simulated','directory shows the latest payment record independently of subscription state');
