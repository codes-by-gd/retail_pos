<?php

namespace Database\Factories;

use App\Modules\Contacts\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contact> */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'code' => 'CUS-'.fake()->unique()->numerify('#####'),
            'type' => Contact::CUSTOMER,
            'name' => fake()->name(),
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'state_code' => '24',
            'credit_limit' => 0,
            'is_active' => true,
        ];
    }

    public function supplier(): static
    {
        return $this->state(fn () => ['type' => Contact::SUPPLIER, 'code' => 'SUP-'.fake()->unique()->numerify('#####')]);
    }
}
