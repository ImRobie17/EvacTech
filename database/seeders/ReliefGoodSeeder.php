<?php

namespace Database\Seeders;

use App\Models\ReliefGood;
use Illuminate\Database\Seeder;

class ReliefGoodSeeder extends Seeder
{
    public function run(): void
    {
        $goods = [
            ['name' => 'Rice', 'unit' => 'kg', 'category' => 'food'],
            ['name' => 'Canned Goods', 'unit' => 'pack', 'category' => 'food'],
            ['name' => 'Bottled Water', 'unit' => 'liter', 'category' => 'water'],
            ['name' => 'Hygiene Kit', 'unit' => 'pack', 'category' => 'hygiene'],
            ['name' => 'Blanket', 'unit' => 'piece', 'category' => 'shelter'],
            ['name' => 'Sleeping Mat', 'unit' => 'piece', 'category' => 'shelter'],
            ['name' => 'First Aid Kit', 'unit' => 'box', 'category' => 'medical'],
            ['name' => 'Face Mask', 'unit' => 'box', 'category' => 'medical'],
        ];

        foreach ($goods as $item) {
            ReliefGood::firstOrCreate(['name' => $item['name']], $item);
        }
    }
}
