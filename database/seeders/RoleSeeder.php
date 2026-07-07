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
            ['name' => Role::CITY_ADMIN, 'display_name' => 'City Dept / CDRRMO'],
            ['name' => Role::BARANGAY_PERSONNEL, 'display_name' => 'Barangay Personnel'],
        ];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role['name']], $role);
        }
    }
}
