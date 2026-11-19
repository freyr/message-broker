<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Adapter\Http\Tests\Unit;

use Freyr\MessageBroker\Adapter\Http\ResponseClassifier;
use Freyr\MessageBroker\Webhook\DeliveryResultKind;
use Freyr\MessageBroker\Webhook\Subscription;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * One test (or one data set) for each row of the result table of the spec.
 */
final class ResponseClassifierTest extends TestCase
{
    private const string SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    private const string URL = 'https://hooks.example.com/secret-path?token=abc';
    private const int NOW_MS = 1_750_000_000_000;

    // Row "2xx": delivered.

    #[DataProvider('successStatuses')]
    public function testA2xxStatusIsDelivered(int $status): void
    {
        $result = new ResponseClassifier()->response($status, [], 'ok', 42, self::NOW_MS);

        self::assertSame(DeliveryResultKind::Delivered, $result->kind);
        self::assertSame(42, $result->requestMs);
        self::assertSame("HTTP {$status}: ok", $result->text);
        self::assertNull($result->retryAt);
        self::assertNull($result->reasonCode);
    }

    /** @return iterable<string, array{int}> */
    public static function successStatuses(): iterable
    {
        yield '200' => [200];
        yield '204' => [204];
        yield '299' => [299];
    }

    // Row "410": refused for all time, reason code gone.

    public function testA410StatusIsRefusedWithTheReasonCodeGone(): void
    {
        $result = new ResponseClassifier()->response(410, [], 'removed', 7, self::NOW_MS);

        self::assertSame(DeliveryResultKind::Refused, $result->kind);
        self::assertSame('gone', $result->reasonCode);
        self::assertSame(7, $result->requestMs);
        self::assertSame('HTTP 410: removed', $result->text);
    }

    // Row "429 or 503 with Retry-After": failed, with the retry time from the header.

    #[DataProvider('retryAfterStatuses')]
    public function testRetryAfterInSecondsGivesTheRetryTime(int $status): void
    {
        $result = new ResponseClassifier()->response($status, [
            'retry-after' => ['120'],
        ], '', 9, self::NOW_MS);

        self::assertSame(DeliveryResultKind::Failed, $result->kind);
        self::assertSame(self::NOW_MS + 120_000, $result->retryAt);
        self::assertSame(9, $result->requestMs);
        self::assertSame("HTTP {$status}", $result->text);
    }

    #[DataProvider('retryAfterStatuses')]
    public function testRetryAfterAsAnHttpDateGivesTheRetryTime(int $status): void
    {
        $result = new ResponseClassifier()->response(
            $status,
            [
                'retry-after' => ['Wed, 21 Oct 2015 07:28:00 GMT'],
            ],
            '',
            9,
            self::NOW_MS,
        );

        self::assertSame(DeliveryResultKind::Failed, $result->kind);
        self::assertSame(1_445_412_480_000, $result->retryAt);
    }

    /** @return iterable<string, array{int}> */
    public static function retryAfterStatuses(): iterable
    {
        yield '429' => [429];
        yield '503' => [503];
    }

    public function testRetryAfterWithSpacesAroundTheValueIsRead(): void
    {
        $result = new ResponseClassifier()->response(429, [
            'retry-after' => [' 5 '],
        ], '', 1, self::NOW_MS);

        self::assertSame(self::NOW_MS + 5_000, $result->retryAt);
    }

    #[DataProvider('unreadableRetryAfter')]
    public function testARetryAfterThatDoesNotParseGivesNoRetryTime(array $headers): void
    {
        $result = new ResponseClassifier()->response(429, $headers, '', 1, self::NOW_MS);

        self::assertSame(DeliveryResultKind::Failed, $result->kind);
        self::assertNull($result->retryAt);
    }

    /** @return iterable<string, array{array<string, list<string>>}> */
    public static function unreadableRetryAfter(): iterable
    {
        yield 'no header' => [[]];
        yield 'empty' => [[
            'retry-after' => [''],
        ]];
        yield 'negative' => [[
            'retry-after' => ['-5'],
        ]];
        yield 'fraction' => [[
            'retry-after' => ['1.5'],
        ]];
        yield 'text' => [[
            'retry-after' => ['soon'],
        ]];
        yield 'too many digits' => [[
            'retry-after' => ['12345678901'],
        ]];
        yield 'date out of range' => [[
            'retry-after' => ['Mon, 31 Feb 2026 99:99:99 GMT'],
        ]];
        yield 'date with day 32' => [[
            'retry-after' => ['Wed, 32 Oct 2015 07:28:00 GMT'],
        ]];
        yield 'date with a trailing text' => [[
            'retry-after' => ['Wed, 21 Oct 2015 07:28:00 GMT x'],
        ]];
    }

