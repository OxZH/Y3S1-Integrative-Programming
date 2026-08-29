<?php
// Shared PDO connection. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    public static function getConnection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            config('db.host'),
            (int) config('db.port'),
            config('db.name'),
            config('db.charset')
        );

        try {
            self::$connection = new PDO($dsn, config('db.user'), config('db.pass'), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

                // Real prepared statements. With emulation on, PDO interpolates
                // values itself before MySQL sees them. Note this also means a
                // named placeholder can only appear once per statement.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // The raw message carries the DSN and username.
            error_log('DB connection failed: ' . $e->getMessage());
            throw new RuntimeException('The service is temporarily unavailable.');
        }

        return self::$connection;
    }

    public static function transaction(callable $work): mixed
    {
        $pdo = self::getConnection();
        $pdo->beginTransaction();

        try {
            $result = $work();
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
