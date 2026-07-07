<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            [
                'name' => 'City Disaster Risk Reduction and Management Office (CDRRMO)',
                'description' => 'Leads disaster preparedness, response, and evacuation operations for the city.',
                'services' => ['Emergency Response', 'Evacuation Management', 'Relief Distribution', 'Disaster Preparedness Training'],
            ],
            [
                'name' => 'City Health Office',
                'description' => 'Handles public health programs and medical services.',
                'services' => ['Health Certificates', 'Medical Missions', 'Vaccination Programs'],
            ],
            [
                'name' => 'Business Permits and Licensing Office',
                'description' => 'Processes business permits and licenses.',
                'services' => ['New Business Registration', 'Business Permit Renewal'],
            ],
            [
                'name' => 'City Civil Registry Office',
                'description' => 'Handles civil registration documents.',
                'services' => ['Birth Certificate', 'Marriage Certificate', 'Death Certificate'],
            ],
            [
                'name' => 'Human Resource Management Office',
                'description' => 'Manages city government job openings and employee records.',
                'services' => ['Job Openings', 'Employment Records'],
            ],
        ];

        foreach ($departments as $item) {
            Department::firstOrCreate(['name' => $item['name']], $item);
        }
    }
}
