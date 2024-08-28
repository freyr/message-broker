<?php

declare(strict_types=1);

use Freyr\MessageBroker\Handler\SleepWorkMessageNativeHandler;
use Freyr\MessageBroker\Native\Consumer;

require_once __DIR__ . '/../vendor/autoload.php';

$clients = [1,2,3];
$buckets = [1,2,3,4,5];

$queues = [];
foreach ($clients as $clientId) {
    foreach ($buckets as $bucketId) {
        $queues[] = 'client.' . $clientId . '.member_bucket.' . $bucketId;
    }
}

$consumer = new Consumer();
$consumer->batchConsume(
    new SleepWorkMessageNativeHandler(),
    ...$queues,
);
