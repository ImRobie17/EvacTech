<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => Role::SUPER_ADMIN, 'display_name' => 'Super Admin'],
            ['name' => Role::CITY_ADMIN, 'display_name' => 'CSWD Office'],
            ['name' => Role::BARANGAY_PERSONNEL, 'display_name' => 'Camp Manager'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['name' => $role['name']], $role);
        }
    }
}
