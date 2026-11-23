<?php

namespace App\Support\Email;

use App\Models\BlogPost;
use App\Models\LoopEvent;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceRequest;
use Illuminate\Support\Collection;

/**
 * TASK-1883 (demande Cyril, 22/11) — les blocs `{demande_aide;N}` et
 * `{proposition_aide;N}` de la newsletter.
 *
 * Developpes AVANT `EmailerService::interpolate()`, qui ne connait que
 * `{{ variable }}` et echappe chaque valeur : le bloc est du HTML que l'on
 * construit ici, chaque texte passe par `e()`.
 *
 * Toujours l'organisation du DESTINATAIRE, posee comme tenant le temps de la
 * requete : dans un job de la Managed Queue il n'y a pas de tenant (la portee
 * refuse alors tout), ou c'est celui du job precedent. Un membre ne voit
 * jamais une demande d'une autre organisation.
 *
 * Les blocs sont memorises par (organisation, langue, type, N) le temps d'UNE
 * instance : une instance par job, rien n'est garde d'un job a l'autre.
 */
final class HelpDigestBlocks
{
    public const DEMANDES = 'demande_aide';

    public const PROPOSITIONS = 'proposition_aide';

    /** Demande Cyril (22/11) : les derniers billets de blog, en option. */
    public const BILLETS = 'billets_blog';

    /**
     * TASK-1891 (demande Cyril, 23/11) — les prochains evenements, EN TETE de
     * la newsletter : ceux des Boucles, « sauf les Boucles privees, accessibles
     * sur invitation ». Donc tout evenement d'une Boucle PUBLIQUE, plus ceux
     * qu'un auteur a remontes a toute l'organisation. Le bloc est le meme pour
     * tous les membres : rien d'une Boucle privee n'y entre autrement.
     */
    public const EVENEMENTS = 'evenements';

    public const MAX = 10;

    public const MOTIF = '/\{(demande_aide|proposition_aide|billets_blog|evenements);(\d{1,2})\}/';

    /** @var array<string, Collection> */
    private array $elements = [];

    public static function contient(string $html): bool
    {
        return preg_match(self::MOTIF, $html) === 1;
    }

    /**
     * Remplace chaque bloc. `$nDemandes` / `$nPropositions`, quand ils sont
     * donnes (ecran d'envoi), remplacent le N ecrit dans le gabarit. Un N hors
     * de 1..MAX laisse le bloc tel quel.
     */
    public function developper(string $html, Organization $organization, string $langue, ?int $nDemandes = null, ?int $nPropositions = null, ?int $nBillets = null, ?int $nEvenements = null): string
    {
        return preg_replace_callback(self::MOTIF, function (array $m) use ($organization, $langue, $nDemandes, $nPropositions, $nBillets, $nEvenements) {
            $type = $m[1];
            $n = match ($type) {
                self::DEMANDES => $nDemandes,
                self::PROPOSITIONS => $nPropositions,
                self::EVENEMENTS => $nEvenements,
                default => $nBillets,
            } ?? (int) $m[2];

            // Billets, evenements : 0 demande « rien », titre de section compris.
            if (in_array($type, [self::BILLETS, self::EVENEMENTS], true) && $n === 0) {
                return '';
            }

            if ($n < 1 || $n > self::MAX) {
                return $m[0];
            }

            return $this->bloc($organization, $langue, $type, $n);
        }, $html);
    }

    /** Vrai si l'organisation a au moins une demande ouverte ou une proposition active a montrer. */
    public function aDuContenu(Organization $organization, int $nDemandes, int $nPropositions, int $nBillets = 0, int $nEvenements = 0): bool
    {
        return ($nDemandes > 0 && $this->elements($organization, self::DEMANDES, $nDemandes)->isNotEmpty())
            || ($nPropositions > 0 && $this->elements($organization, self::PROPOSITIONS, $nPropositions)->isNotEmpty())
            || ($nBillets > 0 && $this->elements($organization, self::BILLETS, $nBillets)->isNotEmpty())
            || ($nEvenements > 0 && $this->elements($organization, self::EVENEMENTS, $nEvenements)->isNotEmpty());
    }

