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

        $user = User::firstOrCreate(
            ['email' => 'cityadmin@evactech.cabuyao.gov.ph'],
            [
                'role_id' => $role->id,
                'barangay_id' => null, // City Admin is not tied to a single barangay
                'name' => 'CSWD Office Admin',
                'password' => Hash::make('ChangeMe!12345'),
                'status' => 'active',
            ]
        );

        /* DROP A. On a database that already had this account, firstOrCreate
           matched on email, found the row and applied NONE of the array above --
           so the rename would not land. Rename it in place instead.

           Deliberately name only: touching the array would re-hash the password
           and undo any change made through the UI. And guarded on the exact old
           value, so a name an admin set by hand is never overwritten by a
           re-run. */
        if ($user->name === 'City Admin (CDRRMO)') {
            $user->update(['name' => 'CSWD Office Admin']);
        }
    }
}
