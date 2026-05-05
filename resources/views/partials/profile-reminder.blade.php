@if(session('profile_required'))
<div class="bg-amber-50 dark:bg-amber-900/20 border-b border-amber-200 dark:border-amber-700">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 flex items-start gap-3">
        <span class="text-amber-500 flex-shrink-0 mt-0.5 text-lg">⚠️</span>
        <p class="text-sm text-amber-800 dark:text-amber-200">
            <strong>Présentez-vous avant de publier.</strong>
            Complétez votre présentation ci-dessous — les membres ont besoin de savoir à qui ils ont affaire avant de répondre à une offre ou une demande.
        </p>
    </div>
</div>
@endif
