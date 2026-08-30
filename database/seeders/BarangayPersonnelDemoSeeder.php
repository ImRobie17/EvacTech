<?php

namespace Database\Seeders;

use App\Models\EvacuationCenter;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Barangay Personnel demo logins, one per shelter.
 *
 * REWRITTEN. The previous version created three shelters of its own and gave one
 * account three of them, to demo a shelter switcher. **That switcher was removed
 * in Phase 6**: a staff account belongs to exactly ONE shelter, and moving
 * somebody is a reassignment performed by City Admin. The old seeder was
 * planting data the application no longer permits, and an account holding three
 * shelters would resolve to whichever one ResolvesCenter happened to pick first.
 *
 * Shelters now come from EvacuationCenterSeeder -- the real 49 -- so this file
 * creates no shelters at all. It attaches a login to each of the first twenty,
 * which are the ones DemoEvacueeSeeder populates, so every demo account opens
 * onto a shelter that actually has evacuees in it.
 *
 * The pivot is the ONLY thing granting access. barangay_id on the user is
 * nominal and must never be used for authorisation.
 *
 * CHANGE THESE PASSWORDS BEFORE ANY REAL DEPLOYMENT.
 */
class BarangayPersonnelDemoSeeder extends Seeder
{
    private const PASSWORD = 'ChangeMe!12345';

    public function run(): void
    {
        $role = Role::where('name', Role::BARANGAY_PERSONNEL)->first();
        if (! $role) {
            return; // RoleSeeder has not run
        }

        // The same twenty shelters DemoEvacueeSeeder populates, so every demo
        // login opens onto a roster that already has households in it.
        $centers = EvacuationCenter::orderBy('id')->take(20)->get();

        foreach ($centers as $center) {
            $slug = $this->slug($center->name);

            $user = User::firstOrCreate(
                ['email' => "brgy.{$slug}@evactech.cabuyao.gov.ph"],
                [
                    'role_id' => $role->id,
                    // Nominal only. Access comes from the pivot, never from this.
                    'barangay_id' => $center->barangay_id,
                    'name' => $center->name . ' Camp Manager',
                    'password' => Hash::make(self::PASSWORD),
                    'status' => 'active',
                ]
            );

            /* DROP A. Same rename guard as CityAdminDemoSeeder. firstOrCreate
               matched on email and applied none of the array above, so an
               account created before the rename still reads "... Staff".
               Name only -- no password re-hash -- and only when the row still
               carries the value this seeder wrote, so a hand-set name survives
               a re-run. */
            if ($user->name === $center->name . ' Staff') {
                $user->update(['name' => $center->name . ' Camp Manager']);
            }

            /* sync(), not syncWithoutDetaching(). One staff account, one
               shelter -- if this seeder is re-run after somebody was reassigned
               through the UI, the account must end up with exactly one shelter,
               not two. */
            $user->assignedCenters()->sync([
                $center->id => ['assigned_by' => null, 'assigned_at' => now()],
            ]);
        }
    }

    /** "Banay-Banay Elementary School" -> "banay-banay-elementary-school" */
    private function slug(string $name): string
    {
        $slug = strtolower($name);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }
}
