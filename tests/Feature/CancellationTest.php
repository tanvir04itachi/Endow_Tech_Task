<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CancellationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_cancel_their_reservation(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['capacity' => 10, 'reserved_count' => 1]);
        $reservation = Reservation::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => Reservation::STATUS_RESERVED,
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/reservations/{$reservation->id}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame('cancelled', $reservation->fresh()->status);
        $this->assertSame(0, $event->fresh()->reserved_count);
    }

    public function test_reserved_count_never_goes_negative_on_cancel(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['capacity' => 10, 'reserved_count' => 0]);
        $reservation = Reservation::factory()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
            'status' => Reservation::STATUS_RESERVED,
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/reservations/{$reservation->id}/cancel")->assertStatus(200);

        $this->assertSame(0, $event->fresh()->reserved_count);
    }

    public function test_cancelling_an_already_cancelled_reservation_returns_409_and_does_not_double_decrement(): void
    {
        $user = User::factory()->create();
        $event = Event::factory()->create(['capacity' => 10, 'reserved_count' => 3]);
        $reservation = Reservation::factory()->cancelled()->create([
            'event_id' => $event->id,
            'user_id' => $user->id,
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/reservations/{$reservation->id}/cancel");

        $response->assertStatus(409)
            ->assertJson(['message' => 'This reservation is already cancelled.']);

        $this->assertSame(3, $event->fresh()->reserved_count);
    }

    public function test_non_owner_cannot_cancel_another_users_reservation(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $event = Event::factory()->create(['capacity' => 10, 'reserved_count' => 1]);
        $reservation = Reservation::factory()->create([
            'event_id' => $event->id,
            'user_id' => $owner->id,
            'status' => Reservation::STATUS_RESERVED,
        ]);
        Sanctum::actingAs($otherUser);

        $response = $this->postJson("/api/reservations/{$reservation->id}/cancel");

        $response->assertStatus(403);

        $this->assertSame('reserved', $reservation->fresh()->status);
        $this->assertSame(1, $event->fresh()->reserved_count);
    }

    public function test_cancelling_a_nonexistent_reservation_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/reservations/99999/cancel');

        $response->assertStatus(404);
    }

    public function test_unauthenticated_user_cannot_cancel(): void
    {
        $reservation = Reservation::factory()->create();

        $response = $this->postJson("/api/reservations/{$reservation->id}/cancel");

        $response->assertStatus(401);
    }
}
