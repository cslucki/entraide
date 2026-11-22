<?php

namespace App\Support\Feed;

use App\Models\BlogPost;
use App\Models\FeedPost;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopMember;
use App\Models\LoopMessage;
use App\Models\LoopPoll;
use App\Models\LoopRoadmapItem;
use App\Models\LoopWatchItem;
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
            // TASK-1809 (decision Cyril, 12/11, REMPLACE l'arbitrage du 05/11
            // « seul l'aidant est nomme ») — « Si on nomme les deux, c'est une
            // plateforme de confiance et ouverte. Et il faut etre connecte et
            // membre accepte. » Le beneficiaire est donc nomme AUSSI, de facon
            // bienveillante. Meme idiome que `:temps` : seul l'evenement
            // d'entraide s'en sert.
            'beneficiaire' => self::beneficiaireDeLEntraide($post),
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
    public static function ligneHtml(FeedPost $post, ?string $organizationSlug, bool $sansBoucle = false): ?string
    {
        $type = (string) $post->type;

        if (! FeedEventCatalogue::connait($type)) {
            return null;
        }

        $clef = FeedEventCatalogue::libelle($type);

        if ($clef === null) {
            return null;
        }

        // TASK-1879 (demande Cyril, 22/11) — quand la carte affiche deja la
        // tuile Boucle, la phrase ne repete pas « dans la Boucle X ».
        $variante = str_replace('feed.events.', 'feed.events_sans_boucle.', $clef);
        if ($sansBoucle && \Illuminate\Support\Facades\Lang::has($variante)) {
            $clef = $variante;
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

        // TASK-1809 (decision Cyril, 12/11) — le beneficiaire est nomme AUSSI,
        // avec le MEME traitement que l'acteur (lien profil si possible, sinon
        // texte neutre). Remplace l'arbitrage du 05/11 qui le taisait.
        $beneficiaireUser = self::beneficiaireUser($post);
        $beneficiaireNom = $beneficiaireUser?->publicDisplayName() ?? __('feed.member_fallback');
        $beneficiaireProfil = self::lienProfil($beneficiaireUser, $organizationSlug);

        $beneficiaireHtml = $beneficiaireProfil !== null
            ? '<a href="'.e($beneficiaireProfil).'" class="font-semibold text-gray-900 hover:underline dark:text-gray-100">'.e($beneficiaireNom).'</a>'
            : '<span class="font-semibold text-gray-500 dark:text-gray-400">'.e($beneficiaireNom).'</span>';

        // `__($clef)` SANS remplacement rend le gabarit, `:name`, `:object`,
        // `:temps` et `:beneficiaire` intacts — aucun caractere que `e()`
        // transformerait.
        //
        // TASK-1757 — `:temps` passe par `e()` comme tout le reste. Il vient
        // d'un entier, donc rien a echapper aujourd'hui ; l'echapper quand meme
        // est ce qui fait que le contrat de cette methode (« tout ce qui est
        // dynamique passe par `e()` ») se verifie a la lecture, sans avoir a
        // remonter a la source de chaque valeur.
        return str_replace(
            [':name', ':object', ':temps', ':beneficiaire'],
            [$nomHtml, $objetHtml, '<span class="font-medium">'.e(self::tempsDeLEntraide($post)).'</span>', $beneficiaireHtml],
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
            // TASK-1879 (demande Cyril, 22/11) — tout ce qui vit dans une Boucle
            // (sondage, depot, Roadmap, article, veille, evenement de Boucle)
            // mene a SON MESSAGE dans ChatLoop (`?message=`, mecanisme T1772 :
            // la conversation se charge autour du message et le surligne).
            // Sans message retrouve : la Boucle seule, comme avant.
            $objet instanceof LoopEvent && ($objet->isOrganizationWide() || $objet->loop_id === null) => self::lienAgenda($objet, $organizationSlug),
            $objet instanceof LoopPoll, $objet instanceof LoopMessage, $objet instanceof LoopRoadmapItem,
            $objet instanceof BlogPost, $objet instanceof LoopWatchItem, $objet instanceof LoopEvent => self::lienVersLeMessage($post, $objet, $organizationSlug),
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

    /** TASK-1879 — la page d'une Boucle, lue sans scopes (comme `objet()`). */
    /**
     * TASK-1879 — l'annonce ChatLoop d'un objet de Boucle : son message, ou la
     * Boucle seule si l'annonce est introuvable (anterieure, supprimee).
     */
    private static function lienVersLeMessage(FeedPost $post, mixed $objet, string $organizationSlug): ?string
    {
        $loopId = $objet instanceof BlogPost ? ($post->loop_id ?? $objet->loop_id) : $objet->loop_id;
        $lien = self::lienDeBoucle($loopId, $organizationSlug);

        if ($lien === null) {
            return null;
        }

        $message = $objet instanceof LoopMessage ? $objet : self::annonceDe($objet, (string) $loopId);

        return $message === null ? $lien : $lien.'?'.http_build_query(['message' => $message->getKey()]);
    }

    /** L'annonce ChatLoop d'un objet, dans SA Boucle (la plus ancienne : la creation). */
    private static function annonceDe(mixed $objet, string $loopId): ?LoopMessage
    {
        [$type, $cle] = match (true) {
            $objet instanceof LoopPoll => ['poll_event', 'poll_id'],
            $objet instanceof LoopRoadmapItem => ['roadmap_action', 'roadmap_item_id'],
            $objet instanceof BlogPost => ['blog_post', 'blog_post_id'],
            $objet instanceof LoopWatchItem => ['watch_item', 'watch_item_id'],
            $objet instanceof LoopEvent => ['loop_event', 'event_id'],
            default => [null, null],
        };

        if ($type === null) {
            return null;
        }

        return LoopMessage::query()
            ->where('loop_id', $loopId)
            ->where('type', $type)
            ->where('metadata->'.$cle, (string) $objet->getKey())
            ->orderBy('created_at')
            ->first();
    }

    /**
     * TASK-1879 (demande Cyril) — un evenement ouvert a toute l'Organization
     * mene a SA ligne de l'Agenda (ancre `agenda-event-{id}`), dans l'onglet
     * « a venir » ou « passes » selon sa date.
     */
    private static function lienAgenda(LoopEvent $evenement, string $organizationSlug): ?string
    {
        $lien = self::route('organization.events.agenda', ['organization' => $organizationSlug]);

        if ($lien === null) {
            return null;
        }

        $passe = $evenement->isPast() || $evenement->status === LoopEvent::STATUS_CANCELLED;

        return $lien.($passe ? '?when=past' : '').'#agenda-event-'.$evenement->getKey();
    }

    private static function lienDeBoucle(?string $loopId, string $organizationSlug): ?string
    {
        $boucle = $loopId !== null ? Loop::withoutGlobalScopes()->find($loopId) : null;

        return $boucle === null ? null : self::route('organization.loops.show', [
            'organization' => $organizationSlug,
            'loop' => $boucle->slug ?: $boucle->getKey(),
        ]);
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
    /** L'icone « Kanban » de la Card Roadmap (demande Cyril, 22/11). */
    private const ICONE_ROADMAP = 'M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v12a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18V6ZM13.5 6A2.25 2.25 0 0 1 15.75 3.75H18A2.25 2.25 0 0 1 20.25 6v6A2.25 2.25 0 0 1 18 14.25h-2.25A2.25 2.25 0 0 1 13.5 12V6Z';

    /** Le trace « Mon profil public » du menu avatar (app-side-nav). */
    private const ICONE_PROFIL = 'M15.75 7.5a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z M4.5 20.25a8.25 8.25 0 1116.5 0';

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

            // TASK-1879 (demande Cyril, 22/11) — l'icone « Mon profil public » du
            // menu avatar (l'avatar, lui, est deja dans la gouttiere de la ligne).
            return [
                'url' => self::lienProfil($personne, $organizationSlug),
                'image' => null,
                'icone' => self::ICONE_PROFIL,
                'teinte' => 'indigo',
                'ronde' => false,
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
            // TASK-1879 (demande Cyril) — sans couverture, l'icone « Boucles » du rail.
            return [
                'url' => self::lien($post, $organizationSlug),
                'image' => $boucle->cover_image_url,
                'icone' => config('navigation_icons.loops'),
                'teinte' => 'teal',
                'ronde' => false,
                'titre' => (string) $boucle->name,
                'texte' => filled($boucle->tagline)
                    ? (string) $boucle->tagline
                    : \Illuminate\Support\Str::limit(trim((string) $boucle->description), 140),
            ];
        }

        // TASK-1879 (demande Cyril) — une moitie de l'icone « Entraide » du rail :
        // fleche vers le haut = je veux recevoir (demande), vers le bas = je veux
        // donner (proposition).
        if ($objet instanceof Service || $objet instanceof ServiceRequest) {
            $demande = $objet instanceof ServiceRequest;

            return [
                'url' => self::lien($post, $organizationSlug),
                'image' => null,
                'icone' => $demande ? 'M12 20V4m0 0-6 6m6-6 6 6' : 'M12 4v16m0 0-6-6m6 6 6-6',
                'teinte' => $demande ? 'amber' : 'teal',
                'ronde' => false,
                'titre' => (string) $objet->title,
                'texte' => \Illuminate\Support\Str::limit(trim((string) $objet->description), 160),
            ];
        }

        // TASK-1879 — apercu d'une veille : seulement si elle existe encore et
        // que le plugin est toujours actif dans la Boucle (sinon la ligne
        // reste, sans contenu). `objet()` lit sans scopes : on ecarte ici la
        // veille supprimee (soft delete).
        if ($objet instanceof LoopWatchItem) {
            $boucle = Loop::withoutGlobalScopes()->find($objet->loop_id);

            if ($objet->trashed() || $boucle === null
                || ! in_array('veille', app(\App\Services\Loops\LoopPluginActivation::class)->enabledPluginKeysFor($boucle), true)) {
                return null;
            }

            return [
                'url' => self::lien($post, $organizationSlug),
                'image' => null,
                // TASK-1879 (design) — l'oeil de la Card Veille, en sky, et la
                // pastille de categorie (meme teinte que dans la Card).
                'icone' => 'M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z',
                'teinte' => 'sky',
                'categorie' => $objet->category,
                'ronde' => false,
                'titre' => filled($objet->subject) ? (string) $objet->subject : $objet->category?->name,
                'texte' => \Illuminate\Support\Str::limit(trim((string) $objet->information), 160),
            ];
        }

        // TASK-1879 (demande Cyril, 22/11) — Sondage, Article, depot : une carte
        // dans la teinte de leur annonce ChatLoop (violet, rose, emeraude).
        if ($objet instanceof LoopPoll) {
            if (Loop::withoutGlobalScopes()->find($objet->loop_id) === null) {
                return null;
            }

            $options = $objet->options()->orderBy('position')->limit(4)->pluck('label');

            return [
                'url' => self::lien($post, $organizationSlug),
                'image' => null,
                'icone' => 'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z',
                'teinte' => 'violet',
                'ronde' => false,
                'titre' => (string) $objet->question,
                'texte' => $options->isEmpty() ? null : \Illuminate\Support\Str::limit($options->implode(' / '), 160),
            ];
        }

        if ($objet instanceof BlogPost) {
            if ($objet->status !== 'published') {
                return null;
            }

            return [
                'url' => self::lien($post, $organizationSlug),
                'image' => $objet->image_url,
                'icone' => 'M12 7.5h1.5m-1.5 3h1.5m-7.5 3h7.5m-7.5 3h7.5m3-9h3.375c.621 0 1.125.504 1.125 1.125V18a2.25 2.25 0 0 1-2.25 2.25M16.5 7.5V18a2.25 2.25 0 0 0 2.25 2.25M16.5 7.5V4.875c0-.621-.504-1.125-1.125-1.125H4.125C3.504 3.75 3 4.254 3 4.875V18a2.25 2.25 0 0 0 2.25 2.25h13.5M6 7.5h3v3H6v-3Z',
                'teinte' => 'rose',
                'ronde' => false,
                'titre' => (string) $objet->title,
                'texte' => \Illuminate\Support\Str::limit(trim(html_entity_decode(strip_tags((string) $objet->content))), 160),
            ];
        }

        // TASK-1879 (demande Cyril, 22/11) — Roadmap : l'icone « Kanban » de la
        // Card Roadmap, en teal comme son annonce ChatLoop.
        if ($objet instanceof LoopRoadmapItem
            || ($objet instanceof LoopMessage && $objet->type === 'roadmap_action')) {
            $action = $objet instanceof LoopRoadmapItem
                ? $objet
                : LoopRoadmapItem::withoutGlobalScopes()->find($objet->metadata['roadmap_item_id'] ?? null);

            if ($action === null || $action->trashed() || Loop::withoutGlobalScopes()->find($action->loop_id) === null) {
                return null;
            }

            $statut = fn (?string $s) => $s !== null && LoopRoadmapItem::isValidStatus($s) ? __('loops.roadmap_status_'.$s) : null;
            $texte = $objet instanceof LoopMessage
                ? trim(($statut($objet->metadata['from'] ?? null) ?? '').' → '.($statut($objet->metadata['to'] ?? null) ?? ''), ' →')
                : $statut($action->status);

            return [
                'url' => self::lien($post, $organizationSlug),
                'image' => null,
                'icone' => self::ICONE_ROADMAP,
                'teinte' => 'teal',
                'ronde' => false,
                'titre' => (string) $action->title,
                'texte' => $texte !== '' ? $texte : null,
            ];
        }

        if ($objet instanceof LoopMessage) {
            $noms = array_values(array_filter((array) ($objet->metadata['files'] ?? []), 'is_string'));
            $nombre = (int) ($objet->metadata['count'] ?? count($noms));

            if ($objet->type !== 'dossier_file' || $nombre < 1 || Loop::withoutGlobalScopes()->find($objet->loop_id) === null) {
                return null;
            }

            return [
                'url' => self::lien($post, $organizationSlug),
                'image' => null,
                'icone' => 'M2.25 12.75V12A2.25 2.25 0 0 1 4.5 9.75h15A2.25 2.25 0 0 1 21.75 12v.75m-8.69-6.44-2.12-2.12a1.5 1.5 0 0 0-1.061-.44H4.5A2.25 2.25 0 0 0 2.25 6v12a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9a2.25 2.25 0 0 0-2.25-2.25h-5.379a1.5 1.5 0 0 1-1.06-.44Z',
                'teinte' => 'emerald',
                'ronde' => false,
                'titre' => $nombre === 1 && $noms !== [] ? $noms[0] : trans_choice('feed.events.files_count', $nombre, ['count' => $nombre]),
                'texte' => $nombre === 1 ? null : \Illuminate\Support\Str::limit(implode(', ', $noms), 160),
            ];
        }

        // TASK-1879 (demande Cyril) — la tuile date de la Card Evenements
        // (jour, quantieme, mois, heure), dans le fuseau de l'evenement.
        if ($objet instanceof LoopEvent) {
            $debut = $objet->startsAtLocal();

            return [
                'url' => self::lien($post, $organizationSlug),
                'image' => null,
                'date' => [
                    'jour' => $debut->translatedFormat('D'),
                    'quantieme' => $debut->translatedFormat('d'),
                    'mois' => $debut->translatedFormat('M'),
                    'heure' => $debut->format('H:i'),
                    'annule' => $objet->status === LoopEvent::STATUS_CANCELLED,
                ],
                'ronde' => false,
                'titre' => (string) $objet->title,
                'texte' => $debut->isoFormat('LLL'),
            ];
        }

        // TASK-1809 (correctif Cyril, 12/11) — « la card n'est pas assez
        // renseignee ». La carte d'une entraide montre desormais, comme les
        // autres types, une piece jointe : l'icone « Entraide » du rail
        // (`config('navigation_icons.exchanges')`, la MEME qu'a gauche de
        // l'application — jamais une seconde icone inventee), et l'evaluation
        // quand elle existe (etoiles + chiffre + commentaire). Le titre de
        // l'annonce, lui, vit dans la LIGNE compacte (`:object`), pas ici —
        // une seule ligne de verite, pas deux endroits a tenir alignes.
        if ($objet instanceof \App\Models\Transaction) {
            $avis = $objet->reviews()->where('reviewer_id', $objet->buyer_id)->first();

            if ($avis === null) {
                return null;
            }

            $etoiles = str_repeat('★', $avis->rating).str_repeat('☆', 5 - $avis->rating);

            return [
                'url' => self::lien($post, $organizationSlug),
                'image' => null,
                'icone' => 'M7 16V4m0 0L3 8m4-4 4 4m6 0v12m0 0l4-4m-4 4l-4-4',
                'ronde' => false,
                'titre' => $etoiles.' ('.$avis->rating.'/5)',
                'texte' => filled($avis->comment) ? trim($avis->comment) : null,
            ];
        }

        return null;
    }

    /**
     * Le TITRE de l'annonce qui portait l'entraide, pour la LIGNE compacte.
     *
     * TASK-1809 (correctif Cyril, 12/11) — remplace la CATEGORIE
     * (`categorieDeLEntraide()`, arbitrage du 05/11) : « il faut le nom de la
     * proposition/demande ». Repli NEUTRE si l'annonce a disparu depuis — une
     * entraide bien reelle n'a pas a se lire « supprime ».
     */
    private static function titreDeLEntraide(\App\Models\Transaction $echange): string
    {
        $titre = ($echange->service ?? $echange->serviceRequest)?->title;

        return filled($titre) ? (string) $titre : __('feed.events.help_category_fallback');
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
            $objet instanceof LoopWatchItem => (string) (Loop::withoutGlobalScopes()->find($objet->loop_id)?->name ?? __('feed.events.object_gone')),
            // TASK-1879 — « dans la Boucle :object » : la Boucle du fait. Un
            // Article n'a pas de Boucle propre : c'est celle de la LIGNE.
            $objet instanceof LoopPoll, $objet instanceof LoopMessage, $objet instanceof LoopRoadmapItem => (string) (Loop::withoutGlobalScopes()->find($objet->loop_id)?->name ?? __('feed.events.object_gone')),
            $objet instanceof BlogPost => (string) (Loop::withoutGlobalScopes()->find($post->loop_id)?->name ?? __('feed.events.object_gone')),
            $objet instanceof User => $objet->publicDisplayName(),
            // TASK-1809 (correctif Cyril, 12/11) — l'objet d'une entraide est
            // desormais le TITRE de l'annonce. REMPLACE l'arbitrage du 05/11
            // (« la categorie, jamais le titre ni le beneficiaire ») : « il
            // faut le nom de la proposition/demande, entre qui et qui ».
            $objet instanceof \App\Models\Transaction => self::titreDeLEntraide($objet),
            default => __('feed.events.object_gone'),
        };
    }

    /**
     * Le BENEFICIAIRE de l'entraide (l'aide), pour le placeholder
     * `:beneficiaire`.
     *
     * TASK-1809 (decision Cyril, 12/11) — REMPLACE l'arbitrage du 05/11
     * (« seul l'aidant est nomme ») : « Si on nomme les deux, c'est une
     * plateforme de confiance et ouverte. Et il faut etre connecte et membre
     * accepte. » Chaine vide pour les six autres gabarits (aucun ne reclame
     * ce placeholder), meme idiome que `tempsDeLEntraide()`.
     */
    private static function beneficiaireDeLEntraide(FeedPost $post): string
    {
        $objet = self::objet($post);

        if (! $objet instanceof \App\Models\Transaction) {
            return '';
        }

        return self::beneficiaireUser($post)?->publicDisplayName() ?? __('feed.member_fallback');
    }

    /** Le MEME beneficiaire, mais en User — pour `ligneHtml()` qui a besoin du lien profil. */
    private static function beneficiaireUser(FeedPost $post): ?User
    {
        $objet = self::objet($post);

        return $objet instanceof \App\Models\Transaction ? $objet->buyer : null;
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
