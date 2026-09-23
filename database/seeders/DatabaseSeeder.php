<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
            'password' => Hash::make('password'),
        ]);

        User::factory()->create([
            'name' => 'Second User',
            'email' => 'second@example.com',
            'password' => Hash::make('password'),
        ]);

        Event::factory()->create([
            'name' => 'Laravel Conf 2026',
            'capacity' => 100,
            'reserved_count' => 0,
        ]);

        Event::factory()->create([
            'name' => 'Almost Full Meetup',
            'capacity' => 5,
            'reserved_count' => 4,
        ]);

        Event::factory()->create([
            'name' => 'Sold Out Workshop',
            'capacity' => 1,
            'reserved_count' => 1,
        ]);
    }
}
