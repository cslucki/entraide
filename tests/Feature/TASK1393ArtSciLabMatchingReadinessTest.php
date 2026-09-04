<?php

namespace Tests\Feature;

use App\Models\Loop;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\User;
use App\Services\People\RelevantPeopleService;
use App\Support\ScenarioPacks\Packs\ArtSciLabEnglishDataset;
use App\Support\ScenarioPacks\Packs\ArtSciLabEnglishPack;
use App\Support\ScenarioPacks\ScenarioPackLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * TASK-1393 — le tenant de demonstration possede de vrais profils IA publies.
 *
 * ## Le constat, MESURE
 *
 * `member_ai_profiles` pour `artscilab-en` etait **vide**. Zero profil, publie
 * ou non. Les etapes 8 et 9 du script Roger — « PersonCard reelle », « Voir le
 * profil » — etaient donc impossibles, quel que soit le matching : l'ensemble
 * eligible n'existait pas. Ce n'etait pas un probleme de pertinence, c'etait
 * une absence de donnee.
 *
 * ## La regle qui gouverne cette tranche
 *
 * > Ne pas inventer de talents pour satisfaire une phrase de demonstration.
 *
 * Chaque competence declaree doit etre ETAYEE par une matiere qui existe deja
 * dans le pack : la bio du persona, une decision qu'il a signee, une demande
 * ou une offre dont il est l'auteur. Une simple appartenance a une Boucle ne
 * vaut PAS preuve — c'est explicitement exclu.
 *
 * Le test `every_declared_skill_is_backed_by_pack_material` mesure cette
 * regle. C'est le test le plus important du fichier : sans lui, la regle
 * serait une intention, et rien n'empecherait la prochaine competence
 * flatteuse d'entrer.
 *
 * ## Repartition de la matiere, mesuree avant d'ecrire
 *
 * | persona | messages | decision | demande | offre | bio | profil |
 * |---|---|---|---|---|---|---|
 * | sam     | 6 | 1 | — | 1 | oui | OUI |
 * | priya   | 4 | 1 | 1 | 1 | oui | OUI |
 * | marcus  | 3 | 1 | 1 | — | oui | OUI |
 * | elena   | 0 | 0 | 0 | 0 | oui | OUI, une seule competence |
 * | wen     | 0 | 0 | 0 | 0 | **null** | **NON** |
 *
 * Wen Zhao n'a aucune preuve, et son absence de bio est DELIBEREE dans le pack
 * (`new_member: true`). Lui fabriquer un profil trahirait a la fois la regle et
 * l'intention du dataset : il reste l'etat vide honnete.
 */
