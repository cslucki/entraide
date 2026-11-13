<?php

/**
 * TASK-1818 — fixture E2E ISOLEE pour la recette des suggestions IA.
 *
 * Le bloc de formulation n'est rendu que si l'IA est reellement utilisable.
 * Une premiere version armait l'IA sur la boucle `main` en LISANT sa cle, en la
 * renvoyant sur la sortie standard et en la reinjectant pour restaurer. Trois
 * fautes en une : un secret affiche, un secret reinjecte dans une commande, et
 * une configuration existante modifiee sans garantie de remise en etat.
 *
 * Cette fixture ne touche a RIEN d'existant : elle cree sa propre boucle, son
 * propre compte et sa propre configuration IA, avec une cle SYNTHETIQUE qui
 * n'appelle aucun fournisseur (le spec double la route et bloque le Service
 * Worker). Le demontage supprime l'ensemble.
 */

$slug = 'e2e-1818';
$email = 'e2e1818@bouclepro.test';
$motDePasse = 'banc-e2e-1818';

// Idempotent : un demontage rate ne doit pas bloquer la prochaine execution.
$ancienne = App\Models\Organization::withTrashed()->where('slug', $slug)->first();
if ($ancienne) {
    App\Models\User::where('organization_id', $ancienne->id)->delete();
    App\Models\OrganizationAiSetting::where('organization_id', $ancienne->id)->delete();
    $ancienne->forceDelete();
}

$organization = App\Models\Organization::create([
    'name' => 'Banc E2E 1818',
    'slug' => $slug,
    'is_active' => true,
    'is_public' => true,
    'ai_profiles_enabled' => true,
]);

App\Models\OrganizationAiSetting::create([
    'organization_id' => $organization->id,
    'is_enabled' => true,
    'provider' => 'openai',
    'model' => 'gpt-4o-mini',
    // Cle SYNTHETIQUE. Aucun appel ne part : la route de formulation est
    // doublee cote navigateur et le Service Worker est bloque.
    'api_key' => 'sk-synthetique-banc-e2e-1818',
]);

App\Models\User::factory()->complete()->create([
    'email' => $email,
    'password' => Illuminate\Support\Facades\Hash::make($motDePasse),
    'organization_id' => $organization->id,
    'email_verified_at' => now(),
    'points_balance' => 100,
]);

// Sentinelle SANS secret : le spec n'attend que cette ligne.
echo 'FIXTURE_1818_MONTEE';
