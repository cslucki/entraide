<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\Country;
use App\Models\MemberAiProfile;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Intervention\Image\Laravel\Facades\Image;

class ProfileController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->route('user');
        if (is_string($user)) {
            $user = User::where('id', $user)->firstOrFail();
        }

        $organization = currentOrganization();
        if (! $organization || $user->organization_id !== $organization->id) {
            abort(404);
        }

        if ($user->banned_at !== null) {
            abort(404);
        }

        $memberAiProfile = $organization->ai_profiles_enabled
            ? MemberAiProfile::where('user_id', $user->id)
                ->where('status', MemberAiProfile::STATUS_PUBLISHED)
                ->first()
            : null;

        $services = $user->services()->where('status', 'active')->with('category', 'skills')->latest()->get();
        $openRequests = $user->serviceRequests()->where('status', 'open')->with('category')->latest()->get();
        $completedCount = Transaction::where(function ($q) use ($user) {
            $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        })->where('status', 'completed')->count();
        $reviews = $user->reviewsReceived()
            ->whereHas('transaction', fn ($q) => $q->where('organization_id', $organization->id))
            ->with('reviewer')
            ->latest('created_at')
            ->get();
        $badges = $user->badges()->get();
        $blogPosts = BlogPost::where('user_id', $user->id)
            ->where('organization_id', $organization->id)
            ->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->with('category')
            ->latest('published_at')
            ->limit(6)
            ->get();

        $ogTitle = $user->name;
        $ogDescription = $user->bio
            ? Str::limit($user->bio, 160)
            : "Profil de {$user->name} sur Entraide";
        $ogImage = $user->avatar_url;
        $jsonLd = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $user->name,
            'url' => route('profile.show', $user),
            'description' => $user->bio,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return view('profile.show', compact('user', 'services', 'openRequests', 'completedCount', 'reviews', 'badges', 'blogPosts', 'ogTitle', 'ogDescription', 'ogImage', 'jsonLd', 'memberAiProfile'));
    }

    public function aiAgentChat(User $user): View
    {
        $organization = currentOrganization();
        if (! $organization || $user->organization_id !== $organization->id) {
            abort(404);
        }

        if (! $user->isDisplayableIn($organization)) {
            abort(404);
        }

        $memberAiProfile = MemberAiProfile::where('user_id', $user->id)
            ->where('status', MemberAiProfile::STATUS_PUBLISHED)
            ->firstOrFail();

        if (! $organization->ai_profiles_enabled) {
            abort(404);
        }

        return view('profile.ai-agent-chat', compact('user', 'memberAiProfile'));
    }

    /**
     * TASK-1689 — l'Organization qui gouverne le PROFIL est celle de
     * l'utilisateur, jamais celle de l'URL.
     *
     * ## Le defaut mesure
     *
     * `edit()` et `update()` lisaient toutes deux
     * `currentOrganization() ?? $request->user()->organization` : l'URL
     * D'ABORD. Or cette Organization decide si `membership_value` est un champ
     * ACCEPTE (`update()`) et s'il est RENDU, avec quel libelle (`edit()`).
     *
     * `organization.profile.edit` et `organization.profile.update` ne portent
     * pas `EnsureOrganizationMember` — a la difference de
     * `organization.profile.show`. N'importe quel membre pouvait donc poser le
     * slug d'une autre Organization et heriter de SA configuration :
     *
     * | contexte | `membership_enabled` de l'org lue | resultat mesure |
     * |---|---|---|
     * | son organisation A | false | `membership_value` ignore |
     * | URL de l'organisation B | true | `membership_value` **ECRIT** |
     *
     * Un reglage que son Organization a deliberement DESACTIVE devenait
     * accessible en changeant un segment d'URL.
     *
     * ## Pourquoi AUCUN repli vers l'URL
     *
     * `users.organization_id` est NULLABLE (mesure sur le schema, 0 ligne
     * concernee aujourd'hui). Pour un utilisateur sans Organization, le contrat
     * EXISTANT est deja le bon : `$organization` vaut `null`, donc
     * `membership_value` n'entre pas dans les regles et le champ n'est pas
     * rendu. Ajouter `?? currentOrganization()` rouvrirait precisement le trou
     * pour ces comptes. On n'en ajoute pas.
     *
     * ## Ce que ce correctif NE touche pas
     *
     * Ni autorisation, ni middleware, ni `CurrentOrganization` : la resolution
     * globale du tenant est inchangee. Dans son propre tenant — le parcours
     * normal — les deux expressions rendent la MEME Organization, et la route
     * non prefixee reste gouvernee par la redirection canonique de TASK-1601.
     */
    private function profileOrganization(Request $request): ?Organization
    {
        return $request->user()?->organization;
    }

    public function edit(Request $request): View
    {
        $organization = $this->profileOrganization($request);
        $localeColumn = app()->getLocale() === 'en' ? 'name_en' : 'name_fr';

        $priorityCountries = $organization
            ? $organization->priorityCountries()->where('active', true)->get()
            : collect();
        $priorityCountryCodes = $priorityCountries->pluck('code');
        $otherCountries = Country::query()
            ->where('active', true)
            ->when($priorityCountryCodes->isNotEmpty(), fn ($query) => $query->whereNotIn('code', $priorityCountryCodes))
            ->orderBy($localeColumn)
            ->get();

        return view('profile.edit', [
            'user' => $request->user(),
            'organization' => $organization,
            'countries' => $priorityCountries->concat($otherCountries),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $organization = $this->profileOrganization($request);

        $rules = [
            'first_name' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,'.$request->user()->id],
            'phone' => ['required', 'string', 'max:30'],
            'show_email' => ['nullable', 'boolean'],
            'show_phone' => ['nullable', 'boolean'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'bio' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:255'],
            'country_code' => ['nullable', 'string', 'size:2', Rule::exists('countries', 'code')->where('active', true)],
            'preferred_locale' => ['nullable', Rule::in(['fr', 'en'])],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'website' => ['nullable', 'url', 'max:255'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
        ];

        if ($organization?->membership_enabled) {
            $rules['membership_value'] = ['nullable', 'string', 'max:255'];
        }

        $data = $request->validate($rules);

        if ($request->hasFile('avatar')) {
            $file = $request->file('avatar');
            $filename = time().'_'.$file->getClientOriginalName();

            $img = Image::decode($file);
            $img->cover(300, 300);

            Storage::disk('public')->put('avatars/'.$filename, (string) $img->encode());
            $data['avatar'] = 'avatars/'.$filename;
        }

        // Handle booleans (checkboxes are not sent if unchecked)
        $data['show_email'] = $request->has('show_email');
        $data['show_phone'] = $request->has('show_phone');

        $request->user()->fill($data);

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return redirect()->intended(route('profile.edit'))->with('status', 'profile-updated');
    }

    public function toggleAvailability(Request $request): RedirectResponse
    {
        $user = $request->user();
        $user->update(['is_available' => ! $user->is_available]);

        return back()->with('success', __('dashboard.availability_updated'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        Auth::logout();
        $user->delete();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
