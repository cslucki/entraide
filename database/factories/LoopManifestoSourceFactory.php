<?php

namespace Database\Factories;

use App\Models\DossierFile;
use App\Models\Loop;
use App\Models\LoopManifestoSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoopManifestoSource> */
class LoopManifestoSourceFactory extends Factory
{
    protected $model = LoopManifestoSource::class;

    public function definition(): array
    {
        $loop = Loop::factory();

        return [
            'loop_id' => $loop,
            'organization_id' => fn (array $attrs) => Loop::find($attrs['loop_id'])?->organization_id,
            'dossier_file_id' => DossierFile::factory(),
            'added_by' => User::factory(),
        ];
    }
}