    public function testRetryAfterOfTenDigitsIsRead(): void
    {
        $result = new ResponseClassifier()->response(503, [
            'retry-after' => ['1234567890'],
        ], '', 1, self::NOW_MS);

        self::assertSame(self::NOW_MS + 1_234_567_890_000, $result->retryAt);
    }

    // Row "Each other status, also 3xx and the other 4xx": failed.

    #[DataProvider('otherStatuses')]
    public function testEachOtherStatusIsFailedWithNoRetryTime(int $status): void
    {
        $result = new ResponseClassifier()->response($status, [
            'retry-after' => ['60'],
        ], 'body', 3, self::NOW_MS);

        self::assertSame(DeliveryResultKind::Failed, $result->kind);
        self::assertNull($result->retryAt, 'Only 429 and 503 read Retry-After');
        self::assertNull($result->reasonCode);
        self::assertSame(3, $result->requestMs);
        self::assertSame("HTTP {$status}: body", $result->text);
    }

    /** @return iterable<string, array{int}> */
    public static function otherStatuses(): iterable
    {
        yield '199' => [199];
        yield '300' => [300];
        yield '302' => [302];
        yield '400' => [400];
        yield '401' => [401];
        yield '403' => [403];
        yield '404' => [404];
        yield '409' => [409];
        yield '411' => [411];
        yield '500' => [500];
        yield '502' => [502];
        yield '504' => [504];
    }

    public function testTheTextHoldsTheFirst500BytesOfTheBody(): void
    {
        $body = str_repeat('a', 499).'bc';

        $result = new ResponseClassifier()->response(500, [], $body, 1, self::NOW_MS);

        self::assertSame('HTTP 500: '.str_repeat('a', 499).'b', $result->text);
    }

    public function testTheTextIsValidUtf8WithNoNulByte(): void
    {
        // A 2-byte character cut at byte 500, an invalid byte and a NUL byte.
        $body = "x\0\xff".str_repeat('a', 496).'é';

        $result = new ResponseClassifier()->response(500, [], $body, 1, self::NOW_MS);

        self::assertSame('HTTP 500: x??'.str_repeat('a', 496).'?', $result->text);
    }

    // Row "Timeout, connection error, TLS error": failed, with the class and one fixed category.

    #[DataProvider('transportErrors')]
    public function testATransportErrorIsFailedWithItsClassAndCategory(\Throwable $error, string $text): void
    {
        $result = new ResponseClassifier()->transportError($error, 11);

        self::assertSame(DeliveryResultKind::Failed, $result->kind);
        self::assertSame(11, $result->requestMs);
        self::assertSame($text, $result->text);
        self::assertNull($result->retryAt);
    }

    /** @return iterable<string, array{\Throwable, string}> */
    public static function transportErrors(): iterable
    {
        $transport = TransportException::class;
        $url = '"'.self::URL.'"';

        yield 'idle timeout' => [new TimeoutException('Idle timeout reached for '.$url.'.'), TimeoutException::class.' (timeout)'];
        yield 'max duration' => [new TransportException('Max duration was reached for '.$url.'.'), "{$transport} (timeout)"];
        yield 'curl timeout' => [new TransportException('Operation timed out after 3001 milliseconds for '.$url.'.'), "{$transport} (timeout)"];
        yield 'connect timeout' => [new TransportException('Connection timed out after 3000 milliseconds for '.$url.'.'), "{$transport} (timeout)"];
        yield 'curl dns' => [new TransportException('Could not resolve host: hooks.example.com for '.$url.'.'), "{$transport} (dns)"];
        yield 'native dns' => [new TransportException('Could not resolve host "hooks.example.com".'), "{$transport} (dns)"];
        yield 'refused' => [new TransportException('Failed to connect to hooks.example.com port 443 after 2 ms: Connection refused for '.$url.'.'), "{$transport} (connect)"];
        yield 'connect to a host named ssl' => [new TransportException('Failed to connect to ssl.example.com port 443 after 2 ms: Could not connect to server for '.$url.'.'), "{$transport} (connect)"];
        yield 'reset' => [new TransportException('Recv failure: Connection reset by peer for '.$url.'.'), "{$transport} (connect)"];
        yield 'certificate' => [new TransportException('SSL certificate problem: self-signed certificate for '.$url.'.'), "{$transport} (tls)"];
        yield 'host name mismatch' => [new TransportException('SSL: no alternative certificate subject name matches target host name for '.$url.'.'), "{$transport} (tls)"];
        yield 'handshake' => [new TransportException('OpenSSL SSL_connect: SSL_ERROR_SYSCALL in connection to hooks.example.com:443 for '.$url.'.'), "{$transport} (tls)"];
        yield 'tls alert' => [new TransportException('TLS connect error: error:0A000410:SSL routines::sslv3 alert handshake failure for '.$url.'.'), "{$transport} (tls)"];
        yield 'not a transport error' => [new RuntimeException('Something for '.$url), RuntimeException::class.' (connect)'];
        yield 'a word in the url is not read' => [new TransportException('Recv failure for "https://timed out.example/ssl/Could not resolve/is blocked for".'), "{$transport} (connect)"];
    }

