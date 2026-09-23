<?php

namespace App\Services;

use App\Exceptions\AlreadyCancelledException;
use App\Exceptions\DuplicateReservationException;
use App\Exceptions\EventFullException;
use App\Exceptions\UnauthorizedCancellationException;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReservationService
{
    /**
     * Reserve one seat on the given event for the given user.
     *
     * Locking order is always Event -> Reservation, matched by cancel(),
     * so the two operations can never deadlock against each other.
     */
    public function reserve(Event $event, User $user): Reservation
    {
        return DB::transaction(function () use ($event, $user) {
            $lockedEvent = Event::where('id', $event->id)->lockForUpdate()->firstOrFail();

            // At most one reservation row exists per (event, user) pair: a
            // cancelled row is reused rather than a new row being created.
            $existing = Reservation::where('event_id', $lockedEvent->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->isReserved()) {
                throw new DuplicateReservationException();
            }

            if (! $lockedEvent->hasAvailableSeats()) {
                throw new EventFullException();
            }

            if ($existing) {
                $existing->update(['status' => Reservation::STATUS_RESERVED]);
                $reservation = $existing;
            } else {
                $reservation = Reservation::create([
                    'event_id' => $lockedEvent->id,
                    'user_id' => $user->id,
                    'status' => Reservation::STATUS_RESERVED,
                ]);
            }

            $lockedEvent->increment('reserved_count');

            return $reservation->fresh();
        });
    }

    /**
     * Cancel a reservation owned by the given user.
     *
     * Locking order is always Event -> Reservation, matching reserve().
     */
    public function cancel(Reservation $reservation, User $user): Reservation
    {
        return DB::transaction(function () use ($reservation, $user) {
            $lockedEvent = Event::where('id', $reservation->event_id)->lockForUpdate()->firstOrFail();

            $lockedReservation = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            if ($lockedReservation->user_id !== $user->id) {
                throw new UnauthorizedCancellationException();
            }

            if (! $lockedReservation->isReserved()) {
                throw new AlreadyCancelledException();
            }

            $lockedReservation->update(['status' => Reservation::STATUS_CANCELLED]);

            if ($lockedEvent->reserved_count > 0) {
                $lockedEvent->decrement('reserved_count');
            }

            return $lockedReservation->fresh();
        });
    }
}
