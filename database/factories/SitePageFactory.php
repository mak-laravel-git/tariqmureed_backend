<?php

namespace Database\Factories;

use App\Models\SitePage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SitePage>
 */
class SitePageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => 'privacy-policy',
            'title' => 'Privacy Policy',
            'description' => fake()->sentence(),
            'content' => "## Who we are\n\n".fake()->paragraph()."\n\n## What we collect\n\n- ".fake()->sentence()."\n- ".fake()->sentence(),
        ];
    }
}
