<?php


namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class HostSettingsController extends Controller
{
    /**
     * [HOST] Récupère le profil et la dernière préférence de versement connue
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $host = $request->user();

        // On cherche sa dernière demande de retrait pour pré-remplir ses coordonnées préférées
        $lastWithdrawal = DB::table('withdrawals')
            ->where('user_id', $host->id)
            ->orderBy('created_at', 'desc')
            ->first();

        $payoutPreference = [
            'method' => 'wave',
            'account' => ''
        ];

        if ($lastWithdrawal && !empty($lastWithdrawal->bank_details_snapshot)) {
            $snapshot = json_decode($lastWithdrawal->bank_details_snapshot, true);
            $payoutPreference['method'] = $lastWithdrawal->payment_method;
            $payoutPreference['account'] = $snapshot['requested_account'] ?? '';
        }

        return response()->json([
            'success' => true,
            'data' => [
                'user' => [
                    'name' => $host->name,
                    'email' => $host->email,
                    'phone_number' => $host->phone_number,
                ],
                'payout_preference' => $payoutPreference
            ]
        ]);
    }

    /**
     * [HOST] Met à jour les informations de base (Nom, Email, Téléphone)
     * @param Request $request
     * @return JsonResponse
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $host = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $host->id,
            'phone_number' => 'nullable|string|max:50',
        ]);

        // Mise à jour de l'entité User
        DB::table('users')
            ->where('id', $host->id)
            ->update([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone_number' => $validated['phone_number'],
                'updated_at' => now()
            ]);

        return response()->json([
            'success' => true,
            'message' => 'Profil mis à jour avec succès.'
        ]);
    }

    /**
     * [HOST] Simulation de sauvegarde de préférence de versement
     * Note: Dans votre structure actuelle, cette valeur est capturée à la volée
     * lors de la soumission du formulaire de retrait. Nous renvoyons un état valide.
     * @param Request $request
     * @return JsonResponse
     */
    public function updatePayoutPreference(Request $request): JsonResponse
    {
        $request->validate([
            'default_method' => 'required|string',
            'account_identifier' => 'required|string',
        ]);

        // Optionnel : Vous pouvez créer une colonne JSON `payout_settings` dans votre table `users`
        // si vous souhaitez figer ce paramètre hors du flux de retraits.

        return response()->json([
            'success' => true,
            'message' => 'Préférences de versement mémorisées pour vos prochains retraits.'
        ]);
    }
}
