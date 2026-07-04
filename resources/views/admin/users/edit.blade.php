<x-admin-layout title="Modifier l'utilisateur">
    <div class="max-w-2xl mx-auto">
        <div class="flex items-center gap-3 mb-6">
            <a href="{{ route('admin.users') }}" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <div class="flex items-center gap-3">
                <img src="{{ $user->avatar_url }}" class="w-10 h-10 rounded-full" alt="">
                <div>
                    <h1 class="text-xl font-bold text-gray-900 dark:text-white leading-tight">{{ $user->fullName }}</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ $user->email }} · Inscrit le {{ $user->created_at->format('d/m/Y') }}</p>
                </div>
            </div>
        </div>

        @if(session('success'))
        <div class="mb-5 p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-700 rounded-xl text-sm text-green-700 dark:text-green-400">
            {{ session('success') }}
        </div>
        @endif

        @if($errors->any())
        <div class="mb-5 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-700 rounded-xl text-sm text-red-700 dark:text-red-400">
            <ul class="list-disc ml-4 space-y-1">
                @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
            </ul>
        </div>
        @endif

        <form method="POST" action="{{ route('admin.users.update', $user) }}" enctype="multipart/form-data" class="space-y-5">
            @csrf @method('PUT')

            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm p-6 space-y-5">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.users_identity') }}</h2>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.avatar') }}</label>
                    <div class="flex items-center gap-4">
                        <img src="{{ $user->avatar_url }}" class="w-12 h-12 rounded-full object-cover" alt="">
                        <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp"
                               class="block w-full text-sm text-gray-500 dark:text-gray-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-indigo-50 dark:file:bg-indigo-900/30 file:text-indigo-700 dark:file:text-indigo-300 hover:file:bg-indigo-100 dark:hover:file:bg-indigo-900/50">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.first_name') }}</label>
                        <input type="text" name="first_name" value="{{ old('first_name', $user->first_name) }}" maxlength="255"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.name') }} <span class="text-red-500">*</span></label>
                        <input type="text" name="name" value="{{ old('name', $user->name) }}" required maxlength="255"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email <span class="text-red-500">*</span></label>
                        <input type="email" name="email" value="{{ old('email', $user->email) }}" required maxlength="255"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.phone') }}</label>
                        <input type="text" name="phone" value="{{ old('phone', $user->phone) }}" maxlength="30"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.city') }}</label>
                        <input type="text" name="city" value="{{ old('city', $user->city) }}" maxlength="255"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.country') }}</label>
                        <select name="country_code"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                            <option value="">{{ __('admin.country_placeholder') }}</option>
                            @foreach($countries as $country)
                            <option value="{{ $country->code }}" {{ old('country_code', $user->country_code) === $country->code ? 'selected' : '' }}>
                                {{ $country->getLocalizedName(app()->getLocale()) }}
                            </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.preferred_locale') }}</label>
                        <select name="preferred_locale"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                            <option value="">{{ __('admin.locale_placeholder') }}</option>
                            <option value="fr" {{ old('preferred_locale', $user->preferred_locale) === 'fr' ? 'selected' : '' }}>Français</option>
                            <option value="en" {{ old('preferred_locale', $user->preferred_locale) === 'en' ? 'selected' : '' }}>English</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.organization') }}</label>
                        <select name="organization_id"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                            <option value="">— {{ __('admin.org_default_placeholder') }} —</option>
                            @foreach($organizations as $organization)
                            <option value="{{ $organization->id }}" {{ old('organization_id', $user->organization_id) === $organization->id ? 'selected' : '' }}>
                                {{ $organization->name }}
                            </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.bio') }}</label>
                    <textarea name="bio" rows="3" maxlength="500"
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">{{ old('bio', $user->bio) }}</textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.website') }}</label>
                        <input type="url" name="website" value="{{ old('website', $user->website) }}" maxlength="255"
                               placeholder="https://…"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">LinkedIn</label>
                        <input type="url" name="linkedin_url" value="{{ old('linkedin_url', $user->linkedin_url) }}" maxlength="255"
                               placeholder="https://linkedin.com/in/…"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                    </div>
                </div>
            </div>

            <!-- Localisation legacy -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm p-6 space-y-3">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.location_legacy') }}</h2>
                <p class="text-xs text-gray-400">{{ __('admin.location_legacy_hint') }}</p>
                <div>
                    <input type="text" value="{{ $user->location }}"
                           readonly
                           class="w-full px-3 py-2 border border-gray-200 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700/50 text-gray-500 dark:text-gray-400 text-sm cursor-not-allowed">
                </div>
            </div>

            <!-- Private billing -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm p-6 space-y-4">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.billing_section') }}</h2>
                <p class="text-xs text-gray-400">{{ __('admin.billing_section_hint') }}</p>

                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.address_line1') }}</label>
                    <input type="text" name="address_line1" value="{{ old('address_line1', $user->address_line1) }}" maxlength="255"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.address_line2') }}</label>
                    <input type="text" name="address_line2" value="{{ old('address_line2', $user->address_line2) }}" maxlength="255"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ __('admin.postal_code') }}</label>
                    <input type="text" name="postal_code" value="{{ old('postal_code', $user->postal_code) }}" maxlength="30"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>

                @if($user->organization?->membership_enabled)
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                        {{ app()->getLocale() === 'en' ? ($user->organization->membership_label_en ?: __('admin.membership')) : ($user->organization->membership_label_fr ?: __('admin.membership')) }}
                    </label>
                    <input type="text" name="membership_value" value="{{ old('membership_value', $user->membership_value) }}" maxlength="255"
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-indigo-500">
                </div>
                @endif
            </div>

            <!-- Droits et statut -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 shadow-sm p-6 space-y-3">
                <h2 class="text-sm font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">{{ __('admin.rights_section') }}</h2>

                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_admin" value="1" {{ old('is_admin', $user->is_admin) ? 'checked' : '' }}
                           {{ $user->id === auth()->id() ? 'disabled' : '' }}
                           class="rounded border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.super_admin') }}</span>
                        <p class="text-xs text-gray-400">{{ __('admin.super_admin_hint') }}</p>
                    </div>
                </label>

                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="is_available" value="1" {{ old('is_available', $user->is_available) ? 'checked' : '' }}
                           class="rounded border-gray-300 dark:border-gray-600 text-green-600 focus:ring-green-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.available') }}</span>
                        <p class="text-xs text-gray-400">{{ __('admin.available_hint') }}</p>
                    </div>
                </label>

                @if($user->id !== auth()->id())
                <label class="flex items-center gap-3 cursor-pointer">
                    <input type="checkbox" name="banned" value="1" {{ old('banned', $user->banned_at ? '1' : '') ? 'checked' : '' }}
                           class="rounded border-gray-300 dark:border-gray-600 text-red-600 focus:ring-red-500">
                    <div>
                        <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ __('admin.banned') }}</span>
                        <p class="text-xs text-gray-400">{{ __('admin.banned_hint') }}</p>
                    </div>
                </label>
                @endif
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="px-6 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-lg shadow-sm transition">
                    {{ __('admin.save') }}
                </button>
                <a href="{{ route('admin.users') }}"
                   class="px-6 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                    {{ __('admin.cancel') }}
                </a>
                <a href="{{ route('admin.users.delete-preview', $user) }}"
                   class="ml-auto text-xs text-red-600 dark:text-red-400 hover:underline">
                    {{ __('admin.delete_user') }} →
                </a>
                <a href="{{ route('profile.show', $user) }}" target="_blank"
                   class="ml-auto text-xs text-indigo-600 dark:text-indigo-400 hover:underline">
                    {{ __('admin.view_public_profile') }} ↗
                </a>
            </div>
        </form>
    </div>
</x-admin-layout>
