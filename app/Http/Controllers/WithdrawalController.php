<?php

namespace App\Http\Controllers;

use App\Http\Requests\WithdrawalRequest;
use App\Models\Withdrawal;
use App\Services\FinanceService;
use App\Http\Events\WithdrawalRequested;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WithdrawalController extends Controller
{
    /**
     * Injection du service financier.
     */
    public function __construct(
        protected FinanceService $financeService
    ) {}

/**
 * [HOST] Liste de l'historique des demandes de retraits de l'hôte connecté.
 */
public function index(Request $request): JsonResponse
{
    $withdrawals = Withdrawal::where('user_id', $request->user()->id)
        ->latest()
        ->paginate(15);

    return response()->json([
        'success' => true,
        'data' => $withdrawals
    ]);
}

/**
 * [HOST] Initier une nouvelle demande de retrait (Virement bancaire, Mobile Money, etc.).
 * * Protégé par le WithdrawalRequest qui vérifie la cohérence du solde.
 */
public function store(WithdrawalRequest $request): JsonResponse
{
    try {
        // Le service déduit immédiatement le montant du solde disponible
        // et enregistre le mouvement comptable au statut 'pending'.
        $withdrawal = $this->financeService->createWithdrawalRequest(
            $request->user(),
            (float) $request->input('amount'),
            $request->input('bank_details')
        );

        // Déclenchement de l'événement pour alerter le back-office admin par queue/notification
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
