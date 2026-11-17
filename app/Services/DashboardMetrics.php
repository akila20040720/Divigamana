<?php
declare(strict_types=1);
namespace Divigamana\Services;

use PDO;
use Divigamana\Support\SchemaCapabilities;

/** Aggregates persisted user and real charge records for the admin overview. */
final class DashboardMetrics {
    private const PROVIDER = "CASE WHEN SUBSTR(u.mobile_e164,1,4)='+947' THEN CASE SUBSTR(u.mobile_e164,5,1) WHEN '4' THEN 'dialog' WHEN '7' THEN 'dialog' WHEN '6' THEN 'dialog' WHEN '0' THEN 'mobitel' WHEN '1' THEN 'mobitel' WHEN '5' THEN 'airtel' WHEN '2' THEN 'hutch' WHEN '8' THEN 'hutch' ELSE 'other' END ELSE 'other' END";
    private PDO $db;
    public function __construct(PDO $db) { $this->db=$db; }
    private function rows(string $sql,array $params=[]): array {
        $statement=$this->db->prepare($sql); $statement->execute($params); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function report(): array {
        $now=date('Y-m-d H:i:s'); $provider=self::PROVIDER;
        $todayStart=date('Y-m-d').' 00:00:00'; $tomorrow=date('Y-m-d',strtotime('+1 day')).' 00:00:00';
        // User status and operator are sourced directly from the users table.
        $groups=$this->rows("SELECT provider, account_status, phone_verified_real, COUNT(*) total FROM (SELECT $provider provider, u.account_status, u.phone_verified_real FROM users u) user_states GROUP BY provider,account_status,phone_verified_real");
        $summary=['total'=>0,'active'=>0,'inactive'=>0,'unsubscribed'=>0,'pending'=>0,'blocked'=>0];
        $funnel=[]; $platform=[]; $pin=['total'=>0,'entered'=>0,'pending'=>0];
        foreach ($groups as $group) {
            $key=$group['provider']; $count=(int)$group['total']; $state=$group['account_status'];
            if (!isset($funnel[$key])) $funnel[$key]=['total'=>0,'active'=>0,'inactive'=>0,'blocked'=>0,'pin'=>0,'charges'=>0];
            $verified=(int)$group['phone_verified_real']===1;
            $summary['total']+=$count;
            if (isset($summary[$state])) $summary[$state]+=$count;
            $pin['total']+=$count; $pin[$verified?'entered':'pending']+=$count;
            $funnel[$key]['total']+=$count;
            if (isset($funnel[$key][$state])) $funnel[$key][$state]+=$count;
            if ($verified) $funnel[$key]['pin']+=$count;
        }
        $todayGroups=$this->rows("SELECT provider, account_status, phone_verified_real, COUNT(*) total FROM (SELECT $provider provider, u.account_status, u.phone_verified_real FROM users u WHERE u.created_at>=? AND u.created_at<?) today_user_states GROUP BY provider,account_status,phone_verified_real",[$todayStart,$tomorrow]);
        $todayFunnel=[]; $todayPin=['total'=>0,'entered'=>0,'pending'=>0];
        foreach ($todayGroups as $group) {
            $key=$group['provider']; $count=(int)$group['total']; $state=$group['account_status'];
            if (!isset($todayFunnel[$key])) $todayFunnel[$key]=['total'=>0,'active'=>0,'inactive'=>0,'blocked'=>0,'pin'=>0,'charges'=>0];
            $verified=(int)$group['phone_verified_real']===1;
            $todayFunnel[$key]['total']+=$count;
            if (isset($todayFunnel[$key][$state])) $todayFunnel[$key][$state]+=$count;
            $todayPin['total']+=$count; $todayPin[$verified?'entered':'pending']+=$count;
            if ($verified) $todayFunnel[$key]['pin']+=$count;
        }
        // API platform is determined by the MSISDN prefix. Account status is
        // sourced from users so the platform totals include users before or
        // between provider subscription callbacks.
        $platformCase="CASE
            WHEN SUBSTR(u.mobile_e164,1,5) IN ('+9470','+9471') THEN 'mspace'
            WHEN SUBSTR(u.mobile_e164,1,5) IN ('+9474','+9477','+9476','+9472','+9478','+9475') THEN 'ideamart'
            ELSE 'other'
        END";
        $platformRows=$this->rows("SELECT $platformCase provider, COUNT(*) total, SUM(CASE WHEN u.account_status='active' THEN 1 ELSE 0 END) active, SUM(CASE WHEN u.account_status<>'active' THEN 1 ELSE 0 END) inactive, SUM(CASE WHEN u.account_status='blocked' THEN 1 ELSE 0 END) blocked FROM users u GROUP BY $platformCase");
        foreach ($platformRows as $row) $platform[$row['provider']]=$row;
        $charges=$this->rows("SELECT $provider provider, COUNT(*) attempts FROM payment_transactions p JOIN users u ON u.id=p.user_id WHERE p.is_simulated=0 GROUP BY $provider");
        foreach ($charges as $row) if (isset($funnel[$row['provider']])) $funnel[$row['provider']]['charges']=(int)$row['attempts'];
        $chargeSql="SELECT COUNT(*) attempts, COALESCE(SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END),0) successful, COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) failed, COALESCE(SUM(CASE WHEN status='authorized' THEN 1 ELSE 0 END),0) authorized, COALESCE(SUM(CASE WHEN status='refunded' THEN 1 ELSE 0 END),0) refunded, COALESCE(SUM(CASE WHEN status NOT IN ('paid','failed','authorized','refunded') THEN 1 ELSE 0 END),0) other, COALESCE(SUM(CASE WHEN status='paid' AND currency='LKR' THEN 1 ELSE 0 END),0) lkr_successful, COALESCE(SUM(CASE WHEN status='paid' AND currency='LKR' THEN amount ELSE 0 END),0) revenue FROM payment_transactions WHERE is_simulated=0";
        $allCharges=$this->rows($chargeSql)[0];
        $todayCharges=$this->rows($chargeSql.' AND created_at>=? AND created_at<?',[$todayStart,$tomorrow])[0];
        $since=date('Y-m-d',strtotime('-13 days')).' 00:00:00';
        $dailyCharges=$this->rows("SELECT SUBSTR(created_at,1,10) day, COUNT(*) attempts, SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) successful, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed, SUM(CASE WHEN status='authorized' THEN 1 ELSE 0 END) authorized, SUM(CASE WHEN status='refunded' THEN 1 ELSE 0 END) refunded, SUM(CASE WHEN status NOT IN ('paid','failed','authorized','refunded') THEN 1 ELSE 0 END) other, SUM(CASE WHEN status='paid' AND currency='LKR' THEN amount ELSE 0 END) revenue FROM payment_transactions WHERE is_simulated=0 AND created_at>=? AND created_at<? GROUP BY SUBSTR(created_at,1,10) ORDER BY day DESC",[$since,$tomorrow]);
        $hourly=$this->rows("SELECT SUBSTR(accepted_at,1,13) hour,COUNT(*) total FROM user_subscriptions WHERE accepted_at>=? AND accepted_at<=? GROUP BY SUBSTR(accepted_at,1,13) ORDER BY hour DESC",[date('Y-m-d H:i:s',time()-86400),$now]);
        // Raw rows intentionally include historical and simulated records. Keep
        // them separate from effective account states so either can be audited.
        $subscriptionRecords=$this->rows("SELECT COUNT(*) active, COALESCE(SUM(CASE WHEN is_simulated=0 THEN 1 ELSE 0 END),0) real_active, COALESCE(SUM(CASE WHEN is_simulated=1 THEN 1 ELSE 0 END),0) simulated_active FROM user_subscriptions WHERE status='active'")[0];
        // Historical transactions may predate failure-reason tracking. Never
        // infer insufficient balance from a generic failed payment.
        $caasReasonsAvailable=SchemaCapabilities::hasColumn($this->db,'payment_transactions','failure_reason');
        $insufficient=$caasReasonsAvailable ? "SUM(CASE WHEN p.status='failed' AND p.failure_reason='insufficient_balance' THEN 1 ELSE 0 END)" : 'NULL';
        $caasRows=$this->rows("SELECT $provider mobile_provider, COUNT(*) attempts, SUM(CASE WHEN p.status='paid' THEN 1 ELSE 0 END) successful, SUM(CASE WHEN p.status='failed' THEN 1 ELSE 0 END) failed, SUM(CASE WHEN p.status='authorized' THEN 1 ELSE 0 END) authorized, SUM(CASE WHEN p.status='refunded' THEN 1 ELSE 0 END) refunded, SUM(CASE WHEN p.status NOT IN ('paid','failed','authorized','refunded') THEN 1 ELSE 0 END) other, $insufficient insufficient FROM payment_transactions p JOIN users u ON u.id=p.user_id WHERE p.is_simulated=0 AND p.provider IN ('ideamart','mspace') GROUP BY $provider");
        $caas=['operators'=>[], 'attempts'=>0, 'successful'=>0, 'failed'=>0, 'authorized'=>0, 'refunded'=>0, 'other'=>0, 'insufficient'=>$caasReasonsAvailable?0:null, 'reasonsAvailable'=>$caasReasonsAvailable];
        foreach ($caasRows as $row) {
            $caas['operators'][$row['mobile_provider']]=$row;
            $caas['attempts']+=(int)$row['attempts'];
            $caas['successful']+=(int)$row['successful'];
            $caas['failed']+=(int)$row['failed'];
            $caas['authorized']+=(int)$row['authorized'];
            $caas['refunded']+=(int)$row['refunded'];
            $caas['other']+=(int)$row['other'];
            if ($caasReasonsAvailable) $caas['insufficient']+=(int)$row['insufficient'];
        }
        return compact('summary','funnel','todayFunnel','platform','pin','todayPin','todayCharges','allCharges','dailyCharges','hourly','subscriptionRecords','caas');
    }
}
