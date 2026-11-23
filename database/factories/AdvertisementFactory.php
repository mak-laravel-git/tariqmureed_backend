<?php

namespace Database\Factories;

use App\Models\Advertisement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Advertisement>
 */
class AdvertisementFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true).' campaign',
            'placement' => 'home-top',
            'advertiser' => fake()->company(),
            'type' => Advertisement::TYPE_IMAGE,
            'image' => 'https://example.com/banner.jpg',
            'link_url' => 'https://example.com/offer',
            'status' => 'Active',
        ];
    }

    public function adsense(string $slot = '1234567890'): static
    {
        return $this->state(fn (): array => ['type' => Advertisement::TYPE_ADSENSE, 'image' => null, 'link_url' => null, 'adsense_slot' => $slot]);
    }
}