    // Row "A block by the private network check at DNS or connect time": failed, category blocked.

    #[DataProvider('blockedErrors')]
    public function testABlockOfThePrivateNetworkCheckIsFailedWithTheCategoryBlocked(TransportException $error): void
    {
        $result = new ResponseClassifier()->transportError($error, 0);

        self::assertSame(DeliveryResultKind::Failed, $result->kind);
        self::assertSame(TransportException::class.' (blocked)', $result->text);
    }

    /** @return iterable<string, array{TransportException}> */
    public static function blockedErrors(): iterable
    {
        yield 'at dns' => [new TransportException('Host "internal.example.com" is blocked for "'.self::URL.'".')];
        yield 'at connect' => [new TransportException('IP "10.0.0.5" is blocked for "'.self::URL.'".')];
    }

    public function testTheDeadlineIsFailedWithTheCategoryTimeout(): void
    {
        $result = new ResponseClassifier()->deadline(10_000);

        self::assertSame(DeliveryResultKind::Failed, $result->kind);
        self::assertSame(10_000, $result->requestMs);
        self::assertSame('deadline (timeout)', $result->text);
    }

    // Row "A URL that is not valid, an http URL, an IP literal in a private network, a secret of a wrong form": refused.

    #[DataProvider('refusedBeforeRequest')]
    public function testTheChecksBeforeTheRequestRefuseForAllTime(Subscription $subscription, string $reasonCode, string $text): void
    {
        $result = new ResponseClassifier()->beforeRequest($subscription, allowHttp: false, refusePrivateIp: true);

        self::assertNotNull($result);
        self::assertSame(DeliveryResultKind::Refused, $result->kind);
        self::assertSame($reasonCode, $result->reasonCode);
        self::assertSame(0, $result->requestMs);
        self::assertSame($text, $result->text);
    }

    /** @return iterable<string, array{Subscription, string, string}> */
    public static function refusedBeforeRequest(): iterable
    {
        $invalidUrl = 'The URL is not valid';
        $private = 'The host is an IP address in a private network';

        yield 'not a url' => [self::subscription('not a url'), 'invalid-destination', $invalidUrl];
        yield 'empty' => [self::subscription(''), 'invalid-destination', $invalidUrl];
        yield 'no host' => [self::subscription('https:///path'), 'invalid-destination', $invalidUrl];
        yield 'relative' => [self::subscription('//hooks.example.com/x'), 'invalid-destination', $invalidUrl];
        yield 'unparsable' => [self::subscription('https://hooks.example.com:99999/'), 'invalid-destination', $invalidUrl];
        yield 'credentials' => [self::subscription('https://user:pass@hooks.example.com/'), 'invalid-destination', 'The URL holds credentials'];
        yield 'user only' => [self::subscription('https://user@hooks.example.com/'), 'invalid-destination', 'The URL holds credentials'];
        yield 'http' => [self::subscription('http://hooks.example.com/'), 'invalid-destination', 'The URL scheme is not permitted'];
        yield 'ftp' => [self::subscription('ftp://hooks.example.com/'), 'invalid-destination', 'The URL scheme is not permitted'];
        yield 'loopback' => [self::subscription('https://127.0.0.1/'), 'invalid-destination', $private];
        yield 'private 10' => [self::subscription('https://10.1.2.3:8443/'), 'invalid-destination', $private];
        yield 'private 192.168' => [self::subscription('https://192.168.0.10/'), 'invalid-destination', $private];
        yield 'link local' => [self::subscription('https://169.254.169.254/latest'), 'invalid-destination', $private];
        yield 'carrier nat' => [self::subscription('https://100.64.0.1/'), 'invalid-destination', $private];
        yield 'zero' => [self::subscription('https://0.0.0.0/'), 'invalid-destination', $private];
        yield 'ipv6 loopback' => [self::subscription('https://[::1]/'), 'invalid-destination', $private];
        yield 'ipv6 unique local' => [self::subscription('https://[fd00::1]/'), 'invalid-destination', $private];
        yield 'ipv4 mapped ipv6' => [self::subscription('https://[::ffff:127.0.0.1]/'), 'invalid-destination', $private];
        yield 'secret without prefix' => [self::subscription(self::URL, ['MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw']), 'invalid-secret', 'A secret does not have the form whsec_ plus base64'];
        yield 'second secret wrong' => [self::subscription(self::URL, [self::SECRET, 'whsec_!!']), 'invalid-secret', 'A secret does not have the form whsec_ plus base64'];
    }

