<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Invitation — {{ $invitation->recipientFullName() }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if (session('success'))
                <div class="rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 p-4 text-sm text-green-800 dark:text-green-300">
                    {{ session('success') }}
                </div>
            @endif

            @if (session('error'))
                <div class="rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 p-4 text-sm text-red-800 dark:text-red-300">
                    {{ session('error') }}
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6 space-y-4">
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Destinataire</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $invitation->recipientFullName() }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Email</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $invitation->recipient_email }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Organisation</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $invitation->organization?->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Statut</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $invitation->status }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Boucle cible</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">
                            {{ $invitation->loop?->name ?? '—' }}
                            @if ($invitation->loop?->visibility === 'private')
                                <span class="text-xs text-gray-400">(privée)</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Langue de l'email</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $invitation->locale === 'en' ? 'English' : 'Français' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Créée par</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $invitation->createdBy?->fullName ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Envoyée le</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $invitation->created_at->format('d/m/Y H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Expiration</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $invitation->expires_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Activée le</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $invitation->accepted_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500 dark:text-gray-400">Compte créé</dt>
                        <dd class="mt-1 text-gray-900 dark:text-gray-100">{{ $invitation->acceptedBy?->fullName ?? '—' }}</dd>
                    </div>
                </dl>

                @if ($invitation->isPending())
                    <div class="flex gap-3 pt-2 border-t border-gray-100 dark:border-gray-700">
                        <form method="POST" action="{{ route('admin.users.bulk-create.invitations.resend', $invitation) }}">
                            @csrf
                            <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 text-sm font-medium">Relancer</button>
                        </form>
                        <form method="POST" action="{{ route('admin.users.bulk-create.invitations.revoke', $invitation) }}">
                            @csrf
                            <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 text-sm font-medium">Révoquer</button>
                        </form>
                    </div>
                @endif
            </div>

            <a href="{{ route('admin.users.bulk-create') }}" class="text-sm text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                &larr; Retour à la création de comptes en masse
            </a>
        </div>
    </div>
</x-admin-layout>
