<?php

declare(strict_types=1);

namespace Freyr\MessageBroker\Storage\PostgreSql\Tests\Functional;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Runs code in several database sessions at the same time: one child process
 * (pcntl_fork) for each session, each with its own connection. The children
 * start their work at one common time, after each connected.
 *
 * A child ends with pcntl_exec() of /bin/true, so PHP runs no destructor in
 * it: the connection of the parent, which the child shares after the fork,
 * stays open.
 */
final class ConcurrentSessions
{
    /**
     * @template T
     *
     * @param callable(): PDO $connect makes the connection of one session
     * @param list<callable(PDO): T> $sessions the work of each session
     *
     * @return list<T> the result of each session, in the order of $sessions
     */
    public static function run(callable $connect, array $sessions): array
    {
        $start = microtime(true) + 0.3;
        $children = [];
        foreach ($sessions as $index => $session) {
            $file = (string) tempnam(sys_get_temp_dir(), 'session');
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('pcntl_fork() failed');
            }
            if ($pid === 0) {
                self::child($connect, $session, $file, $start);
            }
            $children[$index] = [$pid, $file];
        }

        $results = [];
        $errors = [];
        foreach ($children as $index => [$pid, $file]) {
            pcntl_waitpid($pid, $status);
            $outcome = unserialize((string) file_get_contents($file));
            unlink($file);
            if (!is_array($outcome)) {
                $errors[] = "session {$index}: no result";
            } elseif (array_key_exists('error', $outcome)) {
                $errors[] = "session {$index}: {$outcome['error']}";
            } else {
                $results[$index] = $outcome['result'];
            }
        }
        if ($errors !== []) {
            throw new RuntimeException(implode("\n", $errors));
        }

        /** @var list<T> $results */
        return $results;
    }

    /** @return never */
    private static function child(callable $connect, callable $session, string $file, float $start): void
    {
        try {
            $pdo = $connect();
            $wait = $start - microtime(true);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
            $outcome = [
                'result' => $session($pdo),
            ];
        } catch (Throwable $e) {
            $outcome = [
                'error' => $e::class.': '.$e->getMessage(),
            ];
        }
        file_put_contents($file, serialize($outcome));
        pcntl_exec('/bin/true');
        exit(1); // only when pcntl_exec() failed
    }
}
