<?php
namespace Divigamana\Support;
final class Csrf { public static function token(): string { return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32)); } public static function verify(?string $t): bool { return is_string($t)&&isset($_SESSION['_csrf'])&&hash_equals($_SESSION['_csrf'],$t); } }
