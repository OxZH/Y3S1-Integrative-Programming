<?php
// Sends mail over SMTP. Author: Ivan Lim Tze Yang

declare(strict_types=1);

namespace App\Service;

use RuntimeException;
use Throwable;

/**
 * A small SMTP client, written against the protocol directly because this
 * project has no Composer dependencies - composer.json declares none, and
 * adding one would mean every teammate has to run `composer install` before the
 * site runs at all.
 *
 * It speaks enough SMTP for one job: connect, optionally upgrade to TLS,
 * authenticate, send one message, quit. It supports STARTTLS on port 587 and
 * implicit TLS on 465, which between them cover Gmail, Outlook, Mailtrap and
 * most university mail servers.
 *
 * PHP's own mail() is deliberately not used. On Windows it needs a reachable
 * SMTP relay configured in php.ini, gives no usable error when that fails, and
 * cannot authenticate - which rules out every mail provider worth using.
 *
 * Nothing here throws at the caller. Mail is best effort: a password reset must
 * still be recorded, and the link still written to the local mailbox file, when
 * the mail server is unreachable. send() returns false and logs the reason.
 */
final class Mailer
{
    private const CONNECT_TIMEOUT = 8;
    private const REPLY_TIMEOUT    = 10;

    /** @var resource|null */
    private $socket = null;

    /** True when the site has been given somewhere to send mail. */
    public static function isConfigured(): bool
    {
        return (string) config('mail.host', '') !== '';
    }

    /**
     * @param string $to      a single recipient address
     * @param string $text    the plain text body
     * @param string $html    the HTML body; both are sent, the client picks
     * @return bool whether the server accepted the message
     */
    public static function send(string $to, string $subject, string $text, string $html = ''): bool
    {
        if (!self::isConfigured()) {
            error_log('Mailer: no mail.host configured, nothing sent.');

            return false;
        }

        $mailer = new self();

        try {
            return $mailer->deliver($to, $subject, $text, $html);
        } catch (Throwable $e) {
            error_log('Mailer: ' . $e->getMessage());

            return false;
        } finally {
            $mailer->disconnect();
        }
    }

    private function deliver(string $to, string $subject, string $text, string $html): bool
    {
        // A header is one line; a newline inside any of these would let the
        // value inject headers of its own - a second Bcc, say. Refuse instead
        // of trying to clean it.
        foreach ([$to, $subject] as $headerValue) {
            if (preg_match('/[\r\n]/', $headerValue) === 1) {
                throw new RuntimeException('Refusing to send: a header value contains a line break.');
            }
        }

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Refusing to send: the recipient is not a valid address.');
        }

        $host       = (string) config('mail.host');
        $port       = (int) config('mail.port', 587);
        $encryption = strtolower((string) config('mail.encryption', 'tls'));
        $username   = (string) config('mail.username', '');
        $password   = (string) config('mail.password', '');
        $from       = (string) config('mail.from', 'no-reply@localhost');
        $fromName   = (string) config('mail.from_name', 'SportsPlatform');

        // Port 465 is TLS from the first byte; 587 starts in the clear and is
        // upgraded with STARTTLS below.
        $target = $encryption === 'ssl' ? 'ssl://' . $host : $host;

        $errorNumber  = 0;
        $errorMessage = '';
        $socket = @fsockopen($target, $port, $errorNumber, $errorMessage, self::CONNECT_TIMEOUT);

        if ($socket === false) {
            throw new RuntimeException(sprintf('Could not reach %s:%d - %s', $host, $port, $errorMessage));
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, self::REPLY_TIMEOUT);

        $this->expect('220');

        $helo = $this->heloName();
        $this->command('EHLO ' . $helo, '250');

        if ($encryption === 'tls') {
            $this->command('STARTTLS', '220');

            $ok = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT
            );

            if ($ok !== true) {
                throw new RuntimeException('The server would not start TLS.');
            }

