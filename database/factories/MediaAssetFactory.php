<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['title' => fake()->words(2, true), 'path' => 'media/'.fake()->uuid().'.jpg', 'mime' => 'image/jpeg', 'size' => fake()->numberBetween(20_000, 900_000)];
    }
}
