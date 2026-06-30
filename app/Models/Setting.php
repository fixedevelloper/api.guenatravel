<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    // Désactiver les timestamps car la table n'en a pas
    public $timestamps = false;

    protected $fillable = ['key', 'value'];

    /**
     * Cast de la valeur pour gérer les types complexes (JSON)
     */
    protected $casts = [
        'value' => 'json',
    ];

    /**
     * Helper pour récupérer une configuration facilement
     */
    public static function get(string $key, $default = null)
    {
        $setting = self::where('key', $key)->first();
        return $setting ? $setting->value : $default;
    }
    public static function getStoreInfos()
    {
        $storeKeys = ['store_name', 'store_logo', 'store_address', 'store_phone', 'store_bp'];
        $settings = self::whereIn('key', $storeKeys)->get();

        $storeData = [];
        foreach ($storeKeys as $key) {
            $setting = $settings->firstWhere('key', $key);
            $storeData[$key] = $setting ? $setting->value : null;
        }

        return $storeData;
    }
}
