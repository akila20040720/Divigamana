<?php
declare(strict_types=1);
namespace Divigamana\Services;

use PDO;
use Divigamana\Support\SchemaCapabilities;

/** Aggregates the persisted subscriber and real charge records for the admin overview. */
final class DashboardMetrics {
    private const PROVIDER = "CASE WHEN SUBSTR(u.mobile_e164,1,4)='+947' THEN CASE SUBSTR(u.mobile_e164,5,1) WHEN '4' THEN 'dialog' WHEN '7' THEN 'dialog' WHEN '6' THEN 'dialog' WHEN '0' THEN 'mobitel' WHEN '1' THEN 'mobitel' WHEN '5' THEN 'airtel' WHEN '2' THEN 'hutch' WHEN '8' THEN 'hutch' ELSE 'other' END ELSE 'other' END";
    private PDO $db;
    public function __construct(PDO $db) { $this->db=$db; }
    private function rows(string $sql,array $params=[]): array {
        $statement=$this->db->prepare($sql); $statement->execute($params); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    public function report(): array {
        $now=date('Y-m-d H:i:s'); $provider=self::PROVIDER; $state=AdminDashboard::STATE;
        // Aggregate in the database; state matches the directory, including blocked precedence.
        $groups=$this->rows("SELECT provider, state, phone_verified_real, COUNT(*) total FROM (SELECT $provider provider, $state state, u.phone_verified_real FROM users u) subscriber_states GROUP BY provider,state,phone_verified_real");
        $summary=['total'=>0,'active'=>0,'inactive'=>0,'unsubscribed'=>0,'pending'=>0,'blocked'=>0];
        $funnel=[]; $platform=[]; $pin=['total'=>0,'entered'=>0,'pending'=>0];
        foreach ($groups as $group) {
            $key=$group['provider']; $count=(int)$group['total']; $state=$group['state'];
            if (!isset($funnel[$key])) $funnel[$key]=['total'=>0,'active'=>0,'inactive'=>0,'blocked'=>0,'pin'=>0,'charges'=>0];
            $verified=(int)$group['phone_verified_real']===1;
            $summary['total']+=$count;
            $summary[$state==='number_only'?'pending':($state==='inactive'?'unsubscribed':$state)]+=$count;
            if ($state==='inactive' || $state==='number_only') $summary['inactive']+=$count;
            $pin['total']+=$count; $pin[$verified?'entered':'pending']+=$count;
            $funnel[$key]['total']+=$count;
            if ($state==='active') $funnel[$key]['active']+=$count;
            elseif ($state!=='blocked') $funnel[$key]['inactive']+=$count;
            else $funnel[$key]['blocked']+=$count;
            if ($verified) $funnel[$key]['pin']+=$count;
        }
        // A user's IdeaMart and mSpace rows can have different states. Count
        // the latest real record for each platform independently.
        $platformRows=$this->rows("SELECT s.provider, COUNT(*) total, SUM(CASE WHEN u.account_status<>'blocked' AND s.status='active' THEN 1 ELSE 0 END) active, SUM(CASE WHEN u.account_status<>'blocked' AND s.status<>'active' THEN 1 ELSE 0 END) inactive, SUM(CASE WHEN u.account_status='blocked' THEN 1 ELSE 0 END) blocked FROM user_subscriptions s JOIN users u ON u.id=s.user_id WHERE s.is_simulated=0 AND s.provider IN ('ideamart','mspace') AND NOT EXISTS (SELECT 1 FROM user_subscriptions newer WHERE newer.user_id=s.user_id AND newer.provider=s.provider AND newer.is_simulated=0 AND newer.id>s.id) GROUP BY s.provider");
        foreach ($platformRows as $row) $platform[$row['provider']]=$row;
        $charges=$this->rows("SELECT $provider provider, COUNT(*) attempts FROM payment_transactions p JOIN users u ON u.id=p.user_id WHERE p.is_simulated=0 GROUP BY $provider");
        foreach ($charges as $row) if (isset($funnel[$row['provider']])) $funnel[$row['provider']]['charges']=(int)$row['attempts'];
        $chargeSql="SELECT COUNT(*) attempts, COALESCE(SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END),0) successful, COALESCE(SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END),0) failed, COALESCE(SUM(CASE WHEN status='authorized' THEN 1 ELSE 0 END),0) authorized, COALESCE(SUM(CASE WHEN status='refunded' THEN 1 ELSE 0 END),0) refunded, COALESCE(SUM(CASE WHEN status NOT IN ('paid','failed','authorized','refunded') THEN 1 ELSE 0 END),0) other, COALESCE(SUM(CASE WHEN status='paid' AND currency='LKR' THEN 1 ELSE 0 END),0) lkr_successful, COALESCE(SUM(CASE WHEN status='paid' AND currency='LKR' THEN amount ELSE 0 END),0) revenue FROM payment_transactions WHERE is_simulated=0";
        $allCharges=$this->rows($chargeSql)[0];
        $today=date('Y-m-d').' 00:00:00'; $tomorrow=date('Y-m-d',strtotime('+1 day')).' 00:00:00';
        $todayCharges=$this->rows($chargeSql.' AND created_at>=? AND created_at<?',[$today,$tomorrow])[0];
        $since=date('Y-m-d',strtotime('-13 days')).' 00:00:00';
        $dailyCharges=$this->rows("SELECT SUBSTR(created_at,1,10) day, COUNT(*) attempts, SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) successful, SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) failed, SUM(CASE WHEN status='authorized' THEN 1 ELSE 0 END) authorized, SUM(CASE WHEN status='refunded' THEN 1 ELSE 0 END) refunded, SUM(CASE WHEN status NOT IN ('paid','failed','authorized','refunded') THEN 1 ELSE 0 END) other, SUM(CASE WHEN status='paid' AND currency='LKR' THEN amount ELSE 0 END) revenue FROM payment_transactions WHERE is_simulated=0 AND created_at>=? AND created_at<? GROUP BY SUBSTR(created_at,1,10) ORDER BY day DESC",[$since,$tomorrow]);
        $hourly=$this->rows("SELECT SUBSTR(accepted_at,1,13) hour,COUNT(*) total FROM user_subscriptions WHERE accepted_at>=? AND accepted_at<=? GROUP BY SUBSTR(accepted_at,1,13) ORDER BY hour DESC",[date('Y-m-d H:i:s',time()-86400),$now]);
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
        return compact('summary','funnel','platform','pin','todayCharges','allCharges','dailyCharges','hourly','caas');
    }
}
