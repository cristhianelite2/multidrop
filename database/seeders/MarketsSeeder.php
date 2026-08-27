<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MarketsSeeder extends Seeder
{
    public function run(): void
    {
        $hasRegion = Schema::hasColumn('markets', 'region');
        $hasFlag = Schema::hasColumn('markets', 'flag');
        $hasSort = Schema::hasColumn('markets', 'sort_order');

        foreach ($this->markets() as $i => $market) {
            $payload = [
                'name' => $market['name'],
                'locale' => $market['locale'],
                'currency' => $market['currency'],
                'timezone' => $market['timezone'],
                'is_active' => true,
                'updated_at' => now(),
            ];

            if ($hasRegion) {
                $payload['region'] = $market['region'];
            }
            if ($hasFlag) {
                $payload['flag'] = $market['flag'];
            }
            if ($hasSort) {
                $payload['sort_order'] = $i;
            }

            $exists = DB::table('markets')->where('code', $market['code'])->exists();
            if ($exists) {
                DB::table('markets')->where('code', $market['code'])->update($payload);
            } else {
                $payload['code'] = $market['code'];
                $payload['created_at'] = now();
                DB::table('markets')->insert($payload);
            }
        }

        // Legacy UK → GB
        $uk = DB::table('markets')->where('code', 'UK')->first();
        $gb = DB::table('markets')->where('code', 'GB')->first();
        if ($uk && ! $gb) {
            DB::table('markets')->where('id', $uk->id)->update([
                'code' => 'GB',
                'name' => 'Reino Unido',
                'flag' => $this->flagEmoji('GB'),
                'region' => 'europe',
                'locale' => 'en_GB',
                'currency' => 'GBP',
                'timezone' => 'Europe/London',
                'is_active' => true,
                'updated_at' => now(),
            ]);
        } elseif ($uk && $gb) {
            DB::table('markets')->where('id', $uk->id)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Primer mundo + Europa + América del Norte + Australia/NZ.
     *
     * @return list<array{code:string,name:string,region:string,flag:string,locale:string,currency:string,timezone:string}>
     */
    protected function markets(): array
    {
        $flag = fn (string $iso) => $this->flagEmoji($iso);

        return [
            // América del Norte
            ['code' => 'US', 'name' => 'Estados Unidos', 'region' => 'north_america', 'flag' => $flag('US'), 'locale' => 'en_US', 'currency' => 'USD', 'timezone' => 'America/New_York'],
            ['code' => 'CA', 'name' => 'Canadá', 'region' => 'north_america', 'flag' => $flag('CA'), 'locale' => 'en_CA', 'currency' => 'CAD', 'timezone' => 'America/Toronto'],
            ['code' => 'MX', 'name' => 'México', 'region' => 'north_america', 'flag' => $flag('MX'), 'locale' => 'es_MX', 'currency' => 'MXN', 'timezone' => 'America/Mexico_City'],

            // Oceanía
            ['code' => 'AU', 'name' => 'Australia', 'region' => 'oceania', 'flag' => $flag('AU'), 'locale' => 'en_AU', 'currency' => 'AUD', 'timezone' => 'Australia/Sydney'],
            ['code' => 'NZ', 'name' => 'Nueva Zelanda', 'region' => 'oceania', 'flag' => $flag('NZ'), 'locale' => 'en_NZ', 'currency' => 'NZD', 'timezone' => 'Pacific/Auckland'],

            // Europa Occidental / Norte / Sur / Centro
            ['code' => 'GB', 'name' => 'Reino Unido', 'region' => 'europe', 'flag' => $flag('GB'), 'locale' => 'en_GB', 'currency' => 'GBP', 'timezone' => 'Europe/London'],
            ['code' => 'IE', 'name' => 'Irlanda', 'region' => 'europe', 'flag' => $flag('IE'), 'locale' => 'en_IE', 'currency' => 'EUR', 'timezone' => 'Europe/Dublin'],
            ['code' => 'FR', 'name' => 'Francia', 'region' => 'europe', 'flag' => $flag('FR'), 'locale' => 'fr_FR', 'currency' => 'EUR', 'timezone' => 'Europe/Paris'],
            ['code' => 'DE', 'name' => 'Alemania', 'region' => 'europe', 'flag' => $flag('DE'), 'locale' => 'de_DE', 'currency' => 'EUR', 'timezone' => 'Europe/Berlin'],
            ['code' => 'IT', 'name' => 'Italia', 'region' => 'europe', 'flag' => $flag('IT'), 'locale' => 'it_IT', 'currency' => 'EUR', 'timezone' => 'Europe/Rome'],
            ['code' => 'ES', 'name' => 'España', 'region' => 'europe', 'flag' => $flag('ES'), 'locale' => 'es_ES', 'currency' => 'EUR', 'timezone' => 'Europe/Madrid'],
            ['code' => 'PT', 'name' => 'Portugal', 'region' => 'europe', 'flag' => $flag('PT'), 'locale' => 'pt_PT', 'currency' => 'EUR', 'timezone' => 'Europe/Lisbon'],
            ['code' => 'NL', 'name' => 'Países Bajos', 'region' => 'europe', 'flag' => $flag('NL'), 'locale' => 'nl_NL', 'currency' => 'EUR', 'timezone' => 'Europe/Amsterdam'],
            ['code' => 'BE', 'name' => 'Bélgica', 'region' => 'europe', 'flag' => $flag('BE'), 'locale' => 'nl_BE', 'currency' => 'EUR', 'timezone' => 'Europe/Brussels'],
            ['code' => 'LU', 'name' => 'Luxemburgo', 'region' => 'europe', 'flag' => $flag('LU'), 'locale' => 'fr_LU', 'currency' => 'EUR', 'timezone' => 'Europe/Luxembourg'],
            ['code' => 'CH', 'name' => 'Suiza', 'region' => 'europe', 'flag' => $flag('CH'), 'locale' => 'de_CH', 'currency' => 'CHF', 'timezone' => 'Europe/Zurich'],
            ['code' => 'AT', 'name' => 'Austria', 'region' => 'europe', 'flag' => $flag('AT'), 'locale' => 'de_AT', 'currency' => 'EUR', 'timezone' => 'Europe/Vienna'],
            ['code' => 'SE', 'name' => 'Suecia', 'region' => 'europe', 'flag' => $flag('SE'), 'locale' => 'sv_SE', 'currency' => 'SEK', 'timezone' => 'Europe/Stockholm'],
            ['code' => 'NO', 'name' => 'Noruega', 'region' => 'europe', 'flag' => $flag('NO'), 'locale' => 'nb_NO', 'currency' => 'NOK', 'timezone' => 'Europe/Oslo'],
            ['code' => 'DK', 'name' => 'Dinamarca', 'region' => 'europe', 'flag' => $flag('DK'), 'locale' => 'da_DK', 'currency' => 'DKK', 'timezone' => 'Europe/Copenhagen'],
            ['code' => 'FI', 'name' => 'Finlandia', 'region' => 'europe', 'flag' => $flag('FI'), 'locale' => 'fi_FI', 'currency' => 'EUR', 'timezone' => 'Europe/Helsinki'],
            ['code' => 'IS', 'name' => 'Islandia', 'region' => 'europe', 'flag' => $flag('IS'), 'locale' => 'is_IS', 'currency' => 'ISK', 'timezone' => 'Atlantic/Reykjavik'],
            ['code' => 'PL', 'name' => 'Polonia', 'region' => 'europe', 'flag' => $flag('PL'), 'locale' => 'pl_PL', 'currency' => 'PLN', 'timezone' => 'Europe/Warsaw'],
            ['code' => 'CZ', 'name' => 'Chequia', 'region' => 'europe', 'flag' => $flag('CZ'), 'locale' => 'cs_CZ', 'currency' => 'CZK', 'timezone' => 'Europe/Prague'],
            ['code' => 'SK', 'name' => 'Eslovaquia', 'region' => 'europe', 'flag' => $flag('SK'), 'locale' => 'sk_SK', 'currency' => 'EUR', 'timezone' => 'Europe/Bratislava'],
            ['code' => 'HU', 'name' => 'Hungría', 'region' => 'europe', 'flag' => $flag('HU'), 'locale' => 'hu_HU', 'currency' => 'HUF', 'timezone' => 'Europe/Budapest'],
            ['code' => 'RO', 'name' => 'Rumania', 'region' => 'europe', 'flag' => $flag('RO'), 'locale' => 'ro_RO', 'currency' => 'RON', 'timezone' => 'Europe/Bucharest'],
            ['code' => 'BG', 'name' => 'Bulgaria', 'region' => 'europe', 'flag' => $flag('BG'), 'locale' => 'bg_BG', 'currency' => 'BGN', 'timezone' => 'Europe/Sofia'],
            ['code' => 'GR', 'name' => 'Grecia', 'region' => 'europe', 'flag' => $flag('GR'), 'locale' => 'el_GR', 'currency' => 'EUR', 'timezone' => 'Europe/Athens'],
            ['code' => 'HR', 'name' => 'Croacia', 'region' => 'europe', 'flag' => $flag('HR'), 'locale' => 'hr_HR', 'currency' => 'EUR', 'timezone' => 'Europe/Zagreb'],
            ['code' => 'SI', 'name' => 'Eslovenia', 'region' => 'europe', 'flag' => $flag('SI'), 'locale' => 'sl_SI', 'currency' => 'EUR', 'timezone' => 'Europe/Ljubljana'],
            ['code' => 'EE', 'name' => 'Estonia', 'region' => 'europe', 'flag' => $flag('EE'), 'locale' => 'et_EE', 'currency' => 'EUR', 'timezone' => 'Europe/Tallinn'],
            ['code' => 'LV', 'name' => 'Letonia', 'region' => 'europe', 'flag' => $flag('LV'), 'locale' => 'lv_LV', 'currency' => 'EUR', 'timezone' => 'Europe/Riga'],
            ['code' => 'LT', 'name' => 'Lituania', 'region' => 'europe', 'flag' => $flag('LT'), 'locale' => 'lt_LT', 'currency' => 'EUR', 'timezone' => 'Europe/Vilnius'],
            ['code' => 'MT', 'name' => 'Malta', 'region' => 'europe', 'flag' => $flag('MT'), 'locale' => 'mt_MT', 'currency' => 'EUR', 'timezone' => 'Europe/Malta'],
            ['code' => 'CY', 'name' => 'Chipre', 'region' => 'europe', 'flag' => $flag('CY'), 'locale' => 'el_CY', 'currency' => 'EUR', 'timezone' => 'Asia/Nicosia'],
            ['code' => 'LI', 'name' => 'Liechtenstein', 'region' => 'europe', 'flag' => $flag('LI'), 'locale' => 'de_LI', 'currency' => 'CHF', 'timezone' => 'Europe/Vaduz'],
            ['code' => 'MC', 'name' => 'Mónaco', 'region' => 'europe', 'flag' => $flag('MC'), 'locale' => 'fr_MC', 'currency' => 'EUR', 'timezone' => 'Europe/Monaco'],
            ['code' => 'AD', 'name' => 'Andorra', 'region' => 'europe', 'flag' => $flag('AD'), 'locale' => 'ca_AD', 'currency' => 'EUR', 'timezone' => 'Europe/Andorra'],
            ['code' => 'SM', 'name' => 'San Marino', 'region' => 'europe', 'flag' => $flag('SM'), 'locale' => 'it_SM', 'currency' => 'EUR', 'timezone' => 'Europe/San_Marino'],
            ['code' => 'VA', 'name' => 'Ciudad del Vaticano', 'region' => 'europe', 'flag' => $flag('VA'), 'locale' => 'it_VA', 'currency' => 'EUR', 'timezone' => 'Europe/Vatican'],
        ];
    }

    protected function flagEmoji(string $iso): string
    {
        $iso = strtoupper($iso);
        if (strlen($iso) !== 2) {
            return '🏳️';
        }

        $chars = str_split($iso);

        return mb_chr(0x1F1E6 + ord($chars[0]) - 65).mb_chr(0x1F1E6 + ord($chars[1]) - 65);
    }
}
