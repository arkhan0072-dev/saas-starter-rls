<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Create projects inside Tenancy::run(); the tenant comes from there.
 *
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => fake()->words(2, true)];
    }
}