    private function bloc(Organization $organization, string $langue, string $type, int $n): string
    {
        $elements = $this->elements($organization, $type, $n);
        $fr = $langue !== 'en';

        if ($type === self::BILLETS) {
            return $this->blocBillets($organization, $elements, $fr);
        }

        if ($type === self::EVENEMENTS) {
            return $this->blocEvenements($organization, $elements, $langue);
        }

        if ($elements->isEmpty()) {
            $vide = $type === self::DEMANDES
                ? ($fr ? 'Aucune demande d’aide en cours.' : 'No open help request.')
                : ($fr ? 'Aucune proposition d’aide en cours.' : 'No help offer right now.');

            return '<p style="color:#6b7280">'.e($vide).'</p>';
        }

        $lignes = $elements->map(function ($element) use ($organization, $type) {
            $url = $this->url($organization, $type, (string) $element->id);
            $auteur = trim((string) ($element->user?->first_name ?: $element->user?->name));
            // Les descriptions sont saisies en Markdown : rendu puis texte brut,
            // sinon `**gras**` et `\*` arrivent tels quels dans le courriel.
            $texte = html_entity_decode(strip_tags(\Illuminate\Support\Str::markdown((string) $element->description, ['html_input' => 'strip'])), ENT_QUOTES | ENT_HTML5);
            $resume = \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', $texte)), 140);

            return '<li style="margin:0 0 12px">'
                .'<a href="'.e($url).'" style="font-weight:600">'.e((string) $element->title).'</a>'
                .($auteur !== '' ? ' <span style="color:#6b7280">— '.e($auteur).'</span>' : '')
                .($resume !== '' ? '<br><span style="color:#374151">'.e($resume).'</span>' : '')
                .'</li>';
        })->implode('');

        return '<ul style="padding-left:18px;margin:8px 0 16px">'.$lignes.'</ul>';
    }

    private function elements(Organization $organization, string $type, int $n): Collection
    {
        $cle = $organization->id.'|'.$type.'|'.$n;

        return $this->elements[$cle] ??= $this->dans($organization, fn () => match ($type) {
            // Les plus proches d'abord ; ni annules, ni termines.
            self::EVENEMENTS => LoopEvent::query()
                ->where('organization_id', $organization->id)
                ->where(fn ($q) => $q->where('visibility', LoopEvent::VISIBILITY_ORGANIZATION)
                    ->orWhereHas('loop', fn ($boucle) => $boucle->where('visibility', 'public')))
                ->with('loop:id,name,slug,visibility,organization_id')
                ->where('status', '!=', LoopEvent::STATUS_CANCELLED)
                ->where(fn ($q) => $q->where('ends_at', '>', now())
                    ->orWhere(fn ($q) => $q->whereNull('ends_at')->where('starts_at', '>', now())))
                ->orderBy('starts_at')
                ->limit($n)
                ->get(['id', 'title', 'description', 'starts_at', 'ends_at', 'timezone', 'format', 'location', 'meeting_url', 'organization_id', 'loop_id', 'status', 'visibility']),
            // Billets publies et listes dans le blog, les plus recents d'abord.
            self::BILLETS => BlogPost::query()->published()
                ->where('organization_id', $organization->id)
                ->with('user')
                ->latest('published_at')
                ->limit($n)
                ->get(['id', 'title', 'slug', 'summary', 'user_id', 'organization_id', 'published_at']),
            default => ($type === self::DEMANDES ? ServiceRequest::query()->open() : Service::query()->active())
                ->where('organization_id', $organization->id)
                ->with('user')
                ->latest()
                ->limit($n)
                ->get(['id', 'title', 'description', 'user_id', 'organization_id', 'created_at']),
        });
    }

