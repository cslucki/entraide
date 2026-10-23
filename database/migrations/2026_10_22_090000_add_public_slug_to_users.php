<?php

use App\Models\User;
use App\Services\UserSlugService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1693 bug Z — l'adresse publique d'un membre.
 *
 * Deux objets, pas un :
 *
 * - `users.slug` porte l'adresse COURANTE. Nullable : un compte sans nom
 *   exploitable garde son UUID jusqu'a ce que la personne choisisse.
 * - `user_slugs` est le REGISTRE : il garde chaque slug attribue, courant ou
 *   passe, et c'est lui qui empeche le recyclage. Sans lui, changer de slug
 *   libererait le precedent, et quiconque le reprendrait heriterait des liens
 *   deja partages vers quelqu'un d'autre.
 *
 * Unicite COMPOSITE `['organization_id', 'slug']` dans les deux cas :
 * Organization = Tenant, et deux Marie Dupont de deux Organizations
 * differentes ne doivent pas se gener. Meme forme que `tags`, passe d'un
 * unique global a un composite le 25/06/2026.
 *
 * ## La reprise
 *
 * Les comptes existants recoivent un slug `prenom-nom`, suffixe en cas de
 * collision. Arbitrage Cyril du 22/10 : leur nom est deja visible dans
 * l'annuaire pour exactement les memes personnes qui peuvent ouvrir le profil,
 * donc l'exposition supplementaire est quasi nulle et le benefice immediat.
 * Les comptes sans nom exploitable restent en UUID — fabriquer un
 * `membre-1234` serait plus joli et ne voudrait rien dire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'slug')) {
                $table->string('slug', 60)->nullable()->after('name');
                $table->unique(['organization_id', 'slug'], 'users_organization_slug_unique');
            }
        });

        if (! Schema::hasTable('user_slugs')) {
            Schema::create('user_slugs', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignUuid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
                $table->string('slug', 60);
                $table->boolean('is_current')->default(true);
                $table->timestamps();

                $table->unique(['organization_id', 'slug'], 'user_slugs_organization_slug_unique');
                $table->index(['user_id', 'is_current']);
            });
        }

        $service = app(UserSlugService::class);

        User::query()->whereNull('slug')->orderBy('created_at')->chunkById(100, function ($users) use ($service) {
            foreach ($users as $user) {
                $service->attribuerDepuisLeNom($user);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_slugs');

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'slug')) {
                $table->dropUnique('users_organization_slug_unique');
                $table->dropColumn('slug');
            }
        });
    }
};
