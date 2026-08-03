<?php

namespace Database\Factories;

use App\Models\Dossier;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DossierFile> */
class DossierFileFactory extends Factory
{
    protected $model = DossierFile::class;

    public function definition(): array
    {
        $name = fake()->words(2, true).'.pdf';

        return [
            'organization_id' => Organization::factory(),
            'dossier_id' => null,
            'uploaded_by' => User::factory(),
            'disk' => 'local',
            'path' => 'dossiers/'.fake()->uuid().'.pdf',
            'original_name' => $name,
            'display_name' => $name,
            'mime_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1024, 5_000_000),
            'checksum_sha256' => hash('sha256', fake()->uuid()),
            'source' => 'upload',
        ];
    }

    /** Attached to a Dossier of the same Organization. */
    public function inDossier(?Dossier $dossier = null): static
    {
        return $this->state(fn (array $attrs) => [
            'dossier_id' => $dossier?->id ?? Dossier::factory()->create([
                'organization_id' => $attrs['organization_id'],
            ])->id,
        ]);
    }
}
