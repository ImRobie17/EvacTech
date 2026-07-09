<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\ReliefGood;
use App\Models\ReliefInventory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class BarangayPersonnelDemoSeeder extends Seeder
{
    // Creates one sample shelter + one Barangay Personnel login so the role
    // can be tested end to end. CHANGE THE PASSWORD AFTER FIRST LOGIN.
    public function run(): void
    {
        $barangay = Barangay::where('name', 'Mamatid')->first();
        if (! $barangay) {
            return; // BarangaySeeder wasn't run yet
        }

        $center = EvacuationCenter::firstOrCreate(
            ['name' => 'Mamatid Covered Court', 'barangay_id' => $barangay->id],
            [
                'address' => 'Brgy. Mamatid, City of Cabuyao, Laguna',
                'capacity' => 500,
                'current_occupancy' => 0,
                'has_water_supply' => true,
                'has_medical_desk' => true,
                'has_power' => true,
                'has_communal_kitchen' => true,
                'status' => 'active',
            ]
        );

        $role = Role::where('name', Role::BARANGAY_PERSONNEL)->firstOrFail();

        User::firstOrCreate(
            ['email' => 'barangay.mamatid@evactech.cabuyao.gov.ph'],
            [
                'role_id' => $role->id,
                'barangay_id' => $barangay->id,
                'name' => 'Barangay Mamatid Staff',
                'password' => Hash::make('ChangeMe!12345'),
                'status' => 'active',
            ]
        );

        // Seed a small starting inventory so the Relief Distribution screen has data to show.
        $starter = ['Rice' => 200, 'Canned Goods' => 150, 'Bottled Water' => 300, 'Hygiene Kit' => 80, 'Blanket' => 60];
        foreach ($starter as $name => $qty) {
            $good = ReliefGood::where('name', $name)->first();
            if ($good) {
                ReliefInventory::firstOrCreate(
                    ['evacuation_center_id' => $center->id, 'relief_good_id' => $good->id],
                    ['quantity_on_hand' => $qty, 'reorder_level' => (int) ($qty * 0.2), 'last_updated_at' => now()]
                );
            }
        }
    }
}
