<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => null,
            // El modelo la guarda con hash ("hashed" en casts).
            'password' => 'password',
        ];
    }

    /** Cuenta creada con Google: sin contraseña. */
    public function google(string $googleId = 'google-123'): static
    {
        return $this->state(fn () => ['password' => null, 'google_id' => $googleId]);
    }
}
