<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FlagSeeder extends Seeder
{
    public function run(): void
    {
        $countries = [
            ['name' => 'Netherlands', 'code' => 'nl'],
            ['name' => 'Finland', 'code' => 'fi'],
            ['name' => 'France', 'code' => 'fr'],
            ['name' => 'USA', 'code' => 'us'],
            ['name' => 'Germany', 'code' => 'de'],
            ['name' => 'Turkey', 'code' => 'tr'],
            ['name' => 'Great Britain', 'code' => 'gb'],
            ['name' => 'Poland', 'code' => 'pl'],
            ['name' => 'Kazakhstan', 'code' => 'kz'],
            ['name' => 'Russia', 'code' => 'ru'],
            ['name' => 'Ukraine', 'code' => 'ua'],
            ['name' => 'Sweden', 'code' => 'se'],
            ['name' => 'Switzerland', 'code' => 'ch'],
            ['name' => 'Singapore', 'code' => 'sg'],
            ['name' => 'Japan', 'code' => 'jp'],
            ['name' => 'UAE', 'code' => 'ae'],
            ['name' => 'Latvia', 'code' => 'lv'],
            ['name' => 'Lithuania', 'code' => 'lt'],
            ['name' => 'Estonia', 'code' => 'ee'],
            ['name' => 'Moldova', 'code' => 'md'],
        ];

        foreach ($countries as $country) {
            \App\Models\Flag::updateOrCreate(['code' => $country['code']], $country);
        }
    }
}
