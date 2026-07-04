<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            Détails de l'email
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            <!-- Log details -->
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-6">
                        Informations de l'email
                    </h3>

                    <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Date d'envoi</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                {{ $emailLog->created_at->format('d/m/Y H:i:s') }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Statut</dt>
                            <dd class="mt-1">
                                @if($emailLog->status === 'sent')
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300">
                                        Envoyé avec succès
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300">
                                        Échoué
                                    </span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Destinataire</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                {{ $emailLog->to_email }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Utilisateur</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                @if($emailLog->user)
                                    {{ $emailLog->user->fullName }} ({{ $emailLog->user->email }})
                                @else
                                    -
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Template</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                @if($emailLog->template)
                                    {{ $emailLog->template->name }} <span class="text-gray-500 dark:text-gray-400">({{ $emailLog->template->slug }})</span>
                                @else
                                    -
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Sujet</dt>
                            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">
                                {{ $emailLog->subject }}
                            </dd>
                        </div>
                    </dl>

                    @if($emailLog->error_message)
                        <div class="mt-6">
                            <dt class="text-sm font-medium text-red-600 dark:text-red-400">Erreur</dt>
                            <dd class="mt-1 text-sm text-red-700 dark:text-red-300 bg-red-50 dark:bg-red-900/20 p-3 rounded-md">
                                {{ $emailLog->error_message }}
                            </dd>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Email data -->
            @if($emailLog->data)
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-4">
                            Données de l'email
                        </h3>
                        <div class="bg-gray-50 dark:bg-gray-900 rounded-md p-4">
                            <pre class="text-xs text-gray-700 dark:text-gray-300 overflow-auto">{{ json_encode($emailLog->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </div>
                    </div>
                </div>
            @endif

            <div>
                <a href="{{ route('admin.email-logs') }}"
                   class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200">
                    ← Retour à l'historique
                </a>
            </div>
        </div>
    </div>
</x-admin-layout>
