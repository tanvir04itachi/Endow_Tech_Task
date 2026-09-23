<?php

namespace Tests\Unit;

use App\Exceptions\AlreadyCancelledException;
use App\Exceptions\DuplicateReservationException;
use App\Exceptions\EventFullException;
use App\Exceptions\UnauthorizedCancellationException;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use App\Services\ReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReservationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReservationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ReservationService::class);
    }

    public function test_reserve_increments_reserved_count_and_creates_a_reservation(): void
    {
        $event = Event::factory()->create(['capacity' => 3, 'reserved_count' => 0]);
        $user = User::factory()->create();

        $reservation = $this->service->reserve($event, $user);

        $this->assertSame('reserved', $reservation->status);
        $this->assertSame(1, $event->fresh()->reserved_count);
    }

    public function test_reserve_throws_when_event_is_full(): void
    {
        $event = Event::factory()->create(['capacity' => 1, 'reserved_count' => 1]);
        $user = User::factory()->create();

        $this->expectException(EventFullException::class);

        $this->service->reserve($event, $user);
    }

    public function test_reserve_throws_on_duplicate_active_reservation(): void
    {
        $event = Event::factory()->create(['capacity' => 5, 'reserved_count' => 1]);
        $user = User::factory()->create();
        Reservation::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => Reservation::STATUS_RESERVED,
        ]);

        $this->expectException(DuplicateReservationException::class);

        $this->service->reserve($event, $user);
    }

    public function test_reserve_reuses_a_cancelled_row_instead_of_creating_a_new_one(): void
    {
        $event = Event::factory()->create(['capacity' => 5, 'reserved_count' => 0]);
        $user = User::factory()->create();
        $cancelled = Reservation::factory()->cancelled()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        $reservation = $this->service->reserve($event, $user);

        $this->assertSame($cancelled->id, $reservation->id);
        $this->assertSame(1, Reservation::where('event_id', $event->id)->where('user_id', $user->id)->count());
    }

    public function test_cancel_decrements_reserved_count(): void
    {
        $event = Event::factory()->create(['capacity' => 5, 'reserved_count' => 1]);
        $user = User::factory()->create();
        $reservation = Reservation::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => Reservation::STATUS_RESERVED,
        ]);

        $cancelled = $this->service->cancel($reservation, $user);

        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(0, $event->fresh()->reserved_count);
    }

    public function test_cancel_throws_when_already_cancelled(): void
    {
        $event = Event::factory()->create(['capacity' => 5, 'reserved_count' => 0]);
        $user = User::factory()->create();
        $reservation = Reservation::factory()->cancelled()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);

        $this->expectException(AlreadyCancelledException::class);

        $this->service->cancel($reservation, $user);
    }

    public function test_cancel_throws_when_not_the_owner(): void
    {
        $event = Event::factory()->create(['capacity' => 5, 'reserved_count' => 1]);
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $reservation = Reservation::factory()->create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
            'status' => Reservation::STATUS_RESERVED,
        ]);

        $this->expectException(UnauthorizedCancellationException::class);

        $this->service->cancel($reservation, $otherUser);
    }

    public function test_reserved_count_never_exceeds_capacity_across_repeated_contention(): void
    {
        $event = Event::factory()->create(['capacity' => 1, 'reserved_count' => 0]);
        $users = User::factory()->count(50)->create();

        $successes = 0;
        foreach ($users as $user) {
            try {
                $this->service->reserve($event, $user);
                $successes++;
            } catch (EventFullException) {
                // expected for all but one user
            }

            $this->assertLessThanOrEqual($event->capacity, $event->fresh()->reserved_count);
        }

        $this->assertSame(1, $successes);
        $this->assertSame(1, $event->fresh()->reserved_count);
    }
}
