<?php

declare(strict_types=1);

namespace Freyr\Messenger\Doctrine\Type;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;
use Freyr\Identity\Id;

final class IdType extends Type
{
    public const string NAME = 'id_binary';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'BINARY(16)';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?Id
    {
        if ($value === null || $value instanceof Id) {
            return $value;
        }

        if (! is_string($value)) {
            throw new \InvalidArgumentException('Expected string value from database');
        }

        return Id::fromBinary($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof Id) {
            throw new \InvalidArgumentException('Expected Id instance');
        }

        return $value->toBinary();
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function requiresSQLCommentHint(AbstractPlatform $platform): bool
    {
        return true;
    }
}
