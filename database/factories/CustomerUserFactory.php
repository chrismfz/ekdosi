<?php

namespace Database\Factories;

use App\Models\CustomerUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<CustomerUser>
 */
class CustomerUserFactory extends Factory
{
    protected $model = CustomerUser::class;

    protected static ?string $password;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => CustomerUser::STATUS_ACTIVE,
        ];
    }

    /** An invited (not-yet-claimed) login: no password, cannot authenticate. */
    public function invited(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CustomerUser::STATUS_INVITED,
            'password' => null,
            'email_verified_at' => null,
        ]);
    }

    /** A suspended login: has a password but is blocked from logging in. */
    public function suspended(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => CustomerUser::STATUS_SUSPENDED,
        ]);
    }
}
