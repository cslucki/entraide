<?php

namespace Tests\Feature;

use App\Livewire\LoopChat;
use App\Models\Loop;
use App\Models\LoopMessage;
use App\Models\MemberNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\LoopMessageService;
use App\Services\LoopService;
use App\Support\Notifications\NotificationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1772 — l'émission d'une mention.
 *
 * Ce qui est défendu ici n'est pas « combien » mais « quoi » (règle Cyril du
 * 28/10). Trois faits mesurés le 05/11 gouvernent ces tests :
 *
 *  - **le nom affiché n'identifie personne** : quatre comptes « Cyril SLUCKI »
 *    en base, deux partagent le slug. La mention est donc une référence
 *    STRUCTURÉE ; le texte n'est qu'une étiquette ;
 *  - **l'édition écrasait les métadonnées** — `requested_mode` était déjà
 *    perdu à chaque correction de faute de frappe ;
 *  - **l'identifiant d'événement doit être nommé par la clé**, sinon deux
 *    faits sur le même message annulent la transaction et le message n'est
 *    jamais publié.
 */
class TASK1772MentionsEmissionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $auteur;

    private User $cible;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true, 'slug' => 'org-1772']);
        $this->auteur = User::factory()->complete()->create(['organization_id' => $this->org->id, 'first_name' => 'Alice', 'name' => 'Martin']);
        $this->cible = User::factory()->complete()->create(['organization_id' => $this->org->id, 'first_name' => 'Bob', 'name' => 'Durand']);

        app()->instance('current_organization', $this->org);

        $service = new LoopService;
        $this->loop = $service->createLoop($this->auteur, 'Boucle des mentions');
        $service->addMemberByUserId($this->loop, $this->cible->id);
    }

    private function nom(User $u): string
    {
        return $u->fresh()->publicDisplayName();
    }

    private function envoyer(string $corps, array $mentions): void
    {
        Livewire::actingAs($this->auteur)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', $corps)
            ->set('mentionsSelectionnees', $mentions)
            ->call('sendMessage');
    }

    private function mentions(): \Illuminate\Database\Eloquent\Collection
    {
        return MemberNotification::query()
            ->where('notification_key', NotificationCatalogue::LOOP_MENTION_CREATED)
            ->get();
    }

    public function test_mentionner_un_membre_actif_cree_une_notification_pour_lui(): void
    {
        $this->envoyer('Salut @'.$this->nom($this->cible).' tu peux regarder ?', [$this->cible->id]);

        $notifications = $this->mentions();
        $this->assertCount(1, $notifications);
        $this->assertSame($this->cible->id, $notifications->first()->recipient_id);
        $this->assertSame($this->auteur->id, $notifications->first()->actor_id);
        $this->assertSame(NotificationCatalogue::OBJECT_LOOP_MESSAGE, $notifications->first()->object_type);
    }

    /** On ne se mentionne pas soi-meme — meme regle que `notifierAjoutDirect()` en T1761. */
    public function test_se_mentionner_soi_meme_ne_notifie_rien(): void
    {
        $this->envoyer('Note pour @'.$this->nom($this->auteur), [$this->auteur->id]);

        $this->assertCount(0, $this->mentions());
        $this->assertSame(1, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'user')->count());
    }

    /**
     * Un identifiant FORGÉ ne notifie rien — et le refus vient bien de la
     * garde visée.
     *
     * Le sabotage est explicite : un membre d'une AUTRE Organization est un
     * cas hostile réaliste, et sans le re-cadrage serveur il recevrait un
     * courriel disant qu'on l'a mentionné dans une Boucle qu'il ne peut même
     * pas ouvrir.
     */
    public function test_un_identifiant_forge_ne_notifie_rien(): void
    {
        $autreOrg = Organization::factory()->create(['is_active' => true, 'slug' => 'ailleurs-1772']);
        $etranger = User::factory()->complete()->create(['organization_id' => $autreOrg->id, 'first_name' => 'Eve', 'name' => 'Hostile']);
        $nonMembre = User::factory()->complete()->create(['organization_id' => $this->org->id, 'first_name' => 'Carl', 'name' => 'Dehors']);

        foreach ([$etranger, $nonMembre] as $intrus) {
            $this->envoyer('Coucou @'.$this->nom($intrus), [$intrus->id]);
        }

        $this->assertCount(0, $this->mentions(),
            'ni un membre d\'une autre Organization ni un non-membre ne doivent etre notifies');
    }

    /**
     * La garde la moins evidente, et la plus importante : un identifiant
     * valide dont le NOM n'apparait pas dans le corps ne notifie rien.
     *
     * Sans elle, un POST forge notifierait n'importe quel co-membre sans
     * qu'aucun `@` ne figure dans le message : la personne recevrait un
     * courriel disant qu'on l'a mentionnee, et ne trouverait rien.
     */
    public function test_un_identifiant_valide_sans_son_nom_dans_le_corps_ne_notifie_rien(): void
    {
        $this->envoyer('Un message tout a fait ordinaire.', [$this->cible->id]);

        $this->assertCount(0, $this->mentions());
    }

    /** Au-dela du plafond : un refus EXPLICITE, pas une troncature silencieuse. */
    public function test_au_dela_du_plafond_l_envoi_est_refuse_avec_un_message(): void
    {
        $trop = [];
        for ($i = 0; $i <= LoopChat::MENTIONS_MAX; $i++) {
            $trop[] = (string) \Illuminate\Support\Str::uuid();
        }

        Livewire::actingAs($this->auteur)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->set('body', 'Trop de monde')
            ->set('mentionsSelectionnees', $trop)
            ->call('sendMessage')
            ->assertHasErrors('body');

        $this->assertSame(0, LoopMessage::where('loop_id', $this->loop->id)->where('type', 'user')->count(),
            'rien ne doit etre publie : le refus est pose AVANT l\'ecriture');
    }

    /**
     * PREUVE DIRECTE DU PIÈGE P3.
     *
     * Deux clés sur le MÊME message et le MÊME destinataire. Si l'identifiant
     * d'événement était l'identifiant de message nu — ce que fait encore
     * `loop.help_request.created` — l'émetteur lèverait
     * `NotificationEmissionConflict`, l'exception serait attrapée par l'envoi,
     * **la transaction serait annulée et le message ne serait pas publié**.
     *
     * Ce test rougit si quelqu'un remplace le `uuid5` par l'identifiant nu.
     */
    public function test_deux_cles_sur_le_meme_message_ne_se_marchent_pas_dessus(): void
    {
        $this->envoyer('Hep @'.$this->nom($this->cible), [$this->cible->id]);

        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'user')->firstOrFail();

        $this->assertNotSame((string) $message->getKey(),
            LoopMessageService::identifiantEvenementMention($message),
            'l\'identifiant d\'evenement ne doit PAS etre celui du message');

        // On pose la seconde cle a la main, exactement comme le ferait une
        // demande d'aide portee par ce meme message.
        app(\App\Support\Notifications\NotificationEmitter::class)->emit(
            notificationKey: NotificationCatalogue::LOOP_HELP_REQUEST_CREATED,
            organization: (string) $this->loop->organization_id,
            recipient: $this->cible->fresh(),
            eventId: (string) $message->getKey(),
            objectType: NotificationCatalogue::OBJECT_LOOP,
            objectId: (string) $this->loop->getKey(),
            actor: $this->auteur->fresh(),
        );

        $this->assertSame(2, MemberNotification::where('recipient_id', $this->cible->id)->count(),
            'les deux faits coexistent pour la meme personne');
    }

    /**
     * L'édition : les mentions SURVIVENT, et `url_preview` aussi.
     *
     * C'est le défaut préexistant que Cyril a demandé de corriger pour TOUTES
     * les métadonnées : `forceFill(['metadata' => …])` remplaçait le tableau
     * entier, et `requested_mode` était perdu à chaque correction de faute de
     * frappe — sans que rien ne le signale.
     */
    public function test_editer_conserve_les_mentions_et_les_autres_metadonnees(): void
    {
        $this->envoyer('Hello @'.$this->nom($this->cible), [$this->cible->id]);

        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'user')->firstOrFail();
        $message->forceFill(['metadata' => ($message->metadata ?? []) + ['requested_mode' => 'ia']])->save();

        Livewire::actingAs($this->auteur)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('editMessage', $message->id)
            ->set('editingBody', 'Hello @'.$this->nom($this->cible).' (corrigé)')
            ->call('saveEdit');

        $frais = $message->fresh();
        $this->assertContains($this->cible->id, $frais->metadata['mentions'] ?? [],
            'la mention encore presente dans le corps survit a l\'edition');
        $this->assertSame('ia', $frais->metadata['requested_mode'] ?? null,
            'et `requested_mode` aussi : c\'est la fusion demandee par Cyril');

        $this->assertCount(1, $this->mentions(),
            'une mention inchangee ne RE-notifie pas — l\'identifiant deterministe le donne');
    }

    /**
     * D3 — la mention est mise en valeur, et seulement celle qui a été retenue.
     *
     * Une expression régulière qui devinerait ce qu'est une mention mettrait
     * en valeur n'importe quel `@mot`. On ne met en valeur que ce que le
     * SERVEUR a enregistré : un `@Nom` qui n'a pas été retenu reste du texte
     * ordinaire, et c'est ce que ce test mesure.
     *
     * TASK-1772 (recette Cyril, 06/11) — la mise en valeur est désormais le
     * gras ET la couleur. Le test gelait `<strong>@Nom</strong>`, c'est-à-dire
     * un MOYEN ; il mesure maintenant la promesse : le nom mentionné porte le
     * marqueur de mention, l'autre non.
     */
    public function test_seule_une_mention_reellement_enregistree_passe_en_gras(): void
    {
        $nom = $this->nom($this->cible);
        $this->envoyer('Salut @'.$nom.' et aussi @PersonneInventee', [$this->cible->id]);

        $html = Livewire::actingAs($this->auteur)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->html();

        // Le nom n'est PAS ré-échappé ici : CommonMark n'échappe pas comme
        // `e()` — il laisse `'` tel quel là où `e()` écrit `&#039;`. C'est
        // précisément pourquoi le marqueur est posé par des sentinelles avant
        // le rendu, et pas par un `str_replace` sur la sortie.
        //
        // TASK-1801 correctif 03 — `[^>]*` après `class="bp-mention"` : la
        // mention retenue porte désormais aussi les attributs qui ouvrent la
        // fiche (`data-mention-user-id`, `x-on:click`...). Ce test garde la
        // PROMESSE d'origine (gras + couleur) ; il ne gèle plus ces attributs
        // comme un MOYEN, exactement la leçon que son propre commentaire
        // donnait déjà pour `<strong>@Nom</strong>`.
        $this->assertMatchesRegularExpression(
            '#<strong><span class="bp-mention"[^>]*>@'.preg_quote($nom, '#').'</span></strong>#',
            $html,
            'la mention retenue doit etre mise en valeur — gras ET couleur');

        $this->assertStringContainsString('data-mention-user-id="'.$this->cible->id.'"', $html,
            'TASK-1801 correctif 03 — la mention retenue doit porter l\'identifiant qui ouvre sa fiche');

        $this->assertStringNotContainsString('bp-mention">@PersonneInventee', $html,
            'un @mot qui n\'est pas une mention retenue reste du texte ordinaire');
        $this->assertStringNotContainsString('<strong>@PersonneInventee</strong>', $html,
            'et il ne passe pas davantage en gras seul');

        // Aucune sentinelle ne doit survivre au rendu : elles finiraient dans
        // le presse-papier (le bouton « Copier » lit ce bloc) et dans toute
        // copie manuelle, invisibles et indétectables.
        $this->assertStringNotContainsString("\u{E000}", $html,
            'une sentinelle de mention a survécu au rendu');
        $this->assertStringNotContainsString("\u{E001}", $html);
    }

    /**
     * Les deux défauts de la recette du 06/11, et leurs gardes.
     *
     * Aucun des deux ne se voyait au banc, et les deux sont des pièges déjà
     * écrits dans les règles du dépôt :
     *
     * 1. **`$refs` ne traverse pas un composant Alpine.** Le `<textarea>`
     *    porte son propre `x-data` (`resize()`), donc son `x-ref`
     *    s'enregistre sur lui-même et non sur le formulaire. Depuis le bouton
     *    du menu, `$refs.champMessage` valait `undefined` : le menu s'ouvrait
     *    et le clic ne faisait RIEN, en levant une erreur que personne ne
     *    regarde. Mesuré : les refs visibles depuis ce scope sont
     *    `uploadInput` et `cameraInput`.
     *
     * 2. **`stopPropagation` n'arrête pas les écouteurs du MÊME élément.**
     *    Elle arrête la remontée vers les ancêtres, c'est tout. Le
     *    `@keydown.enter` du champ s'exécutait donc juste après le menu — et
     *    comme choisir referme le menu, sa garde ne voyait plus rien : le
     *    message partait à la seconde où l'on choisissait la personne.
     */
    public function test_le_menu_ne_depend_pas_d_un_ref_inatteignable_ni_ne_laisse_partir_le_message(): void
    {
        $html = $this->blade(
            '<x-conversation.composer model="body" :mentionables="$m"><x-slot:leading><span>+</span></x-slot:leading></x-conversation.composer>',
            ['m' => [['id' => 'u1', 'nom' => 'Habiba Ounaha']]],
        )->__toString();

        $this->assertStringNotContainsString('$refs.champMessage', $html,
            'le champ n\'est PAS atteignable par `$refs` depuis le menu : il a son propre x-data');
        $this->assertStringContainsString("closest('form').querySelector('textarea')", $html,
            'le menu doit retrouver le champ par le DOM');

        $this->assertStringContainsString('stopImmediatePropagation', $html,
            '`stopPropagation` laisserait le `@keydown.enter` du meme element envoyer le message');
    }

    /** Une mention AJOUTÉE par l'édition notifie la nouvelle personne. */
    public function test_une_mention_ajoutee_par_l_edition_notifie(): void
    {
        $this->envoyer('Bonjour tout le monde', []);
        $this->assertCount(0, $this->mentions());

        $message = LoopMessage::where('loop_id', $this->loop->id)->where('type', 'user')->firstOrFail();

        Livewire::actingAs($this->auteur)
            ->test(LoopChat::class, ['loop' => $this->loop])
            ->call('editMessage', $message->id)
            ->set('editingBody', 'Bonjour tout le monde, surtout @'.$this->nom($this->cible))
            ->call('saveEdit');

        $this->assertCount(1, $this->mentions());
        $this->assertSame($this->cible->id, $this->mentions()->first()->recipient_id);
    }
}
