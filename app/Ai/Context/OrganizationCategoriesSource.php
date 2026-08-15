<?php

namespace App\Ai\Context;

use App\Ai\ContexteIa;
use App\Models\Category;
use App\Models\Organization;
use Illuminate\Support\Str;

/**
 * Categories de demandes autorisees dans l'Organization du contexte.
 *
 * Une categorie est exposee par son UUID canonique, son libelle metier et un
 * extrait borne des services configures. Le modele ne recoit ainsi que les
 * identifiants qu'il a le droit de recopier dans `suggested_category_id`.
 */
class OrganizationCategoriesSource implements ContextSource
{
    public const NAME = 'organization.categories';

    public function name(): string
    {
        return self::NAME;
    }

    public function collect(ContexteIa $contexte, int $charBudget): SourceFragment
    {
        $organization = Organization::query()
            ->whereKey($contexte->organizationId)
            ->first(['id', 'transactions_naming']);

        if ($organization === null) {
            return SourceFragment::empty();
        }

        $nameColumn = $organization->transactions_naming === 'b2b' ? 'name_b2b' : 'name_b2c';
        $categories = Category::query()
            ->where('organization_id', $contexte->organizationId)
            ->orderBy($nameColumn)
            ->get([
                'id', 'organization_id', 'name_b2c', 'name_b2b',
                'service_1', 'service_2', 'service_3', 'service_4', 'service_5',
            ]);

        $lines = [];
        $provenance = [];
        $length = 0;

        foreach ($categories as $category) {
            $label = trim((string) $category->{$nameColumn});
            $services = collect(range(1, 5))
                ->map(fn (int $index): string => trim((string) $category->{'service_'.$index}))
                ->filter()
                ->take(3)
                ->map(fn (string $service): string => Str::limit($service, 120))
                ->implode(', ');
            $line = '- '.$category->id.' | '.$label;

            if ($services !== '') {
                $line .= ' | '.$services;
            }

            if ($length > 0 && $length + mb_strlen($line) + 1 > $charBudget) {
                break;
            }

            $lines[] = $line;
            $provenance[] = [
                'source' => self::NAME,
                'id' => (string) $category->id,
                'type' => 'direct',
                'extrait' => $label,
            ];
            $length += mb_strlen($line) + 1;
        }

        if ($lines === []) {
            return SourceFragment::empty();
        }

        return new SourceFragment(
            "--- CATÉGORIES AUTORISÉES ---\n".implode("\n", $lines)."\n--- FIN DES CATÉGORIES ---",
            $provenance,
        );
    }
}
