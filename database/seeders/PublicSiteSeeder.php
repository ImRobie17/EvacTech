<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\EmergencyHotline;
use Illuminate\Database\Seeder;

class PublicSiteSeeder extends Seeder
{
    // Placeholder numbers/emails: replace with the real Cabuyao directory
    // before deployment. Structured so Super Admin can edit them later.
    public function run(): void
    {
        $hotlines = [
            ['label' => 'CDRRMO Operations Center', 'number' => '(049) 000-0000', 'category' => 'disaster', 'description' => '24/7 disaster response and rescue', 'sort_order' => 1],
            ['label' => 'CDRRMO Mobile', 'number' => '0917-000-0000', 'category' => 'disaster', 'description' => 'Text or call during emergencies', 'sort_order' => 2],
            ['label' => 'Cabuyao City Police Station', 'number' => '(049) 000-0001', 'category' => 'police', 'description' => null, 'sort_order' => 1],
            ['label' => 'PNP Emergency', 'number' => '911', 'category' => 'police', 'description' => 'National emergency hotline', 'sort_order' => 2],
            ['label' => 'Cabuyao Fire Station (BFP)', 'number' => '(049) 000-0002', 'category' => 'fire', 'description' => null, 'sort_order' => 1],
            ['label' => 'City Health Office', 'number' => '(049) 000-0003', 'category' => 'medical', 'description' => 'Health emergencies and ambulance', 'sort_order' => 1],
            ['label' => 'Cabuyao City Hospital', 'number' => '(049) 000-0004', 'category' => 'medical', 'description' => null, 'sort_order' => 2],
            ['label' => 'Meralco (Power)', 'number' => '16211', 'category' => 'utilities', 'description' => 'Power outages and downed lines', 'sort_order' => 1],
            ['label' => 'Laguna Water', 'number' => '(049) 000-0005', 'category' => 'utilities', 'description' => 'Water service interruptions', 'sort_order' => 2],
        ];

        foreach ($hotlines as $h) {
            EmergencyHotline::firstOrCreate(['label' => $h['label']], $h);
        }

        // Placeholder per-barangay office contacts -- replace with the real
        // office lines. Skips any barangay that already has one set.
        Barangay::whereNull('office_contact_number')->get()->each(function (Barangay $b) {
            $b->update([
                'office_contact_number' => '(049) 000-00' . str_pad((string) $b->id, 2, '0', STR_PAD_LEFT),
                'office_contact_email' => 'brgy.' . strtolower(str_replace([' ', '-'], '', $b->name)) . '@cabuyao.gov.ph',
            ]);
        });
    }
}
