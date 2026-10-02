<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PointLedger;
use App\Models\User;
use App\Support\Tenancy\DefaultOrganizationResolver;
use Illuminate\Http\JsonResponse;
use App\Support\ScenarioManager\SandboxGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users'],
            'phone' => ['required', 'string', 'max:30'],
            'country_code' => ['required', 'string', 'size:2', 'exists:countries,code'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $organization = currentOrganization() ?? DefaultOrganizationResolver::resolve();

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

        PointLedger::create([
            'user_id' => $user->id,
            'transaction_id' => null,
            'delta' => 100,
            'organization_id' => $user->organization_id,
            'reason' => 'welcome_bonus',
        ]);

        $token = $user->createToken('api')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

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
