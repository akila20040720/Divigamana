<?php
declare(strict_types=1);
namespace Divigamana\Support;

use PDO;

/** Read-only checks for optional features on databases awaiting migration. */
final class SchemaCapabilities {
    public static function hasColumn(PDO $db, string $table, string $column): bool {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') {
            // SQLite does not accept a placeholder in PRAGMA table_info.
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) throw new \InvalidArgumentException('Invalid table name');
            foreach ($db->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['name']===$column) return true;
            }
            return false;
        }
        $statement=$db->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
        $statement->execute([$table,$column]);
        return (bool)$statement->fetchColumn();
    }

    public static function hasTable(PDO $db, string $table): bool {
        $sql=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'
            ? "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?"
            : 'SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?';
        $statement=$db->prepare($sql);
        $statement->execute([$table]);
        return (bool)$statement->fetchColumn();
    }
}
