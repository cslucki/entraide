<?php

namespace App\Support\ScenarioManager\Persona;

use App\Models\Organization;
use App\Models\ScenarioManifestVersion;
use App\Models\ScenarioPackLoad;
use App\Models\User;
use App\Support\ScenarioManager\SandboxGuard;
use Illuminate\Support\Collection;

/**
 * TASK-1654 — qui peut etre emprunte, et ce que la session en retient.
 *
 * ## Une seule autorite, pour la meme raison qu'en Phase 0
 *
 * La Phase 0 a paye le prix d'une regle recopiee en deux demi-reponses. Ici la
 * regle est bien plus dangereuse : elle decide sous QUELLE IDENTITE le produit
 * s'execute. Elle ne vit donc qu'ici, et les trois surfaces — l'ecran de
 * selection, l'entree, et la revalidation a chaque requete — l'interrogent au
 * lieu de la redire.
 *
 * ## La preuve de sandbox vient du Scenario Manager, PAS de l'email
 *
 * C'est le point le plus important de ce fichier. Il serait tentant de
 * reconnaitre un persona a son adresse `.test` : c'est faux, et dangereusement.
 * N'importe qui peut creer un compte `quelquun@chose.test` dans une
 * Organization CLIENTE. Le suffixe ne prouve rien sur le tenant.
 *
 * La chaine de preuve est donc STRUCTURELLE, et se remonte dans ce sens :
 *
 *   version -> `scenario_pack_load_id` -> chargement -> `organization_id`
 *           -> Organization -> `scenario_sandbox_created_at` (SandboxGuard)
 *           -> et le compte doit porter CETTE `organization_id`.
 *
 * Le `.test` reste EXIGE, en plus. Il ne prouve pas la sandbox ; il interdit
 * d'entrer sous une identite qui pourrait etre reelle. Deux conditions
 * distinctes, deux raisons distinctes.
 *
 * ## `User` n'est PAS rattache au tenant par un scope — mesure, pas supposition
 *
 * Premiere ecriture de ce fichier : un `withoutGlobalScope(BelongsToOrganizationScope::class)`
 * sur chaque lecture, par prudence et par habitude prise sur les autres
 * modeles. MESURE : `User` ne porte pas ce scope. Il n'est applique qu'a
 * `Service`, `ServiceRequest`, `MemberNotification`, `Referral`,
 * `ReferralReward`, `Transaction`. L'appel etait donc inoperant — et le
 * commentaire qui l'accompagnait affirmait une protection inexistante.
 *
 * La consequence est l'inverse d'un soulagement : comme AUCUN scope ne rattrape
 * un appelant qui oublie le tenant, la frontiere doit etre posee A LA MAIN sur
 * chaque lecture, et rien ne signalerait son absence. C'est pour cela que
 * `where('users.organization_id', $sandbox->id)` figure sur les DEUX lectures
 * de ce fichier, et qu'aucune n'en fait l'economie.
 *
 * ## Ce que la session retient, et pourquoi sous UNE clef
 *
 * Six valeurs sous une seule clef, jamais six clefs paralleles. La purge
 * devient alors un geste unique et total : impossible de laisser derriere soi
 * la moitie d'un mode persona — un `persona_id` sans son `original_admin_id`
 * serait un etat dont personne ne sait sortir.
 *
 * Aucun credential n'y figure, ni mot de passe, ni jeton, ni empreinte. La
 * session ne retient que des identifiants d'objets deja lisibles par
 * l'administrateur qui a ouvert le mode.
 */
final class ScenarioPersonaAccess
{
    /**
     * L'unique clef de session du mode persona.
     *
     * Volontairement distincte de `admin_original_id`, la clef de
     * l'impersonation generique de `/admin/users`. Les deux modes ne doivent
     * jamais se confondre : celui-ci porte une preuve de sandbox, l'autre non.
     */
    public const SESSION_KEY = 'scenario_persona_mode';

    /**
     * La clef de l'impersonation generique preexistante.
     *
     * Elle est lue ICI pour une seule raison : refuser l'imbrication. Sans
     * cela, la porte la plus ancienne rouvrirait ce que la nouvelle interdit.
     */
    public const LEGACY_IMPERSONATION_KEY = 'admin_original_id';