    /** Titre de section COMPRIS : sans billet, rien du tout. */
    private function blocBillets(Organization $organization, Collection $billets, bool $fr): string
    {
        if ($billets->isEmpty()) {
            return '';
        }

        $lignes = $billets->map(function (BlogPost $billet) use ($organization) {
            $url = $organization->is_default
                ? route('blog.show', ['post' => $billet->slug])
                : route('organization.blog.show', ['organization' => $organization->slug, 'post' => $billet->slug]);
            $auteur = trim((string) ($billet->user?->first_name ?: $billet->user?->name));
            $resume = \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $billet->summary))), 140);

            return '<li style="margin:0 0 12px">'
                .'<a href="'.e($url).'" style="font-weight:600">'.e((string) $billet->title).'</a>'
                .($auteur !== '' ? ' <span style="color:#6b7280">— '.e($auteur).'</span>' : '')
                .($resume !== '' ? '<br><span style="color:#374151">'.e($resume).'</span>' : '')
                .'</li>';
        })->implode('');

        return '<h2 style="font-size:18px;margin:24px 0 8px">'.e($fr ? 'À lire sur le blog' : 'On the blog').'</h2>'
            .'<ul style="padding-left:18px;margin:8px 0 16px">'.$lignes.'</ul>';
    }

    /**
     * TASK-1891 — section « Agenda » : titre (lien vers l'agenda ou la Boucle
     * publique) — Boucle, date et heure, description, lien de connexion
     * et/ou lieu selon le format. Titre de section COMPRIS : sans evenement, rien du tout.
     */
    private function blocEvenements(Organization $organization, Collection $evenements, string $langue): string
    {
        if ($evenements->isEmpty()) {
            return '';
        }

        $fr = $langue !== 'en';
        $agenda = $organization->is_default
            ? route('events.agenda')
            : route('organization.events.agenda', ['organization' => $organization->slug]);

        $lignes = $evenements->map(function (LoopEvent $evenement) use ($organization, $agenda, $langue, $fr) {
            // Remonte a toute l'organisation : l'agenda le montre a chacun.
            // Sinon (Boucle publique) : la Boucle, que tout membre peut ouvrir.
            $boucle = $evenement->loop;
            $lien = $evenement->visibility === LoopEvent::VISIBILITY_ORGANIZATION || $boucle === null
                ? $agenda
                : route('organization.loops.show', ['organization' => $organization->slug, 'loop' => $boucle->slug ?: $boucle->getKey()]);
            $quand = $evenement->startsAtLocal()->locale($langue)->isoFormat($fr ? 'dddd D MMMM, HH:mm' : 'dddd, MMMM D, h:mm A');

            // Demande Cyril (23/11) : titre, description, lien de connexion ou lieu.
            $texte = html_entity_decode(strip_tags(\Illuminate\Support\Str::markdown((string) $evenement->description, ['html_input' => 'strip'])), ENT_QUOTES | ENT_HTML5);
            $description = \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', $texte)), 220);

            $distance = in_array($evenement->format, [LoopEvent::FORMAT_ONLINE, LoopEvent::FORMAT_HYBRID], true);
            $presence = in_array($evenement->format, [LoopEvent::FORMAT_IN_PERSON, LoopEvent::FORMAT_HYBRID], true);
            $connexion = trim((string) $evenement->meeting_url);
            // Seul un lien http(s) devient cliquable (valide a la creation, revalide ici).
            $connexion = $distance && preg_match('#^https?://#i', $connexion) ? $connexion : '';
            $lieu = $presence ? trim((string) $evenement->location) : '';

            $acces = '';
            if ($connexion !== '') {
                $acces .= '<br><span style="color:#374151">'.e($fr ? 'Lien de connexion : ' : 'Join link: ')
                    .'<a href="'.e($connexion).'">'.e($connexion).'</a></span>';
            } elseif ($distance) {
                $acces .= '<br><span style="color:#374151">'.e($fr ? 'En ligne' : 'Online').'</span>';
            }
            if ($lieu !== '') {
                $acces .= '<br><span style="color:#374151">'.e(($fr ? 'Lieu : ' : 'Location: ').$lieu).'</span>';
            }

            return '<li style="margin:0 0 14px">'
                .'<a href="'.e($lien).'" style="font-weight:600">'.e((string) $evenement->title).'</a>'
                .($boucle?->name ? ' <span style="color:#6b7280">— '.e((string) $boucle->name).'</span>' : '')
                .'<br><span style="color:#374151">'.e(\Illuminate\Support\Str::ucfirst($quand)).'</span>'
                .($description !== '' ? '<br><span style="color:#374151">'.e($description).'</span>' : '')
                .$acces
                .'</li>';
        })->implode('');

        return '<h2 style="font-size:18px;margin:24px 0 8px">Agenda</h2>'
            .'<ul style="padding-left:18px;margin:8px 0 16px">'.$lignes.'</ul>';
    }

    /**
     * L'organisation du destinataire devient le tenant le temps de la requete :
     * les portees `BelongsToOrganizationScope` (demandes, auteurs) s'appliquent
     * a ELLE, et le tenant precedent est rendu ensuite.
     */
    private function dans(Organization $organization, callable $requete): mixed
    {
        $avait = app()->bound('current_organization');
        $precedent = $avait ? app('current_organization') : null;
        app()->instance('current_organization', $organization);

        try {
            return $requete();
        } finally {
            $avait ? app()->instance('current_organization', $precedent) : app()->forgetInstance('current_organization');
        }
    }

    private function url(Organization $organization, string $type, string $id): string
    {
        $nom = $type === self::DEMANDES ? 'requests.show' : 'services.show';
        $param = $type === self::DEMANDES ? 'request' : 'service';

        return $organization->is_default
            ? route($nom, [$param => $id])
            : route('organization.'.$nom, ['organization' => $organization->slug, $param => $id]);
    }
}
