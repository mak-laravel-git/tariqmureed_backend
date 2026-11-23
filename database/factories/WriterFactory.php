<?php

namespace Database\Factories;

use App\Models\Writer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Writer>
 */
class WriterFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->name();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'title' => 'Senior cricket writer',
            'desk' => 'Cricket desk',
            'bio' => fake()->paragraph(),
            'status' => 'Active',
        ];
    }
}
