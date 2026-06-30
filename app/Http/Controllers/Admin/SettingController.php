<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SettingController extends Controller
{
    /**
     * Récupérer les paramètres du système.
     */
    public function index()
    {
        // Utilisation du Cache pour optimiser les performances de l'application
        $settings = Cache::remember('system_settings', now()->addDays(7), function () {
            return [
                'site_name'               => Setting::get('site_name', "Guen's Travel"),
                'contact_email'           => Setting::get('contact_email', 'contact@guenstravel.com'),
                'default_commission_rate' => Setting::get('default_commission_rate', '10.00'),
                'service_fee_flights'     => Setting::get('service_fee_flights', '15.00'),
                'currency_default'        => Setting::get('currency_default', 'EUR'),
                'maintenance_mode'        => Setting::get('maintenance_mode', false),

                // Intégration optionnelle des infos agence / store issues de votre helper getStoreInfos()
                'store_infos'             => Setting::getStoreInfos(),
            ];
        });

        return response()->json($settings);
    }

    /**
     * Mettre à jour les paramètres du système.
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'site_name'               => 'required|string|max:255',
            'contact_email'           => 'required|email',
            'default_commission_rate' => 'required|numeric|min:0|max:100',
            'service_fee_flights'     => 'required|numeric|min:0',
            'currency_default'        => 'required|string|max:3',
            'maintenance_mode'        => 'required|boolean',
        ]);

        // Persistance dynamique en base de données avec updateOrCreate
        foreach ($validated as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => $value] // Le modèle se charge du cast en JSON automatiquement
            );
        }

        // Nettoyage immédiat du cache pour appliquer les changements instantanément
        Cache::forget('system_settings');

        return response()->json([
            'message'  => 'Paramètres système mis à jour avec succès.',
            'settings' => $validated
        ]);
    }
}
