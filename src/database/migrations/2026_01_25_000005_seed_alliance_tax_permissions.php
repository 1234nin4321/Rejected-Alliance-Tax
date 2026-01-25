<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        // SeAT's permission schema has varied. 
        // Newer versions use 'name' for the slug, older ones use 'title'.
        // We dynamically detect the column to be safe.
        $slugColumn = Schema::hasColumn('permissions', 'name') ? 'name' : 'title';
        $hasDivision = Schema::hasColumn('permissions', 'division');

        $fullPermissions = [
             [
                'slug' => 'alliancetax.view',
                'label' => 'View Alliance Tax',
                'desc' => 'View alliance mining tax information'
             ],
             [
                'slug' => 'alliancetax.manage',
                'label' => 'Manage Alliance Tax',
                'desc' => 'Manage alliance mining tax settings and rates'
             ],
             [
                'slug' => 'alliancetax.reports',
                'label' => 'Alliance Tax Reports',
                'desc' => 'Access alliance mining tax reports'
             ],
             [
                'slug' => 'alliancetax.admin',
                'label' => 'Alliance Tax Administrator',
                'desc' => 'Full administrative access to alliance tax system'
             ],
        ];

        foreach ($fullPermissions as $perm) {
            $match = [$slugColumn => $perm['slug']];
            
            $values = [
                'description' => $perm['desc'],
                'updated_at' => Carbon::now(),
            ];

            if ($hasDivision) {
                // Ensure 'financial' division exists or default to 'general'
                // SeAT usually has 'financial', but let's be safe
                $values['division'] = 'financial';
            }
            
            // If the slug is 'name', we should populate 'title' with the Label
            if ($slugColumn === 'name') {
                $values['title'] = $perm['label'];
            }

            // Ensure created_at is set on insert
            if (!DB::table('permissions')->where($match)->exists()) {
                $values['created_at'] = Carbon::now();
                DB::table('permissions')->insert(array_merge($match, $values));
            } else {
                DB::table('permissions')->where($match)->update($values);
            }
        }

        // Auto-assign to Superuser
        $superUserRole = DB::table('roles')->where('title', 'Superuser')->first();
        
        if ($superUserRole) {
            $slugs = array_column($fullPermissions, 'slug');
            $permissionIds = DB::table('permissions')
                ->whereIn($slugColumn, $slugs)
                ->pluck('id');

            foreach ($permissionIds as $permId) {
                // Use explicit check instead of updateOrInsert
                $exists = DB::table('permission_role')
                    ->where('permission_id', $permId)
                    ->where('role_id', $superUserRole->id)
                    ->exists();

                if (!$exists) {
                    DB::table('permission_role')->insert([
                        'permission_id' => $permId,
                        'role_id' => $superUserRole->id,
                    ]);
                }
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
        $slugColumn = Schema::hasColumn('permissions', 'name') ? 'name' : 'title';
        
        $names = [
            'alliancetax.view',
            'alliancetax.manage',
            'alliancetax.reports',
            'alliancetax.admin',
        ];

        $permissionIds = DB::table('permissions')->whereIn($slugColumn, $names)->pluck('id');

        if ($permissionIds->isNotEmpty()) {
            // Remove role associations
            DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();

            // Remove permissions
            DB::table('permissions')->whereIn($slugColumn, $names)->delete();
        }
    }
}
