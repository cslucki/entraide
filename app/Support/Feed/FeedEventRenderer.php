<?php

namespace App\Support\Feed;

use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\Service;
use App\Models\ServiceRequest;
use App\Models\User;

/**
 * TASK-1752 — le libelle d'un evenement, CALCULE a l'affichage.
 *
 * ## Pourquoi rien n'est ecrit en base
 *
 * Ecrire « Jean a fini sa presentation » dans `content` casserait trois
 * choses, et le CDC les nomme une par une :
 *
 * - **la langue** — le produit est FR+EN (`AGENTS.md` §9). Un texte fige en
 *   francais serait lu tel quel par un compte anglophone ;
 * - **le nom** — fige au moment de l'ecriture. Un changement de nom, une
 *   desactivation de compte, et la phrase devient fausse. `publicDisplayName()`
 *   repond deja correctement — « Utilisateur desactive » le cas echeant — **a
 *   condition d'etre appele a l'affichage** ;
 * - **le temps du verbe** — « vient de » n'est vrai que le jour de la lecture.
 *
 * ## Et surtout : JAMAIS `markdown()`
 *
 * La carte rend `content` par `{!! markdown($post->content) !!}`. `markdown()`
 * est configure surement (`html_input => escape`), donc pas de faille HTML —
 * mais la **syntaxe Markdown reste active** : un nom contenant `[texte](url)`
 * produirait un lien. Un libelle rendu en Blade avec `{{ }}` n'a pas ce
 * probleme, et c'est pour cela que la carte a une branche distincte.
 *
 * ## L'objet peut avoir disparu
 *
 * Une Boucle archivee, une demande fermee, un Evenement annule : la ligne de
 * Flux survit. Le rendu doit donc tenir sans son objet, plutot que de laisser
 * un trou ou de lever.
 */
final class FeedEventRenderer
{
    /**
     * Le texte a afficher. `null` si la ligne n'est pas un evenement.
     */
    public static function libelle(FeedPost $post): ?string
    {
        $type = (string) $post->type;

        if (! FeedEventCatalogue::connait($type)) {
            return null;
        }

        $clef = FeedEventCatalogue::libelle($type);

        if ($clef === null) {
            return null;
        }

        return __($clef, [
            // `publicDisplayName()` est l'autorite : elle sait dire
            // « Utilisateur desactive » quand il le faut.
            'name' => $post->user?->publicDisplayName() ?? __('feed.member_fallback'),
            'object' => self::nomDeLObjet($post),
            // TASK-1757 — seul l'evenement d'entraide s'en sert. Les six autres
            // gabarits ne contiennent pas `:temps`, et `__()` ignore simplement
            // un parametre qu'aucun gabarit ne reclame.
            'temps' => self::tempsDeLEntraide($post),
        ]);
    }

