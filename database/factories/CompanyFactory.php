<?php

namespace Database\Factories;

use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 9999),
            'country_code' => 'GR',
            'einvoice_provider' => 'gr-mydata',
            'afm' => $this->faker->numerify('#########'),
            'tax_office' => $this->faker->city(),
            'address' => $this->faker->streetAddress(),
            'city' => $this->faker->city(),
            'postcode' => $this->faker->postcode(),
            'phone' => $this->faker->phoneNumber(),
            'email' => $this->faker->safeEmail(),
            'mydata_aade_id' => null,
            'mydata_subscription_key' => null,
            'mydata_production' => false,
        ];
    }

    public function estonian(): static
    {
        return $this->state(fn () => [
            'country_code' => 'EE',
            'einvoice_provider' => 'ee-peppol',
            'afm' => null,
            'tax_office' => null,
            'mydata_aade_id' => null,
            'mydata_subscription_key' => null,
        ]);
    }
}
