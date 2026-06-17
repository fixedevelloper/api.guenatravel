<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostProfile extends Model
{
    use HasFactory;

    /**
     * Les attributs pouvant être assignés en masse.
     */
    protected $fillable = [
        'phone',
        'country_code',
        'city',
        'address',
        'is_particular',
        'user_id',
        'bank_name',
        'account_holder_name',
        'rib_iban',
        'swift_bic',
        'tax_identification_number',
        'business_registration_number',
        'company_name',
        'vat_number',
        'verification_status',
        'verified_at',
    ];

    /**
     * Conversion des types de données.
     */
    protected function casts(): array
    {
        return [
            'is_particular' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    /**
     * Relation vers l'utilisateur propriétaire du profil.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Logique métier
    |--------------------------------------------------------------------------
    */

    /**
     * Vérifie si le profil est entièrement vérifié.
     */
    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    /**
     * Vérifie si l'hôte est une entité professionnelle.
     */
    public function isProfessional(): bool
    {
        return !$this->is_particular;
    }

    /**
     * Accesseur pour savoir si l'hôte a besoin d'un numéro de TVA (logique métier).
     */
    public function getRequiresVatAttribute(): bool
    {
        return $this->isProfessional() && !empty($this->vat_number);
    }
}
