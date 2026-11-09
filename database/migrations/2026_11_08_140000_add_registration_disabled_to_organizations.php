<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-1787 — « Interdire la creation de compte », par Organization.
 *
 * ## Pourquoi une colonne a part, et surtout pas `is_public`
 *
 * `is_public` dit « la vitrine est visible sans connexion » : page d'accueil,
 * explorateur, selecteurs de CONNEXION. Il est deja lu par `GuestShellGate`,
 * `WorkshopResumption`, `GuestPageContextResolver` et
 * `OrganizationLandingController`.
 *
 * Le besoin est different, et c'est tout l'objet de cette TASK : `main` reste
 * PUBLIQUE — vitrine, administration, tests de production, et ses 20 membres
 * continuent de s'y connecter — mais n'accepte plus de nouvelle inscription.
 * Les deux notions doivent donc se regler separement. Les confondre aurait
 * rendu `main` invisible pour en fermer l'inscription.
 *
 * ## Additive et non destructive
 *
 * Une colonne ajoutee, `false` par defaut : **toutes** les Organizations
 * existantes gardent exactement le comportement qu'elles ont aujourd'hui, et
 * aucune ligne n'est reecrite. Rien a retro-remplir, puisque le defaut porte
 * deja la valeur voulue — contrairement a `blog_comments.organization_id`, pose
 * nullable sans defaut utile, qui avait exige une migration de backfill.
 *
 * ## `NOT NULL`, et c'est deliberé
 *
 * Un drapeau de SECURITE ne doit pas avoir d'etat « inconnu » : `null` se lit
 * `false` dans un `if` distrait, et une Organization fermee redeviendrait
 * ouverte au premier chemin qui oublie le `?? false`. La colonne porte donc un
 * defaut de fabrique et refuse `NULL`.
 *
 * `down()` retire la colonne : aucune autre table ne la reference, et la
 * reversibilite est entiere.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('organizations', 'registration_disabled')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('registration_disabled')
                ->default(false)
                ->after('is_public');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('organizations', 'registration_disabled')) {
            return;
        }

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('registration_disabled');
        });
    }
};
