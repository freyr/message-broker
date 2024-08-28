<?php

declare(strict_types=1);

use Freyr\MessageBroker\Hash;
use Freyr\MessageBroker\Message\SleepMessage;
use Freyr\MessageBroker\Native\Producer;
use Ramsey\Uuid\Uuid;

require_once __DIR__ . '/../vendor/autoload.php';

$sender = new Producer();
while(true) {
    $clientId = rand(1, 3);
    $duration = 0;#rand(100, 1000);
    $uuid = Uuid::uuid4();
    $bucket = Hash::convert($uuid);
    $key = 'client.'.$clientId.'.member_bucket.'.$bucket;
    $message = new SleepMessage($duration, true);
    $sender->produce($message, $key, 'import');
    time_nanosleep(0, 100000);
}
