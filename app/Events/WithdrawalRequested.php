<?php

namespace App\Events;

use App\Models\Withdrawal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WithdrawalRequested
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Crée une nouvelle instance d'événement.
     * * Grâce à la promotion de propriété de PHP 8+, la variable $withdrawal
     * est déclarée et injectée automatiquement en une seule ligne.
     */
    public function __construct(
        public Withdrawal $withdrawal
    ) {}
}
