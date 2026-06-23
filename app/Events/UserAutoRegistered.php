<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserAutoRegistered
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $user;
    public $temporaryPassword;

    /**
     * Crée une nouvelle instance d'événement.
     *
     * @param User $user
     * @param string $temporaryPassword
     */
    public function __construct(User $user, string $temporaryPassword)
    {
        $this->user = $user;
        $this->temporaryPassword = $temporaryPassword;
    }
}
