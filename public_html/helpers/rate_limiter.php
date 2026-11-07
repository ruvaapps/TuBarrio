<?php
// helpers/rate_limiter.php
require_once __DIR__ . '/../config/database.php';

class RateLimiter
{
    private static $tableReady = false;

    private static function ensureTable(PDO $db)
    {
        if (self::$tableReady) {
            return;
        }

        $db->exec("CREATE TABLE IF NOT EXISTS api_rate_limits (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            key_hash CHAR(64) NOT NULL,
            window_start INT UNSIGNED NOT NULL,
            attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_arl_key_window (key_hash, window_start),
            INDEX idx_arl_window (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        self::$tableReady = true;
    }

    private static function cleanupOldWindows(PDO $db, int $windowSeconds)
    {
        // Limpieza probabilística para mantener costo bajo en cada request.
        if (random_int(1, 50) !== 1) {
            return;
        }

        $threshold = time() - max($windowSeconds * 6, 3600);
        $stmt = $db->prepare('DELETE FROM api_rate_limits WHERE window_start < :threshold');
        $stmt->execute([':threshold' => $threshold]);
    }

    public static function consume(string $key, int $maxAttempts, int $windowSeconds): array
    {
        $db = Database::getConnection();
        self::ensureTable($db);
        self::cleanupOldWindows($db, $windowSeconds);

        $windowStart = (int) (floor(time() / $windowSeconds) * $windowSeconds);
        $keyHash = hash('sha256', $key);

        $stmtUpsert = $db->prepare("INSERT INTO api_rate_limits (key_hash, window_start, attempt_count)
                                    VALUES (:key_hash, :window_start, 1)
                                    ON DUPLICATE KEY UPDATE attempt_count = attempt_count + 1");
        $stmtUpsert->execute([
            ':key_hash' => $keyHash,
            ':window_start' => $windowStart
        ]);

        $stmtRead = $db->prepare('SELECT attempt_count FROM api_rate_limits WHERE key_hash = :key_hash AND window_start = :window_start LIMIT 1');
        $stmtRead->execute([
            ':key_hash' => $keyHash,
            ':window_start' => $windowStart
        ]);

        $attempts = (int) ($stmtRead->fetchColumn() ?: 0);
        $remaining = max($maxAttempts - $attempts, 0);
        $retryAfter = ($windowStart + $windowSeconds) - time();

        return [
            'allowed' => $attempts <= $maxAttempts,
            'attempts' => $attempts,
            'remaining' => $remaining,
            'retry_after' => max($retryAfter, 1)
        ];
    }
}

