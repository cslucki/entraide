{{--
    TASK-1306 : qui gere le credential IA de cette Organization — reserve au
    SuperAdmin, la garde reelle est cote serveur
    (OrgAdminController::updateAiCredentialMode()). UNE seule autorite,
    incluse ici et depuis /admin/ai-organizations.

    Attend en scope : $organization, $credentialMode (string).
--}}
<div class="space-y-3" data-ai-credential-mode-panel="{{ $organization->slug }}" data-ai-credential-mode-current="{{ $credentialMode }}">
    <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">{{ __('admin.organization_ai_credential_mode_label') }}</h3>
    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('admin.organization_ai_credential_mode_help') }}</p>
    <form method="POST" action="{{ route('organization.admin.ai.credential-mode.update', $organization) }}" class="flex flex-wrap items-center gap-3">
        @csrf
        @method('PUT')
        <select name="credential_management_mode" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm" data-ai-credential-mode-select>
            <option value="{{ \App\Models\OrganizationAiSetting::CREDENTIAL_MODE_PLATFORM }}" @selected($credentialMode === \App\Models\OrganizationAiSetting::CREDENTIAL_MODE_PLATFORM)>{{ __('admin.organization_ai_credential_mode_platform') }}</option>
            <option value="{{ \App\Models\OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION }}" @selected($credentialMode === \App\Models\OrganizationAiSetting::CREDENTIAL_MODE_ORGANIZATION)>{{ __('admin.organization_ai_credential_mode_organization') }}</option>
        </select>
        <button type="submit" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-800 dark:text-gray-100 text-sm font-medium rounded-lg transition">
            {{ __('admin.organization_save') }}
        </button>
    </form>
</div>
