<?php
declare(strict_types=1);
namespace Divigamana\Services;

use PDO;

/** Live, read-only reporting over the application's existing records. */
final class AdminDashboard {
    private const PROVIDER = "CASE WHEN SUBSTR(u.mobile_e164,1,4) IN ('+947') THEN CASE SUBSTR(u.mobile_e164,5,1) WHEN '4' THEN 'dialog' WHEN '7' THEN 'dialog' WHEN '6' THEN 'dialog' WHEN '0' THEN 'mobitel' WHEN '1' THEN 'mobitel' WHEN '5' THEN 'airtel' WHEN '2' THEN 'hutch' WHEN '8' THEN 'hutch' ELSE 'other' END ELSE 'other' END";
    // A newer row for the same carrier supersedes an older state.
    private const ACTIVE = "EXISTS (SELECT 1 FROM user_subscriptions s WHERE s.user_id=u.id AND s.status='active' AND s.starts_at<=? AND s.ends_at>? AND NOT EXISTS (SELECT 1 FROM user_subscriptions newer WHERE newer.user_id=s.user_id AND newer.provider=s.provider AND newer.id>s.id))";
    public function __construct(private PDO $db) {}
    private function rows(string $sql, array $params=[]): array {
        $stmt=$this->db->prepare($sql); $stmt->execute($params); return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    public function report(array $filters, int $page=1): array {
        $now=date('Y-m-d H:i:s');
        $active=self::ACTIVE; $provider=self::PROVIDER;
        $summary=$this->rows("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN $active THEN 1 ELSE 0 END),0) active, COALESCE(SUM(CASE WHEN u.account_status='blocked' THEN 1 ELSE 0 END),0) blocked FROM users u",[$now,$now])[0];
        $summary['inactive']=(int)$summary['total']-(int)$summary['active'];
        $funnel=$this->rows("SELECT $provider provider, COUNT(*) total, SUM(CASE WHEN u.phone_verified_real=1 THEN 1 ELSE 0 END) verified, SUM(CASE WHEN $active THEN 1 ELSE 0 END) active FROM users u GROUP BY $provider",[$now,$now]);
        $where=[]; $params=[];
        if ($filters['from']!=='') { $where[]='u.created_at>=?'; $params[]=$filters['from'].' 00:00:00'; }
        if ($filters['to']!=='') { $where[]='u.created_at<?'; $params[]=(new \DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d').' 00:00:00'; }
        if ($filters['provider']!=='') { $where[]="$provider=?"; $params[]=$filters['provider']; }
        if ($filters['verification']==='ideamart') $where[]="u.phone_verified_real=1 AND u.ideamart_subscriber_id IS NOT NULL AND u.ideamart_subscriber_id<>''";
        if ($filters['verification']==='mspace') $where[]="u.phone_verified_real=1 AND u.mspace_subscriber_id IS NOT NULL AND u.mspace_subscriber_id<>''";
        if ($filters['verification']==='unverified') $where[]="(u.phone_verified_real=0 OR ((u.ideamart_subscriber_id IS NULL OR u.ideamart_subscriber_id='') AND (u.mspace_subscriber_id IS NULL OR u.mspace_subscriber_id='')))";
        if ($filters['search']!=='') { $where[]='u.mobile_e164 LIKE ?'; $params[]='%'.$filters['search'].'%'; }
        $clause=$where?' WHERE '.implode(' AND ',$where):'';
        $verification=$this->rows("SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN u.phone_verified_real=1 AND u.ideamart_subscriber_id IS NOT NULL AND u.ideamart_subscriber_id<>'' THEN 1 ELSE 0 END),0) ideamart, COALESCE(SUM(CASE WHEN u.phone_verified_real=1 AND u.mspace_subscriber_id IS NOT NULL AND u.mspace_subscriber_id<>'' THEN 1 ELSE 0 END),0) mspace FROM users u$clause",$params)[0];
        $count=(int)$verification['total']; $pages=max(1,(int)ceil($count/25)); $page=max(1,min($page,$pages)); $offset=($page-1)*25;
        $users=$this->rows("SELECT u.mobile_e164,u.account_status,u.phone_verified_real,u.ideamart_subscriber_id,u.mspace_subscriber_id,u.created_at,u.last_login_at,$provider provider FROM users u$clause ORDER BY u.created_at DESC,u.id DESC LIMIT 25 OFFSET $offset",$params);
        $since=date('Y-m-d H:i:s',time()-86400);
        $subscriptions=$this->rows("SELECT s.provider,s.status,COUNT(*) total FROM user_subscriptions s WHERE s.accepted_at>=? AND s.accepted_at<=? GROUP BY s.provider,s.status ORDER BY s.provider,s.status",[$since,$now]);
        return compact('summary','funnel','verification','users','subscriptions','count','pages','page');
    }
}