    /** Le suffixe qui rend une adresse non attribuable a une personne reelle. */
    public const FICTIONAL_SUFFIX = '.test';

    // =================================================================
    // La chaine de preuve
    // =================================================================

    /**
     * La sandbox VIVANTE de cette version, ou un refus nomme.
     *
     * Chaque maillon est verifie, y compris ceux qui « ne devraient pas »
     * casser : c'est cette methode qui fait la difference entre Persona Access
     * et une impersonation ordinaire, et une preuve supposee n'est pas une
     * preuve.
     */
    public function sandboxVivante(ScenarioManifestVersion $version): Organization
    {
        if (! $version->isLoaded()) {
            throw ScenarioPersonaRefused::versionNotLoaded();
        }

        $chargement = ScenarioPackLoad::query()->find($version->scenario_pack_load_id);

        if ($chargement === null) {
            throw ScenarioPersonaRefused::loadGone();
        }

        // `withTrashed()` : une sandbox en corbeille EXISTE encore, et il faut
        // pouvoir la distinguer d'une sandbox disparue. Lue sans cela, elle
        // rendrait `null` et le refus nommerait la mauvaise cause.
        $sandbox = Organization::query()->withTrashed()->find($chargement->organization_id);

        if ($sandbox === null) {
            throw ScenarioPersonaRefused::sandboxGone();
        }

        if ($sandbox->trashed()) {
            throw ScenarioPersonaRefused::sandboxNotLiving();
        }

        if (! SandboxGuard::estUneSandbox($sandbox)) {
            throw ScenarioPersonaRefused::notASandbox();
        }

        return $sandbox;
    }

    /**
     * Le chargement vivant de cette version.
     */
    public function chargementVivant(ScenarioManifestVersion $version): ScenarioPackLoad
    {
        $this->sandboxVivante($version);

        return ScenarioPackLoad::query()->findOrFail($version->scenario_pack_load_id);
    }

    /**
     * Les personas empruntables de cette version, ordonnes.
     *
     * Les personas CREES APRES le Load en font partie : la sandbox est vivante,
     * et l'eligibilite se juge sur l'etat present, jamais sur le manifeste
     * source. Un persona ajoute a la main hier doit etre empruntable aujourd'hui
     * s'il satisfait les memes conditions.
     *
     * @return Collection<int, User>
     */
    public function personasEligibles(ScenarioManifestVersion $version): Collection
    {
        $sandbox = $this->sandboxVivante($version);

        return User::query()
            ->where('users.organization_id', $sandbox->id)
            // `id` en second critere : un tri sur le seul nom n'est pas TOTAL,
            // et deux homonymes s'echangeraient d'un affichage a l'autre.
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->get()
            ->filter(fn (User $candidat): bool => self::raisonDInegibilite($candidat, $sandbox) === null)
            ->values();
    }

    /**
     * Ce compte, prouve empruntable pour cette version — ou un refus nomme.
     *
     * L'identifiant recu n'est jamais cru : le compte est relu depuis la base,
     * a l'interieur de la frontiere de la sandbox, et confronte a toutes les
     * conditions. C'est la seule porte d'entree du mode.
     */
    public function exigerUnPersonaEligible(ScenarioManifestVersion $version, string $personaId): User
    {
        $sandbox = $this->sandboxVivante($version);

        $candidat = User::query()
            ->where('users.organization_id', $sandbox->id)
            ->whereKey($personaId)
            ->first();

        if ($candidat === null) {
            throw ScenarioPersonaRefused::notInSandbox();
        }

        if (($raison = self::raisonDInegibilite($candidat, $sandbox)) !== null) {
            throw $raison;
        }

        return $candidat;
    }

