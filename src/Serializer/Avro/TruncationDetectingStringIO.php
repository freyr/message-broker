<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Serializer\Avro;

use Apache\Avro\IO\AvroStringIO;

/**
 * AvroStringIO that records short reads so truncated payloads can be rejected.
 *
 * Why this exists: apache/avro's AvroStringIO::read() null-coalesces past the
 * buffer end (it returns however many bytes remain, padding with nothing), and
 * AvroIOBinaryDecoder::readLong() treats the resulting empty string as byte 0.
 * The net effect is that corrupt or truncated binary never throws — it silently
 * decodes to a garbage record full of empty strings and zeros. This subclass
 * flags any read($n) that returns fewer than $n bytes, so AvroDeserializer can
 * detect the truncation after decoding and reject the payload as
 * MalformedMessage instead of handing a fabricated record to the consumer.
 *
 * @internal
 */
final class TruncationDetectingStringIO extends AvroStringIO
{
    public bool $hadShortRead = false;

    public function read(mixed $len): string
    {
        $result = parent::read($len);
        if (is_int($len) && $len > 0 && \strlen($result) < $len) {
            $this->hadShortRead = true;
        }

        return $result;
    }
}
