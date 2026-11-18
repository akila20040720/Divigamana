<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
date_default_timezone_set('Asia/Colombo');
require dirname(__DIR__).'/app/Support/SchemaCapabilities.php';
require dirname(__DIR__).'/app/Services/AdminDashboard.php';
require dirname(__DIR__).'/app/Services/DashboardMetrics.php';

use Divigamana\Services\DashboardMetrics;

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, mobile_e164 TEXT, account_status TEXT, phone_verified_real INTEGER, ideamart_subscriber_id TEXT, mspace_subscriber_id TEXT, created_at TEXT)');
$db->exec('CREATE TABLE user_subscriptions (id INTEGER PRIMARY KEY,user_id INTEGER,status TEXT,is_simulated INTEGER,provider TEXT,accepted_at TEXT)');
$db->exec('CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY,user_id INTEGER,amount DECIMAL,currency TEXT,status TEXT,is_simulated INTEGER,provider TEXT,created_at TEXT,failure_reason TEXT)');
$now=date('Y-m-d H:i:s');
$db->exec("INSERT INTO users VALUES (7,'+94770000007','active',1,'idea-7','mspace-7','$now'),(8,'+94710000008','blocked',1,'idea-8',NULL,'$now')");
$sub=$db->prepare('INSERT INTO user_subscriptions VALUES (?,?,?,?,?,?)');
foreach ([[1,7,'active',0,'ideamart',$now],[2,7,'inactive',0,'ideamart',$now],[3,7,'active',0,'mspace',$now],[4,8,'active',0,'ideamart',$now],[5,7,'active',1,'simulated',$now]] as $row) $sub->execute($row);
$payment=$db->prepare('INSERT INTO payment_transactions VALUES (?,?,?,?,?,?,?,?,?)');
foreach ([[1,7,20,'LKR','failed',0,'ideamart',$now,null],[2,7,20,'LKR','failed',0,'ideamart',$now,null],[3,7,20,'LKR','failed',0,'ideamart',$now,null],[4,7,0.02,'LKR','paid',0,'ideamart',$now,null],[5,7,0.02,'LKR','authorized',0,'ideamart',$now,null],[6,7,0.02,'LKR','paid',0,'ideamart',$now,null],[7,7,0.02,'LKR','paid',0,'ideamart',$now,null],[8,7,900,'LKR','simulated_confirmed',1,'simulated',$now,null]] as $row) $payment->execute($row);
function expectReconciled(bool $condition,string $message): void { if (!$condition) { fwrite(STDERR,"FAIL $message\n"); exit(1); } echo "PASS $message\n"; }
$metrics=new DashboardMetrics($db);
$report=$metrics->report(); $all=$report['allCharges']; $today=$report['todayCharges']; $caas=$report['caas'];
expectReconciled((int)$all['attempts']===7 && (int)$all['successful']===3 && (int)$all['failed']===3 && (int)$all['authorized']===1 && (int)$all['refunded']===0 && abs((float)$all['revenue']-0.06)<0.00001, 'seven pictured real payments reconcile to three paid, three failed, one authorized and LKR 0.06');
expectReconciled((int)$today['attempts']===7 && (int)$today['authorized']===1 && (int)$caas['operators']['dialog']['failed']===3 && (int)$caas['operators']['dialog']['authorized']===1 && (int)$caas['insufficient']===0, 'today and CAAS report real statuses; unknown failure reasons are not classified');
expectReconciled((int)$report['platform']['ideamart']['active']===1 && (int)$report['platform']['ideamart']['inactive']===0 && (int)$report['platform']['mspace']['blocked']===1, 'today platform counts use users-table account status and MSISDN prefix');
expectReconciled((int)$report['funnel']['mobitel']['blocked']===1 && (int)$report['funnel']['mobitel']['total']===(int)$report['funnel']['mobitel']['active']+(int)$report['funnel']['mobitel']['inactive']+(int)$report['funnel']['mobitel']['blocked'], 'provider funnel accounts for blocked users in its total');
$payment->execute([9,7,0.02,'LKR','refunded',0,'ideamart',$now,null]);
$payment->execute([10,7,0.02,'LKR','simulated_confirmed',0,'ideamart',$now,null]);
$report=$metrics->report(); $all=$report['allCharges']; $caas=$report['caas'];
expectReconciled((int)$all['attempts']===9 && (int)$all['refunded']===1 && (int)$all['other']===1 && (int)$caas['refunded']===1 && (int)$caas['other']===1 && abs((float)$all['revenue']-0.06)<0.00001, 'refunded and other statuses are separated without adding them to paid revenue');