    #[DataProvider('passBeforeRequest')]
    public function testAValidSubscriptionPassesTheChecksBeforeTheRequest(string $url): void
    {
        self::assertNull(new ResponseClassifier()->beforeRequest(self::subscription($url), allowHttp: false, refusePrivateIp: true));
    }

    /** @return iterable<string, array{string}> */
    public static function passBeforeRequest(): iterable
    {
        yield 'host name' => [self::URL];
        yield 'upper case scheme' => ['HTTPS://hooks.example.com/'];
        yield 'public ipv4' => ['https://93.184.215.14/hook'];
        yield 'public ipv6' => ['https://[2606:2800:21f:cb07:6820:80da:af6b:8b2c]/hook'];
        yield 'host name that resolves later' => ['https://localhost/'];
    }

    public function testAnHttpUrlPassesWhenHttpIsPermitted(): void
    {
        self::assertNull(new ResponseClassifier()->beforeRequest(
            self::subscription('http://hooks.example.com/'),
            allowHttp: true,
            refusePrivateIp: true,
        ));
    }

    public function testAnOtherSchemeIsRefusedAlsoWhenHttpIsPermitted(): void
    {
        $result = new ResponseClassifier()->beforeRequest(
            self::subscription('ftp://hooks.example.com/'),
            allowHttp: true,
            refusePrivateIp: true,
        );

        self::assertSame('The URL scheme is not permitted', $result?->text);
    }

    public function testAPrivateIpLiteralPassesWhenThePrivateNetworkCheckIsOff(): void
    {
        self::assertNull(new ResponseClassifier()->beforeRequest(
            self::subscription('https://10.1.2.3/'),
            allowHttp: false,
            refusePrivateIp: false,
        ));
    }

    public function testTheUrlIsCheckedBeforeTheSecret(): void
    {
        $result = new ResponseClassifier()->beforeRequest(
            self::subscription('http://hooks.example.com/', ['bad']),
            allowHttp: false,
            refusePrivateIp: true,
        );

        self::assertSame('invalid-destination', $result?->reasonCode);
    }

    public function testNoResultTextHoldsTheUrlOrASecret(): void
    {
        $classifier = new ResponseClassifier();
        $texts = [];
        foreach (self::refusedBeforeRequest() as [$subscription]) {
            $texts[] = $classifier->beforeRequest($subscription, allowHttp: false, refusePrivateIp: true)?->text;
        }
        foreach (self::transportErrors() as [$error]) {
            $texts[] = $classifier->transportError($error, 1)->text;
        }
        foreach (self::blockedErrors() as [$error]) {
            $texts[] = $classifier->transportError($error, 1)->text;
        }
        $texts[] = $classifier->deadline(1)->text;
        $texts[] = $classifier->response(500, [], '', 1, self::NOW_MS)->text;

        foreach ($texts as $text) {
            self::assertIsString($text);
            foreach (['hooks.example.com', 'secret-path', 'token=abc', 'internal.example.com', '10.0.0.5', 'MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw', 'whsec_!!', 'user', 'pass', '127.0.0.1'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $text);
            }
        }
    }

    /** @param list<string> $secrets */
    private static function subscription(string $url, array $secrets = [self::SECRET]): Subscription
    {
        return new Subscription('lane-a', $url, $secrets);
    }
}
