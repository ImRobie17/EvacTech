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
            // PHASE 4 item 12: DepartmentSeeder removed. `departments` was the
            // city office directory behind the web portal's Contact Us form.
            // Its only consumer was the department_id foreign key on
            // `inquiries`, and the whole portal cluster is gone -- the portal
            // is a separate system now.
            SuperAdminSeeder::class,
            BarangayPersonnelDemoSeeder::class, 
            CityAdminDemoSeeder::class, 
            PublicSiteSeeder::class,
        ]);
    }
}