    /**
     * TASK-1752 (design A, choix Cyril) — la LIGNE compacte, en HTML.
     *
     * ## Le contrat de securite, et il est strict
     *
     * Cette methode rend du HTML consomme avec `{!! !!}`. La regle qui rend
     * cela sur : **tout ce qui est dynamique passe par `e()`** — le gabarit
     * de traduction, le nom, le nom de l'objet, les URLs. Les seules balises
     * non echappees sont celles ecrites ICI, en dur. Un nom contenant
     * `<script>` ou `[texte](url)` sort donc en texte, jamais en balise.
     *
     * ## Pourquoi composer sur le GABARIT, placeholders intacts
     *
     * « :name a rejoint la Boucle :object » et « :name joined the :object
     * Loop » n'ont pas le meme ordre de mots. Decouper la phrase en morceaux
     * cote PHP aurait fige l'ordre francais ; substituer les placeholders
     * APRES coup laisse chaque langue ecrire sa phrase.
     */
    public static function ligneHtml(FeedPost $post, ?string $organizationSlug): ?string
    {
        $type = (string) $post->type;

        if (! FeedEventCatalogue::connait($type)) {
            return null;
        }

        $clef = FeedEventCatalogue::libelle($type);

        if ($clef === null) {
            return null;
        }

        $nom = $post->user?->publicDisplayName() ?? __('feed.member_fallback');
        $profil = self::lienProfil($post->user, $organizationSlug);

        $nomHtml = $profil !== null
            ? '<a href="'.e($profil).'" class="font-semibold text-gray-900 hover:underline dark:text-gray-100">'.e($nom).'</a>'
            : '<span class="font-semibold text-gray-500 dark:text-gray-400">'.e($nom).'</span>';

        $objetNom = self::nomDeLObjet($post);
        $objetUrl = self::lien($post, $organizationSlug);

        $objetHtml = $objetUrl !== null
            ? '<a href="'.e($objetUrl).'" class="font-medium text-indigo-600 hover:underline dark:text-indigo-400">'.e($objetNom).'</a>'
            : '<span class="font-medium">'.e($objetNom).'</span>';

        // `__($clef)` SANS remplacement rend le gabarit, `:name`, `:object` et
        // `:temps` intacts — aucun caractere que `e()` transformerait.
        //
        // TASK-1757 — `:temps` passe par `e()` comme tout le reste. Il vient
        // d'un entier, donc rien a echapper aujourd'hui ; l'echapper quand meme
        // est ce qui fait que le contrat de cette methode (« tout ce qui est
        // dynamique passe par `e()` ») se verifie a la lecture, sans avoir a
        // remonter a la source de chaque valeur.
        return str_replace(
            [':name', ':object', ':temps'],
            [$nomHtml, $objetHtml, '<span class="font-medium">'.e(self::tempsDeLEntraide($post)).'</span>'],
            e(__($clef))
        );
    }

    /**
     * Le lien vers le profil PUBLIC de l'acteur.
     *
     * `null` — donc pas de lien — pour un compte disparu ou DESACTIVE : sa
     * fiche n'a plus rien a montrer, et `publicDisplayName()` rend deja un
     * libelle neutre. Un lien qui mene a un refus est pire que pas de lien.
     */
    public static function lienProfil(?User $user, ?string $organizationSlug): ?string
    {
        if ($user === null || $organizationSlug === null || $user->isDeactivated()) {
            return null;
        }

        return self::route('organization.profile.show', [
            'organization' => $organizationSlug,
            'user' => $user->slug ?: $user->getKey(),
        ]);
    }

    /**
     * Le lien vers l'objet concerne, s'il est encore la et nommable.
     *
     * Rend `null` plutot qu'une adresse morte : un lien qui mene a un 404 est
     * pire que pas de lien.
     */
    public static function lien(FeedPost $post, ?string $organizationSlug): ?string
    {
        $objet = self::objet($post);

        if ($objet === null || $organizationSlug === null) {
            return null;
        }

        return match (true) {
            $objet instanceof LoopMember => (fn () => (($b = Loop::withoutGlobalScopes()->find($objet->loop_id)) === null) ? null : self::route('organization.loops.show', [
                'organization' => $organizationSlug,
                'loop' => $b->slug ?: $b->getKey(),
            ]))(),
            $objet instanceof Loop => self::route('organization.loops.show', [
                'organization' => $organizationSlug,
                'loop' => $objet->slug ?: $objet->getKey(),
            ]),
            // Le parametre s'appelle `{request}` et non `{serviceRequest}` :
            // avec le mauvais nom, `route()` levait et le repli rendait
            // `null` — la pastille « Voir » disparaissait EN SILENCE.
            // Verifie contre `route:list`, jamais devine.
            $objet instanceof ServiceRequest => self::route('organization.requests.show', [
                'organization' => $organizationSlug,
                'request' => $objet->getKey(),
            ]),
            $objet instanceof Service => self::route('organization.services.show', [
                'organization' => $organizationSlug,
                'service' => $objet->getKey(),
            ]),
            $objet instanceof User => self::route('organization.profile.show', [
                'organization' => $organizationSlug,
                'user' => $objet->slug ?: $objet->getKey(),
            ]),
            default => null,
        };
    }

