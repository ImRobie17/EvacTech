<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    // Creates the first Super Admin account so there's a way to log in and
    // create everyone else through the User Management screen.
    // CHANGE THIS PASSWORD IMMEDIATELY AFTER FIRST LOGIN.
    public function run(): void
    {
        $superAdminRole = Role::where('name', Role::SUPER_ADMIN)->firstOrFail();

        User::firstOrCreate(
            ['email' => 'superadmin@evactech.cabuyao.gov.ph'],
            [
                'role_id' => $superAdminRole->id,
                'barangay_id' => null,
                'name' => 'EvacTech Super Admin',
                'contact_number' => null,
                'password' => Hash::make('ChangeMe!12345'),
                'status' => 'active',
            ]
        );
    }
}
