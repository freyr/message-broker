<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\DeadLetter;

use Freyr\MessageBroker\Time\EpochMillis;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * One row of the dead_letters table — the uniform, database-backed DLQ
 * shared by all transports (design decision D8).
 *
 * CONSUMER-SIDE ONLY (D17): only consumption failures dead-letter. The
 * outbox/relay path never does — it retries forever.
 *
 * Invariant: each dead letter holds text that each sink can store. The
 * constructor makes source, messageId, messageName, errorClass, errorMessage,
 * errorTrace and each header key and string header value (nested too) valid
 * UTF-8 with no NUL character: each invalid byte sequence and each NUL becomes
 * U+FFFD. Then it cuts source, messageId, messageName and errorClass to 255
 * characters. The body stays the exact bytes. A value that obeys the rules
 * stays unchanged.
 */
final readonly class DeadLetter
{
    private const int MAX_NAME_LENGTH = 255;

    public string $source;        // queue / topic / lane it failed on

    public string $messageId;

    public string $messageName;

    /** @var array<string, mixed> */
    public array $headers;

    public string $errorClass;

    public string $errorMessage;

    public string $errorTrace;

    /** @param array<string, mixed> $headers */
    public function __construct(
        public string $id,
        string $source,
        string $messageId,
        string $messageName,
        public string $body,          // raw bytes as received / as stored
        array $headers,
        string $errorClass,
        string $errorMessage,
        string $errorTrace,
        public int $attempts,
        public int $failedAt,         // epoch milliseconds
        public ?int $replayedAt = null,
        public bool $replayable = true, // false: the body is raw wire bytes, not the canonical document
    ) {
        $this->source = self::name($source);
        $this->messageId = self::name($messageId);
        $this->messageName = self::name($messageName);
        /** @var array<string, mixed> $validHeaders a string key stays a string key */
        $validHeaders = self::headers($headers);
        $this->headers = $validHeaders;
        $this->errorClass = self::name($errorClass);
        $this->errorMessage = self::text($errorMessage);
        $this->errorTrace = self::text($errorTrace);
    }

    /** @param array<string, mixed> $headers */
    public static function fromFailure(
        string $source,
        string $messageId,
        string $messageName,
        string $body,
        array $headers,
        Throwable $error,
        int $attempts,
        bool $replayable = true,
    ): self {
        return new self(
            id: Uuid::v7()->toString(),
            source: $source,
            messageId: $messageId,
            messageName: $messageName,
            body: $body,
            headers: $headers,
            errorClass: $error::class,
            errorMessage: self::flattenMessages($error),
            errorTrace: $error->getTraceAsString(),
            attempts: $attempts,
            failedAt: EpochMillis::now(),
            replayable: $replayable,
        );
    }

    /** The full previous-exception chain, root cause last. */
    private static function flattenMessages(Throwable $error): string
    {
        $messages = [];
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        return implode(' <- ', $messages);
    }

    /** Valid UTF-8 with no NUL character: each invalid byte sequence and each NUL becomes U+FFFD. */
    private static function text(string $value): string
    {
        /** @var string $valid a JSON string decodes to a string */
        $valid = json_decode(
            json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            flags: JSON_THROW_ON_ERROR,
        );

        return str_replace("\0", "\u{FFFD}", $valid);
    }

    /** A text of at most 255 characters, the unit of a VARCHAR(255) column. */
    private static function name(string $value): string
    {
        return mb_substr(self::text($value), 0, self::MAX_NAME_LENGTH, 'UTF-8');
    }

    /**
     * @param array<array-key, mixed> $headers
     *
     * @return array<array-key, mixed> the same keys, with each string key made valid
     */
    private static function headers(array $headers): array
    {
        $valid = [];
        foreach ($headers as $key => $value) {
            $valid[is_string($key) ? self::text($key) : $key] = match (true) {
                is_string($value) => self::text($value),
                is_array($value) => self::headers($value),
                default => $value,
            };
        }

        return $valid;
    }
}
