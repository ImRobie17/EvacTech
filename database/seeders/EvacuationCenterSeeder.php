<?php

namespace Database\Seeders;

use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\ReliefGood;
use App\Models\ReliefInventory;
use Illuminate\Database\Seeder;

/**
 * The real evacuation centres of the City of Cabuyao, as supplied by the client.
 *
 * BARANGAY NAMES ARE NORMALISED HERE, NOT IN THE SOURCE LIST. The client's list
 * writes the three Poblacion barangays four different ways -- "Pob. Uno",
 * "Pob. Dos", "Pob. Tres", "Brgy. Dos", "Brgy. Tres" -- while BarangaySeeder
 * holds the official names "Poblacion Uno", "Poblacion Dos", "Poblacion Tres".
 * The map below is the single place that reconciles them. Adding a centre with a
 * new spelling means adding it to the map, not inventing a barangay row.
 *
 * CAPACITIES ARE ESTIMATES AND ARE MARKED AS SUCH. Nobody supplied real figures.
 * They are scaled by facility type -- national high schools hold more than a
 * child development centre -- so occupancy bars and the capacity thresholds
 * (<70 green, 70-89 amber, 90-100 red, >100 black) all have something sensible
 * to colour. Replace them with real numbers through the admin UI before this is
 * used for anything but a demo.
 *
 * COORDINATES ARE DELIBERATELY LEFT NULL. These are real, named institutions; a
 * plausible-looking guess would put a school in the wrong place on a public map
 * that anyone can check. Enter coordinates through Add/Edit Shelter for the
 * handful of centres you actually demo. Shelters without coordinates simply do
 * not appear as map pins, which is the honest behaviour.
 */
class EvacuationCenterSeeder extends Seeder
{
    /** Client spelling => official BarangaySeeder name. */
    private const BARANGAY_ALIASES = [
        'Pob. Uno' => 'Poblacion Uno',
        'Pob. Dos' => 'Poblacion Dos',
        'Pob. Tres' => 'Poblacion Tres',
        'Brgy. Dos' => 'Poblacion Dos',
        'Brgy. Tres' => 'Poblacion Tres',
    ];

    /**
     * [name, barangay as written by the client, estimated capacity].
     *
     * NOTE ON A POSSIBLE DUPLICATE: the client's list contains both "Baclaran
     * Brgy. Hall" and "Baclaran Barangay Hall". They may be one building written
     * twice. Both are seeded because the list also distinguishes "Butong New
     * Barangay Hall", so an old/new pair is plausible and guessing wrong would
     * silently delete a real shelter. Merge them in the admin UI if they are the
     * same place.
     */
    private const CENTERS = [
        ['PAGCOR Evacuation Center', 'Banay-Banay', 600],
        ['Baclaran Elementary School', 'Baclaran', 400],
        ['Banay-Banay Elementary School', 'Banay-Banay', 400],
        ['Banlic Elementary School', 'Banlic', 380],
        ['Bigaa Elementary School', 'Bigaa', 400],
        ['Bigaa National High School', 'Bigaa', 550],
        ['Butong Elementary School', 'Butong', 360],
        ['Butong Covered Court', 'Butong', 250],
        ['Cabuyao Athletes Basic School', 'Banay-Banay', 450],
        ['Cabuyao Central School', 'Brgy. Dos', 500],
        ['Cabuyao Integrated National High School', 'Brgy. Tres', 600],
        ['Casile Elementary School', 'Casile', 300],
        ['Casile National High School', 'Casile', 420],
        ['Diezmo Elementary School', 'Diezmo', 340],
        ['Guinting Elementary School', 'Casile', 280],
        ['Gulod Elementary School', 'Gulod', 400],
        ['Gulod National High School', 'Gulod', 560],
        ['Gulod National Highschool - Mamatid Extension', 'Mamatid', 380],
        ['Mamatid Elementary School', 'Mamatid', 420],
        ['Marinig National High School', 'Marinig', 560],
        ['Marinig South Elementary School', 'Marinig', 380],
        ['Niugan Elementary School', 'Niugan', 340],
        ['North Marinig Elementary School', 'Marinig', 360],
        ['Pamantasan ng Cabuyao', 'Banay-Banay', 800],
        ['Pittland Elementary School', 'Pittland', 320],
        ['Pulo Elementary School', 'Pulo', 380],
        ['Pulo National High School', 'Pulo', 540],
        ['Pulo National High School - Diezmo Annex', 'Diezmo', 320],
        ['Sala Elementary School', 'Sala', 360],
        ['San Isidro Elementary School', 'San Isidro', 340],
        ['Southville Elementary School', 'Marinig', 400],
        ['Southville National High School', 'Niugan', 520],
        ['Baclaran Brgy. Hall', 'Baclaran', 150],
        ['Bigaa Covered Court', 'Bigaa', 260],
        ['Cabuyao Gym', 'Pob. Uno', 700],
        ['Gulod Multi-purpose Covered Court', 'Gulod', 280],
        ['Mamatid Covered Court', 'Mamatid', 300],
        ['Sala Brgy. Hall', 'Sala', 150],
        ['San Isidro Multi-Purpose Hall', 'San Isidro', 200],
        ['Pob. Tres Brgy. Hall', 'Pob. Tres', 150],
        ['Pob. Dos Brgy. Hall', 'Pob. Dos', 150],
        ['Pob. Uno Brgy. Hall', 'Pob. Uno', 150],
        ['Pob. Uno Child Development Center', 'Pob. Uno', 90],
        ['Pob. Tres Covered Court', 'Pob. Tres', 260],
        ['Banlic Child Development Center', 'Banlic', 90],
        ['Banlic SPTA Office', 'Banlic', 80],
        ['Butong New Barangay Hall', 'Butong', 170],
        ['Baclaran Barangay Hall', 'Baclaran', 160],
        ['Southville Marinig - NHA Covered Court', 'Marinig', 300],
    ];

