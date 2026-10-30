<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointLedger;
use App\Models\User;
use App\Rules\UniqueEmailInOrganization;
use App\Support\Tenancy\DefaultOrganizationResolver;
use App\Support\Tenancy\UserWriteLock;
use Illuminate\Http\JsonResponse;
use App\Support\ScenarioManager\SandboxGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        // TASK-1729 (A4) — l'Organization d'accueil est resolue AVANT la
        // validation, parce que la regle d'unicite en a besoin : une adresse
        // n'est plus unique « sur la plateforme » mais DANS un tenant. Elle
        // etait resolue juste apres ; elle ne l'est plus qu'ici, et la ligne
        // qui suivait a disparu.
        $organization = currentOrganization() ?? DefaultOrganizationResolver::resolve();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            // TASK-1729 (A4) — unicite par Organization d'accueil, sans casse.
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255',
                new UniqueEmailInOrganization($organization?->getKey())],
            'phone' => ['required', 'string', 'max:30'],
            'country_code' => ['required', 'string', 'size:2', 'exists:countries,code'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // TASK-1650 — le MEME refus que la route web, par le MEME predicat.
        //
        // Ce controleur est une copie ligne pour ligne de l'inscription web.
        // La garde n'avait ete posee que sur l'une des deux : un predicat de
        // securite recopie est un predicat qui diverge.
        if (SandboxGuard::estUneSandbox($organization)) {
            return response()->json([
                'message' => "Cette organisation est un monde de demonstration : on ne peut pas s'y inscrire.",
            ], 422);
        }

        if (! $organization) {
            throw ValidationException::withMessages([
                'email' => ['Aucune organisation active n\'est disponible pour l\'inscription.'],
            ]);
        }

        // TASK-1729 phase 2 — meme verrou, meme recontrole que la route web.
        //
        // Ce point d'entree est une copie ligne pour ligne de l'inscription
        // web, et la fenetre y etait identique : la regle d'unicite tourne
        // dans `$request->validate()`, donc hors transaction. Ne la fermer que
        // d'un cote aurait laisse l'API fabriquer exactement l'orphelin ambigu
        // que le web refuse desormais — et c'est deja ce qui etait arrive a la
        // garde sandbox de TASK-1650, posee sur une seule des deux portes.
        $user = DB::transaction(function () use ($data, $organization) {
            UserWriteLock::acquire();

            UniqueEmailInOrganization::refuserSiPrise(
                'email', $data['email'], $organization->getKey()
            );

            $user = User::create([
                'name' => $data['name'],
                'first_name' => $data['first_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'country_code' => $data['country_code'],
                'password' => Hash::make($data['password']),
                'points_balance' => 100,
                'organization_id' => $organization->id,
            ]);

            // TASK-1714 volet 2 — l'approbation, posee HORS assignation de masse.
            //
            // `approved_at` n'est deliberement PAS dans `User::$fillable`, comme
            // `email_verified_at` et `must_set_password` : un drapeau qui ouvre
            // l'acces ne doit pas pouvoir etre bascule par un formulaire qui
            // recopierait trop large. `forceFill()` est le seul chemin, et il est
            // ici explicite.
            //
            // `now()` quand l'Organization n'exige rien, jamais `null` : un compte
            // approuve doit l'etre EXPLICITEMENT en base, pour qu'un `SELECT`
            // reponde a « qui attend ? » sans connaitre le reglage. C'est aussi ce
            // qui rend la non-retroactivite stable — activer le reglage plus tard
            // ne remet personne en attente.
            $user->forceFill([
                'approved_at' => $organization->requires_account_approval ? null : now(),
            ])->save();

            PointLedger::create([
                'user_id' => $user->id,
                'transaction_id' => null,
                'delta' => 100,
                'organization_id' => $user->organization_id,
                'reason' => 'welcome_bonus',
            ]);

            return $user;
        });

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ], 201);
    }

    /**
     * TASK-1728 (A3) — la connexion API designe un COMPTE (arbitrage MASTER D3).
     *
     * ## Ce qui a change
     *
     * `User::where('email', ...)->first()` est remplace par une resolution
     * explicite. Le format de la reponse est INCHANGE, et le champ ajoute est
     * FACULTATIF : aucun client existant n'est casse.
     *
     * ## Pourquoi un champ, et pas un contexte
     *
     * `ResolveApiOrganization` derive l'Organization de l'utilisateur
     * AUTHENTIFIE, et retombe sur celle par defaut quand personne ne l'est. Or
     * au moment du login, personne ne l'est encore : l'API n'avait donc
     * AUCUNE facon de nommer son tenant. `routes/api.php` ne porte pas non
     * plus de prefixe `/org/{slug}`. Un champ etait le seul chemin possible.
     *
     * ## Le refus est unique, et c'est voulu
     *
     * Slug inconnu, Organization inactive, adresse absente de cette
     * Organization, adresse ambigue, mot de passe faux : **un seul message**.
     * Distinguer le slug inconnu aurait fait de ce point d'entree un
     * enumerateur d'Organizations ; distinguer l'ambiguite aurait annonce
     * qu'une adresse existe dans plusieurs tenants.
     */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            // Facultatif, et jamais `exists` : un slug inconnu doit tomber
            // dans le refus generique, pas dans une erreur de validation qui
            // confirmerait quelles Organizations existent.
            'organization' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $comptes = app(\App\Services\Users\LoginAccountResolver::class);

        $slug = $data['organization'] ?? null;
        $organization = $slug === null || $slug === ''
            ? null
            : \App\Models\Organization::query()->where('slug', $slug)->where('is_active', true)->first();

        // Un slug FOURNI mais introuvable ne doit pas elargir la recherche a
        // tous les tenants : ce serait l'inverse de ce qu'on a demande. On
        // refuse, avec le message commun.
        $user = ($slug !== null && $slug !== '' && $organization === null)
            ? null
            : $comptes->compteUnique($data['email'], $organization);

        // `Hash::check` n'est evalue QUE sur un compte resolu : jamais compte
        // par compte pour savoir lequel repond. Le mot de passe verifie une
        // identite, il ne la choisit pas.
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Les identifiants fournis sont incorrects.'],
            ]);
        }

        if ($user->banned_at !== null) {
            return response()->json(['message' => 'Votre compte est suspendu.'], 403);
        }

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté.']);
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'first_name' => $user->first_name,
            'full_name' => $user->fullName,
            'email' => $user->email,
            'points_balance' => $user->points_balance,
            'is_available' => $user->is_available,
            'rating' => $user->rating,
            'avatar_url' => $user->avatar_url,
        ];
    }
}
