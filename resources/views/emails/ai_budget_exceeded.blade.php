<x-mail::message>
# Alerte budget IA — {{ $scenarioId }}

Le coût mensuel du scénario **{{ $scenarioId }}** a dépassé le budget configuré.

- **Budget** : {{ number_format($budgetLimit, 4) }} USD
- **Coût actuel** : {{ number_format($currentCost, 4) }} USD
- **Dépassement** : {{ number_format($currentCost - $budgetLimit, 4) }} USD

<x-mail::button :url="route('admin.ai-benchmark')">
Voir le tableau de bord
</x-mail::button>

Merci,<br>
{{ config('app.name') }}
</x-mail::message>
