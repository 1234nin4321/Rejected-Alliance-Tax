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
        // Newer versions often use 'name' for the slug, older ones use 'title'.
        // We dynamically detect the column to be safe.
        $slugColumn = Schema::hasColumn('permissions', 'name') ? 'name' : 'title';
        $hasDivision = Schema::hasColumn('permissions', 'division');

        $permissions = [
            'alliancetax.view' => 'View alliance mining tax information',
            'alliancetax.manage' => 'Manage alliance mining tax settings and rates',
            'alliancetax.reports' => 'Access alliance mining tax reports',
            'alliancetax.admin' => 'Full administrative access to alliance tax system',
        ];

        foreach ($permissions as $slug => $desc) {
            $data = [
                'description' => $desc,
                'updated_at' => Carbon::now(),
            ];

            // Only add creation timestamp if inserting (handled by updateOrInsert, but good practice to have in attributes)
            // Ideally updateOrInsert merges attributes. We'll simplify.
            // If it's a new record, we need created_at.
            // updateOrInsert(attributes, values). 
            
            $values = $data;
            if ($hasDivision) {
                $values['division'] = 'financial';
            }
            // Add title if we are using name as slug? 
            // If slugColumn is 'name', then 'title' is likely the human readable name.
            // If slugColumn is 'title', then 'title' is the slug.
            if ($slugColumn === 'name') {
                 // We don't have a human readable title in our array, so let's capitalize the slug parts or use description
                 // Actually the user's previous code had 'title' => 'View Alliance Tax'
                 // Let's reconstruct that map
            }
        }
        
        // Re-defining permissions with full metadata to be precise
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
                $values['division'] = 'financial';
            }
            
            // If the slug is 'name', we should populate 'title' with the Label
            if ($slugColumn === 'name') {
                $values['title'] = $perm['label'];
            }
            // If the slug is 'title', we can't put the Label in 'title' because 'title' is occupied by the slug.
            // In that case, the label usually doesn't exist or is handled by translation files.

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
        $slugColumn = Schema::hasColumn('permissions', 'name') ? 'name' : 'title';
        
        $names = [
            'alliancetax.view',
            'alliancetax.manage',
            'alliancetax.reports',
            'alliancetax.admin',
        ];

        $permissionIds = DB::table('permissions')->whereIn($slugColumn, $names)->pluck('id');

        // Remove role associations
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();

        // Remove permissions
        DB::table('permissions')->whereIn($slugColumn, $names)->delete();
    }
}