    private static function route(string $nom, array $parametres): ?string
    {
        if (! \Illuminate\Support\Facades\Route::has($nom)) {
            return null;
        }

        return rescue(fn () => route($nom, $parametres), null, report: false);
    }

    /**
     * TASK-1752 (demande Cyril) — la PIECE JOINTE graphique d'un evenement.
     *
     * La ligne compacte reste le socle ; sous elle, chaque type montre ce
     * qu'il a de plus parlant : l'extrait de presentation et la photo pour
     * une personne, l'image de couverture pour une Boucle, l'extrait du
     * texte pour une demande ou une proposition.
     *
     * Tout est resolu A L'AFFICHAGE, comme le libelle : une bio reecrite, une
     * couverture changee, un compte desactive — l'apercu suit, jamais gele.
     *
     * La photo n'est montree QUE si une image a reellement ete deposee
     * (`filled($user->avatar)`) : `avatar_url` retombe sinon sur ui-avatars,
     * un tiers a qui partirait le nom de la personne — la regle du composant
     * `x-user-avatar`, reprise ici.
     *
     * `null` = pas de piece jointe, et la ligne se suffit. Jamais de boite
     * vide.
     *
     * @return array{url: ?string, image: ?string, ronde: bool, titre: ?string, texte: ?string}|null
     */
    public static function apercu(FeedPost $post, ?string $organizationSlug): ?array
    {
        $type = (string) $post->type;

        if (! FeedEventCatalogue::connait($type)) {
            return null;
        }

        if ($type === FeedEventCatalogue::PRESENTATION_TERMINEE) {
            $personne = $post->user;

            if ($personne === null || $personne->isDeactivated() || blank($personne->bio)) {
                return null;
            }

            return [
                'url' => self::lienProfil($personne, $organizationSlug),
                'image' => filled($personne->avatar) ? $personne->avatar_url : null,
                'ronde' => true,
                'titre' => null,
                'texte' => \Illuminate\Support\Str::limit(trim((string) $personne->bio), 180),
            ];
        }

        $objet = self::objet($post);

        if ($objet === null) {
            return null;
        }

        $boucle = match (true) {
            $objet instanceof Loop => $objet,
            $objet instanceof LoopMember => Loop::withoutGlobalScopes()->find($objet->loop_id),
            default => null,
        };

        if ($boucle !== null) {
            return [
                'url' => self::lien($post, $organizationSlug),
                'image' => $boucle->cover_image_url,
                'ronde' => false,
                'titre' => (string) $boucle->name,
                'texte' => filled($boucle->tagline)
                    ? (string) $boucle->tagline
                    : \Illuminate\Support\Str::limit(trim((string) $boucle->description), 140),
            ];
        }

        if ($objet instanceof Service || $objet instanceof ServiceRequest) {
            return [
                'url' => self::lien($post, $organizationSlug),
                'image' => null,
                'ronde' => false,
                'titre' => (string) $objet->title,
                'texte' => \Illuminate\Support\Str::limit(trim((string) $objet->description), 160),
            ];
        }

        if ($objet instanceof LoopEvent) {
            return [
                'url' => null,
                'image' => null,
                'ronde' => false,
                'titre' => (string) $objet->title,
                'texte' => $objet->starts_at?->isoFormat('LLL'),
            ];
        }

        return null;
    }

