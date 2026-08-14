<?php

namespace Database\Factories;

use App\Models\LoopPermissionSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoopPermissionSetting> */
class LoopPermissionSettingFactory extends Factory
{
    protected $model = LoopPermissionSetting::class;

    public function definition(): array
    {
        return [
            'loop_type' => 'general',
            'loop_role' => 'facilitator',
            'permission' => 'manifesto.publish',
            'allowed' => true,
        ];
    }
}