    public function run(): void
    {
        $barangays = Barangay::pluck('id', 'name');

        if ($barangays->isEmpty()) {
            return; // BarangaySeeder has not run
        }

        foreach (self::CENTERS as [$name, $rawBarangay, $capacity]) {
            $officialName = self::BARANGAY_ALIASES[$rawBarangay] ?? $rawBarangay;
            $barangayId = $barangays[$officialName] ?? null;

            if (! $barangayId) {
                // Loud rather than silent: a typo here would otherwise create a
                // shelter attached to nothing, or skip it with no trace.
                $this->command?->warn("Skipped '{$name}': no barangay named '{$officialName}'.");
                continue;
            }

            EvacuationCenter::firstOrCreate(
                ['name' => $name, 'barangay_id' => $barangayId],
                [
                    'address' => "Brgy. {$officialName}, City of Cabuyao, Laguna",
                    'capacity' => $capacity,
                    // Occupancy is DERIVED. recalcOccupancy() sums members_present
                    // over checked-in households, so seeding a number here would
                    // be overwritten the first time anything recalculates. Zero
                    // is the only honest starting value.
                    'current_occupancy' => 0,
                    'status' => 'active',
                ]
            );
        }

        $this->stockRelief();
    }

    /**
     * Starter stock at every shelter, so Relief Distribution is never an empty
     * screen wherever the demo happens to land.
     *
     * Quantities vary by shelter id so the stock matrix is not a wall of
     * identical numbers, and a few shelters sit below their reorder level -- the
     * low-stock state needs to be visible somewhere.
     */
    private function stockRelief(): void
    {
        $goods = ReliefGood::pluck('id', 'name');
        $base = ['Rice' => 200, 'Canned Goods' => 150, 'Bottled Water' => 300, 'Hygiene Kit' => 80, 'Blanket' => 60];

        foreach (EvacuationCenter::all() as $center) {
            foreach ($base as $name => $qty) {
                $goodId = $goods[$name] ?? null;
                if (! $goodId) {
                    continue;
                }

                // Deterministic spread: same seed run twice gives the same data.
                $scaled = (int) round($qty * (0.4 + (($center->id % 7) * 0.18)));
                $reorder = (int) round($qty * 0.25);

                ReliefInventory::firstOrCreate(
                    ['evacuation_center_id' => $center->id, 'relief_good_id' => $goodId],
                    [
                        'quantity_on_hand' => $scaled,
                        'reorder_level' => $reorder,
                        'last_updated_at' => now(),
                    ]
                );
            }
        }
    }
}
