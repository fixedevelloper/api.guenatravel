<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Withdrawal;
use App\Models\Commission;
use App\Services\FinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminWithdrawalController extends Controller
{
    /**
     * Injection du service financier.
     */
    public function __construct(
        protected FinanceService $financeService
    ) {}

/**
 * [ADMIN] Lister l'intégralité des demandes de retraits de la plateforme.
 * * Permet de filtrer par statut via l'URL (Ex: /api/admin/withdrawals?status=pending)
 */
public function index(Request $request): JsonResponse
{
    $query = Withdrawal::with('user'); // Eager loading pour afficher le nom de l'hôte lié

    // Filtrage optionnel par statut
    if ($request->has('status')) {
        $query->where('status', $request->input('status'));
    }

    $withdrawals = $query->latest()->paginate(20);

    return response()->json([
        'success' => true,
        'data' => $withdrawals
    ]);
}

/**
 * [ADMIN] Traiter (Approuver / Rejeter) une demande de retrait en attente.
 * * Déclenche les verrous SQL du FinanceService pour sécuriser le mouvement de fonds.
 */
public function process(Request $request, Withdrawal $withdrawal): JsonResponse
{
    // 1. Validation de la décision de l'administrateur
    $validator = Validator::make($request->all(), [
        'status' => ['required', 'string', 'in:completed,rejected,failed'],
        'gateway_transaction_id' => ['required_if:status,completed', 'nullable', 'string', 'max:255'],
        'admin_notes' => ['nullable', 'string', 'max:500'],
    ], [
        'gateway_transaction_id.required_if' => 'L\'identifiant de transaction bancaire est obligatoire en cas d\'approbation.',
        'status.in' => 'Le statut doit être completed, rejected ou failed.',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'errors' => $validator->errors()
        ], 422);
    }

    try {
        // 2. Traitement de la demande via le service financier (sécurité anti-race condition)
        $processedWithdrawal = $this->financeService->processWithdrawal(
            $withdrawal,
            $request->input('status'),
            $request->input('gateway_transaction_id'),
            $request->input('admin_notes')
        );

        return response()->json([
            'success' => true,
            'message' => 'La demande de retrait a été mise à jour avec succès.',
            'data' => $processedWithdrawal
        ]);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Impossible de traiter ce retrait.',
            'error' => $e->getMessage()
        ], 400); // Code 400 pour les erreurs de logique métier (ex: retrait déjà traité)
    }
}

/**
 * [ADMIN] Tableau de bord comptable de la plateforme (Suivi des commissions).
 * * Donne une vue d'ensemble sur le chiffre d'affaires généré par le système.
 */
public function commissionsDashboard(): JsonResponse
{
    // Somme totale des commissions perçues par la plateforme
    $totalCommissions = (float) Commission::sum('commission_amount');

    // Volume d'affaires global traité (Montant brut total des nuitées)
    $totalVolumeProcessed = (float) Commission::sum('base_amount');

    // Top 5 des établissements ayant généré le plus de commissions
    $topProperties = Commission::select('property_id')
        ->selectRaw('SUM(commission_amount) as total_generated')
        ->with('property:id,name,city')
        ->groupBy('property_id')
        ->orderByDesc('total_generated')
        ->take(5)
        ->get();

    return response()->json([
        'success' => true,
        'metrics' => [
            'total_commissions_earned' => $totalCommissions,
            'total_volume_processed' => $totalVolumeProcessed,
            'platform_net_revenue_formatted' => number_format($totalCommissions, 2, ',', ' ') . ' EUR',
        ],
        'top_performing_properties' => $topProperties
    ]);
}
}
