<?php

declare(strict_types=1);

use Freyr\MessageBroker\Native\Configurator;
use PhpAmqpLib\Exchange\AMQPExchangeType;

require_once __DIR__ . '/../vendor/autoload.php';

$c = new Configurator();
$c->initialize();

$c->createExchange('import', AMQPExchangeType::DIRECT);

$clients = [1,2,3];
$buckets = [1,2,3,4,5];

foreach ($clients as $clientId) {
    foreach ($buckets as $bucketId) {
        $queue = 'client.' . $clientId . '.member_bucket.' . $bucketId;
        $c->createQueue($queue);
        $c->bindQueueToExchange($queue, 'import', $queue);
    }
}
