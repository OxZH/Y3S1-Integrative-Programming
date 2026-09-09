<?php
// HTTP transport for consumed web services. Author: Goh Jian Yu, Ooi Kean Wei, Ng Jing Siang, Khor Zhi Hong, Ivan Lim Tze Yang

namespace App\Service;

use App\ServiceUnavailableException;

// Builds the IFA envelope, makes the call, checks the reply is the shape it
// promised, logs both ends, and turns anything unexpected into one exception.
//
// Timeouts matter: a teammate's dev server that accepts the connection and then
// goes quiet would otherwise hang our page until PHP's own limit.
//
// Failures raise rather than returning null. A caller that forgets to check a
// null gets a working page with silently wrong data; one that ignores an
// exception gets an obvious failure.
final class ServiceClient
{
    public function call(string $service, string $function, array $params = []): array
    {
        $url    = (string) config("services.$service.url", '');
        $target = (string) config("services.$service.module", $service);

        if ($url === '') {
            throw new ServiceUnavailableException('No endpoint configured for ' . $target . '.');
        }

        $request   = Ifa::buildRequest($function, $params);
        $requestId = (string) $request['requestId'];

        ServiceLog::start(
            $requestId,
            ServiceLog::OUTBOUND,
            ServiceLog::thisModule(),
            $target,
            $function,
            (string) $request['timeStamp']
        );

        [$body, $httpCode, $transportError] = $this->post($url, $request);

        if ($transportError !== null) {
            ServiceLog::finish($requestId, Ifa::STATUS_ERROR, $httpCode ?: null, $transportError);
            error_log(sprintf('%s %s: %s', $target, $function, $transportError));

            throw new ServiceUnavailableException($target . ' is not responding. Please try again shortly.');
        }

        $envelope = json_decode((string) $body, true);

        if (!is_array($envelope) || !isset($envelope['status'])) {
            ServiceLog::finish($requestId, Ifa::STATUS_ERROR, $httpCode, 'Malformed envelope.');
            error_log(sprintf('%s %s returned: %s', $target, $function, mb_substr((string) $body, 0, 300)));

            throw new ServiceUnavailableException($target . ' returned an unexpected response.');
        }

        $status  = (string) $envelope['status'];
        $message = isset($envelope['message']) ? (string) $envelope['message'] : '';

        ServiceLog::finish($requestId, $status, $httpCode, $status === Ifa::STATUS_SUCCESS ? null : $message);

        if ($status === Ifa::STATUS_ERROR) {
            // Their error text can carry file paths and SQL. Log it, do not pass it on.
            error_log(sprintf('%s %s reported E: %s', $target, $function, $message));

            throw new ServiceUnavailableException($target . ' could not complete the request.');
        }

        if ($status === Ifa::STATUS_FAIL) {
            // A refusal is a real answer. Hand it back for the caller to interpret.
            return ['__status' => Ifa::STATUS_FAIL];
        }

        $data = $envelope['data'] ?? [];

        return (is_array($data) ? $data : []) + ['__status' => Ifa::STATUS_SUCCESS];
    }

    public function refused(array $data): bool
    {
        return ($data['__status'] ?? null) === Ifa::STATUS_FAIL;
    }

    private function post(string $url, array $payload): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return [null, 0, 'curl_init failed'];
        }

        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) config('http.timeout', 5),
            CURLOPT_CONNECTTIMEOUT => (int) config('http.connect_timeout', 3),

            // These are fixed endpoints. A redirect means something is wrong.
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body     = curl_exec($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error    = curl_errno($handle) !== 0 ? curl_error($handle) : null;

        curl_close($handle);

        if ($error === null && $httpCode >= 500) {
            $error = 'Remote service returned HTTP ' . $httpCode;
        }

        return [is_string($body) ? $body : null, $httpCode, $error];
    }
}
