<?php
declare(strict_types=1);
// Add only the fields needed by the dashboard, without deleting existing data.
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/app/bootstrap.php';
use Divigamana\Support\Database;
try {
    $db=Database::pdo();
    $columns=static function(string $table) use ($db): array {
        $rows=$db->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        return array_column($rows,'Type','Field');
    };
    $users=$columns('users'); $subscriptions=$columns('user_subscriptions');
    foreach (['id','mobile_e164','phone_verified_real','created_at'] as $name) if (!isset($users[$name])) throw new RuntimeException("users.$name is missing; migrate the base application schema first.");
    foreach (['id','user_id','status','is_simulated','accepted_at','starts_at','ends_at'] as $name) if (!isset($subscriptions[$name])) throw new RuntimeException("user_subscriptions.$name is missing; migrate the base application schema first.");
    $add=static function(string $table,array &$existing,string $name,string $definition) use ($db): bool {
        if (isset($existing[$name])) return false;
        $db->exec("ALTER TABLE `$table` ADD COLUMN `$name` $definition");
        $existing[$name]=$definition; echo "Added $table.$name\n"; return true;
    };
    $add('users',$users,'ideamart_subscriber_id','VARCHAR(120) NULL');
    $add('users',$users,'mspace_subscriber_id','VARCHAR(120) NULL');
    $add('users',$users,'account_status',"ENUM('active','blocked') NOT NULL DEFAULT 'active'");
    $add('users',$users,'blocked_at','DATETIME NULL');
    $add('users',$users,'last_login_at','DATETIME NULL');
    $providerAdded=$add('user_subscriptions',$subscriptions,'provider',"ENUM('simulated','ideamart','mspace') NOT NULL DEFAULT 'simulated'");
    $add('user_subscriptions',$subscriptions,'provider_status','VARCHAR(30) NULL');
    $add('user_subscriptions',$subscriptions,'frequency','VARCHAR(60) NULL');
    $add('user_subscriptions',$subscriptions,'notified_at','VARCHAR(40) NULL');
    if ($providerAdded) {
        $db->exec("UPDATE user_subscriptions s JOIN users u ON u.id=s.user_id SET s.provider='ideamart', s.provider_status=IF(s.status='active','REGISTERED','UNREGISTERED') WHERE s.is_simulated=0 AND u.ideamart_subscriber_id IS NOT NULL AND u.ideamart_subscriber_id<>''");
        echo "Classified existing IdeaMart subscription records where identifiable.\n";
    }
    $indexes=static function(string $table) use ($db): array { return array_column($db->query("SHOW INDEX FROM `$table`")->fetchAll(PDO::FETCH_ASSOC),'Key_name'); };
    foreach (['users'=>['idx_users_created_id'=>'(created_at, id)'],'user_subscriptions'=>['idx_subscriptions_accepted'=>'(accepted_at)','idx_subscriptions_user_latest'=>'(user_id, id)']] as $table=>$definitions) {
        $existing=$indexes($table);
        foreach ($definitions as $name=>$definition) if (!in_array($name,$existing,true)) { $db->exec("ALTER TABLE `$table` ADD INDEX `$name` $definition"); echo "Added $table.$name\n"; }
    }
    $db->exec(file_get_contents(dirname(__DIR__).'/database/seed.sql'));
    echo "Dashboard migration and catalog seed complete. Existing records were retained.\n";
} catch (Throwable $e) { fwrite(STDERR,"Dashboard migration failed: ".$e->getMessage()."\n"); exit(1); }
