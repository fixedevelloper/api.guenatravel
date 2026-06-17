<?php


namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Http\Requests\WithdrawalRequest;
use App\Http\Events\WithdrawalRequested;
use App\Models\Withdrawal;
use App\Services\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HostPayoutController extends Controller
{
    /**
     * Injection de votre service financier existant.
     */
    public function __construct(
        protected FinanceService $financeService
    ) {}

/**
 * [HOST] Agrege toutes les données pour l'interface Next.js (Portefeuille, Retraits, Journal)
 * URL: GET /api/host/payouts-data
 */
public function getPayoutsData(Request $request): JsonResponse
{
    $host = $request->user();

    // 1. Historique complet des demandes de retraits de l'hôte
    $withdrawals = Withdrawal::where('user_id', $host->id)
        ->latest()
        ->get();

    // 2. Journal du livre des comptes (crédits / débits rattachés à wallet_transactions)
    $transactions = DB::table('wallet_transactions')
        ->where('user_id', $host->id)
        ->latest()
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

/**
 * [HOST] Initier une nouvelle demande de retrait via votre Service & Request
 * URL: POST /api/host/withdrawals
 */
public function requestWithdrawal(WithdrawalRequest $request): JsonResponse
{
    try {
        // Reconstitution du tableau attendu par votre service à partir des inputs Next.js
        $bankDetails = [
            'method' => $request->input('payment_method'),
            'account' => $request->input('account_details'),
        ];

        // Appel de votre méthode métier existante (déduction du solde, écriture comptable)
        $withdrawal = $this->financeService->createWithdrawalRequest(
            $request->user(),
            (float) $request->input('amount'),
            $bankDetails
        );

        // Déclenchement de votre événement pour le back-office / queues
        WithdrawalRequested::dispatch($withdrawal);

        return response()->json([
            'success' => true,
            'message' => 'Votre demande de retrait a été enregistrée et est en attente de traitement par nos services.',
            'data' => [
                'reference' => $withdrawal->reference,
                'amount' => (float) $withdrawal->amount,
                'status' => $withdrawal->status,
                'created_at' => $withdrawal->created_at->toISOString()
            ]
        ], 201);

    } catch (\Illuminate\Validation\ValidationException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Erreur de validation financière.',
            'errors' => $e->errors()
        ], 422);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Une erreur technique est survenue lors de l\'initialisation de votre retrait.',
            'error' => $e->getMessage()
        ], 500);
    }
}
}
