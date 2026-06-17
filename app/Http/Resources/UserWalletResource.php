<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserWalletResource extends JsonResource
{
    /**
     * Transforme la ressource en tableau.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // 'this' fait référence ici à l'instance de l'utilisateur (User) injectée
        return [
            'user_id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,

            // Formatage monétaire standardisé pour le front-end
            'wallet' => [
                'raw_balance' => (float) $this->wallet_balance,
                'formatted_balance' => number_format($this->wallet_balance, 2, ',', ' ') . ' EUR',
                'currency' => 'EUR', // Modifiez dynamiquement si votre colonne gère plusieurs devises
            ],

            // Chargement conditionnel de l'historique pour éviter le problème de requêtes N+1
            'transactions' => $this->whenLoaded('walletTransactions', function () {
                return $this->walletTransactions->map(function ($transaction) {
                    return [
                        'id' => $transaction->id,
                        'type' => $transaction->type, // 'credit' ou 'debit'
                        'amount' => (float) $transaction->amount,
                        'formatted_amount' => ($transaction->type === 'credit' ? '+' : '-') . number_format($transaction->amount, 2, ',', ' ') . ' EUR',
                        'description' => $transaction->description,
                        'date' => $transaction->created_at->toISOString(),

                        // Informations polymorphes sur l'origine du flux financier
                        'source' => [
                            'id' => $transaction->source_id,
                            'type' => class_basename($transaction->source_type), // Renvoie 'Payment' ou 'Withdrawal'
                        ],
                    ];
                });
            }),
        ];
    }
}
