<?php

namespace App\Services;

use App\Jobs\ProcessImageVariants;
use App\Models\BlogPost;
use App\Models\Category;
use App\Models\ImageVariant;
use App\Models\Loop;
use App\Models\Organization;
use App\Models\Scopes\BelongsToOrganizationScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * TASK-1882 (demande Cyril, 22/11) — CLONER un compte vers une autre
 * Organization, en remplacement du transfert (un compte ne change plus
 * d'Organization depuis TASK-1726, et le transfert oubliait des donnees).
 *
 * Copie VOLONTAIREMENT restreinte : identite et presentation, mot de passe,
 * photo, points, billets de blog (couverture et images de l'article copiees)
 * et leur Boucle. Rien d'autre : ni co-auteurs, ni etiquettes, ni annotations,
 * ni demandes, ni messages. Le compte d'origine n'est pas modifie.
 *
 * Les fichiers sont COPIES, jamais partages : supprimer l'un des deux comptes
 * ou billets ne casse pas l'image de l'autre.
 */
final class AccountCloner
{
    /** Colonnes recopiees telles quelles. */
    private const CHAMPS_COMPTE = [
        'first_name', 'name', 'email', 'email_verified_at', 'password',
        'bio', 'slogan', 'structure', 'location', 'website', 'linkedin_url', 'profile_links',
        'phone', 'show_email', 'show_phone',
        'address_line1', 'address_line2', 'postal_code', 'city', 'country_code',
        'preferred_locale', 'membership_value', 'is_available', 'points_balance', 'approved_at',
    ];

    private const CHAMPS_BILLET = [
        'title', 'summary', 'status', 'published_at', 'meta_title', 'meta_description',
        'show_toc', 'toc_max_level', 'toc_navigation_enabled', 'audience', 'listed_in_blog',
        'source_url', 'source_published_at',
    ];

    /** @var list<string> fichiers copies, effaces si le clonage echoue */
    private array $copies = [];

    public function __construct(private readonly UserSlugService $slugs) {}

    /** Les comptes d'une Organization, hors portee de tenant (contexte superadmin). */
    public static function comptesDe(string $organizationId)
    {
        return User::query()
            ->withoutGlobalScope(BelongsToOrganizationScope::class)
            ->where('organization_id', $organizationId);
    }

    public static function emailPrisDans(string $email, string $organizationId): bool
    {
        return self::comptesDe($organizationId)->whereRaw('lower(email) = ?', [Str::lower($email)])->exists();
    }

    /**
     * @param  array<string, array{category_id?: ?string, loop_id?: ?string}>  $options  par identifiant de billet
     * @return array{0: User, 1: int} le clone et le nombre de billets copies
     */
    public function cloner(User $source, Organization $cible, array $options = []): array
    {
        if ((string) $source->organization_id === (string) $cible->id) {
            throw new RuntimeException('Le compte appartient deja a cette organisation.');
        }

        if (self::emailPrisDans((string) $source->email, (string) $cible->id)) {
            throw new RuntimeException("Un compte {$source->email} existe deja dans {$cible->name}.");
        }

        $this->copies = [];

        try {
            return DB::transaction(function () use ($source, $cible, $options) {
                $clone = new User;
                $clone->forceFill($this->valeurs($source, self::CHAMPS_COMPTE));
                $clone->forceFill([
                    'organization_id' => $cible->id,
                    'is_admin' => false,
                    'must_set_password' => false,
                    'avatar' => $this->copierFichier($source->avatar, 'avatars'),
                ]);
                $clone->save();

                $slug = $source->slug && $this->slugs->disponiblePour($clone, $source->slug) ? $source->slug : null;
                $slug ? $this->slugs->attribuer($clone, $slug) : $this->slugs->attribuerDepuisLeNom($clone);

                if ($clone->avatar) {
                    ProcessImageVariants::forUpload('user_avatar', $clone->avatar, (string) $cible->id);
                }

                $billets = BlogPost::query()->where('user_id', $source->id)->with('loops')->orderBy('created_at')->get();

                foreach ($billets as $billet) {
                    $this->clonerBillet($billet, $clone, $cible, $options[(string) $billet->id] ?? []);
                }

                return [$clone, $billets->count()];
            });
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($this->copies);

            throw $e;
        }
    }

    private function clonerBillet(BlogPost $billet, User $clone, Organization $cible, array $option): void
    {
        $categorie = filled($option['category_id'] ?? null)
            ? Category::query()->where('organization_id', $cible->id)->whereKey($option['category_id'])->value('id')
            : null;

        $copie = new BlogPost;
        $copie->forceFill($this->valeurs($billet, self::CHAMPS_BILLET));
        $copie->forceFill([
            'user_id' => $clone->id,
            'organization_id' => $cible->id,
            'category_id' => $categorie,
            'slug' => $billet->slug,
            'image' => $this->copierFichier($billet->image, 'blog'),
            'content' => $this->copierImagesDuContenu((string) $billet->content, 'blog/images/'.$cible->id.'/'.$clone->id),
            'views_count' => 0,
        ]);
        $copie->save();

        if ($copie->image) {
            ProcessImageVariants::forUpload('blog_cover', $copie->image, (string) $cible->id);
        }

        // La Boucle n'est proposee que si le billet d'origine en avait une.
        if ($billet->loops->isNotEmpty() && filled($option['loop_id'] ?? null)) {
            $boucle = Loop::query()->where('organization_id', $cible->id)->whereKey($option['loop_id'])->value('id');

            if ($boucle) {
                $copie->loops()->attach($boucle);
            }
        }
    }

    /**
     * Les valeurs renseignees seulement : une valeur vide laisse a la colonne son
     * defaut (`show_email`, `points_balance`...) au lieu d'y ecrire NULL.
     */
    private function valeurs(\Illuminate\Database\Eloquent\Model $modele, array $champs): array
    {
        return collect($champs)
            ->mapWithKeys(fn ($c) => [$c => $modele->getAttribute($c)])
            ->reject(fn ($v) => $v === null)
            ->all();
    }

    /** Copie un fichier du disque public sous `$dossier`, nom neuf ; null si absent ou externe. */
    private function copierFichier(?string $chemin, string $dossier): ?string
    {
        $disque = Storage::disk('public');

        if (blank($chemin) || preg_match('#^https?://#i', $chemin) === 1 || ! $disque->exists($chemin)) {
            return null;
        }

        $nouveau = $dossier.'/'.Str::uuid().'.'.(pathinfo($chemin, PATHINFO_EXTENSION) ?: 'jpg');
        $disque->copy($chemin, $nouveau);
        $this->copies[] = $nouveau;

        return $nouveau;
    }

    /**
     * Les images de l'article hebergees chez nous sont copiees et leur URL
     * reecrite ; une variante optimisee est ramenee a son original. Une image
     * externe (LinkedIn...) reste telle quelle.
     */
    private function copierImagesDuContenu(string $html, string $dossier): string
    {
        if (! preg_match_all('/<img[^>]+src="([^"]+)"/i', $html, $trouvees)) {
            return $html;
        }

        $disque = Storage::disk('public');
        $base = rtrim($disque->url(''), '/');

        foreach (array_unique($trouvees[1]) as $url) {
            $brute = html_entity_decode($url);
            $chemin = match (true) {
                str_starts_with($brute, $base.'/') => substr($brute, strlen($base) + 1),
                str_starts_with($brute, '/storage/') => substr($brute, strlen('/storage/')),
                default => null,
            };

            if ($chemin === null) {
                continue;
            }

            $chemin = rawurldecode(strtok($chemin, '?'));
            $chemin = ImageVariant::query()->where('path', $chemin)->value('source_path') ?? $chemin;

            if ($nouveau = $this->copierFichier($chemin, $dossier)) {
                $html = str_replace($url, $disque->url($nouveau), $html);
            }
        }

        return $html;
    }
}
