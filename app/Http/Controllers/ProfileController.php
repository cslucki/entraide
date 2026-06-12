<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\MemberAiProfile;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Intervention\Image\Laravel\Facades\Image;

class ProfileController extends Controller
{
    public function show(User $user): View
    {
        $organization = currentOrganization();
        if (! $organization || $user->organization_id !== $organization->id) {
            abort(404);
        }

        $memberAiProfile = MemberAiProfile::where('user_id', $user->id)
            ->where('status', MemberAiProfile::STATUS_PUBLISHED)
            ->first();

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

    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email,'.$request->user()->id],
            'phone' => ['required', 'string', 'max:30'],
            'show_email' => ['nullable', 'boolean'],
            'show_phone' => ['nullable', 'boolean'],
            'avatar' => ['nullable', 'image', 'max:2048'],
            'bio' => ['nullable', 'string', 'max:500'],
            'location' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url', 'max:255'],
            'linkedin_url' => ['nullable', 'url', 'max:255'],
        ]);

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

        return back()->with('success', 'Disponibilité mise à jour.');
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
