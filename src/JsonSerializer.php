<?php

declare(strict_types=1);

namespace Freyr\MessageBroker;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

class JsonSerializer implements SerializerInterface
{
    private $serializer;

    public function __construct()
    {
        $encoders = [new JsonEncoder()];
        $normalizers = [new ObjectNormalizer()];
        $this->serializer = new Serializer($normalizers, $encoders);
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        $data = $this->serializer->decode($encodedEnvelope['body'], 'json');
        $message = $this->serializer->denormalize($data, $encodedEnvelope['headers']['type']);

        return new Envelope($message);
    }

    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        $data = $this->serializer->normalize($message);
        $encodedMessage = $this->serializer->encode($data, 'json');

        return [
            'body' => $encodedMessage,
            'headers' => [
                'type' => get_class($message),
            ],
        ];
    }
}
