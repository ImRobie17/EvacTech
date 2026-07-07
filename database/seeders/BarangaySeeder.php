<?php

namespace Database\Seeders;

use App\Models\Barangay;
use Illuminate\Database\Seeder;

class BarangaySeeder extends Seeder
{
    // The 18 official barangays of the City of Cabuyao, Laguna.
    // Coordinates and risk_level are left as defaults (null / low) -- have the
    // City Admin / Super Admin fill these in through the admin UI once real
    // hazard-mapping data is available, rather than us guessing them here.
    public function run(): void
    {
        $barangays = [
            'Baclaran', 'Banay-Banay', 'Banlic', 'Bigaa', 'Butong', 'Casile',
            'Diezmo', 'Gulod', 'Mamatid', 'Marinig', 'Niugan', 'Pittland',
            'Pulo', 'Sala', 'San Isidro', 'Poblacion Uno', 'Poblacion Dos', 'Poblacion Tres',
        ];

        foreach ($barangays as $index => $name) {
            $code = 'CBY-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            Barangay::firstOrCreate(['code' => $code], [
                'name' => $name,
                'code' => $code,
                'risk_level' => 'low',
            ]);
        }
    }
}
