<?php

namespace Database\Factories;

use App\Models\Loop;
use App\Models\LoopCard;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoopCard> */
class LoopCardFactory extends Factory
{
    protected $model = LoopCard::class;

    public function definition(): array
    {
        $loop = Loop::factory();

        return [
            'loop_id' => $loop,
            'organization_id' => fn (array $attrs) => Loop::find($attrs['loop_id'])?->organization_id,
            'card_key' => 'core.manifesto',
            'enabled' => true,
            'added_by_preset' => null,
        ];
    }
}
