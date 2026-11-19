<?php

namespace Database\Factories;

use App\Enums\SyncTarget;
use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'target' => SyncTarget::FIXTURES->value,
            'status' => SyncRun::SUCCESS,
            'records_synced' => fake()->numberBetween(1, 300),
            'message' => null,
            'started_at' => now()->subMinutes(5),
            'finished_at' => now()->subMinutes(4),
        ];
    }
}
