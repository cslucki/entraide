{{-- TASK-1463 (audit OPUS final P1-1, Growth V3 §8) : bloc « Ateliers » de l'accueil public — ateliers PUBLIES de CETTE Organization avec une session PUBLIEE a venir (PublicWorkshopListing, la meme selection que workshops.runtime). Lecture pure : aucun cookie, aucune identite. Chaque lien interne transporte SEULEMENT le code de Shortcut et les UTM bornes de la page courante (GuestAttribution::carry) ; la Journey et la campagne sont relues en base au premier geste. Aucun meeting_url, aucun inscrit, aucune capacite, aucune donnee CRM. --}}
@if(isset($publicWorkshops) && $publicWorkshops->isNotEmpty())
@php $carry = \App\Services\Acquisition\GuestAttribution::carry(request()->query()); @endphp
<style>
.bp-orgws{max-width:1100px;margin:0 auto;padding:32px 20px;color:var(--bp-text,#1f2937);font-family:inherit}
.bp-orgws h2{font-size:1.35rem;font-weight:800;margin:0 0 6px}
.bp-orgws .bp-orgws-hint{margin:0 0 18px;color:var(--bp-muted,#6b7280);font-size:.95rem}
.bp-orgws ul{list-style:none;margin:0;padding:0;display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(260px,1fr))}
.bp-orgws li{border:1px solid var(--bp-border,#e5e7eb);border-radius:18px;padding:16px 18px;background:var(--bp-card,#fff)}
.bp-orgws a.bp-orgws-link{font-weight:700;font-size:1.05rem;color:inherit;text-decoration:none}
.bp-orgws a.bp-orgws-link:hover{text-decoration:underline}
.bp-orgws .bp-orgws-promise{margin:6px 0 0;font-size:.95rem}
.bp-orgws .bp-orgws-when{margin:8px 0 0;font-size:.85rem;color:var(--bp-muted,#6b7280)}
</style>
<section class="bp-orgws" data-org-workshops aria-labelledby="bp-orgws-title">
    <h2 id="bp-orgws-title">{{ __('workshops.home_block_title') }}</h2>
    <p class="bp-orgws-hint">{{ __('workshops.home_block_hint') }}</p>
    <ul>
        @foreach($publicWorkshops as $workshop)
        @php $session = $workshop->sessions->first(); @endphp
        <li data-org-workshop="{{ $workshop->id }}">
            <a class="bp-orgws-link" href="{{ route('organization.workshop.show', ['organization' => $organization->slug, 'workshop' => $workshop->slug] + $carry) }}" data-org-workshop-link>{{ $workshop->title }}</a>
            @if(trim((string) $workshop->promise) !== '')<p class="bp-orgws-promise">{{ $workshop->promise }}</p>@endif
            <p class="bp-orgws-when"><time datetime="{{ $session->starts_at->toIso8601String() }}">{{ $session->localStartsAt()->translatedFormat(__('workshops.public_session_date_format')) }}</time> ({{ $session->timezone }}) · {{ __('workshops.format_'.$workshop->format) }}</p>
        </li>
        @endforeach
    </ul>
</section>
@endif
