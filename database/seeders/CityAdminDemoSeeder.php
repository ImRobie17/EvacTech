<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class CityAdminDemoSeeder extends Seeder
{
    // Creates a City Admin login for testing the role end to end.
    // CHANGE THE PASSWORD AFTER FIRST LOGIN.
    public function run(): void
    {
        $role = Role::where('name', Role::CITY_ADMIN)->firstOrFail();

        User::firstOrCreate(
            ['email' => 'cityadmin@evactech.cabuyao.gov.ph'],
            [
                'role_id' => $role->id,
                'barangay_id' => null, // City Admin is not tied to a single barangay
                'name' => 'City Admin (CDRRMO)',
                'password' => Hash::make('ChangeMe!12345'),
                'status' => 'active',
            ]
        );
    }
}
