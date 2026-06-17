<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawalRequest extends FormRequest
{
    /**
     * Détermine si l'utilisateur est autorisé à effectuer cette requête.
     * Le demandeur doit être connecté pour initier un retrait.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Obtient les règles de validation qui s'appliquent à la requête.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = auth()->user();

        return [
            // Le montant doit être numérique, strictement supérieur à 0, et inférieur ou égal au solde actuel
            'amount' => [
                'required',
                'numeric',
                'gt:0',
                'max:' . ($user ? $user->wallet_balance : 0)
            ],

            // Méthode de retrait (virement bancaire, Wave, Orange Money, PayPal...)
            'method' => ['required', 'string', 'in:bank_transfer,wave,orange_money,paypal'],

            // Validation des détails de snapshot (doit être un tableau contenant les infos requises)
            'bank_details' => ['required', 'array', 'min:1'],

            // Exemples de validations optionnelles imbriquées selon la méthode choisie
            'bank_details.iban' => ['required_if:method,bank_transfer', 'string', 'nullable'],
            'bank_details.bic' => ['required_if:method,bank_transfer', 'string', 'nullable'],
            'bank_details.phone_number' => ['required_if:method,wave,orange_money', 'string', 'nullable'],
            'bank_details.email' => ['required_if:method,paypal', 'email', 'nullable'],
        ];
    }

    /**
     * Personnalisation des messages d'erreur en français.
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Le montant du retrait est obligatoire.',
            'amount.numeric' => 'Le montant doit être une valeur numérique.',
            'amount.gt' => 'Le montant du retrait doit être strictement supérieur à 0.',
            'amount.max' => 'Votre solde disponible est insuffisant pour effectuer ce retrait.',
            'method.required' => 'La méthode de retrait est obligatoire.',
            'method.in' => 'La méthode de paiement sélectionnée n\'est pas supportée par notre plateforme.',
            'bank_details.required' => 'Les informations de destination des fonds sont obligatoires.',
            'bank_details.iban.required_if' => 'L\'IBAN est obligatoire pour un virement bancaire.',
            'bank_details.phone_number.required_if' => 'Le numéro de téléphone est obligatoire pour ce transfert mobile money.',
            'bank_details.email.required_if' => 'L\'adresse e-mail PayPal est obligatoire.',
        ];
    }
}
