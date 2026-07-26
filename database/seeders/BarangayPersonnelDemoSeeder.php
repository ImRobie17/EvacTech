<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\ReliefGood;
use App\Models\ReliefInventory;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data for the Barangay Personnel role.
 *
 * PHASE 1 ITEM 1: a barangay now has MANY shelters and staff are assigned to
 * specific shelters via the evacuation_center_user pivot. This seeder therefore
 * creates THREE shelters and three logins that exercise the new cases:
 *
 *   - staff.multi   -> 3 shelters (switcher visible, can move between them)
 *   - staff.single  -> 1 shelter  (switcher collapses to a static label)
 *   - staff.roving  -> 2 shelters in DIFFERENT barangays (cross-barangay surge)
 *
 * CHANGE THE PASSWORDS AFTER FIRST LOGIN.
 */
class BarangayPersonnelDemoSeeder extends Seeder
{
    public function run(): void
    {
        $mamatid = Barangay::where('name', 'Mamatid')->first();
        if (! $mamatid) {
            return; // BarangaySeeder wasn't run yet
        }
        // Second barangay for the cross-barangay case; falls back to Mamatid.
        $other = Barangay::where('name', '!=', 'Mamatid')->orderBy('name')->first() ?? $mamatid;

        $role = Role::where('name', Role::BARANGAY_PERSONNEL)->firstOrFail();

        // ---- Shelters: two in Mamatid, one elsewhere ----
        $court = $this->shelter('Mamatid Covered Court', $mamatid, 500, [
            'has_water_supply' => true,
            'has_medical_desk' => true,
            'has_power' => true,
            'has_communal_kitchen' => true,
        ]);

        $school = $this->shelter('Mamatid Elementary School', $mamatid, 320, [
            'has_water_supply' => true,
            'has_medical_desk' => false,
            'has_power' => true,
            'has_communal_kitchen' => false,
        ]);

        $barangayHall = $this->shelter($other->name . ' Barangay Hall', $other, 180, [
            'has_water_supply' => true,
            'has_medical_desk' => false,
            'has_power' => true,
            'has_communal_kitchen' => false,
        ]);

        // ---- Staff logins ----
        $multi = $this->staff($role, 'barangay.mamatid@evactech.cabuyao.gov.ph', 'Mamatid Shelter Staff', $mamatid);
        $single = $this->staff($role, 'staff.single@evactech.cabuyao.gov.ph', 'Single Shelter Staff', $mamatid);
        $roving = $this->staff($role, 'staff.roving@evactech.cabuyao.gov.ph', 'Roving Shelter Staff', $mamatid);

        // ---- Assignments (the pivot is the ONLY thing granting access) ----
        $this->assign($multi, [$court, $school, $barangayHall]);
        $this->assign($single, [$court]);
        $this->assign($roving, [$court, $barangayHall]);

        // Starter inventory so Relief Distribution has data at every shelter.
        $starter = ['Rice' => 200, 'Canned Goods' => 150, 'Bottled Water' => 300, 'Hygiene Kit' => 80, 'Blanket' => 60];
        foreach ([$court, $school, $barangayHall] as $center) {
            foreach ($starter as $name => $qty) {
                $good = ReliefGood::where('name', $name)->first();
                if (! $good) {
                    continue;
                }
                ReliefInventory::firstOrCreate(
                    ['evacuation_center_id' => $center->id, 'relief_good_id' => $good->id],
                    ['quantity_on_hand' => $qty, 'reorder_level' => (int) ($qty * 0.2), 'last_updated_at' => now()]
                );
            }
        }
    }

    private function shelter(string $name, Barangay $barangay, int $capacity, array $facilities): EvacuationCenter
    {
        return EvacuationCenter::firstOrCreate(
            ['name' => $name, 'barangay_id' => $barangay->id],
            array_merge([
                'address' => "Brgy. {$barangay->name}, City of Cabuyao, Laguna",
                'capacity' => $capacity,
                'current_occupancy' => 0,
                'status' => 'active',
            ], $facilities)
        );
    }

    private function staff(Role $role, string $email, string $name, Barangay $nominal): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            [
                'role_id' => $role->id,
                // Nominal only. Access comes from the pivot, never from this.
                'barangay_id' => $nominal->id,
                'name' => $name,
                'password' => Hash::make('ChangeMe!12345'),
                'status' => 'active',
            ]
        );
    }

    /** @param  EvacuationCenter[]  $centers */
    private function assign(User $user, array $centers): void
    {
        $user->assignedCenters()->syncWithoutDetaching(
            collect($centers)->mapWithKeys(fn ($c) => [$c->id => [
                'assigned_by' => null,
                'assigned_at' => now(),
            ]])->all()
        );
    }
}
