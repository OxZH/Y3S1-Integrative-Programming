<?php
// Interface Agreement envelope. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Service;

use DateTimeImmutable;

/**
 * The request and response format agreed between modules. Requests carry a
 * requestId and a timeStamp so a call can be traced across two modules' logs.
 * Responses carry a status and a timeStamp.
 *
 * S, F and E mean different things to a caller. S worked. F was understood and
 * refused, so retrying will not help - the booking really is unpaid. E means the
 * serving side broke and the answer is unknown.
 */
final class Ifa
{
    public const STATUS_SUCCESS = 'S';
    public const STATUS_FAIL    = 'F';
    public const STATUS_ERROR   = 'E';

    public const TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    private function __construct()
    {
    }

    public static function now(): string
    {
        return (new DateTimeImmutable())->format(self::TIMESTAMP_FORMAT);
    }

    /** @param array<string,mixed> $params */
    public static function buildRequest(string $function, array $params = []): array
    {
        return array_merge($params, [
            'requestId'    => uuid(),
            'timeStamp'    => self::now(),
            'function'     => $function,
            'sourceModule' => (string) config('app.module'),
        ]);
    }

    /** Read a request from a JSON body, or from query and form fields. */
    public static function readRequest(): array
    {
        $raw = file_get_contents('php://input');

        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return array_merge($_GET, $_POST);
    }

    /**
     * @param array<string,mixed> $request
     * @return string|null the reason it is invalid, or null when it is fine
     */
    public static function validateRequest(array $request): ?string
    {
        $requestId = $request['requestId'] ?? null;

        if (!is_string($requestId) || trim($requestId) === '' || mb_strlen($requestId) > 36) {
            return 'requestId is mandatory and must be 36 characters or fewer.';
        }

        $timeStamp = $request['timeStamp'] ?? null;

        if (!is_string($timeStamp) || !self::isValidTimestamp($timeStamp)) {
            return 'timeStamp is mandatory and must be formatted YYYY-MM-DD HH:MM:SS.';
        }

        return null;
    }

    public static function isValidTimestamp(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!' . self::TIMESTAMP_FORMAT, $value);

        return $parsed !== false && $parsed->format(self::TIMESTAMP_FORMAT) === $value;
    }

    /** @param array<string,mixed> $data */
    public static function success(string $requestId, array $data, string $message = 'OK'): array
    {
        return [
            'status'    => self::STATUS_SUCCESS,
            'requestId' => $requestId,
            'timeStamp' => self::now(),
            'message'   => $message,
            'data'      => $data,
        ];
    }

    public static function fail(string $requestId, string $message): array
    {
        return [
            'status'    => self::STATUS_FAIL,
            'requestId' => $requestId,
            'timeStamp' => self::now(),
            'message'   => $message,
            'data'      => null,
        ];
    }

    // The message is written for another developer, never copied from an
    // exception: a PDO message would hand the caller our table names.
    public static function error(string $requestId, string $message = 'The service encountered an internal error.'): array
    {
        return [
            'status'    => self::STATUS_ERROR,
            'requestId' => $requestId,
            'timeStamp' => self::now(),
            'message'   => $message,
            'data'      => null,
        ];
    }

    /** @param array<string,mixed> $envelope */
    public static function respond(array $envelope, int $httpStatus = 200): never
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            // Stops a browser being talked into rendering the JSON as HTML.
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
            http_response_code($httpStatus);
        }

        echo json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        exit;
    }
}
