<?php


namespace App\Http\Controllers\Host;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class HostRegisterController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'phone' => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'company_name' => $request->company_name ?? null,
            'password' => Hash::make($request->password),
            'role' => 'host', // Affectation critique du rôle professionnel
        ]);

        // Génération du token Sanctum pour connecter l'utilisateur immédiatement
        $token = $user->createToken('host_auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'token' => $token,
            'data' => [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role
            ]
        ], 201);
    }
}
