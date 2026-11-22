<?php

declare(strict_types=1);

namespace Modules\Identity\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Identity\Models\User;
use Modules\Identity\Support\UserStatus;

/**
 * Users must be created in the platform context (row-level security on users).
 *
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone_e164' => '+2782'.$this->faker->unique()->numerify('#######'),
            'locale' => 'en',
            'status' => UserStatus::Active,
            'password' => 'correct horse battery staple',
            'email_verified_at' => now(),
        ];
    }
}