    /**
     * La categorie de l'annonce qui portait l'entraide.
     *
     * Repli NEUTRE et non « un contenu supprime » : une annonce sans categorie
     * n'est pas une annonce disparue, et afficher « supprime » sur une entraide
     * bien reelle serait faux.
     */
    private static function categorieDeLEntraide(\App\Models\Transaction $echange): string
    {
        $annonce = $echange->service ?? $echange->serviceRequest;

        // `displayName('transactions')` et NON `->name` : `categories` n'a pas
        // de colonne `name` — elle porte `name_b2c` et `name_b2b`, et
        // l'Organization choisit le registre par `transactions_naming`.
        //
        // Defaut trouve par le test de ce lot : `->name` rendait `null` SANS
        // erreur, et la carte retombait donc toujours sur le libelle neutre.
        // Une categorie ne se serait jamais affichee, en silence.
        $categorie = $annonce?->category?->displayName('transactions');

        return $categorie !== null && $categorie !== ''
            ? (string) $categorie
            : __('feed.events.help_category_fallback');
    }

    /** Le temps partage, pour le seul gabarit qui le demande. */
    private static function tempsDeLEntraide(FeedPost $post): string
    {
        $objet = self::objet($post);

        if (! $objet instanceof \App\Models\Transaction) {
            return '';
        }

        return \App\Support\Entraide\TempsPartage::duree($objet->points_agreed ?? $objet->points_proposed);
    }

    private static function nomDeLObjet(FeedPost $post): string
    {
        $objet = self::objet($post);

        return match (true) {
            // Une appartenance nomme SA Boucle : c'est d'elle que parle la
            // phrase, l'appartenance n'est que la reference unique du fait.
            $objet instanceof LoopMember => (string) (Loop::withoutGlobalScopes()->find($objet->loop_id)?->name ?? __('feed.events.object_gone')),
            $objet instanceof Loop => (string) $objet->name,
            $objet instanceof ServiceRequest, $objet instanceof Service => (string) $objet->title,
            $objet instanceof LoopEvent => (string) $objet->title,
            $objet instanceof User => $objet->publicDisplayName(),
            // TASK-1757 §9.5 — l'objet d'une entraide est sa CATEGORIE, et non
            // le titre de l'annonce ni le nom du beneficiaire.
            //
            // Arbitrage Cyril du 05/11 : seul l'aidant est nomme. La categorie
            // donne le contexte utile (« Bricolage ») sans designer personne,
            // et elle reste vraie quand l'annonce a ete retiree depuis.
            $objet instanceof \App\Models\Transaction => self::categorieDeLEntraide($objet),
            default => __('feed.events.object_gone'),
        };
    }

    /**
     * L'objet metier, relu SANS les scopes globaux.
     *
     * `Service` et `ServiceRequest` portent `BelongsToOrganizationScope`. Ce
     * rendu peut tourner hors d'une requete HTTP — la page SuperAdmin lit
     * plusieurs tenants, et un worker n'a pas d'Organization courante : le
     * scope rendrait l'objet introuvable et le libelle afficherait
     * « un contenu supprime » pour un objet bien vivant.
     *
     * La frontiere de locataire n'est pas perdue : la LIGNE de Flux porte deja
     * `organization_id`, et c'est elle qui a ete filtree en amont.
     */
    /** @var array<string, mixed> memo par ligne — voir `objet()` */
    private static array $objets = [];

    private static function objet(FeedPost $post): mixed
    {
        // MEMOISE : `ligneHtml()` (nom + lien) et `apercu()` resolvent le
        // meme objet. Sans ce cache, chaque ligne du Flux coutait TROIS fois
        // la meme requete — la garde de pente de T1640 finirait par le voir.
        if (array_key_exists((string) $post->id, self::$objets)) {
            return self::$objets[(string) $post->id];
        }

        $classe = $post->object_type;

        if (! is_string($classe) || $post->object_id === null || ! class_exists($classe)) {
            return self::$objets[(string) $post->id] = null;
        }

        return self::$objets[(string) $post->id] = rescue(
            fn () => $classe::withoutGlobalScopes()->whereKey($post->object_id)->first(),
            null,
            report: false,
        );
    }
}
