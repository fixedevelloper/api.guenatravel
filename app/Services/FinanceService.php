<?php

namespace App\Services;

use App\Models\User;
use App\Models\Payment;
use App\Models\Withdrawal;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FinanceService
{
    /**
     * Étape 1 : Créditer l'hôte du montant net après un paiement client réussi.
     * Déclenché généralement par le BookingService ou un webhook de paiement.
     */
    public function creditHostAfterPayment(Payment $payment): void
    {
        $booking = $payment->booking;

        // Montant calculé au préalable : Total payé - Commission plateforme
        $netAmount = $booking->host_payout_amount;

        // Identification de l'hôte via la chambre réservée
        $hostId = $booking->items->first()->room->property->host_id;

        DB::transaction(function () use ($hostId, $payment, $netAmount, $booking) {
            // Verrouiller la ligne de l'utilisateur pour modification sécurisée (Anti-Race Condition)
            $host = User::where('id', $hostId)->lockForUpdate()->firstOrFail();

            // 1. Incrémenter physiquement le solde disponible de l'hôte
            $host->increment('wallet_balance', $netAmount);

            // 2. Écrire la ligne de crédit dans le livre comptable immuable (Piste d'audit)
            WalletTransaction::create([
                'user_id' => $host->id,
                'source_id' => $payment->id,
                'source_type' => Payment::class,
                'type' => 'credit',
                'amount' => $netAmount,
                'description' => "Revenu net généré par la réservation {$booking->booking_reference}",
            ]);
        });
    }

    /**
     * Étape 2 : Initier une demande de retrait par l'hôte.
     * Déduit immédiatement l'argent de la balance et le met en attente (séquestre visuel).
     */
    public function createWithdrawalRequest(User $host, float $amount, array $bankDetails): Withdrawal
    {
        return DB::transaction(function () use ($host, $amount, $bankDetails) {
            // Re-vérifier et verrouiller le solde de l'hôte en BDD avant action
            $lockedHost = User::where('id', $host->id)->lockForUpdate()->firstOrFail();

            // Sécurité financière stricte
            if ($lockedHost->wallet_balance < $amount) {
                throw ValidationException::withMessages([
                    'amount' => 'Solde disponible insuffisant pour effectuer ce retrait.'
                ]);
            }

            // 1. Déduire immédiatement l'argent du solde disponible
            $lockedHost->decrement('wallet_balance', $amount);

            // 2. Générer la demande au statut 'pending' avec snapshot des coordonnées de destination
            $withdrawal = Withdrawal::create([
                'reference' => 'WD-' . date('Ymd') . '-' . strtoupper(Str::random(6)),
                'user_id' => $lockedHost->id,
                'amount' => $amount,
                'payment_method' => $bankDetails['method'] ?? 'bank_transfer',
                'bank_details_snapshot' => $bankDetails,
                'status' => 'pending'
            ]);

            // 3. Documenter le mouvement de débit dans l'historique
            WalletTransaction::create([
                'user_id' => $lockedHost->id,
                'source_id' => $withdrawal->id,
                'source_type' => Withdrawal::class,
                'type' => 'debit',
                'amount' => $amount,
                'description' => "Mise en réserve pour demande de retrait en attente ({$withdrawal->reference})",
            ]);

            return $withdrawal;
        });
    }

    /**
     * Étape 3 : Traitement de la demande de retrait par l'administrateur (Approbation / Rejet).
     */
    public function processWithdrawal(Withdrawal $withdrawal, string $status, ?string $gatewayTxId = null, ?string $adminNotes = null): Withdrawal
    {
        if ($withdrawal->status !== 'pending') {
            throw new \Exception("Cette demande de retrait a déjà été traitée.");
        }

        return DB::transaction(function () use ($withdrawal, $status, $gatewayTxId, $adminNotes) {
            $host = User::where('id', $withdrawal->user_id)->lockForUpdate()->firstOrFail();

            if ($status === 'completed') {
                // L'argent est envoyé avec succès sur le compte réel de l'hôte (Stripe Payout, Bank, Mobile Money)
                $withdrawal->update([
                    'status' => 'completed',
                    'gateway_transaction_id' => $gatewayTxId,
                    'admin_notes' => $adminNotes,
                    'processed_at' => now()
                ]);
            } elseif ($status === 'rejected' || $status === 'failed') {
                // Le virement a échoué ou a été refusé : On réinjecte l'argent sur le solde de l'hôte
                $host->increment('wallet_balance', $withdrawal->amount);

                $withdrawal->update([
                    'status' => $status,
                    'admin_notes' => $adminNotes,
                    'processed_at' => now()
                ]);

                // On crée une écriture comptable de compensation (Crédit d'annulation)
                WalletTransaction::create([
                    'user_id' => $host->id,
                    'source_id' => $withdrawal->id,
                    'source_type' => Withdrawal::class,
                    'type' => 'credit',
                    'amount' => $withdrawal->amount,
                    'description' => "Restitution des fonds suite au rejet ou échec du retrait ({$withdrawal->reference})",
                ]);
            }

            return $withdrawal;
        });
    }
}
