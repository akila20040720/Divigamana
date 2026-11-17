<?php
declare(strict_types=1);
namespace Divigamana\Services;

use PDO;
use Divigamana\Support\SchemaCapabilities;

/** Live, read-only reporting over the application's existing records. */
final class AdminDashboard {
    private const PROVIDER = "CASE WHEN SUBSTR(u.mobile_e164,1,4) IN ('+947') THEN CASE SUBSTR(u.mobile_e164,5,1) WHEN '4' THEN 'dialog' WHEN '7' THEN 'dialog' WHEN '6' THEN 'dialog' WHEN '0' THEN 'mobitel' WHEN '1' THEN 'mobitel' WHEN '5' THEN 'airtel' WHEN '2' THEN 'hutch' WHEN '8' THEN 'hutch' ELSE 'other' END ELSE 'other' END";
    // Dashboard account state is defined by the users table.
    public const ACTIVE = "u.account_status='active'";
    public const STATE = "u.account_status";
    private PDO $db;
    public function __construct(PDO $db) { $this->db=$db; }
    private function rows(string $sql, array $params=[]): array {
        $stmt=$this->db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    /** The directory defaults to all join dates; a phone search also spans all dates. */
    public static function directoryFilters(array $filters): array {
        $day=(string)($filters['day']??'');
        $date=($filters['search']??'')==='' ? $day : '';
        $filters['from']=$date;
        $filters['to']=$date;
        return $filters;
    }
    public function report(array $filters, int $page=1, array $verificationDates=[], int $subscriptionPage=1): array {
        $now=date('Y-m-d H:i:s');
        $active=self::ACTIVE; $provider=self::PROVIDER;
        $summary=$this->rows("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN u.account_status<>'blocked' AND $active THEN 1 ELSE 0 END),0) active, COALESCE(SUM(CASE WHEN u.account_status='blocked' THEN 1 ELSE 0 END),0) blocked FROM users u")[0];
        $summary['inactive']=(int)$summary['total']-(int)$summary['active']-(int)$summary['blocked'];
        $funnel=$this->rows("SELECT $provider provider, COUNT(*) total, SUM(CASE WHEN u.phone_verified_real=1 THEN 1 ELSE 0 END) verified, SUM(CASE WHEN u.account_status='active' THEN 1 ELSE 0 END) active, SUM(CASE WHEN u.account_status='inactive' THEN 1 ELSE 0 END) inactive, SUM(CASE WHEN u.account_status='blocked' THEN 1 ELSE 0 END) blocked FROM users u GROUP BY $provider");
        $where=[]; $params=[];
        if ($filters['from']!=='') { $where[]='u.created_at>=?'; $params[]=$filters['from'].' 00:00:00'; }
        if ($filters['to']!=='') { $where[]='u.created_at<?'; $params[]=(new \DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d').' 00:00:00'; }
        if ($filters['provider']!=='') { $where[]="$provider=?"; $params[]=$filters['provider']; }
        if ($filters['verification']==='ideamart') $where[]="u.phone_verified_real=1 AND u.ideamart_subscriber_id IS NOT NULL AND u.ideamart_subscriber_id<>''";
        if ($filters['verification']==='mspace') $where[]="u.phone_verified_real=1 AND u.mspace_subscriber_id IS NOT NULL AND u.mspace_subscriber_id<>''";
        if ($filters['verification']==='unverified') $where[]="(u.phone_verified_real=0 OR ((u.ideamart_subscriber_id IS NULL OR u.ideamart_subscriber_id='') AND (u.mspace_subscriber_id IS NULL OR u.mspace_subscriber_id='')))";
        if ($filters['search']!=='') { $where[]='u.mobile_e164 LIKE ?'; $params[]='%'.$filters['search'].'%'; }
        if (!empty($filters['status'])) { $where[]='('.self::STATE.')=?'; $params[]=$filters['status']; }
        $clause=$where?' WHERE '.implode(' AND ',$where):'';
        $verificationWhere=[]; $verificationParams=[];
        if (!empty($verificationDates['from'])) { $verificationWhere[]='u.created_at>=?'; $verificationParams[]=$verificationDates['from'].' 00:00:00'; }
        if (!empty($verificationDates['to'])) { $verificationWhere[]='u.created_at<?'; $verificationParams[]=(new \DateTimeImmutable($verificationDates['to']))->modify('+1 day')->format('Y-m-d').' 00:00:00'; }
        $verificationClause=$verificationWhere?' WHERE '.implode(' AND ',$verificationWhere):'';
        $verification=$this->rows("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN u.phone_verified_real=1 AND u.ideamart_subscriber_id IS NOT NULL AND u.ideamart_subscriber_id<>'' THEN 1 ELSE 0 END),0) ideamart, COALESCE(SUM(CASE WHEN u.phone_verified_real=1 AND u.mspace_subscriber_id IS NOT NULL AND u.mspace_subscriber_id<>'' THEN 1 ELSE 0 END),0) mspace FROM users u$verificationClause",$verificationParams)[0];
        $count=(int)$this->rows("SELECT COUNT(*) total FROM users u$clause",$params)[0]['total']; $pages=max(1,(int)ceil($count/25)); $page=max(1,min($page,$pages)); $offset=($page-1)*25;
        $firstLogin=SchemaCapabilities::hasColumn($this->db,'users','first_login_at')?'u.first_login_at':'NULL AS first_login_at';
        // The directory also shows the latest stored rows separately. They
        // are not used to infer payment from subscription state or vice versa.
        $users=$this->rows("SELECT u.id,u.mobile_e164,u.account_status,u.phone_verified_real,u.ideamart_subscriber_id,u.mspace_subscriber_id,u.created_at,$firstLogin,u.last_login_at,$provider provider,".self::STATE." AS display_status, directory_sub.id AS directory_sub_id,directory_sub.status AS sub_status,directory_sub.provider AS sub_provider,directory_sub.provider_status AS sub_provider_status,directory_sub.is_simulated AS sub_simulated,directory_payment.id AS payment_id,directory_payment.status AS payment_status,directory_payment.amount AS payment_amount,directory_payment.currency AS payment_currency,directory_payment.provider AS payment_provider,directory_payment.is_simulated AS payment_simulated FROM users u LEFT JOIN user_subscriptions directory_sub ON directory_sub.id=(SELECT MAX(latest_sub.id) FROM user_subscriptions latest_sub WHERE latest_sub.user_id=u.id) LEFT JOIN payment_transactions directory_payment ON directory_payment.id=(SELECT MAX(latest_payment.id) FROM payment_transactions latest_payment WHERE latest_payment.user_id=u.id)$clause ORDER BY u.created_at DESC,u.id DESC LIMIT 25 OFFSET $offset",$params);
        $day=($filters['day']??'')!=='' ? $filters['day'] : date('Y-m-d');
        $dayStart=$day.' 00:00:00'; $dayEnd=(new \DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d').' 00:00:00';
        $dailyNew=(int)$this->rows('SELECT COUNT(*) total FROM users WHERE created_at>=? AND created_at<?',[$dayStart,$dayEnd])[0]['total'];
        $dailyLogins=SchemaCapabilities::hasTable($this->db,'audit_events') ? (int)$this->rows("SELECT COUNT(*) total FROM audit_events WHERE event_type='login_success' AND created_at>=? AND created_at<?",[$dayStart,$dayEnd])[0]['total'] : null;
        $since=date('Y-m-d H:i:s',time()-86400);
        $subscriptions=$this->rows("SELECT s.provider,s.status,COUNT(*) total FROM user_subscriptions s WHERE s.accepted_at>=? AND s.accepted_at<=? GROUP BY s.provider,s.status ORDER BY s.provider,s.status",[$since,$now]);
        $subscriptionCount=(int)$this->rows("SELECT COUNT(*) total FROM user_subscriptions s JOIN users u ON u.id=s.user_id WHERE s.accepted_at>=? AND s.accepted_at<=?",[$since,$now])[0]['total'];
        $subscriptionPages=max(1,(int)ceil($subscriptionCount/25));
        $subscriptionPage=max(1,min($subscriptionPage,$subscriptionPages));
        $subscriptionOffset=($subscriptionPage-1)*25;
        $subscriptionRows=$this->rows("SELECT s.accepted_at,u.mobile_e164,s.provider,s.status FROM user_subscriptions s JOIN users u ON u.id=s.user_id WHERE s.accepted_at>=? AND s.accepted_at<=? ORDER BY s.accepted_at DESC,s.id DESC LIMIT 25 OFFSET $subscriptionOffset",[$since,$now]);
        return compact('summary','funnel','verification','users','subscriptions','count','pages','page','subscriptionCount','subscriptionPages','subscriptionPage','subscriptionRows','dailyNew','dailyLogins');
    }
}
