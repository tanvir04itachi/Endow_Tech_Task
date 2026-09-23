<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReservationResource;
use App\Models\Event;
use App\Models\Reservation;
use App\Services\ReservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function __construct(private readonly ReservationService $reservations)
    {
    }

    public function reserve(Request $request, Event $event): JsonResponse
    {
        $reservation = $this->reservations->reserve($event, $request->user());

        return response()->json([
            'message' => 'Reservation confirmed.',
            'data' => new ReservationResource($reservation),
        ], 201);
    }

    public function cancel(Request $request, Reservation $reservation): JsonResponse
    {
        $cancelled = $this->reservations->cancel($reservation, $request->user());

        return response()->json([
            'message' => 'Reservation cancelled.',
            'data' => new ReservationResource($cancelled),
        ]);
    }
}
