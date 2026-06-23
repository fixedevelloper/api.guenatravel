<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role', 'phone_number', 'wallet_balance', 'wallet_escrow'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable,HasApiTokens;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'wallet_balance' => 'decimal:2',
            'wallet_escrow' => 'decimal:2',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations Eloquent
    |--------------------------------------------------------------------------
    */

    /**
     * Un utilisateur (Hôte) possède plusieurs établissements.
     */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class, 'host_id');
    }

    /**
     * Un utilisateur (Client) peut effectuer plusieurs réservations.
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'guest_id');
    }
    public function wishlist()
    {
        return $this->belongsToMany(Property::class, 'wishlists', 'user_id', 'property_id')
            ->withTimestamps();
    }
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
    /**
     * Un utilisateur (Hôte) possède un historique de transactions de portefeuille.
     */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * Un utilisateur (Hôte) peut faire plusieurs demandes de retraits.
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(Withdrawal::class);
    }
    /**
     * Récupérer toutes les réservations de vols de l'utilisateur
     */
    public function flightBookings(): HasMany
    {
        return $this->hasMany(FlightBooking::class)->orderBy('created_at', 'desc');
    }
    /*
    |--------------------------------------------------------------------------
    | Helpers de Rôles (Pratique pour les Middlewares / Politiques)
    |--------------------------------------------------------------------------
    */

    /**
     * Vérifie si l'utilisateur est un administrateur.
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Vérifie si l'utilisateur est un hôte/partenaire hôtelier.
     */
    public function isHost(): bool
    {
        return $this->role === 'host';
    }

    /**
     * Vérifie si l'utilisateur est un client standard.
     */
    public function isClient(): bool
    {
        return $this->role === 'client';
    }
}
