<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Http;

use DateTimeImmutable;
use DateTimeZone;
use Freyr\MessageBroker\Webhook\DeliveryResult;
use Freyr\MessageBroker\Webhook\Subscription;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Contracts\HttpClient\Exception\TimeoutExceptionInterface;
use Throwable;

/**
 * Maps one request to a {@see DeliveryResult}:
 *
 *  - 2xx: delivered;
 *  - 410: refused, reason code `gone`;
 *  - 429 or 503: failed, with the retry time of a `Retry-After` header;
 *  - each other status (also 3xx and the other 4xx): failed;
 *  - a transport error or the deadline: failed;
 *  - an invalid URL, a scheme that is not permitted, a private IP literal or a
 *    secret of a wrong form: refused before the request, reason code
 *    `invalid-destination` or `invalid-secret`.
 *
 * The text is `HTTP <status>` and the first 500 bytes of the response body,
 * or for a transport error the exception class and one fixed category
 * (timeout, connect, dns, tls, blocked). It never holds the URL, a secret or
 * the exception message: Symfony puts the full URL into that message.
 */
final readonly class ResponseClassifier
{
    public const int MAX_BODY_BYTES = 500;

    public const string REASON_GONE = 'gone';
    public const string REASON_INVALID_DESTINATION = 'invalid-destination';
    public const string REASON_INVALID_SECRET = 'invalid-secret';

    public const string CATEGORY_TIMEOUT = 'timeout';
    public const string CATEGORY_CONNECT = 'connect';
    public const string CATEGORY_DNS = 'dns';
    public const string CATEGORY_TLS = 'tls';
    public const string CATEGORY_BLOCKED = 'blocked';

    /**
     * @param array<string, list<string>> $headers the response headers, lower-case names
     * @param int $nowMs the current time, epoch milliseconds (the base of a Retry-After in seconds)
     */
    public function response(int $status, array $headers, string $body, int $requestMs, int $nowMs): DeliveryResult
    {
        $text = self::statusText($status, $body);

        if ($status >= 200 && $status <= 299) {
            return DeliveryResult::delivered($requestMs, $text);
        }
        if ($status === 410) {
            return DeliveryResult::refused(self::REASON_GONE, $requestMs, $text);
        }
        if ($status === 429 || $status === 503) {
            return DeliveryResult::failed($requestMs, $text, self::retryAt($headers['retry-after'][0] ?? '', $nowMs));
        }

        return DeliveryResult::failed($requestMs, $text);
    }

    public function transportError(Throwable $error, int $requestMs): DeliveryResult
    {
        return DeliveryResult::failed($requestMs, \sprintf('%s (%s)', $error::class, $this->category($error)));
    }

    /** A host name that does not resolve, with the private network check on: no request. */
    public function noAddress(int $requestMs): DeliveryResult
    {
        return DeliveryResult::failed($requestMs, 'no address ('.self::CATEGORY_DNS.')');
    }

    /** A pair whose lookup cannot start or end inside the DNS allowance of the send() call: no request. */
    public function dnsAllowanceUsedUp(int $requestMs): DeliveryResult
    {
        return DeliveryResult::failed($requestMs, 'dns allowance used up ('.self::CATEGORY_DNS.')');
    }

    /** A request that is still open at the deadline of the send() call. */
    public function deadline(int $requestMs): DeliveryResult
    {
        return DeliveryResult::failed($requestMs, 'deadline ('.self::CATEGORY_TIMEOUT.')');
    }

    /**
     * The checks before the request. Returns null when the request can start.
     *
     * @param bool $refusePrivateIp refuse a host that is an IP literal in a private network
     */
    public function beforeRequest(Subscription $subscription, bool $allowHttp, bool $refusePrivateIp): ?DeliveryResult
    {
        $url = parse_url($subscription->url);
        if (!\is_array($url) || !isset($url['scheme'], $url['host']) || $url['host'] === '') {
            return self::invalidDestination('The URL is not valid');
        }
        if (isset($url['user']) || isset($url['pass'])) {
            return self::invalidDestination('The URL holds credentials');
        }
        $scheme = strtolower($url['scheme']);
        if ($scheme !== 'https' && !($allowHttp && $scheme === 'http')) {
            return self::invalidDestination('The URL scheme is not permitted');
        }
        if ($refusePrivateIp && self::isPrivateIp(trim($url['host'], '[]'))) {
            return self::invalidDestination('The host is an IP address in a private network');
        }
        foreach ($subscription->secrets as $secret) {
            if (!StandardWebhooksSigner::isValidSecret($secret)) {
                return DeliveryResult::refused(
                    self::REASON_INVALID_SECRET,
                    0,
                    'A secret does not have the form whsec_ plus base64',
                );
            }
        }

        return null;
    }

    private static function invalidDestination(string $text): DeliveryResult
    {
        return DeliveryResult::refused(self::REASON_INVALID_DESTINATION, 0, $text);
    }

    /**
     * The default list of NoPrivateNetworkHttpClient. Its extra quick check
     * (the private and reserved ranges of filter_var()) blocks no address
     * that this list does not hold.
     */
    private static function isPrivateIp(string $host): bool
    {
        // checkIp() is false for a host name.
        return IpUtils::checkIp($host, IpUtils::PRIVATE_SUBNETS);
    }

    private static function statusText(int $status, string $body): string
    {
        $text = 'HTTP '.$status;
        if ($body === '') {
            return $text;
        }
        // The body is not trusted: a fixed maximum, valid UTF-8 and no NUL byte (a text column refuses both).
        $prefix = mb_scrub(substr($body, 0, self::MAX_BODY_BYTES), 'UTF-8');

        return $text.': '.str_replace("\0", '?', $prefix);
    }

    private static function retryAt(string $value, int $nowMs): ?int
    {
        $value = trim($value);
        if (preg_match('/^\d{1,10}\z/', $value) === 1) {
            return $nowMs + (int) $value * 1000;
        }
        $date = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new DateTimeZone('UTC'));
        // createFromFormat() moves an out-of-range field (31 Feb, 99:99:99) forward and reports a warning.
        if ($date === false || DateTimeImmutable::getLastErrors() !== false) {
            return null;
        }

        return (int) $date->format('Uv');
    }

    /**
     * The category comes from the type and from the fixed words of the
     * message, with each quoted part (the URL, the host) removed first. The
     * message itself is never part of a result.
     */
    public function category(Throwable $error): string
    {
        if ($error instanceof TimeoutExceptionInterface) {
            return self::CATEGORY_TIMEOUT;
        }
        $message = strtolower((string) preg_replace('/"[^"]*"/', '', $error->getMessage()));

        return match (true) {
            str_contains($message, 'is blocked for') => self::CATEGORY_BLOCKED,
            str_contains($message, 'timed out'), str_contains($message, 'max duration') => self::CATEGORY_TIMEOUT,
            str_contains($message, 'could not resolve') => self::CATEGORY_DNS,
            str_contains($message, 'failed to connect') => self::CATEGORY_CONNECT,
            preg_match('/\b(open)?ssl\b|\btls\b|certificate/', $message) === 1 => self::CATEGORY_TLS,
            default => self::CATEGORY_CONNECT,
        };
    }
}
