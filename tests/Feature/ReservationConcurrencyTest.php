<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\DB;
use PDO;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Throwable;

/**
 * FR5 / FR6: reserved_count must never exceed capacity, and correctness
 * must hold when 50 users race for the last seat at the same time.
 *
 * This class does NOT use RefreshDatabase: both tests below need data that
 * is genuinely committed and visible to a second, independent connection
 * (a raw PDO connection, or a separately-spawned `php artisan serve`
 * process), which an uncommitted RefreshDatabase transaction would hide.
 * Rows created here are cleaned up manually in tearDown().
 */
class ReservationConcurrencyTest extends TestCase
{
    private ?Process $server = null;

    private ?int $eventId = null;

    private array $userIds = [];

    private int $port = 8098;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        $this->server?->stop(3);

        if ($this->eventId) {
            DB::table('reservations')->where('event_id', $this->eventId)->delete();
            DB::table('events')->where('id', $this->eventId)->delete();
        }

        if (! empty($this->userIds)) {
            DB::table('personal_access_tokens')->whereIn('tokenable_id', $this->userIds)->delete();
            DB::table('users')->whereIn('id', $this->userIds)->delete();
        }

        parent::tearDown();
    }

    /**
     * Directly proves the serialization primitive: a second, fully
     * independent database connection cannot acquire a lock on the same
     * event row while the first connection holds it via `FOR UPDATE`. This
     * is the exact mechanism that makes the 50-concurrent-user case below
     * safe, demonstrated deterministically without relying on OS-level
     * request timing.
     */
    public function test_a_locked_event_row_blocks_a_concurrent_connection(): void
    {
        $event = Event::factory()->create(['capacity' => 1, 'reserved_count' => 0]);
        $this->eventId = $event->id;

        $connA = $this->rawConnection();
        $connB = $this->rawConnection();

        $connA->beginTransaction();
        $connA->prepare('SELECT * FROM events WHERE id = ? FOR UPDATE')->execute([$event->id]);

        $connB->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $connB->beginTransaction();

        $blocked = false;
        try {
            $connB->prepare('SELECT * FROM events WHERE id = ? FOR UPDATE')->execute([$event->id]);
        } catch (Throwable $e) {
            $blocked = true;
        }

        $this->assertTrue(
            $blocked,
            'A second connection should not be able to lock the same event row while the first transaction holds it.'
        );

        $connB->rollBack();
        $connA->commit();
    }

    /**
     * The literal FR6 scenario: 50 users, fired concurrently over real HTTP
     * against a separately-spawned server process (so requests genuinely
     * overlap at the OS/connection level), race for the single remaining
     * seat. Exactly one must win.
     */
    public function test_fifty_concurrent_requests_for_the_last_seat_result_in_exactly_one_success(): void
    {
        $event = Event::factory()->create(['capacity' => 1, 'reserved_count' => 0]);
        $this->eventId = $event->id;

        $users = User::factory()->count(50)->create();
        $this->userIds = $users->pluck('id')->all();

        $tokens = $users->map(fn (User $u) => $u->createToken('concurrency-test')->plainTextToken)->all();

        $this->startServer();

        $client = new Client([
            'base_uri' => "http://127.0.0.1:{$this->port}/",
            'http_errors' => false,
            'timeout' => 30,
        ]);

        $requests = function () use ($tokens, $event) {
            foreach ($tokens as $token) {
                yield new Request('POST', "api/events/{$event->id}/reserve", [
                    'Authorization' => "Bearer {$token}",
                    'Accept' => 'application/json',
                ]);
            }
        };

        $statusCodes = [];
        $pool = new Pool($client, $requests(), [
            'concurrency' => 50,
            'fulfilled' => function ($response) use (&$statusCodes) {
                $statusCodes[] = $response->getStatusCode();
            },
            'rejected' => function () use (&$statusCodes) {
                $statusCodes[] = 0;
            },
        ]);

        $pool->promise()->wait();

        $successes = count(array_filter($statusCodes, fn ($code) => $code === 201));
        $conflicts = count(array_filter($statusCodes, fn ($code) => $code === 409));

        $this->assertSame(50, count($statusCodes), 'All 50 requests should have completed.');
        $this->assertSame(1, $successes, 'Exactly one of the 50 concurrent reservation attempts should succeed.');
        $this->assertSame(49, $conflicts, 'The other 49 attempts should be rejected because the event is full.');

        $freshEvent = DB::table('events')->where('id', $event->id)->first();
        $this->assertSame(1, (int) $freshEvent->reserved_count);
        $this->assertLessThanOrEqual((int) $freshEvent->capacity, (int) $freshEvent->reserved_count);

        $reservedCount = DB::table('reservations')
            ->where('event_id', $event->id)
            ->where('status', 'reserved')
            ->count();
        $this->assertSame(1, $reservedCount);
    }

    private function rawConnection(): PDO
    {
        $config = config('database.connections.mysql');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $config['host'], $config['port'], $config['database']);

        return new PDO($dsn, $config['username'], $config['password']);
    }

    private function startServer(): void
    {
        $phpBinary = (new PhpExecutableFinder())->find() ?: 'php';

        $this->server = new Process(
            [$phpBinary, 'artisan', 'serve', '--port='.$this->port],
            base_path(),
            ['APP_ENV' => 'testing', 'PHP_CLI_SERVER_WORKERS' => '16']
        );
        $this->server->start();

        $probe = new Client(['base_uri' => "http://127.0.0.1:{$this->port}/"]);
        $ready = false;

        for ($i = 0; $i < 60; $i++) {
            try {
                $probe->get('up', ['timeout' => 1]);
                $ready = true;
                break;
            } catch (Throwable $e) {
                usleep(250_000);
            }
        }

        if (! $ready) {
            $this->server->stop(3);
            $this->markTestSkipped('Could not start a local server for the concurrency test on port '.$this->port.'.');
        }
    }
}
