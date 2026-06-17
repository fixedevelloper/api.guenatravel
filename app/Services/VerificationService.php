<?php


namespace App\Services;

use App\Models\HostProfile;

class VerificationService
{
    /**
     * Valide automatiquement le profil si les informations minimales sont présentes.
     * @param HostProfile $profile
     * @return bool
     */
    public function autoVerify(HostProfile $profile): bool
    {
        // Conditions minimales pour un profil "vérifié"
        $isValid = !empty($profile->tax_identification_number) &&
            !empty($profile->rib_iban) &&
            !empty($profile->bank_name);

        if ($isValid) {
            $profile->update([
                'verification_status' => 'verified',
                'verified_at' => now(),
            ]);
            return true;
        }

        return false;
    }
}
