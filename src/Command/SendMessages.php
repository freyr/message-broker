<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Command;


use Freyr\MessageBroker\Hash;
use Freyr\MessageBroker\Message\SleepMessage;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\MessageBusInterface;


class SendMessages extends Command
{
    public function __construct(private readonly MessageBusInterface $bus)
    {
        parent::__construct();

    }
    protected function configure(): void
    {
        // Configure the command
        $this
            ->setName('sender:send')
            ->setDescription('Start Sending')
            ->setHelp('This command allows you to start multiple RabbitMQ consumers for different queues.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        while(true) {
            $clientId = rand(1, 3);
            $duration = 0;#rand(100, 1000);
            $uuid = Uuid::uuid4();
            $bucket = Hash::convert($uuid);
            $key = 'client.'.$clientId.'.member_bucket.'.$bucket;
            $message = new SleepMessage($duration, true);
            $this->bus->dispatch($message, [new AmqpStamp($key)]);
            time_nanosleep(0, 100000);
        }
    }

}
