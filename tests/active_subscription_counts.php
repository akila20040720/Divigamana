<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
date_default_timezone_set('Asia/Colombo');
require dirname(__DIR__).'/app/Support/SchemaCapabilities.php';
require dirname(__DIR__).'/app/Services/AdminDashboard.php';
require dirname(__DIR__).'/app/Services/DashboardMetrics.php';

use Divigamana\Services\DashboardMetrics;

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, mobile_e164 TEXT, account_status TEXT, phone_verified_real INTEGER)');
$db->exec('CREATE TABLE user_subscriptions (id INTEGER PRIMARY KEY,user_id INTEGER,status TEXT,is_simulated INTEGER,provider TEXT,accepted_at TEXT)');
$db->exec('CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY,user_id INTEGER,amount DECIMAL,currency TEXT,status TEXT,is_simulated INTEGER,provider TEXT,created_at TEXT)');
$user=$db->prepare('INSERT INTO users VALUES (?,?,?,?)');
foreach ([1,2,3,4,5,7] as $id) $user->execute([$id,'+9477'.str_pad((string)$id,7,'0',STR_PAD_LEFT),'active',1]);
$sub=$db->prepare('INSERT INTO user_subscriptions VALUES (?,?,?,?,?,?)');
foreach ([
    [1,1,'active',1,'simulated'], [2,2,'active',1,'simulated'],
    [3,3,'active',1,'simulated'], [4,4,'active',1,'simulated'],
    [5,5,'cancelled',1,'simulated'], [6,7,'inactive',0,'ideamart'],
    [7,3,'inactive',0,'ideamart'],
] as $row) $sub->execute(array_merge($row,['2026-10-24 12:00:00']));
function expectActiveCount(bool $condition,string $message): void {
    if (!$condition) { fwrite(STDERR,"FAIL $message\n"); exit(1); }
    echo "PASS $message\n";
}
$metrics=new DashboardMetrics($db);
$report=$metrics->report(); $records=$report['subscriptionRecords'];
expectActiveCount((int)$records['active']===4 && (int)$records['simulated_active']===4 && (int)$records['real_active']===0,'visible subscription records include four active simulated rows');
expectActiveCount((int)$report['summary']['active']===4,'any active row counts the user even when a newer real row is inactive');
$db->exec("UPDATE user_subscriptions SET status='active' WHERE id=7");
$report=$metrics->report(); $records=$report['subscriptionRecords'];
expectActiveCount((int)$records['active']===5 && (int)$records['real_active']===1 && (int)$records['simulated_active']===4 && (int)$report['summary']['active']===4,'additional active row for the same user increases record count without double-counting the user');
$db->exec("UPDATE users SET account_status='blocked' WHERE id=3");
$report=$metrics->report();
expectActiveCount((int)$report['summary']['active']===3 && (int)$report['subscriptionRecords']['active']===5,'blocked account is excluded from active users but its stored rows remain visible');