    /**
     * La raison pour laquelle ce compte n'est PAS empruntable, ou `null`.
     *
     * Une seule implementation, interrogee par la liste comme par l'entree
     * comme par la revalidation : l'ecran ne peut donc pas proposer un persona
     * que l'entree refuserait, ni l'inverse.
     *
     * Le RANG des conditions est deliberement le plus dangereux d'abord.
     *
     * A noter : etre administrateur du TENANT (`organizations.admin_id`) ne
     * disqualifie pas. Un persona est souvent l'admin de sa propre sandbox, et
     * c'est meme le seul moyen de voir le produit de ce point de vue. Le seul
     * predicat disqualifiant est `users.is_admin`, celui de la PLATEFORME.
     */
    public static function raisonDInegibilite(User $candidat, Organization $sandbox): ?ScenarioPersonaRefused
    {
        if ($candidat->is_admin) {
            return ScenarioPersonaRefused::platformAdmin();
        }

        if ($candidat->organization_id !== $sandbox->getKey()) {
            return ScenarioPersonaRefused::notInSandbox();
        }

        if (! str_ends_with(strtolower((string) $candidat->email), self::FICTIONAL_SUFFIX)) {
            return ScenarioPersonaRefused::notFictional();
        }

        // Le produit renverrait aussitot un compte banni vers le login
        // (`EnsureUserIsNotBanned`). L'accepter ici fabriquerait un mode persona
        // dont la seule issue serait un ecran de connexion.
        if ($candidat->banned_at !== null) {
            return ScenarioPersonaRefused::banned();
        }

        return null;
    }

    // =================================================================
    // La session
    // =================================================================

    public static function actif(): bool
    {
        return self::contexte() !== null;
    }

    /**
     * Le contexte du mode persona, ou `null`.
     *
     * Fail-closed sur la FORME : un contexte auquel manque une seule valeur
     * n'est pas un demi-contexte, c'est une absence. Sans cela, une session
     * tronquee — par un deploiement, une purge partielle, une main humaine —
     * donnerait un mode dont on ne sait plus sortir.
     *
     * @return array{original_admin_id: string, persona_id: string, sandbox_organization_id: string, scenario_version_id: string, scenario_pack_load_id: string, started_at: string}|null
     */
    public static function contexte(): ?array
    {
        $contexte = session(self::SESSION_KEY);

        if (! is_array($contexte)) {
            return null;
        }

        foreach (self::CHAMPS as $champ) {
            if (! isset($contexte[$champ]) || ! is_string($contexte[$champ]) || $contexte[$champ] === '') {
                return null;
            }
        }

        return $contexte;
    }

    /** @var list<string> */
    private const CHAMPS = [
        'original_admin_id',
        'persona_id',
        'sandbox_organization_id',
        'scenario_version_id',
        'scenario_pack_load_id',
        'started_at',
    ];

    /**
     * Ouvre le mode persona en session.
     *
     * N'ECRIT AUCUN credential. Ni mot de passe, ni jeton, ni empreinte : que
     * des identifiants d'objets que l'administrateur qui ouvre le mode peut
     * deja lire.
     */
    public static function memoriser(
        User $acteur,
        User $persona,
        Organization $sandbox,
        ScenarioManifestVersion $version,
        ScenarioPackLoad $chargement,
    ): void {
        session()->put(self::SESSION_KEY, [
            'original_admin_id' => (string) $acteur->getKey(),
            'persona_id' => (string) $persona->getKey(),
            'sandbox_organization_id' => (string) $sandbox->getKey(),
            'scenario_version_id' => (string) $version->getKey(),
            'scenario_pack_load_id' => (string) $chargement->getKey(),
            'started_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Efface tout etat de mode persona.
     *
     * Une seule clef, donc un seul geste, donc aucun reliquat possible.
     */
    public static function purger(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Une identite d'emprunt est-elle DEJA active, par l'une ou l'autre porte ?
     *
     * Les deux sont lues, parce que l'imbrication passerait sinon par la plus
     * ancienne : un SuperAdmin deja connecte « sous » quelqu'un via
     * `/admin/users` n'atteindrait de toute facon plus l'ecran de selection
     * (il n'est plus admin), mais compter sur cet effet de bord serait compter
     * sur une coincidence plutot que sur une garde.
     */
    public static function uneIdentiteEstDejaEmpruntee(): bool
    {
        return self::actif() || session()->has(self::LEGACY_IMPERSONATION_KEY);
    }
}
