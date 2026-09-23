<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * FR7: a failure partway through reserve()/cancel() must not leave the
 * database in a partially-applied state (reservation row without a matching
 * counter change, or vice versa). We force a failure *inside* the
 * transaction via a model event listener and assert a full rollback.
 */
class ReservationConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Reservation::flushEventListeners();

        parent::tearDown();
    }

    public function test_reserve_rolls_back_entirely_if_reservation_creation_fails(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['capacity' => 5, 'reserved_count' => 0]);

        Reservation::creating(function () {
            throw new RuntimeException('Simulated failure during reservation creation.');
        });

        $service = app(ReservationService::class);

        try {
            $service->reserve($event, $user);
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure during reservation creation.', $e->getMessage());
        }

        // Nothing must have been committed: no reservation row, counter untouched.
        $this->assertSame(0, $event->fresh()->reserved_count);
        $this->assertSame(0, Reservation::where('event_id', $event->id)->count());
    }

    public function test_reserve_rolls_back_entirely_if_cancelled_row_reuse_fails(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['capacity' => 5, 'reserved_count' => 0]);
        $cancelled = Reservation::factory()->cancelled()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        Reservation::updating(function () {
            throw new RuntimeException('Simulated failure during reservation reuse.');
        });

        $service = app(ReservationService::class);

        try {
            $service->reserve($event, $user);
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure during reservation reuse.', $e->getMessage());
        }

        $this->assertSame(0, $event->fresh()->reserved_count);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
    }

    public function test_cancel_rolls_back_entirely_if_update_fails(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['capacity' => 5, 'reserved_count' => 1]);
        $reservation = Reservation::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => Reservation::STATUS_RESERVED,
        ]);

        Reservation::updating(function () {
            throw new RuntimeException('Simulated failure during cancellation.');
        });

        $service = app(ReservationService::class);

        try {
            $service->cancel($reservation, $user);
            $this->fail('Expected exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('Simulated failure during cancellation.', $e->getMessage());
        }

        // Counter must still reflect the still-active reservation.
        $this->assertSame(1, $event->fresh()->reserved_count);
        $this->assertSame('reserved', $reservation->fresh()->status);
    }
}
