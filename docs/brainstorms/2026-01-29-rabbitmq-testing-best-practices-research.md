# RabbitMQ/AMQP Testing Best Practices Research (2026)

**Research Date**: 2026-01-29
**Purpose**: Document best practices for testing RabbitMQ/AMQP applications with php-amqplib and Symfony Messenger

## Table of Contents

1. [Queue Management in Tests](#1-queue-management-in-tests)
2. [Exchange Configuration](#2-exchange-configuration)
3. [Message Publishing Patterns](#3-message-publishing-patterns)
4. [Message Consumption Patterns](#4-message-consumption-patterns)
5. [Header and Stamp Handling](#5-header-and-stamp-handling)
6. [Test Isolation Strategies](#6-test-isolation-strategies)
7. [php-amqplib Testing Patterns](#7-php-amqplib-testing-patterns)
8. [RabbitMQ Management API](#8-rabbitmq-management-api)
9. [Symfony Messenger Testing](#9-symfony-messenger-testing)
10. [Complete Test Examples](#10-complete-test-examples)

---

## 1. Queue Management in Tests

### 1.1 Core Operations

php-amqplib provides three essential queue management methods via `AMQPChannel`:

```php
// Queue declaration
$channel->queue_declare(
    string $queue,           // Queue name
    bool $passive = false,   // Check if queue exists (don't create)
    bool $durable = false,   // Survive broker restart
    bool $exclusive = false, // Delete when connection closes
    bool $auto_delete = false // Delete when no consumers
);

// Queue purging (remove all messages)
$channel->queue_purge(string $queue);

// Queue deletion
$channel->queue_delete(
    string $queue,
    bool $if_unused = false,  // Only delete if no consumers
    bool $if_empty = false    // Only delete if no messages
);
```

### 1.2 Temporary Test Queues Pattern

**Best Practice**: Use exclusive queues that auto-delete when connection closes.

```php
class RabbitMQTestCase extends TestCase
{
    protected AMQPStreamConnection $connection;
    protected AMQPChannel $channel;
    protected array $testQueues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: 'localhost',
            (int) (getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: 'guest',
            getenv('RABBITMQ_PASSWORD') ?: 'guest',
            getenv('RABBITMQ_VHOST') ?: '/'
        );

        $this->channel = $this->connection->channel();
    }

    protected function createTestQueue(string $prefix = 'test'): string
    {
        // Let RabbitMQ generate unique queue name
        [$queueName, ,] = $this->channel->queue_declare(
            '',              // Empty string = auto-generate name
            false,           // Not passive
            false,           // Not durable (test queue)
            true,            // Exclusive (deleted when connection closes)
            true             // Auto-delete (deleted when no consumers)
        );

        $this->testQueues[] = $queueName;

        return $queueName;
    }

    protected function tearDown(): void
    {
        // Cleanup: purge and delete non-exclusive queues
        foreach ($this->testQueues as $queue) {
            try {
                $this->channel->queue_purge($queue);
                $this->channel->queue_delete($queue);
            } catch (\Exception $e) {
                // Queue might already be deleted (exclusive)
            }
        }

        $this->channel->close();
        $this->connection->close();

        parent::tearDown();
    }
}
```

### 1.3 Named Test Queues (For Integration Tests)

When you need specific queue names for testing bindings:

```php
protected function createNamedTestQueue(string $name): string
{
    $testQueueName = sprintf('test_%s_%s', $name, bin2hex(random_bytes(4)));

    $this->channel->queue_declare(
        $testQueueName,
        false,  // Not durable
        false,  // Not exclusive (so we can manually clean up)
        false,  // Not auto-delete
        true    // Passive = false (create if doesn't exist)
    );

    $this->testQueues[] = $testQueueName;

    return $testQueueName;
}
```

### 1.4 Avoiding Test Interference

**Problem**: Queue declaration can block if another process is purging the same queue.

**Solution**: Use unique queue names per test or test run:

```php
protected static function getUniqueQueueName(string $base): string
{
    return sprintf(
        '%s_%s_%d',
        $base,
        date('YmdHis'),
        getmypid()
    );
}
```

---

## 2. Exchange Configuration

### 2.1 Exchange Declaration

```php
use PhpAmqpLib\Exchange\AMQPExchangeType;

// Declare exchange
$channel->exchange_declare(
    string $exchange,                    // Exchange name
    string $type = AMQPExchangeType::DIRECT,  // Type: direct, topic, headers, fanout
    bool $passive = false,               // Check if exists
    bool $durable = false,               // Survive broker restart
    bool $auto_delete = false            // Delete when no bindings
);

// Bind queue to exchange
$channel->queue_bind(
    string $queue,        // Queue name
    string $exchange,     // Exchange name
    string $routing_key = ''  // Routing key (for direct/topic)
);

// Delete exchange
$channel->exchange_delete(string $exchange);
```

### 2.2 Test Exchange Pattern

```php
class MessagePublishingTest extends RabbitMQTestCase
{
    private string $testExchange;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test exchange
        $this->testExchange = $this->createTestExchange('test_exchange');
    }

    protected function createTestExchange(string $name, string $type = AMQPExchangeType::TOPIC): string
    {
        $exchangeName = sprintf('test_%s_%s', $name, bin2hex(random_bytes(4)));

        $this->channel->exchange_declare(
            $exchangeName,
            $type,
            false,  // Not passive
            false,  // Not durable
            true    // Auto-delete
        );

        return $exchangeName;
    }

    public function testMessageRouting(): void
    {
        // Create queue and bind to exchange
        $queue = $this->createTestQueue();
        $this->channel->queue_bind($queue, $this->testExchange, 'order.*');

        // Publish message
        $message = new AMQPMessage('test payload');
        $this->channel->basic_publish($message, $this->testExchange, 'order.created');

        // Verify message arrived
        $receivedMessage = $this->channel->basic_get($queue);
        $this->assertNotNull($receivedMessage);
        $this->assertEquals('test payload', $receivedMessage->body);
    }
}
```

---

## 3. Message Publishing Patterns

### 3.1 Basic Publishing

```php
use PhpAmqpLib\Message\AMQPMessage;

// Create message with properties
$message = new AMQPMessage(
    'message body',
    [
        'content_type' => 'application/json',
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        'message_id' => '550e8400-e29b-41d4-a716-446655440000',
        'timestamp' => time(),
        'type' => 'order.placed',
    ]
);

// Publish to exchange
$channel->basic_publish(
    $message,
    'exchange_name',
    'routing.key'
);
```

### 3.2 Publishing with Custom Headers

```php
use PhpAmqpLib\Wire\AMQPTable;

// Create complex headers
$headers = new AMQPTable([
    'x-message-type' => 'order.placed',
    'x-correlation-id' => '123e4567-e89b-12d3-a456-426614174000',
    'x-tenant-id' => 'tenant_123',
    'x-retry-count' => 0,
    'x-published-at' => (new DateTime('now', new DateTimeZone('UTC')))->format('c'),
    'x-nested-data' => [
        'version' => 1,
        'source' => 'order-service',
    ],
]);

// Create message with application headers
$message = new AMQPMessage(
    json_encode(['orderId' => '123', 'amount' => 99.99]),
    [
        'content_type' => 'application/json',
        'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        'application_headers' => $headers,
    ]
);

$channel->basic_publish($message, 'orders_exchange', 'order.placed');
```

### 3.3 Testing Message Publishing

```php
public function testPublishingMessageWithHeaders(): void
{
    $queue = $this->createTestQueue();
    $exchange = $this->createTestExchange('orders');
    $this->channel->queue_bind($queue, $exchange, 'order.#');

    // Publish message
    $expectedHeaders = new AMQPTable([
        'x-message-type' => 'order.placed',
        'x-tenant-id' => 'tenant_123',
    ]);

    $message = new AMQPMessage(
        json_encode(['orderId' => '123']),
        [
            'content_type' => 'application/json',
            'message_id' => 'test-message-id',
            'type' => 'order.placed',
            'application_headers' => $expectedHeaders,
        ]
    );

    $this->channel->basic_publish($message, $exchange, 'order.placed');

    // Verify message
    $receivedMessage = $this->channel->basic_get($queue);
    $this->assertNotNull($receivedMessage, 'Message should be in queue');

    // Assert message properties
    $this->assertEquals('test-message-id', $receivedMessage->get('message_id'));
    $this->assertEquals('order.placed', $receivedMessage->get('type'));
    $this->assertEquals('application/json', $receivedMessage->get('content_type'));

    // Assert headers
    $receivedHeaders = $receivedMessage->get('application_headers');
    $this->assertInstanceOf(AMQPTable::class, $receivedHeaders);

    $headerData = $receivedHeaders->getNativeData();
    $this->assertEquals('order.placed', $headerData['x-message-type']);
    $this->assertEquals('tenant_123', $headerData['x-tenant-id']);

    // Acknowledge message
    $receivedMessage->ack();
}
```

---

## 4. Message Consumption Patterns

### 4.1 Non-Blocking Single Message Retrieval (basic_get)

**Best for testing**: Retrieve messages one at a time without callbacks.

```php
// Retrieve single message (non-blocking)
$message = $channel->basic_get($queueName, $no_ack = false);

if ($message === null) {
    // No message available
    return;
}

// Process message
$body = $message->body;
$properties = $message->get_properties();

// Acknowledge message
$message->ack();

// Or reject and requeue
// $message->nack(true); // requeue = true

// Or reject without requeue
// $message->reject(false); // requeue = false
```

### 4.2 Testing Message Consumption

```php
public function testConsumingMessages(): void
{
    $queue = $this->createTestQueue();

    // Publish test messages
    for ($i = 1; $i <= 3; $i++) {
        $message = new AMQPMessage("Message $i");
        $this->channel->basic_publish($message, '', $queue);
    }

    // Consume messages
    $consumedMessages = [];

    while ($message = $this->channel->basic_get($queue)) {
        $consumedMessages[] = $message->body;
        $message->ack();
    }

    $this->assertCount(3, $consumedMessages);
    $this->assertEquals(['Message 1', 'Message 2', 'Message 3'], $consumedMessages);
}
```

### 4.3 Callback-Based Consumption (basic_consume)

**Not recommended for tests**: Blocks until message arrives.

```php
// Setup consumer callback
$callback = function (AMQPMessage $message) {
    echo "Received: " . $message->body . "\n";
    $message->ack();
};

$channel->basic_consume(
    $queue,
    '',          // Consumer tag
    false,       // No local
    false,       // No ack (manual ack)
    false,       // Exclusive
    false,       // No wait
    $callback
);

// Start consuming (blocks!)
while ($channel->is_consuming()) {
    $channel->wait();
}
```

---

## 5. Header and Stamp Handling

### 5.1 Symfony Messenger Stamps

Symfony Messenger automatically serialises stamps to AMQP headers with the prefix `X-Message-Stamp-`.

**Example**: `MessageIdStamp` becomes `X-Message-Stamp-MessageIdStamp` header.

#### Creating Custom Stamps

```php
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class MessageIdStamp implements StampInterface
{
    public function __construct(
        public string $messageId,
    ) {}
}
```

#### Adding Stamps to Envelope

```php
use Symfony\Component\Messenger\Envelope;

$envelope = new Envelope($message, [
    new MessageIdStamp('550e8400-e29b-41d4-a716-446655440000'),
    new MessageNameStamp('order.placed'),
]);

$bus->dispatch($envelope);
```

### 5.2 Accessing Stamps in Tests

```php
public function testMessageHasStamps(): void
{
    $message = new OrderPlaced(Id::new(), 99.99, CarbonImmutable::now());

    $envelope = $this->bus->dispatch($message);

    // Get stamps from envelope
    $messageIdStamps = $envelope->all(MessageIdStamp::class);
    $this->assertNotEmpty($messageIdStamps);

    /** @var MessageIdStamp $stamp */
    $stamp = $messageIdStamps[0];
    $this->assertNotEmpty($stamp->messageId);

    // Verify UUID v7 format
    $this->assertMatchesRegularExpression(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
        $stamp->messageId
    );
}
```

### 5.3 Verifying AMQP Headers in Integration Tests

```php
public function testAmqpHeadersContainStamps(): void
{
    // Publish via Symfony Messenger
    $message = new OrderPlaced(Id::new(), 99.99, CarbonImmutable::now());
    $this->bus->dispatch($message);

    // Consume directly from RabbitMQ
    $amqpMessage = $this->channel->basic_get('orders_queue');
    $this->assertNotNull($amqpMessage);

    // Get application headers
    $headers = $amqpMessage->get('application_headers');
    $headerData = $headers->getNativeData();

    // Verify type header
    $this->assertEquals('order.placed', $amqpMessage->get('type'));

    // Verify stamp headers
    $this->assertArrayHasKey('X-Message-Stamp-MessageIdStamp', $headerData);

    // Decode stamp JSON
    $stampData = json_decode($headerData['X-Message-Stamp-MessageIdStamp'], true);
    $this->assertIsArray($stampData);
    $this->assertArrayHasKey('messageId', $stampData[0]);
}
```

---

## 6. Test Isolation Strategies

### 6.1 PHPUnit setUp/tearDown Pattern

```php
abstract class RabbitMQIntegrationTest extends TestCase
{
    protected AMQPStreamConnection $connection;
    protected AMQPChannel $channel;
    protected array $testQueues = [];
    protected array $testExchanges = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: 'localhost',
            (int) (getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: 'guest',
            getenv('RABBITMQ_PASSWORD') ?: 'guest',
            getenv('RABBITMQ_VHOST') ?: '/'
        );

        $this->channel = $this->connection->channel();
    }

    protected function tearDown(): void
    {
        // Clean up test queues
        foreach ($this->testQueues as $queue) {
            try {
                $this->channel->queue_purge($queue);
                $this->channel->queue_delete($queue);
            } catch (\Exception $e) {
                // Queue already deleted or doesn't exist
            }
        }

        // Clean up test exchanges
        foreach ($this->testExchanges as $exchange) {
            try {
                $this->channel->exchange_delete($exchange);
            } catch (\Exception $e) {
                // Exchange already deleted or doesn't exist
            }
        }

        $this->channel->close();
        $this->connection->close();

        parent::tearDown();
    }

    protected function registerTestQueue(string $queue): void
    {
        $this->testQueues[] = $queue;
    }

    protected function registerTestExchange(string $exchange): void
    {
        $this->testExchanges[] = $exchange;
    }
}
```

### 6.2 Broker Reset Between Test Suites (Advanced)

For comprehensive isolation, reset RabbitMQ state between test suites:

```php
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestSuite;

class RabbitMQListener implements TestListener
{
    public function startTestSuite(TestSuite $suite): void
    {
        if ($suite->getName() === 'Integration') {
            $this->resetRabbitMQ();
        }
    }

    protected function resetRabbitMQ(): void
    {
        $rabbitBase = getenv('RABBITMQ_BASE_PATH') ?: '/usr/local/opt/rabbitmq/';

        // Stop app
        exec($rabbitBase . 'sbin/rabbitmqctl stop_app');

        // Reset all state
        exec($rabbitBase . 'sbin/rabbitmqctl reset');

        // Start app
        exec($rabbitBase . 'sbin/rabbitmqctl start_app');

        // Wait for broker to be ready
        sleep(2);
    }

    // Other TestListener methods...
}
```

**Configure in phpunit.xml**:

```xml
<phpunit>
    <listeners>
        <listener class="Tests\RabbitMQListener" />
    </listeners>
</phpunit>
```

### 6.3 Database Transaction Isolation (Symfony Doctrine)

Wrap tests in database transactions to ensure clean state:

```php
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class DatabaseTest extends KernelTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }
}
```

---

## 7. php-amqplib Testing Patterns

### 7.1 Complete Test Setup Example

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageBroker;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;

abstract class RabbitMQTestCase extends TestCase
{
    protected AMQPStreamConnection $connection;
    protected AMQPChannel $channel;
    protected array $testQueues = [];
    protected array $testExchanges = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: 'localhost',
            (int) (getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: 'guest',
            getenv('RABBITMQ_PASSWORD') ?: 'guest',
            getenv('RABBITMQ_VHOST') ?: '/'
        );

        $this->channel = $this->connection->channel();
    }

    protected function tearDown(): void
    {
        foreach ($this->testQueues as $queue) {
            try {
                $this->channel->queue_purge($queue);
                $this->channel->queue_delete($queue);
            } catch (\Exception $e) {
                // Ignore - queue might be exclusive or already deleted
            }
        }

        foreach ($this->testExchanges as $exchange) {
            try {
                $this->channel->exchange_delete($exchange);
            } catch (\Exception $e) {
                // Ignore - exchange might be auto-deleted
            }
        }

        $this->channel->close();
        $this->connection->close();

        parent::tearDown();
    }

    protected function createTestQueue(string $prefix = 'test'): string
    {
        [$queueName, ,] = $this->channel->queue_declare(
            '',
            false,
            false,
            true,  // Exclusive
            true   // Auto-delete
        );

        $this->testQueues[] = $queueName;

        return $queueName;
    }

    protected function createNamedTestQueue(string $name): string
    {
        $queueName = sprintf('test_%s_%s', $name, bin2hex(random_bytes(4)));

        $this->channel->queue_declare($queueName, false, false, false, false);
        $this->testQueues[] = $queueName;

        return $queueName;
    }

    protected function createTestExchange(
        string $name,
        string $type = AMQPExchangeType::TOPIC
    ): string {
        $exchangeName = sprintf('test_%s_%s', $name, bin2hex(random_bytes(4)));

        $this->channel->exchange_declare($exchangeName, $type, false, false, true);
        $this->testExchanges[] = $exchangeName;

        return $exchangeName;
    }

    protected function publishTestMessage(
        string $body,
        string $exchange,
        string $routingKey,
        array $properties = []
    ): void {
        $defaultProperties = [
            'content_type' => 'text/plain',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];

        $message = new AMQPMessage($body, array_merge($defaultProperties, $properties));
        $this->channel->basic_publish($message, $exchange, $routingKey);
    }

    protected function assertQueueEmpty(string $queue, string $message = ''): void
    {
        $amqpMessage = $this->channel->basic_get($queue);
        $this->assertNull(
            $amqpMessage,
            $message ?: sprintf('Queue "%s" should be empty', $queue)
        );
    }

    protected function assertQueueHasMessage(string $queue, string $message = ''): void
    {
        $amqpMessage = $this->channel->basic_get($queue);
        $this->assertNotNull(
            $amqpMessage,
            $message ?: sprintf('Queue "%s" should have at least one message', $queue)
        );

        // Clean up
        if ($amqpMessage) {
            $amqpMessage->ack();
        }
    }

    protected function getQueueMessageCount(string $queue): int
    {
        [, $messageCount,] = $this->channel->queue_declare($queue, true);

        return $messageCount;
    }
}
```

---

## 8. RabbitMQ Management API

### 8.1 HTTP API Endpoints for Testing

The RabbitMQ Management HTTP API provides RESTful endpoints for queue/exchange management.

**Authentication**: HTTP Basic Auth (default: `guest:guest`)
**Base URL**: `http://localhost:15672/api`

### 8.2 Queue Operations via HTTP API

#### Create Queue

```bash
curl -u guest:guest -X PUT \
  -H "Content-Type: application/json" \
  -d '{"durable":false,"auto_delete":false,"arguments":{}}' \
  http://localhost:15672/api/queues/%2F/test_queue
```

**PHP Implementation**:

```php
protected function createQueueViaAPI(string $queueName): void
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, sprintf(
        'http://localhost:15672/api/queues/%%2F/%s',
        urlencode($queueName)
    ));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'durable' => false,
        'auto_delete' => false,
    ]));
    curl_setopt($ch, CURLOPT_USERPWD, 'guest:guest');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_exec($ch);
    curl_close($ch);
}
```

#### Purge Queue

```bash
curl -u guest:guest -X DELETE \
  http://localhost:15672/api/queues/%2F/test_queue/contents
```

**PHP Implementation**:

```php
protected function purgeQueueViaAPI(string $queueName): void
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, sprintf(
        'http://localhost:15672/api/queues/%%2F/%s/contents',
        urlencode($queueName)
    ));
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_USERPWD, 'guest:guest');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    curl_exec($ch);
    curl_close($ch);
}
```

#### Delete Queue

```bash
curl -u guest:guest -X DELETE \
  http://localhost:15672/api/queues/%2F/test_queue
```

#### Get Messages from Queue (Testing Only!)

**Warning**: This operation alters queue state - use only for development/testing.

```bash
curl -u guest:guest -X POST \
  -H "Content-Type: application/json" \
  -d '{"count":5,"ackmode":"ack_requeue_false","encoding":"auto"}' \
  http://localhost:15672/api/queues/%2F/test_queue/get
```

**PHP Implementation**:

```php
protected function getMessagesViaAPI(string $queueName, int $count = 1): array
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, sprintf(
        'http://localhost:15672/api/queues/%%2F/%s/get',
        urlencode($queueName)
    ));
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'count' => $count,
        'ackmode' => 'ack_requeue_false',
        'encoding' => 'auto',
    ]));
    curl_setopt($ch, CURLOPT_USERPWD, 'guest:guest');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true) ?: [];
}
```

### 8.3 Management API Helper Class

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

final readonly class RabbitMQManagementAPI
{
    public function __construct(
        private string $baseUrl = 'http://localhost:15672/api',
        private string $username = 'guest',
        private string $password = 'guest',
        private string $vhost = '/',
    ) {}

    public function createQueue(
        string $name,
        bool $durable = false,
        bool $autoDelete = false
    ): void {
        $this->request('PUT', sprintf('/queues/%s/%s', $this->encodeVhost(), $name), [
            'durable' => $durable,
            'auto_delete' => $autoDelete,
        ]);
    }

    public function deleteQueue(string $name): void
    {
        $this->request('DELETE', sprintf('/queues/%s/%s', $this->encodeVhost(), $name));
    }

    public function purgeQueue(string $name): void
    {
        $this->request('DELETE', sprintf('/queues/%s/%s/contents', $this->encodeVhost(), $name));
    }

    public function getQueueInfo(string $name): array
    {
        $response = $this->request('GET', sprintf('/queues/%s/%s', $this->encodeVhost(), $name));

        return json_decode($response, true) ?: [];
    }

    public function getMessages(string $queueName, int $count = 1, bool $requeue = false): array
    {
        $response = $this->request('POST', sprintf('/queues/%s/%s/get', $this->encodeVhost(), $queueName), [
            'count' => $count,
            'ackmode' => $requeue ? 'ack_requeue_true' : 'ack_requeue_false',
            'encoding' => 'auto',
        ]);

        return json_decode($response, true) ?: [];
    }

    private function request(string $method, string $path, ?array $body = null): string
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_USERPWD, sprintf('%s:%s', $this->username, $this->password));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException(sprintf('HTTP %d: %s', $httpCode, $response));
        }

        return $response;
    }

    private function encodeVhost(): string
    {
        return urlencode($this->vhost);
    }
}
```

---

## 9. Symfony Messenger Testing

### 9.1 zenstruck/messenger-test Library

**Installation**:

```bash
composer require --dev zenstruck/messenger-test
```

**Configuration** (`config/packages/messenger.yaml`):

```yaml
when@test:
    framework:
        messenger:
            transports:
                # Use test:// transport in tests
                async: test://
                outbox: test://
```

### 9.2 Basic Usage

```php
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Messenger\Test\InteractsWithMessenger;

class MessengerTest extends KernelTestCase
{
    use InteractsWithMessenger;

    public function testMessageDispatched(): void
    {
        // Dispatch message
        $message = new OrderPlaced(Id::new(), 99.99);
        $this->bus()->dispatch($message);

        // Assert message in queue
        $this->transport()->queue()->assertCount(1);
        $this->transport()->queue()->assertContains(OrderPlaced::class);

        // Process message
        $this->transport()->process(1);

        // Assert queue empty
        $this->transport()->queue()->assertEmpty();
    }
}
```

### 9.3 Stamp Assertions

```php
public function testMessageHasStamps(): void
{
    $message = new OrderPlaced(Id::new(), 99.99);
    $this->bus()->dispatch($message);

    // Get first envelope from queue
    $envelope = $this->transport()->queue()->first();

    // Assert stamps
    $envelope->assertHasStamp(DelayStamp::class);
    $envelope->assertNotHasStamp(SomeOtherStamp::class);

    // Get stamp
    /** @var DelayStamp $delayStamp */
    $delayStamp = $envelope->get(DelayStamp::class);
    $this->assertEquals(5000, $delayStamp->getDelay());
}
```

### 9.4 Multiple Transports

```php
public function testMultipleTransports(): void
{
    // Access specific transport
    $this->transport('async')->queue()->assertEmpty();
    $this->transport('outbox')->queue()->assertCount(1);

    // Process specific transport
    $this->transport('outbox')->process();
}
```

---

## 10. Complete Test Examples

### 10.1 Integration Test: Outbox to AMQP

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageBroker;

use App\Message\OrderPlaced;
use Carbon\CarbonImmutable;
use Doctrine\DBAL\Connection;
use Freyr\Identity\Id;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class OutboxToAmqpIntegrationTest extends KernelTestCase
{
    private MessageBusInterface $bus;
    private Connection $connection;
    private AMQPStreamConnection $amqpConnection;
    private AMQPChannel $amqpChannel;
    private array $testQueues = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->bus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');

        // Setup AMQP connection
        $this->amqpConnection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: 'localhost',
            (int) (getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: 'guest',
            getenv('RABBITMQ_PASSWORD') ?: 'guest',
            getenv('RABBITMQ_VHOST') ?: '/'
        );

        $this->amqpChannel = $this->amqpConnection->channel();

        // Clean outbox table
        $this->connection->executeStatement('DELETE FROM messenger_outbox');
    }

    protected function tearDown(): void
    {
        // Clean up test queues
        foreach ($this->testQueues as $queue) {
            try {
                $this->amqpChannel->queue_purge($queue);
                $this->amqpChannel->queue_delete($queue);
            } catch (\Exception $e) {
                // Ignore
            }
        }

        $this->amqpChannel->close();
        $this->amqpConnection->close();

        parent::tearDown();
    }

    public function testOutboxMessagePublishedToAmqp(): void
    {
        // Given: Test queue bound to orders exchange
        [$testQueue, ,] = $this->amqpChannel->queue_declare('', false, false, true, true);
        $this->testQueues[] = $testQueue;
        $this->amqpChannel->queue_bind($testQueue, 'orders', 'order.#');

        // When: Domain event dispatched
        $orderId = Id::new();
        $message = new OrderPlaced($orderId, 99.99, CarbonImmutable::now());
        $this->bus->dispatch($message);

        // Then: Message should be in outbox table
        $outboxCount = $this->connection->fetchOne('SELECT COUNT(*) FROM messenger_outbox');
        $this->assertEquals(1, $outboxCount, 'Message should be in outbox');

        // When: Outbox worker consumes message
        $this->runCommand('messenger:consume', ['outbox', '--limit=1', '--time-limit=1']);

        // Then: Message should be published to AMQP
        $amqpMessage = $this->amqpChannel->basic_get($testQueue);
        $this->assertNotNull($amqpMessage, 'Message should be in RabbitMQ');

        // Verify message content
        $body = json_decode($amqpMessage->body, true);
        $this->assertEquals($orderId->__toString(), $body['orderId']);
        $this->assertEquals(99.99, $body['totalAmount']);

        // Verify headers
        $this->assertEquals('order.placed', $amqpMessage->get('type'));

        $headers = $amqpMessage->get('application_headers');
        $headerData = $headers->getNativeData();
        $this->assertArrayHasKey('X-Message-Stamp-MessageIdStamp', $headerData);

        // Clean up
        $amqpMessage->ack();
    }

    private function runCommand(string $command, array $args = []): void
    {
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
        $application->setAutoExit(false);

        $input = new \Symfony\Component\Console\Input\ArrayInput(
            array_merge(['command' => $command], $args)
        );

        $output = new \Symfony\Component\Console\Output\NullOutput();
        $application->run($input, $output);
    }
}
```

### 10.2 Integration Test: Inbox Deduplication

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\MessageBroker;

use App\Message\OrderPlaced;
use Carbon\CarbonImmutable;
use Doctrine\DBAL\Connection;
use Freyr\Identity\Id;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class InboxDeduplicationTest extends KernelTestCase
{
    private Connection $connection;
    private AMQPStreamConnection $amqpConnection;
    private AMQPChannel $amqpChannel;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');

        // Setup AMQP
        $this->amqpConnection = new AMQPStreamConnection(
            getenv('RABBITMQ_HOST') ?: 'localhost',
            (int) (getenv('RABBITMQ_PORT') ?: 5672),
            getenv('RABBITMQ_USER') ?: 'guest',
            getenv('RABBITMQ_PASSWORD') ?: 'guest',
            getenv('RABBITMQ_VHOST') ?: '/'
        );

        $this->amqpChannel = $this->amqpConnection->channel();

        // Clean deduplication table
        $this->connection->executeStatement('DELETE FROM message_broker_deduplication');
    }

    protected function tearDown(): void
    {
        $this->amqpChannel->close();
        $this->amqpConnection->close();

        parent::tearDown();
    }

    public function testDuplicateMessageOnlyProcessedOnce(): void
    {
        // Given: Message with messageId stamp
        $messageId = Id::new()->__toString();
        $orderId = Id::new();

        $body = json_encode([
            'orderId' => $orderId->__toString(),
            'totalAmount' => 99.99,
            'placedAt' => CarbonImmutable::now()->toIso8601String(),
        ]);

        $headers = new AMQPTable([
            'X-Message-Stamp-MessageIdStamp' => json_encode([
                ['messageId' => $messageId]
            ]),
        ]);

        // Publish same message 3 times
        for ($i = 0; $i < 3; $i++) {
            $message = new AMQPMessage($body, [
                'content_type' => 'application/json',
                'type' => 'order.placed',
                'application_headers' => $headers,
            ]);

            $this->amqpChannel->basic_publish($message, '', 'orders_queue');
        }

        // When: Consumer processes messages
        $this->runCommand('messenger:consume', ['amqp_orders', '--limit=3', '--time-limit=5']);

        // Then: Only 1 deduplication entry
        $dedupCount = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM message_broker_deduplication WHERE message_id = ?',
            [hex2bin(str_replace('-', '', $messageId))]
        );

        $this->assertEquals(1, $dedupCount, 'Should have exactly 1 deduplication entry');

        // And: Order should be processed only once (verify in your domain)
        // Example: Check orders table has only 1 entry
    }

    private function runCommand(string $command, array $args = []): void
    {
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
        $application->setAutoExit(false);

        $input = new \Symfony\Component\Console\Input\ArrayInput(
            array_merge(['command' => $command], $args)
        );

        $output = new \Symfony\Component\Console\Output\NullOutput();
        $application->run($input, $output);
    }
}
```

### 10.3 Unit Test: Message Serialisation

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageBroker;

use App\Message\OrderPlaced;
use Carbon\CarbonImmutable;
use Freyr\Identity\Id;
use Freyr\MessageBroker\Serializer\OutboxSerializer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Serializer\SerializerInterface;

final class OutboxSerializerTest extends TestCase
{
    private OutboxSerializer $serializer;

    protected function setUp(): void
    {
        parent::setUp();

        $symfonySerializer = $this->createMock(SerializerInterface::class);
        $symfonySerializer
            ->method('serialize')
            ->willReturn('{"orderId":"123","totalAmount":99.99}');

        $this->serializer = new OutboxSerializer($symfonySerializer);
    }

    public function testEncodeExtractsMessageNameFromAttribute(): void
    {
        // Given: Message with #[MessageName] attribute
        $message = new OrderPlaced(Id::new(), 99.99, CarbonImmutable::now());
        $envelope = new Envelope($message);

        // When: Encode
        $encoded = $this->serializer->encode($envelope);

        // Then: Should have type header with semantic name
        $this->assertArrayHasKey('headers', $encoded);
        $this->assertArrayHasKey('type', $encoded['headers']);
        $this->assertEquals('order.placed', $encoded['headers']['type']);

        // And: Body should be JSON
        $this->assertArrayHasKey('body', $encoded);
        $this->assertJson($encoded['body']);
    }

    public function testEncodeThrowsExceptionIfMessageNameMissing(): void
    {
        // Given: Message WITHOUT #[MessageName] attribute
        $message = new \stdClass();
        $envelope = new Envelope($message);

        // Expect: Exception
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must have #[MessageName] attribute');

        // When: Encode
        $this->serializer->encode($envelope);
    }
}
```

---

## Summary of Best Practices

### Queue Management
1. ✅ Use **exclusive queues** (`exclusive=true`) for automatic cleanup
2. ✅ Use **server-generated queue names** (empty string) for uniqueness
3. ✅ Register queues/exchanges for cleanup in `tearDown()`
4. ✅ Use unique prefixes/suffixes to avoid collisions

### Message Testing
1. ✅ Use **`basic_get()`** for non-blocking message retrieval in tests
2. ✅ Avoid **`basic_consume()`** in tests (blocks indefinitely)
3. ✅ Always **acknowledge messages** to prevent requeue
4. ✅ Use **AMQPTable** for complex headers

### Test Isolation
1. ✅ Clean up queues/exchanges in **`tearDown()`**
2. ✅ Use **database transactions** for Doctrine tests
3. ✅ Consider **broker reset** between test suites for full isolation
4. ✅ Use **separate RabbitMQ vhost** for testing

### Headers & Stamps
1. ✅ Symfony stamps → **`X-Message-Stamp-*`** headers automatically
2. ✅ Use **`application_headers`** (AMQPTable) for custom headers
3. ✅ Extract stamp data from JSON-encoded header values

### Tools
1. ✅ Use **zenstruck/messenger-test** for Symfony Messenger tests
2. ✅ Use **RabbitMQ Management API** for queue inspection/cleanup
3. ✅ Use **php-amqplib** directly for integration tests
4. ✅ Use **PHPUnit listeners** for test suite setup/teardown

### Performance
1. ✅ Minimise broker resets (expensive operation)
2. ✅ Use **purge instead of delete** when possible
3. ✅ Batch cleanup operations in `tearDown()`
4. ✅ Consider **shared test fixtures** for expensive setup

---

## Sources

- [php-amqplib GitHub Repository](https://github.com/php-amqplib/php-amqplib)
- [php-amqplib AMQPChannel Documentation](https://php-amqplib.github.io/php-amqplib/classes/PhpAmqpLib-Channel-AMQPChannel.html)
- [php-amqplib AMQPMessage Documentation](https://php-amqplib.github.io/php-amqplib/classes/PhpAmqpLib-Message-AMQPMessage.html)
- [Codeception AMQP Module](https://codeception.com/docs/modules/AMQP)
- [RabbitMQ Management Plugin Documentation](https://www.rabbitmq.com/docs/management)
- [RabbitMQ HTTP API Reference](https://www.rabbitmq.com/docs/http-api-reference)
- [RabbitMQ Queues Documentation](https://www.rabbitmq.com/docs/queues)
- [RabbitMQ Queue Types Explained (CloudAMQP)](https://www.cloudamqp.com/blog/rabbitmq-queue-types.html)
- [RabbitMQ Best Practices Part 1 (CloudAMQP)](https://www.cloudamqp.com/blog/part1-rabbitmq-best-practice.html)
- [zenstruck/messenger-test GitHub Repository](https://github.com/zenstruck/messenger-test)
- [Symfony Messenger Documentation](https://symfony.com/doc/current/messenger.html)
- [Using RabbitMQ in Unit Tests (Alvaro Videla)](https://alvaro-videla.com/2013/04/using-rabbitmq-in-unit-tests.html)
- [Tests Symfony RabbitMQBundle Producers with PHPUnit (Dylan Ballandras)](https://www.dylan-ballandras.fr/blog/phpunit-tests-symfony-rabbitmq-bundle)
- [RabbitMqBundle ConsumerTest Example](https://github.com/php-amqplib/RabbitMqBundle/blob/master/Tests/RabbitMq/ConsumerTest.php)
- [PHPUnit Fixtures Documentation](https://docs.phpunit.de/en/10.5/fixtures.html)
- [SymfonyCasts: Testing Messenger](https://symfonycasts.com/screencast/phpunit-integration/messenger)
