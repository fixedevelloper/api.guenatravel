<?php

namespace App\Helpers;

use Illuminate\Http\Request;
use Stevebauman\Location\Facades\Location;

class AppHelper
{
    /**
     * Détecte les infos de localisation, d'IP et de langue du client.
     *
     * @param Request|null $request
     * @return array
     */
    public static function getClientContext(?Request $request = null): array
    {
        // Si aucune requête n'est passée en paramètre, on récupère la requête globale en cours
        $request = $request ?? request();

        // 1. Détection de la langue
        $languages = $request->getLanguages();
        $locale = !empty($languages) ? substr($languages[0], 0, 2) : 'fr';

        // 2. Détection du pays (Priorité Cloudflare header, sinon GeoIP, sinon fallback)
        $countryCode = $request->header('CF-IPCountry');

        if (!$countryCode) {
            $ip = $request->ip();

            // Skip localhost pour éviter les erreurs GeoIP en développement
            if ($ip !== '127.0.0.1' && $ip !== '::1') {
                $position = Location::get($ip);
                $countryCode = $position ? $position->countryCode : null;
            }
        }

        return [
            'lang'         => strtolower($locale),                  // ex: "fr"
            'country_code' => strtoupper($countryCode ?? 'FR'),    // ex: "CM"
            'ip'           => $request->ip() ?? '0.0.0.0',
        ];
    }
}
