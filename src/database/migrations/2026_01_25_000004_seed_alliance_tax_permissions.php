<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SeedAllianceTaxPermissions extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $permissions = [
            [
                'title' => 'View Alliance Tax',
                'name' => 'alliancetax.view',
                'description' => 'View alliance mining tax information',
                'division' => 'financial',
            ],
            [
                'title' => 'Manage Alliance Tax',
                'name' => 'alliancetax.manage',
                'description' => 'Manage alliance mining tax settings and rates',
                'division' => 'financial',
            ],
            [
                'title' => 'Alliance Tax Reports',
                'name' => 'alliancetax.reports',
                'description' => 'Access alliance mining tax reports',
                'division' => 'financial',
            ],
            [
                'title' => 'Alliance Tax Administrator',
                'name' => 'alliancetax.admin',
                'description' => 'Full administrative access to alliance tax system',
                'division' => 'financial',
            ],
        ];

        foreach ($permissions as $perm) {
            // Insert permission
            DB::table('permissions')->updateOrInsert(
                ['name' => $perm['name']],
                [
                    'title' => $perm['title'],
                    'description' => $perm['description'],
                    'division' => $perm['division'],
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]
            );
        }

        // Auto-assign to Superuser
        $superUserRole = DB::table('roles')->where('title', 'Superuser')->first();
        
        if ($superUserRole) {
            $permissionIds = DB::table('permissions')
                ->whereIn('name', array_column($permissions, 'name'))
                ->pluck('id');

            foreach ($permissionIds as $permId) {
                DB::table('permission_role')->updateOrInsert(
                    [
                        'permission_id' => $permId,
                        'role_id' => $superUserRole->id,
                    ]
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $names = [
            'alliancetax.view',
            'alliancetax.manage',
            'alliancetax.reports',
            'alliancetax.admin',
        ];

        $permissionIds = DB::table('permissions')->whereIn('name', $names)->pluck('id');

        // Remove role associations
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();

        // Remove permissions
        DB::table('permissions')->whereIn('name', $names)->delete();
    }
}
