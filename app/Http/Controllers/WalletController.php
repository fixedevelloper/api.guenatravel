<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserWalletResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletController extends Controller
{
    /**
     * [HOST] Consulter le solde du portefeuille et l'historique complet des transactions.
     * * Cette méthode récupère l'utilisateur connecté (qui doit être un hôte),
     * charge ses transactions financières triées de la plus récente à la plus ancienne,
     * et passe le tout au composant de filtrage d'API UserWalletResource.
     * @param Request $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        // Optimisation SQL : On charge les transactions et leurs sources polymorphes (Payment, Withdrawal)
        // en une seule fois pour éviter le syndrome des requêtes N+1.
        $user->load(['walletTransactions' => function ($query) {
            $query->latest();
        }]);

        return response()->json([
            'success' => true,
            'message' => 'Données du portefeuille récupérées avec succès.',
            'data' => new UserWalletResource($user)
        ]);
    }

    /**
     * Récupère le récapitulatif du compte, des retraits et des transactions
     * @param Request $request
     * @return JsonResponse
     */
    public function getPayoutsData(Request $request)
    {
        $host = $request->user();

        // 1. Historique des demandes de retraits
        $withdrawals = DB::table('withdrawals')
            ->where('user_id', $host->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // 2. Journal du livre des comptes (crédits / débits)
        $transactions = DB::table('wallet_transactions')
            ->where('user_id', $host->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'user' => [
                'wallet_balance' => (float) $host->wallet_balance,
                'wallet_escrow' => (float) $host->wallet_escrow,
            ],
            'withdrawals' => $withdrawals,
            'transactions' => $transactions
        ]);
    }
}