            // The conversation restarts once encrypted: anything the server
            // advertised before the upgrade cannot be trusted.
            $this->command('EHLO ' . $helo, '250');
        }

        if ($username !== '') {
            $this->command('AUTH LOGIN', '334');
            $this->command(base64_encode($username), '334');
            // A wrong password fails here, and the reply says so in the log.
            $this->command(base64_encode($password), '235');
        }

        $this->command('MAIL FROM:<' . $from . '>', '250');
        $this->command('RCPT TO:<' . $to . '>', '250');
        $this->command('DATA', '354');

        $this->write($this->message($to, $subject, $text, $html, $from, $fromName));
        $this->expect('250');

        $this->command('QUIT', '221');

        return true;
    }

    /**
     * A multipart/alternative message: the plain text first, then the HTML.
     * A client that cannot render HTML still shows a readable mail with a
     * working link in it.
     */
    private function message(
        string $to,
        string $subject,
        string $text,
        string $html,
        string $from,
        string $fromName
    ): string {
        $boundary = 'sp-' . bin2hex(random_bytes(12));
        $eol      = "\r\n";

        // Non-ASCII in a subject has to be encoded, or it arrives as mojibake.
        $encodedSubject = preg_match('/[\x80-\xFF]/', $subject) === 1
            ? '=?UTF-8?B?' . base64_encode($subject) . '?='
            : $subject;

        $headers = [
            'From: ' . $this->addressHeader($fromName, $from),
            'To: ' . $to,
            'Subject: ' . $encodedSubject,
            'Date: ' . date(DATE_RFC2822),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->heloName() . '>',
            'MIME-Version: 1.0',
        ];

        if ($html === '') {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';

            return implode($eol, $headers) . $eol . $eol . chunk_split(base64_encode($text));
        }

        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $body = '--' . $boundary . $eol
              . 'Content-Type: text/plain; charset=UTF-8' . $eol
              . 'Content-Transfer-Encoding: base64' . $eol . $eol
              . chunk_split(base64_encode($text)) . $eol
              . '--' . $boundary . $eol
              . 'Content-Type: text/html; charset=UTF-8' . $eol
              . 'Content-Transfer-Encoding: base64' . $eol . $eol
              . chunk_split(base64_encode($html)) . $eol
              . '--' . $boundary . '--';

        return implode($eol, $headers) . $eol . $eol . $body;
    }

    private function addressHeader(string $name, string $address): string
    {
        $name = trim(str_replace(['"', "\r", "\n"], '', $name));

        return $name === '' ? $address : sprintf('"%s" <%s>', $name, $address);
    }

    /**
     * A line starting with a single dot means "end of message" in SMTP, so any
     * real line that begins with one gets a second dot added. Without this a
     * message body could truncate itself.
     */
    private function write(string $data): void
    {
        $data = preg_replace('/^\./m', '..', $data) ?? $data;

        $this->raw($data . "\r\n.\r\n");
    }

    private function command(string $command, string $expected): void
    {
        $this->raw($command . "\r\n");
        $this->expect($expected, $command);
    }

    private function raw(string $data): void
    {
        if ($this->socket === null || fwrite($this->socket, $data) === false) {
            throw new RuntimeException('The connection to the mail server was lost.');
        }
    }

    /** Reads a reply, following the multi-line "250-" continuation form. */
    private function expect(string $code, string $after = 'connect'): void
    {
        $reply = '';

        while ($this->socket !== null && !feof($this->socket)) {
            $line = fgets($this->socket, 1024);

            if ($line === false) {
                break;
            }

            $reply .= $line;

            // "250-..." means more lines follow; "250 ..." is the last one.
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        if (!str_starts_with(trim($reply), $code)) {
            // Never logs the AUTH lines, which carry the credentials base64'd.
            $safe = str_starts_with($after, 'AUTH') || preg_match('/^[A-Za-z0-9+\/=]+$/', $after) === 1
                ? '(authentication step)'
                : $after;

            throw new RuntimeException(sprintf(
                'Expected %s after %s, got: %s',
                $code,
                $safe,
                trim($reply) === '' ? '(no reply - timed out)' : trim($reply)
            ));
        }
    }

    /** The name we announce ourselves as. Must not be empty. */
    private function heloName(): string
    {
        $host = (string) parse_url((string) config('app.base_url'), PHP_URL_HOST);

        return $host === '' || $host === 'localhost' ? 'sportsplatform.local' : $host;
    }

    private function disconnect(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }
}
