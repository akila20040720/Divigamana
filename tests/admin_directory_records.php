<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__).'/app/Support/SchemaCapabilities.php';
require dirname(__DIR__).'/app/Services/AdminDashboard.php';

use Divigamana\Services\AdminDashboard;

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, mobile_e164 TEXT, account_status TEXT, phone_verified_real INTEGER, ideamart_subscriber_id TEXT, mspace_subscriber_id TEXT, created_at TEXT, last_login_at TEXT)');
$db->exec('CREATE TABLE user_subscriptions (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT, is_simulated INTEGER, provider TEXT, provider_status TEXT, accepted_at TEXT, starts_at TEXT, ends_at TEXT)');
$db->exec('CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY, user_id INTEGER, status TEXT, amount DECIMAL, currency TEXT, provider TEXT, is_simulated INTEGER)');
$db->exec("INSERT INTO users VALUES (3,'+94770000003','active',1,'idea3',NULL,'2026-10-24 10:00:00',NULL), (7,'+94770000007','active',1,'idea7',NULL,'2026-10-24 10:00:00',NULL), (8,'+94770000008','active',0,NULL,NULL,'2026-10-24 10:00:00',NULL)");
$db->exec("INSERT INTO user_subscriptions VALUES (3,3,'active',1,'simulated',NULL,'2026-10-24 14:55:38','2026-10-24 14:55:38','2026-10-25 14:55:38'), (7,3,'inactive',0,'ideamart','INITIAL CHARGING PENDING','2026-11-07 23:33:03','2026-11-07 23:33:03','2026-11-07 23:35:35'), (8,7,'active',0,'ideamart','REGISTERED','2026-11-07 23:33:03','2026-11-07 23:33:03','2026-11-07 23:35:35')");
$db->exec("INSERT INTO payment_transactions VALUES (1,7,'failed',20.00,'LKR','ideamart',0), (2,7,'failed',20.00,'LKR','ideamart',0), (3,7,'failed',20.00,'LKR','ideamart',0), (4,7,'paid',0.02,'LKR','ideamart',0), (5,7,'authorized',0.02,'LKR','ideamart',0), (6,7,'paid',0.02,'LKR','ideamart',0), (7,7,'paid',0.02,'LKR','ideamart',0)");
$filters=['from'=>'','to'=>'','provider'=>'','verification'=>'','search'=>'','status'=>''];
$report=(new AdminDashboard($db))->report($filters);
$users=[]; foreach ($report['users'] as $row) $users[(int)$row['id']]=$row;
function expectDirectory(bool $ok,string $message): void {
    if (!$ok) { fwrite(STDERR,"FAIL $message\n"); exit(1); }
    echo "PASS $message\n";
}
expectDirectory(count($users)===3 && $users[3]['display_status']==='active' && (int)$users[3]['directory_sub_id']===7 && $users[3]['sub_provider_status']==='INITIAL CHARGING PENDING','older simulated active row counts even with a newer inactive real subscription');
expectDirectory($users[7]['display_status']==='active' && (int)$users[7]['payment_id']===7 && $users[7]['payment_status']==='paid' && (float)$users[7]['payment_amount']===0.02 && $users[7]['payment_currency']==='LKR','latest payment is independent of subscription state and preserves amount');
expectDirectory($users[8]['directory_sub_id']===null && $users[8]['payment_id']===null,'users without subscription or payment remain in directory');
$db->exec("INSERT INTO user_subscriptions VALUES (9,3,'cancelled',1,'simulated',NULL,'2026-11-07 23:35:00','2026-11-07 23:35:00','2026-11-08 23:35:00')");
$again=(new AdminDashboard($db))->report($filters); $users=[]; foreach ($again['users'] as $row) $users[(int)$row['id']]=$row;
expectDirectory($users[3]['display_status']==='active' && (int)$users[3]['directory_sub_id']===9 && (int)$users[3]['sub_simulated']===1,'newer cancelled subscription does not suppress a stored active row');
$db->exec("UPDATE user_subscriptions SET status='inactive' WHERE id=3");
$again=(new AdminDashboard($db))->report($filters); $users=[]; foreach ($again['users'] as $row) $users[(int)$row['id']]=$row;
expectDirectory($users[3]['display_status']==='inactive','user becomes inactive when no subscription row remains active');