class TASK1393ArtSciLabMatchingReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(ArtSciLabEnglishPack::DISK);

        $this->organization = Organization::factory()->create([
            'slug' => ArtSciLabEnglishPack::ORGANIZATION_SLUG,
            'locale' => 'en',
            'is_active' => true,
            'loops_enabled' => true,
            'ai_profiles_enabled' => true,
        ]);

        config(['scenario_packs.allowed_organizations' => [ArtSciLabEnglishPack::ORGANIZATION_SLUG]]);
    }

    // =====================================================================
    // Ce que le pack doit produire
    // =====================================================================

    /**
     * Le pack publie QUATRE profils, et pas cinq.
     *
     * Le compte est le sujet : « au moins trois » est le seuil produit, et le
     * cinquieme persona n'a aucune preuve. Mesurer un compte exact empeche
     * autant le sous-remplissage que la generosite.
     */
    public function test_the_pack_publishes_four_ai_profiles(): void
    {
        $this->chargerLePack();

        $profils = MemberAiProfile::query()
            ->where('organization_id', $this->organization->id)
            ->get();

        $this->assertCount(4, $profils);
        $this->assertTrue(
            $profils->every(fn (MemberAiProfile $p): bool => $p->published_at !== null),
            'Un profil non publie ne produit aucune PersonCard : il ne servirait a rien.',
        );
    }

    /**
     * Le nouveau membre n'a PAS de profil.
     *
     * L'etat vide doit rester honnete. Wen Zhao est declare `new_member` avec
     * une bio nulle : le pack l'a voulu sans contexte. Lui fabriquer des
     * competences serait exactement l'invention que la regle interdit — et
     * cela detruirait le seul cas de demonstration ou le produit doit dire
     * « je ne sais pas encore ».
     */
    public function test_the_new_member_has_no_profile(): void
    {
        $this->chargerLePack();

        $wen = User::query()->where('email', 'wen@artscilab-en.test')->firstOrFail();

        $this->assertSame(
            0,
            MemberAiProfile::query()->where('user_id', $wen->id)->count(),
            'Sans matiere, pas de profil — l\'etat vide est un resultat, pas un oubli.',
        );
    }

    /**
     * CHAQUE competence declaree se retrouve dans la matiere du pack.
     *
     * Le test central. Il prend les competences reellement ecrites en base et
     * verifie que chacune est ancree dans un texte que le pack contenait DEJA :
     * bio, decision, demande ou offre de cette personne.
     *
     * Il ne mesure pas une intention, il mesure une trace. Une competence
     * ajoutee demain parce qu'elle rendrait la demonstration plus jolie ferait
     * rougir ce test — c'est tout son objet.
     */
    public function test_every_declared_skill_is_backed_by_pack_material(): void
    {
        $this->chargerLePack();

        $matiereParPersona = $this->matiereParPersona();

        foreach (MemberAiProfile::query()->with('user')->get() as $profil) {
            $cle = explode('@', (string) $profil->user->email)[0];
            $matiere = mb_strtolower($matiereParPersona[$cle] ?? '');

            $this->assertNotSame('', $matiere, "Aucune matiere connue pour le persona « {$cle} ».");

            foreach ((array) $profil->skills as $competence) {
                $ancre = $this->ancre($competence);

                $this->assertStringContainsString(
                    $ancre,
                    $matiere,
                    "La competence « {$competence} » de {$cle} n'est etayee par aucun texte du pack. ".
                    'Une competence sans source est une invention.',
                );
            }
        }
    }

    /**
     * Les profils sont en anglais, comme leur Organization.
     *
     * TASK-1388 a pose la locale de l'Organization sur le chemin des packs.
     * Un profil de demonstration ecrit en francais dans un tenant anglais
     * annulerait ce travail a l'endroit le plus visible du parcours.
     */
    public function test_the_profiles_are_written_in_the_organizations_language(): void
    {
        $this->chargerLePack();

        foreach (MemberAiProfile::query()->get() as $profil) {
            $this->assertSame('en', $profil->locale);
        }
    }

    /**
     * Recharger le pack ne duplique aucun profil.
     *
     * Le pack est rejouable par contrat (TASK-1240). Un profil duplique
     * ferait apparaitre deux fois la meme personne dans les resultats.
     */
    public function test_reloading_the_pack_does_not_duplicate_profiles(): void
    {
        $this->chargerLePack();
        $this->chargerLePack();

        $this->assertSame(4, MemberAiProfile::query()->count());
    }

    // =====================================================================
    // Le matching, MESURE sur l'ensemble eligible reel
    // =====================================================================

    /**
     * Un besoin PERTINENT trouve la bonne personne.
     *
     * La mesure positive d'abord : sans elle, constater qu'un besoin hors
     * sujet remonte quelqu'un ne prouverait rien — un matching qui remonte
     * TOUT LE MONDE pour TOUTE question serait « confirme » par le seul test
     * negatif.
     */
    public function test_a_relevant_need_finds_the_right_person(): void
    {
        $this->chargerLePack();

        $pertinents = $this->pertinentsPour('I need someone to review how our climate data becomes sound');

        $this->assertContains(
            'Priya Nandakumar',
            $pertinents,
            'Priya declare « Data sonification » : c\'est exactement sa competence.',
        );
    }

    /**
     * MESURE DU FINDING « un token suffit ».
     *
     * `RelevantPeopleService::matchedReasons()` retient un signal des que
     * `array_intersect` est non vide : **un seul terme commun** produit une
     * raison, donc une PersonCard.
     *
     * Consequence mesuree ici sur le dataset reel : une question de logistique
     * de bureau, qui n'a rien a voir avec la sonification de donnees
     * climatiques, remonte quand meme Priya — parce que le mot « mapping »
     * apparait dans « Uncertainty mapping ».
     *
     * Ce test CONSTATE, il ne corrige pas. Le correctif — un seuil de
     * discrimination — n'est pas ecrit dans cette tranche : il ne se decide
     * qu'une fois le comportement mesure sur une donnee reelle, ce qui est
     * exactement ce que fait cette methode. Le jour ou un seuil sera pose,
     * c'est ici que son effet se lira.
     */
    public function test_measuring_the_single_token_finding(): void
    {
        $this->chargerLePack();

        $pertinents = $this->pertinentsPour('who can help mapping the desks for our office move');

        $this->assertContains(
            'Priya Nandakumar',
            $pertinents,
            'FINDING CONFIRME : un seul terme commun — « mapping » — suffit a recommander une personne '.
            'pour un besoin sans aucun rapport avec sa competence.',
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * Les personnes que le produit recommanderait pour ce besoin, dans la
     * Boucle du pack ou les personas se cotoient.
     *
     * Passe par le service reel — pas par une reimplementation du matching :
     * une mesure qui recoderait la regle ne mesurerait qu'elle-meme.
     *
     * @return list<string>
     */
    private function pertinentsPour(string $besoin): array
    {
        // La Boucle de Priya, ciblee explicitement : `sonic_terrain` a Priya
        // pour proprietaire et Elena parmi ses membres. Prendre « la premiere
        // Boucle venue » rendait la mesure dependante d'un ordre d'insertion,
        // et elle rougissait pour une raison qui n'avait rien a voir avec le
        // matching.
        $loop = Loop::query()
            ->where('organization_id', $this->organization->id)
            ->where('name', ArtSciLabEnglishPack::LOOPS['sonic_terrain']['name'])
            ->firstOrFail();

        $demandeur = User::query()->where('email', 'elena@artscilab-en.test')->firstOrFail();

        $resultat = app(RelevantPeopleService::class)
            ->relevantFor($this->organization, $loop, $demandeur, $besoin);

        if (! $resultat->authorized) {
            return [];
        }

        return array_values(array_map(
            static fn ($personne): string => (string) User::query()->whereKey($personne->person->userId)->value('name'),
            $resultat->people,
        ));
    }

    private function chargerLePack(): void
    {
        app(ScenarioPackLoader::class)->load(app(ArtSciLabEnglishPack::class), $this->organization->refresh());
    }

    /**
     * Tout le texte que le pack attribue nominativement a chaque persona.
     *
     * Volontairement reconstruit ICI a partir des sources du pack plutot que
     * lu depuis une constante de provenance : un test qui interrogerait la
     * meme table que le code produit ne prouverait rien — il comparerait une
     * declaration a elle-meme.
     *
     * @return array<string, string>
     */
    private function matiereParPersona(): array
    {
        $matiere = [];

        foreach (ArtSciLabEnglishPack::PERSONAS as $cle => $persona) {
            $matiere[$cle] = (string) ($persona['bio'] ?? '');
        }

        foreach (ArtSciLabEnglishDataset::decisions() as $decision) {
            $matiere[$decision['author']] = ($matiere[$decision['author']] ?? '').' '.$decision['title'].' '.$decision['rationale'];
        }

        foreach (ArtSciLabEnglishDataset::requests() as $demande) {
            $matiere[$demande['author']] = ($matiere[$demande['author']] ?? '').' '.$demande['title'].' '.$demande['description'];
        }

        foreach (ArtSciLabEnglishDataset::offers() as $offre) {
            $matiere[$offre['author']] = ($matiere[$offre['author']] ?? '').' '.$offre['title'].' '.$offre['description'];
        }

        foreach (ArtSciLabEnglishDataset::messages() as $message) {
            if (isset($message['author'])) {
                $matiere[$message['author']] = ($matiere[$message['author']] ?? '').' '.($message['body'] ?? '');
            }
        }

        return $matiere;
    }

    /**
     * Le mot le plus significatif d'une competence, ramene a un radical.
     *
     * Une competence est un LIBELLE (« Data sonification »), la matiere est de
     * la prose (« Turns climate datasets into sound »). Exiger la chaine
     * entiere ne prouverait rien d'utile : ce qu'il faut verifier, c'est que le
     * terme porteur existe dans le texte source.
     *
     * Le radical absorbe les variations que l'anglais impose ici —
     * « sonification » / « sonification », « visualisation » / « visualisations »,
     * « facilitation » / « facilitating », « ethics » / « ethical ».
     */
    private function ancre(string $competence): string
    {
        $mots = preg_split('/\s+/', mb_strtolower(trim($competence))) ?: [];
        $porteur = '';

        foreach ($mots as $mot) {
            if (mb_strlen($mot) > mb_strlen($porteur)) {
                $porteur = $mot;
            }
        }

        // Coupe les suffixes qui different entre un libelle et sa prose.
        foreach (['ation', 'ing', 'ment', 's'] as $suffixe) {
            if (mb_strlen($porteur) > mb_strlen($suffixe) + 3 && str_ends_with($porteur, $suffixe)) {
                return mb_substr($porteur, 0, mb_strlen($porteur) - mb_strlen($suffixe));
            }
        }

        return $porteur;
    }
}
