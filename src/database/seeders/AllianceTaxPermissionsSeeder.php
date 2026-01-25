<?php

namespace Rejected\SeatAllianceTax\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AllianceTaxPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $permissions = [
            [
                'title' => 'Alliance Tax View',
                'name' => 'alliancetax.view',
                'description' => 'View alliance mining tax information',
                'division' => 'financial',
            ],
            [
                'title' => 'Alliance Tax Manage',
                'name' => 'alliancetax.manage',
                'description' => 'Manage alliance mining tax settings',
                'division' => 'financial',
            ],
            [
                'title' => 'Alliance Tax Reports',
                'name' => 'alliancetax.reports',
                'description' => 'Access alliance tax reports',
                'division' => 'financial',
            ],
            [
                'title' => 'Alliance Tax Administrator',
                'name' => 'alliancetax.admin',
                'description' => 'Full administrative access to alliance tax plugin',
                'division' => 'financial',
            ],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                [
                    'title' => $permission['title'],
                    'description' => $permission['description'],
                    'division' => $permission['division'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $this->command->info('Alliance Tax permissions seeded successfully!');
    }
}
