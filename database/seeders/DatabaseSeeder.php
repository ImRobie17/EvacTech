<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            BarangaySeeder::class,
            VulnerableClassificationSeeder::class,
            ReliefGoodSeeder::class,
            DepartmentSeeder::class,
            SuperAdminSeeder::class,
            BarangayPersonnelDemoSeeder::class, // adds a sample shelter + testable Barangay Personnel login
        ]);
    }
}
