<?php

namespace Database\Seeders;

use App\Models\ReliefGood;
use Illuminate\Database\Seeder;

/**
 * DROP B1 -- the client's sixteen relief types replace the original eight.
 *
 * They are not a taxonomy layered above the old catalogue; they ARE the
 * catalogue.
 *
 * ON UNITS. Every item carries one, and the unit is part of what identifies
 * the stock line. That is the whole point: "Rice 50kg" and
 * "Rice 5kg" are two rows here, so they are two rows in
 * relief_inventories, so the Current Inventory table can say one sack and zero
 * bags rather than pooling them into a "51" that means nothing. If one 50kg
 * sack is all that is left, the screen shows that it cannot serve two families
 * expecting 5kg bags.
 *
 * Rice is seeded twice for exactly that reason -- it is the worked example of
 * the pattern, and the one the client raised. Delete the second line if you
 * want the list to be literally sixteen rows.
 *
 * Camp managers add further variants themselves from the Receive modal
 * ("item not on the list"), which is what covers the donations nobody can
 * predict: the lugaw that arrives in its own pot.
 *
 * ON CATEGORY. `relief_goods.category` is written here and read NOWHERE --
 * grep confirms zero display or filter sites. Values are assigned sensibly and
 * no time is spent on them.
 *
 * IDEMPOTENT via firstOrCreate keyed on name. Unlike RoleSeeder in Drop A,
 * there is no existing row here whose other columns need updating, so
 * firstOrCreate is correct rather than a silent no-op. If you are re-seeding
 * over the OLD eight goods, note that firstOrCreate cannot delete them --
 * see the README for the migrate:fresh path and the non-destructive fallback.
 */
class ReliefGoodSeeder extends Seeder
{
    public function run(): void
    {
        $goods = [
            ['name' => 'FFP', 'unit' => 'pack', 'category' => 'food'],
            ['name' => 'HEB', 'unit' => 'box', 'category' => 'food'],
            ['name' => 'RTEF', 'unit' => 'pack', 'category' => 'food'],
            ['name' => 'Rice 50kg', 'unit' => 'sack', 'category' => 'food'],
            // The variant example. Same commodity, different pack size,
            // deliberately its own stock line.
            ['name' => 'Rice 5kg', 'unit' => 'bag', 'category' => 'food'],
            ['name' => 'Shelter Kits', 'unit' => 'kit', 'category' => 'shelter'],
            ['name' => 'Hygiene Kits', 'unit' => 'kit', 'category' => 'hygiene'],
            ['name' => 'Sleeping Kits', 'unit' => 'kit', 'category' => 'shelter'],
            ['name' => 'Kitchen Kits', 'unit' => 'kit', 'category' => 'other'],
            ['name' => 'Family Kits', 'unit' => 'kit', 'category' => 'other'],
            ['name' => 'Bottled Water', 'unit' => 'bottle', 'category' => 'water'],
            ['name' => 'Water Container', 'unit' => 'piece', 'category' => 'water'],
            ['name' => 'Laminated Sacks', 'unit' => 'piece', 'category' => 'other'],
            ['name' => 'Family Tent', 'unit' => 'piece', 'category' => 'shelter'],
            ['name' => 'Modular Tent', 'unit' => 'piece', 'category' => 'shelter'],
            // The only monetary good. Quantity IS the peso amount, one unit per
            // peso, by the client's decision. is_monetary keeps it out of every
            // unit counter and out of the days-of-stock projection -- without
            // that flag a single 5,000-peso grant makes "Received" read 5,200.
            ['name' => 'Financial Assistance', 'unit' => 'PHP', 'category' => 'other', 'is_monetary' => true],
            ['name' => 'Others', 'unit' => 'piece', 'category' => 'other'],
        ];

        foreach ($goods as $item) {
            ReliefGood::firstOrCreate(['name' => $item['name']], $item);
        }
    }
}
