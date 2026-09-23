<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    /** @use HasFactory<\Database\Factories\EventFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'capacity',
        'reserved_count',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'reserved_count' => 'integer',
        ];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function hasAvailableSeats(): bool
    {
        return $this->reserved_count < $this->capacity;
    }
}
