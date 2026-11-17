<?php
declare(strict_types=1);
namespace Divigamana\Services;

use Divigamana\Support\SchemaCapabilities;
use PDO;

/** Persist provider outcomes when the optional reporting migration is installed. */
final class ChargeOutcome {
    public static function failureReason(string $provider, string $code): ?string {
        // https://docs.ideamart.io/developer-docs/response-codes/
        // mSpace has its own codes; do not assume IdeaMart codes apply there.
        return $provider==='ideamart' && strtoupper(trim($code))==='E1378' ? 'insufficient_balance' : null;
    }

    /** SQL assignments and values, appended to the same atomic status update. */
    public static function assignments(PDO $db, string $provider, string $code, bool $failed): array {
        $code=strtoupper(trim($code));
        $code=preg_match('/^[A-Z0-9._-]{1,32}$/',$code) ? $code : null;
        $sql=''; $params=[];
        try {
            if (SchemaCapabilities::hasColumn($db,'payment_transactions','provider_status_code')) {
                $sql.=',provider_status_code=?'; $params[]=$code;
            }
            if (SchemaCapabilities::hasColumn($db,'payment_transactions','failure_reason')) {
                $sql.=',failure_reason=?'; $params[]=$failed && $code!==null ? self::failureReason($provider,$code) : null;
            }
        } catch (\Throwable $error) {
            // Optional analytics must not change the result of a real debit.
            error_log('CAAS outcome metadata unavailable: '.$error->getMessage());
            return ['',[]];
        }
        return [$sql,$params];
    }

    /** Update the actual payment even when optional analytics fields cannot be written. */
    public static function update(PDO $db, string $set, string $where, array $values, array $keys, string $provider, string $code, bool $failed): \PDOStatement {
        [$extra,$extraValues]=self::assignments($db,$provider,$code,$failed);
        try {
            $statement=$db->prepare($set.$extra.$where);
            $statement->execute(array_merge($values,$extraValues,$keys));
            return $statement;
        } catch (\PDOException $error) {
            if ($extra==='') throw $error;
            error_log('CAAS outcome tracking unavailable: '.$error->getMessage());
            $statement=$db->prepare($set.$where);
            $statement->execute(array_merge($values,$keys));
            return $statement;
        }
    }
}
