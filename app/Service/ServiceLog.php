<?php
// Records web service calls in both directions. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

namespace App\Service;

use App\Core\Database;
use Throwable;

// What the IFA's mandatory requestId is for. Recorded at both ends, it lets us
// take an id out of our log and ask a teammate to find the same one in theirs.
//
// Nothing here may break the call it is logging, so every method swallows its
// own exceptions.
final class ServiceLog
{
    public const INBOUND  = 'INBOUND';
    public const OUTBOUND = 'OUTBOUND';

    private function __construct()
    {
    }

    public static function start(
        string $requestId,
        string $direction,
        string $sourceModule,
        string $targetModule,
        string $functionName,
        ?string $requestTimestamp = null
    ): void {
        try {
            Database::getConnection()->prepare(
                'INSERT INTO `WebServiceLog`
                    (`requestId`, `direction`, `sourceModule`, `targetModule`,
                     `functionName`, `requestTimestamp`)
                 VALUES (:requestId, :direction, :sourceModule, :targetModule,
                         :functionName, :requestTimestamp)
                 ON DUPLICATE KEY UPDATE
                     `functionName` = VALUES(`functionName`),
                     `requestTimestamp` = VALUES(`requestTimestamp`)'
            )->execute([
                ':requestId'        => mb_substr($requestId, 0, 36),
                ':direction'        => $direction,
                ':sourceModule'     => mb_substr($sourceModule, 0, 100),
                ':targetModule'     => mb_substr($targetModule, 0, 100),
                ':functionName'     => mb_substr($functionName, 0, 100),
                ':requestTimestamp' => $requestTimestamp ?? Ifa::now(),
            ]);
        } catch (Throwable $e) {
            error_log('ServiceLog start failed: ' . $e->getMessage());
        }
    }

    public static function finish(
        string $requestId,
        string $status,
        ?int $httpStatusCode = null,
        ?string $errorMessage = null
    ): void {
        try {
            Database::getConnection()->prepare(
                'UPDATE `WebServiceLog`
                    SET `responseTimestamp` = :ts,
                        `responseStatus` = :status,
                        `httpStatusCode` = :http,
                        `errorMessage` = :error
                  WHERE `requestId` = :requestId'
            )->execute([
                ':ts'        => Ifa::now(),
                ':status'    => in_array($status, ['S', 'F', 'E'], true) ? $status : 'E',
                ':http'      => $httpStatusCode,
                ':error'     => $errorMessage === null ? null : mb_substr($errorMessage, 0, 500),
                ':requestId' => mb_substr($requestId, 0, 36),
            ]);
        } catch (Throwable $e) {
            error_log('ServiceLog finish failed: ' . $e->getMessage());
        }
    }

    public static function thisModule(): string
    {
        return (string) config('app.module');
    }
}
