<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/app/Support/SchemaCapabilities.php';
require dirname(__DIR__).'/app/Services/ChargeOutcome.php';

use Divigamana\Services\ChargeOutcome;

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY, status TEXT, provider_reference TEXT)');
$db->exec("INSERT INTO payment_transactions VALUES (1,'authorized',NULL)");
function expectOutcome(bool $condition,string $message): void {
    if (!$condition) { fwrite(STDERR,"FAIL $message\n"); exit(1); }
    echo "PASS $message\n";
}
$set="UPDATE payment_transactions SET status='failed',provider_reference=?";
$where=" WHERE id=? AND status='authorized'";
ChargeOutcome::update($db,$set,$where,['old-ref'],[1],'ideamart','E1378',true);
expectOutcome($db->query('SELECT status FROM payment_transactions')->fetchColumn()==='failed','legacy schema records payment status without optional outcome columns');
$db->exec('ALTER TABLE payment_transactions ADD COLUMN provider_status_code TEXT');
$db->exec('ALTER TABLE payment_transactions ADD COLUMN failure_reason TEXT');
$db->exec("UPDATE payment_transactions SET status='authorized'");
ChargeOutcome::update($db,$set,$where,['ref'],[1],'ideamart','E1378',true);
$row=$db->query('SELECT * FROM payment_transactions')->fetch(PDO::FETCH_ASSOC);
expectOutcome($row['status']==='failed' && $row['provider_status_code']==='E1378' && $row['failure_reason']==='insufficient_balance','IdeaMart insufficient balance is recorded with its code');
ChargeOutcome::update($db,"UPDATE payment_transactions SET status='paid',provider_reference=?"," WHERE id=? AND status<>'refunded'",['paid-ref'],[1],'ideamart','S1000',false);
$row=$db->query('SELECT * FROM payment_transactions')->fetch(PDO::FETCH_ASSOC);
expectOutcome($row['status']==='paid' && $row['provider_status_code']==='S1000' && $row['failure_reason']===null,'a confirmed success clears any earlier failure');
ChargeOutcome::update($db,$set,$where,['late'],[1],'ideamart','E1378',true);
expectOutcome($db->query('SELECT status FROM payment_transactions')->fetchColumn()==='paid','delayed failure cannot overwrite a paid transaction');
$db->exec("UPDATE payment_transactions SET status='authorized'");
ChargeOutcome::update($db,$set,$where,['ref'],[1],'mspace','E1378',true);
$row=$db->query('SELECT * FROM payment_transactions')->fetch(PDO::FETCH_ASSOC);
expectOutcome($row['provider_status_code']==='E1378' && $row['failure_reason']===null,'mSpace code is retained without guessing its meaning');
$db->exec("UPDATE payment_transactions SET status='authorized'");
ChargeOutcome::update($db,$set,$where,['ref'],[1],'ideamart','E1406',true);
expectOutcome($db->query('SELECT failure_reason FROM payment_transactions')->fetchColumn()===null,'other failures are not counted as insufficient balance');
$db->exec("UPDATE payment_transactions SET status='authorized'");
$db->exec("CREATE TRIGGER deny_optional_fields BEFORE UPDATE OF failure_reason ON payment_transactions BEGIN SELECT RAISE(ABORT,'optional field unavailable'); END");
ChargeOutcome::update($db,"UPDATE payment_transactions SET status='paid',provider_reference=?",$where,['confirmed'],[1],'ideamart','S1000',false);
expectOutcome($db->query('SELECT status FROM payment_transactions')->fetchColumn()==='paid','optional outcome write failure does not prevent confirming a real debit');
