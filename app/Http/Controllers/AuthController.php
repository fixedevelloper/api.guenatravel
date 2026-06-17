<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Inscription d'un nouvel utilisateur (Client ou Hôte).
     */
    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'role'     => ['nullable', 'string', 'in:customer,host'], // Interdiction de s'inscrire en tant qu'admin
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        // Création de l'utilisateur avec mot de passe haché (bcrypt automatique via cast)
        $user = User::create([
            'name'           => $request->name,
            'email'          => $request->email,
            'password'       => Hash::make($request->password),
            'role'           => $request->role??'customer',
            'wallet_balance' => 0.00, // Initialisation du portefeuille financier
        ]);

        // Génération du Token Sanctum incluant le rôle dans les capacités (abilities)
        $token = $user->createToken('auth_token', [$user->role])->plainTextToken;

        return response()->json([
            'success'      => true,
            'message'      => 'Utilisateur enregistré avec succès.',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ]
        ], 201);
    }

    /**
     * Connexion de l'utilisateur et génération du jeton.
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        // Recherche de l'utilisateur
        $user = User::where('email', $request->email)->first();

        // Vérification du mot de passe
        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Identifiants de connexion incorrects.'
            ], 412);
        }

        // Révocation des anciens tokens si vous souhaitez une session unique par utilisateur
        // $user->tokens()->delete();

        // Génération du nouveau jeton d'API
        $token = $user->createToken('auth_token', [$user->role])->plainTextToken;

        return response()->json([
            'success'      => true,
            'message'      => 'Connexion réussie.',
            'access_token' => $token,
            'token_type'   => 'Bearer',
            'user'         => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'role'  => $user->role,
            ]
        ]);
    }

    /**
     * Déconnexion de l'utilisateur (Révocation du jeton actuel).
     */
    public function logout(Request $request): JsonResponse
    {
        // Supprime le token spécifique utilisé pour la requête actuelle
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion réussie. Le jeton d\'accès a été révoqué.'
        ]);
    }

    /**
     * Déconnexion globale (Révocation de TOUS les jetons - Utile en cas de suspicion de piratage).
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Déconnexion globale réussie. Tous les appareils ont été déconnectés.'
        ]);
    }
}
