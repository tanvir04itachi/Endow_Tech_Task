<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_reserve_a_seat(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['capacity' => 10, 'reserved_count' => 0]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/events/{$event->id}/reserve");

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'reserved')
            ->assertJsonPath('data.event_id', $event->id)
            ->assertJsonPath('data.user_id', $user->id);

        $this->assertDatabaseHas('reservations', [
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => 'reserved',
        ]);

        $this->assertSame(1, $event->fresh()->reserved_count);
    }

    public function test_reserving_a_nonexistent_event_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/events/99999/reserve');

        $response->assertStatus(404);
    }

    public function test_reserving_a_full_event_returns_409(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['capacity' => 1, 'reserved_count' => 1]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/events/{$event->id}/reserve");

        $response->assertStatus(409)
            ->assertJson(['message' => 'This event is fully booked.']);

        $this->assertSame(1, $event->fresh()->reserved_count);
    }

    public function test_user_cannot_hold_two_active_reservations_for_the_same_event(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['capacity' => 10, 'reserved_count' => 1]);
        Reservation::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => Reservation::STATUS_RESERVED,
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/events/{$event->id}/reserve");

        $response->assertStatus(409)
            ->assertJson(['message' => 'You already have an active reservation for this event.']);

        $this->assertSame(1, $event->fresh()->reserved_count);
        $this->assertSame(1, Reservation::where('event_id', $event->id)->where('user_id', $user->id)->count());
    }

    public function test_user_who_cancelled_can_reserve_again(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['capacity' => 10, 'reserved_count' => 0]);
        $cancelled = Reservation::factory()->cancelled()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/events/{$event->id}/reserve");

        $response->assertStatus(201)
            ->assertJsonPath('data.id', $cancelled->id)
            ->assertJsonPath('data.status', 'reserved');

        $this->assertSame(1, $event->fresh()->reserved_count);
        $this->assertSame(1, Reservation::where('event_id', $event->id)->where('user_id', $user->id)->count());
    }

    public function test_unauthenticated_user_cannot_reserve(): void
    {
        $event = Event::factory()->create();

        $response = $this->postJson("/api/events/{$event->id}/reserve");

        $response->assertStatus(401);
    }
}
