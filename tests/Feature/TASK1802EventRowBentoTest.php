<?php

namespace Tests\Feature;

use App\Livewire\LoopEventsCard;
use App\Models\Loop;
use App\Models\LoopEvent;
use App\Models\LoopEventResponse;
use App\Models\LoopMember;
use App\Models\Organization;
use App\Models\User;
use App\Services\Loops\LoopEventService;
use App\Support\Loops\LoopTypeRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TASK-1802 (suite, demande Cyril, 10/11) — « le design est loin d'etre
 * premium. Que pourrait-on faire en mode bento et qui reste dans l'esprit
 * BouclePro ? » Mockup valide par Cyril (« Oui c'est bien »), applique a
 * `loops/partials/event-row.blade.php`, partage par la Card et l'agenda.
 *
 * Ce fichier ne verifie AUCUNE couleur ni mise en page (hors de portee d'un
 * test PHP) : il verifie que l'information reste TOUTE PRESENTE apres le
 * reagencement en tuiles — c'est le risque reel d'une refonte visuelle,
 * perdre un champ en route.
 */
class TASK1802EventRowBentoTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $owner;

    private Loop $loop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['is_active' => true]);
        $this->owner = User::factory()->create(['organization_id' => $this->org->id]);

        $this->loop = Loop::create([
            'organization_id' => $this->org->id,
            'name' => 'Boucle Bento',
            'slug' => Str::slug('Boucle Bento').'-'.uniqid(),
            'type' => 'general',
            'status' => 'active',
            'visibility' => 'public',
            'created_by' => $this->owner->id,
        ]);
        app(LoopTypeRegistry::class)->applyPreset($this->loop);

        LoopMember::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'user_id' => $this->owner->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        app()->instance('current_organization', $this->org);
    }

    /** @param array<string, mixed> $overrides */
    private function event(array $overrides = []): LoopEvent
    {
        return app(LoopEventService::class)->create($this->owner, $this->loop, array_merge([
            'title' => 'Atelier bento',
            'description' => null,
            'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00')->format('Y-m-d\TH:i'),
            'ends_at' => '',
            'timezone' => 'Europe/Paris',
            'location' => 'Salle A',
            'meeting_url' => '',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
        ], $overrides));
    }

    private function renderCard(): string
    {
        return Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->html();
    }

    public function test_the_date_tile_carries_weekday_day_month_time_and_timezone(): void
    {
        $this->event(['starts_at' => CarbonImmutable::parse('2026-12-01 10:00')->format('Y-m-d\TH:i'), 'timezone' => 'Europe/Paris']);

        $html = $this->renderCard();

        $this->assertStringContainsString('10:00', $html);
        $this->assertStringContainsString('Europe/Paris', $html);
        $this->assertStringContainsString('2026', $html);
    }

    public function test_an_event_with_an_end_shows_the_time_range_in_the_date_tile(): void
    {
        $this->event([
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00')->format('Y-m-d\TH:i'),
            'ends_at' => CarbonImmutable::parse('2026-12-01 11:30')->format('Y-m-d\TH:i'),
        ]);

        $html = $this->renderCard();

        $this->assertStringContainsString('10:00–11:30', $html);
    }

    public function test_a_location_only_event_shows_the_where_tile_with_the_address(): void
    {
        $this->event(['location' => 'Salle du fond']);

        $html = $this->renderCard();

        $this->assertStringContainsString('Salle du fond', $html);
        // Avec une tuile « ou », le format n'y figure QU'UNE fois — plus dans
        // la meta du titre, qui ne le repete plus (demande Cyril, « optimiser
        // encore » : jamais dire la meme chose deux fois).
        $this->assertSame(1, substr_count($html, __('events.format_in_person')));
    }

    public function test_an_online_event_shows_the_where_tile_with_a_clickable_link(): void
    {
        $this->event(['format' => LoopEvent::FORMAT_ONLINE, 'meeting_url' => 'https://visio.example.org/bento']);

        $html = $this->renderCard();

        $this->assertStringContainsString(
            '<a href="https://visio.example.org/bento" target="_blank" rel="noopener noreferrer"',
            $html,
        );
    }

    /**
     * `LoopEventService::create()` exige un lieu pour une rencontre « sur
     * place » — ce cas n'est donc PLUS atteignable par le formulaire. Mais il
     * reste atteignable par une ligne plus ancienne que cette regle (donnee
     * figee, meme precaution que pour les messages ChatLoop de cette TASK) :
     * cree ici en passant directement par le modele, hors du service.
     */
    public function test_a_legacy_in_person_event_without_a_location_has_no_where_tile(): void
    {
        $event = LoopEvent::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'created_by' => $this->owner->id,
            'status' => LoopEvent::STATUS_SCHEDULED,
            'title' => 'Vieille rencontre sans lieu',
            'description' => null,
            'format' => LoopEvent::FORMAT_IN_PERSON,
            'starts_at' => CarbonImmutable::parse('2026-12-01 10:00', 'Europe/Paris')->utc(),
            'ends_at' => null,
            'timezone' => 'Europe/Paris',
            'location' => '',
            'meeting_url' => '',
            'visibility' => LoopEvent::VISIBILITY_LOOP,
        ]);

        $html = $this->renderCard();

        $this->assertStringContainsString($event->title, $html);
        // Le format ne figure qu'UNE fois (meta du titre) : pas de tuile
        // « ou » dediee a afficher en double quand il n'y a rien a y mettre.
        $this->assertSame(1, substr_count($html, __('events.format_in_person')));
    }

    /**
     * TASK-1802 (suite, demande Cyril) — « il faut mettre le descriptif
     * dans le bento ou il y a le titre » : la description a quitte sa tuile
     * dediee (qui restait vide en dessous du titre sur un titre court) pour
     * vivre dans la tuile titre elle-meme.
     */
    public function test_the_description_renders_inside_the_headline_tile_when_present(): void
    {
        $this->event(['title' => 'Atelier bento', 'description' => 'Apporter son ordinateur portable.']);

        $html = $this->renderCard();

        $this->assertStringContainsString('Apporter son ordinateur portable.', $html);
        // Avant ce correctif, la description vivait dans sa PROPRE tuile,
        // APRES celle des presences. Verifier qu'elle precede desormais le
        // label « Presences » prouve qu'elle est remontee dans la tuile
        // titre, pas simplement restee visible quelque part sur la page.
        $this->assertTrue(
            strpos($html, 'Apporter son ordinateur portable.') < strpos($html, __('events.attendance_label')),
        );
    }

    /**
     * TASK-1802 (suite, demande Cyril) — « concernant ceux qui participent,
     * on met uniquement ceux-là [...] un icône et un chiffre » : jamais la
     * repartition Peut-être/Absents.
     */
    public function test_the_presence_tile_shows_only_the_going_count(): void
    {
        $this->event();

        $html = $this->renderCard();

        $this->assertStringContainsString(__('events.attendance_label'), $html);
        $this->assertStringNotContainsString(__('events.maybe_short'), $html);
        $this->assertStringNotContainsString(__('events.not_going_short'), $html);
    }

    public function test_the_title_and_author_remain_visible_in_the_headline_tile(): void
    {
        $this->event(['title' => 'Cafe du mardi']);

        $html = $this->renderCard();

        $this->assertStringContainsString('Cafe du mardi', $html);
        $this->assertStringContainsString(__('events.by', ['name' => $this->owner->publicDisplayName()]), $html);
    }

    public function test_the_agenda_page_renders_the_same_bento_row(): void
    {
        $this->event(['title' => 'Rencontre agenda', 'location' => 'Hall principal']);

        $html = $this->actingAs($this->owner)
            ->get(route('organization.events.agenda', ['organization' => $this->org->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Rencontre agenda', $html);
        $this->assertStringContainsString('Hall principal', $html);
    }

    /**
     * TASK-1802 (suite, demande Cyril) — « Sur la page agenda, remplace le
     * bouton "Je ne participe pas" par "Ajouter à l'agenda". » Demande
     * LIMITEE a ce remplacement : « Je participe »/« Peut-être » gardent
     * leur libelle d'origine sur CETTE page (la renomination en
     * « Oui »/« Intéressé » ne concernait que la Card).
     */
    public function test_the_agenda_page_replaces_not_going_with_add_to_calendar(): void
    {
        $this->event(['title' => 'Rencontre agenda RSVP']);

        $html = $this->actingAs($this->owner)
            ->get(route('organization.events.agenda', ['organization' => $this->org->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(e(__('events.going')), $html);
        $this->assertStringContainsString(e(__('events.maybe')), $html);
        $this->assertStringNotContainsString(e(__('events.not_going')), $html);
        $this->assertStringContainsString('data-agenda-add-to-calendar', $html);
        $this->assertStringContainsString('calendar.google.com', $html);
    }

    /**
     * TASK-1802 (suite, demande Cyril) — « fait deux boutons : oui et
     * interessé » (remplace le bouton unique « Je participe » de l'iteration
     * precedente). Les deux sont outline tant que personne n'a repondu.
     */
    public function test_two_rsvp_buttons_are_offered_yes_and_interested(): void
    {
        $event = $this->event();

        $html = $this->renderCard();

        $this->assertStringContainsString(e(__('events.rsvp_yes')), $html);
        $this->assertStringContainsString(e(__('events.rsvp_interested')), $html);
        // Verifie le CABLAGE statique bouton -> reponse (un `->call()` sur le
        // composant ne passerait jamais par cet attribut, et ne proteger pas
        // contre un copier-coller qui inverserait les deux valeurs).
        $this->assertStringContainsString(
            "wire:click=\"respond('".$event->id."', '".LoopEventResponse::GOING."')\"",
            $html,
        );
        $this->assertStringContainsString(
            "wire:click=\"respond('".$event->id."', '".LoopEventResponse::MAYBE."')\"",
            $html,
        );
        $this->assertStringNotContainsString('bg-emerald-600 text-white', $html);
        $this->assertStringNotContainsString('bg-amber-600 text-white', $html);
    }

    public function test_choosing_yes_fills_the_first_button_and_marks_it_pressed(): void
    {
        $event = $this->event();

        $html = Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('respond', $event->id, LoopEventResponse::GOING)
            ->html();

        $this->assertStringContainsString('bg-emerald-600 text-white', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);
    }

    public function test_choosing_interested_fills_the_second_button_without_touching_going(): void
    {
        $event = $this->event();

        $html = Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('respond', $event->id, LoopEventResponse::MAYBE)
            ->html();

        $this->assertStringContainsString('bg-amber-600 text-white', $html);
        $this->assertStringNotContainsString('bg-emerald-600 text-white', $html);
        // Une seule des deux reponses est cochee a la fois.
        $this->assertSame(1, substr_count($html, 'aria-pressed="true"'));
    }

    public function test_the_presence_tile_shows_both_going_and_interested_counts(): void
    {
        $event = $this->event();
        $interested1 = User::factory()->create(['organization_id' => $this->org->id]);
        $interested2 = User::factory()->create(['organization_id' => $this->org->id]);
        foreach ([$interested1, $interested2] as $member) {
            LoopMember::create([
                'organization_id' => $this->org->id,
                'loop_id' => $this->loop->id,
                'user_id' => $member->id,
                'role' => 'member',
                'status' => 'active',
            ]);
        }

        $service = app(\App\Services\Loops\LoopEventService::class);
        $service->respond($this->owner, $event, $this->loop, LoopEventResponse::GOING);
        $service->respond($interested1, $event, $this->loop, LoopEventResponse::MAYBE);
        $service->respond($interested2, $event, $this->loop, LoopEventResponse::MAYBE);

        $html = $this->renderCard();

        // 1 participant, 2 interesses : chaque chiffre apparait bien a sa
        // place, et AUCUN compte d'absents (demande Cyril : jamais "ceux qui
        // ne participent pas"). Deux valeurs distinctes (1 et 2) pour ne pas
        // se contenter d'un hasard d'egalite entre les deux compteurs.
        $this->assertStringContainsString(__('events.attendance_label'), $html);
        $this->assertStringContainsString('>1<', $html);
        $this->assertStringContainsString('>2<', $html);
        $this->assertStringNotContainsString(__('events.not_going_short'), $html);
    }

    public function test_the_add_to_calendar_button_shows_only_the_short_verb(): void
    {
        $this->event();

        $html = $this->renderCard();

        $this->assertStringContainsString(e(__('events.add_cta')), $html);
        // Le libelle complet reste accessible (lecteur d'ecran), simplement
        // pas affiche a cote de l'icone : jamais les DEUX mots a la fois
        // visibles (texte du bouton) — seul `aria-label` porte la phrase
        // entiere.
        $this->assertStringContainsString('aria-label="'.e(__('events.add_to_calendar')).'"', $html);
        $this->assertSame(1, substr_count($html, e(__('events.add_to_calendar'))));
    }

    public function test_participating_again_toggles_back_to_not_going(): void
    {
        $event = $this->event();
        $component = Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('respond', $event->id, LoopEventResponse::GOING);

        // Le MEME bouton, actionne une seconde fois, retire la participation
        // plutot que d'en proposer une troisieme (Peut-être a quitte l'ecran).
        $component->call('respond', $event->id, LoopEventResponse::NOT_GOING);

        $this->assertSame(
            LoopEventResponse::NOT_GOING,
            app(\App\Services\Loops\LoopEventService::class)->responseOf($this->owner, $event->fresh()),
        );
    }

    /**
     * TASK-1802 (suite, demande Cyril) — « un bouton pour ajouter à son
     * agenda [...] compatible Google Agenda et si possible les autres [...]
     * pop-up alpine qui permet de choisir le type d'agenda ».
     */
    public function test_the_add_to_calendar_menu_lists_google_outlook_yahoo_and_ics(): void
    {
        $event = $this->event();

        $html = $this->renderCard();

        $this->assertStringContainsString(e(__('events.add_to_calendar')), $html);
        $this->assertStringContainsString('calendar.google.com', $html);
        $this->assertStringContainsString('outlook.live.com', $html);
        $this->assertStringContainsString('calendar.yahoo.com', $html);
        $this->assertStringContainsString('data:text/calendar', $html);
        $this->assertStringContainsString(__('events.calendar_download_ics'), $html);
    }

    public function test_the_attendee_list_shows_only_those_going(): void
    {
        $event = $this->event();
        $other = User::factory()->create(['organization_id' => $this->org->id]);
        LoopMember::create([
            'organization_id' => $this->org->id,
            'loop_id' => $this->loop->id,
            'user_id' => $other->id,
            'role' => 'member',
            'status' => 'active',
        ]);

        $component = Livewire::actingAs($this->owner)
            ->test(LoopEventsCard::class, ['loop' => $this->loop])
            ->call('respond', $event->id, LoopEventResponse::GOING);

        $this->actingAs($other);
        app(\App\Services\Loops\LoopEventService::class)->respond($other, $event, $this->loop, LoopEventResponse::NOT_GOING);

        $html = $component->call('toggleAttendees', $event->id)->html();

        $this->assertStringContainsString(e($this->owner->publicDisplayName()), $html);
        $this->assertStringNotContainsString(e($other->publicDisplayName()), $html);
    }

    /**
     * TASK-1802 (suite, demande Cyril) — « sur la page agenda, il faut
     * qu'on puisse cliquer sur [...] les icones des nombres de participants
     * [...] un pop-up alpine affiche les participants ». L'agenda n'est pas
     * un composant Livewire : pas de `toggleAttendees()` a appeler, les noms
     * doivent deja etre dans la page (charges par le controleur).
     */
    public function test_the_agenda_page_opens_an_attendee_popup_on_click(): void
    {
        $event = $this->event(['title' => 'Atelier popup presences']);
        app(\App\Services\Loops\LoopEventService::class)->respond($this->owner, $event, $this->loop, LoopEventResponse::GOING);

        $html = $this->actingAs($this->owner)
            ->get(route('organization.events.agenda', ['organization' => $this->org->slug]))
            ->assertOk()
            ->getContent();

        // Le declencheur est bien cliquable (pas un simple compteur statique)...
        $this->assertStringContainsString('aria-haspopup="dialog"', $html);
        // ...et le nom est DEJA dans la page : aucun round-trip a attendre
        // pour un simple clic.
        $this->assertStringContainsString(e($this->owner->publicDisplayName()), $html);
        $this->assertStringContainsString('x-teleport="body"', $html);
    }

    public function test_the_agenda_popup_trigger_is_inert_without_any_attendee(): void
    {
        $this->event(['title' => 'Atelier sans reponse']);

        $html = $this->actingAs($this->owner)
            ->get(route('organization.events.agenda', ['organization' => $this->org->slug]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('aria-haspopup="dialog"', $html);
    }

    /**
     * TASK-1802 (suite, demande Cyril) — « pouvoir cliquer sur la card et non
     * pas sur les chiffres [...] plus facile pour afficher les participants ».
     * Le declencheur du pop-up doit etre la tuile presences ENTIERE — pas
     * seulement les deux pastilles icone+chiffre qu'elle contient.
     */
    public function test_the_whole_presence_tile_is_clickable_not_just_the_counts(): void
    {
        $event = $this->event(['title' => 'Atelier tuile cliquable']);
        app(\App\Services\Loops\LoopEventService::class)->respond($this->owner, $event, $this->loop, LoopEventResponse::GOING);

        $html = $this->actingAs($this->owner)
            ->get(route('organization.events.agenda', ['organization' => $this->org->slug]))
            ->assertOk()
            ->getContent();

        $triggerPos = strpos($html, 'x-on:click="ouvert = true"');
        $rolePos = strpos($html, 'role="button"');
        $labelPos = strpos($html, __('events.attendance_label'));

        $this->assertNotFalse($triggerPos);
        $this->assertNotFalse($rolePos);
        $this->assertNotFalse($labelPos);
        // Le declencheur (et le role bouton qui l'accompagne) precedent le
        // LABEL de la tuile dans le document : ils portent donc sur la tuile
        // ENTIERE, englobante, et non sur un element niche plus bas en son
        // sein (ce que prouverait l'ordre inverse).
        $this->assertLessThan($labelPos, $triggerPos);
        $this->assertLessThan($labelPos, $rolePos);
    }

    /**
     * TASK-1802 (suite, demande Cyril) — « sur smartphone [...] la page se
     * rafraichit [...] il faudrait que ca se mette a jour sans rafraichir ».
     * Le controleur doit repondre en JSON, sans redirection, a un appel
     * `fetch()` (`Accept: application/json`) — c'est ce que l'Alpine de
     * `agenda.blade.php` envoie desormais au lieu d'un POST de formulaire
     * classique.
     */
    public function test_the_agenda_rsvp_answers_in_json_without_a_redirect_when_asked(): void
    {
        $event = $this->event(['title' => 'Atelier sans rafraichissement']);

        $response = $this->actingAs($this->owner)
            ->postJson(
                route('organization.events.agenda.respond', ['organization' => $this->org->slug, 'event' => $event->id]),
                ['response' => LoopEventResponse::GOING],
            );

        $response->assertOk()->assertJson(['status' => 'ok']);
        $this->assertSame(
            LoopEventResponse::GOING,
            app(\App\Services\Loops\LoopEventService::class)->responseOf($this->owner, $event->fresh()),
        );
    }

    public function test_the_agenda_rsvp_json_error_carries_the_message_without_a_redirect(): void
    {
        $event = $this->event(['title' => 'Atelier annule']);
        app(\App\Services\Loops\LoopEventService::class)->cancel($this->owner, $event, $this->loop);

        $response = $this->actingAs($this->owner)
            ->postJson(
                route('organization.events.agenda.respond', ['organization' => $this->org->slug, 'event' => $event->id]),
                ['response' => LoopEventResponse::GOING],
            );

        $response->assertStatus(422)->assertJson(['message' => __('events.error_not_allowed')]);
    }
}
