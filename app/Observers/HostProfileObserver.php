<?php

namespace App\Observers;

use App\Models\HostProfile;
use App\Services\VerificationService;

class HostProfileObserver
{
    public function saved(HostProfile $profile): void
    {
        // Si le profil est encore en attente, on tente la vérification
        if ($profile->verification_status === 'pending') {
            app(VerificationService::class)->autoVerify($profile);
        }
    }
}
