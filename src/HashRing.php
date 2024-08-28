<?php

declare(strict_types=1);

namespace Freyr\MessageBroker;

use Ramsey\Uuid\Uuid;

class HashRing
{

    public function calculateBucket(Uuid $uuid): int
    {
        $hash = Hash::convert($uuid);
        return $this->findNearest($hash);
    }

    private function findNearest(int $hash): int
    {
        $hashRing = [10, 22, 35, 48, 53, 60, 72, 81, 90, 99];
        $filteredArray = array_filter($hashRing, fn($value) => $value > $hash);
        return min($filteredArray);
    }
}
