<?php

namespace Database\Seeders;

use App\Models\VulnerableClassification;
use Illuminate\Database\Seeder;

class VulnerableClassificationSeeder extends Seeder
{
    public function run(): void
    {
        $classifications = [
            ['name' => 'Person with Disability (PWD)', 'description' => 'Individuals with physical, sensory, or cognitive disabilities'],
            ['name' => 'Senior Citizen', 'description' => '60 years old and above'],
            ['name' => 'Pregnant Woman', 'description' => 'Currently pregnant'],
            ['name' => 'Infant / Young Child', 'description' => '0-5 years old'],
            ['name' => 'Person with Chronic Illness', 'description' => 'Requires ongoing medical attention or medication'],
            ['name' => 'Solo Parent', 'description' => 'Sole caregiver of dependents'],
        ];

        foreach ($classifications as $item) {
            VulnerableClassification::firstOrCreate(['name' => $item['name']], $item);
        }
    }
}
