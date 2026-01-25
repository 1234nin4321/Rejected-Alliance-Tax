<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // SeAT permissions table only has 'id' and 'title' columns
        // The title IS the permission name (e.g., 'alliancetax.view')
        
        $permissions = [
            'alliancetax.view',
            'alliancetax.manage',
            'alliancetax.reports',
            'alliancetax.admin',
        ];

        foreach ($permissions as $permission) {
            // Check if permission already exists
            $exists = DB::table('permissions')
                ->where('title', $permission)
                ->exists();
            
            if (!$exists) {
                DB::table('permissions')->insert([
                    'title' => $permission,
                ]);
            }
        }

        // Auto-grant to Superuser role if it exists
        $superuserRole = DB::table('roles')->where('title', 'Superuser')->first();
        
        if ($superuserRole) {
            $permissionIds = DB::table('permissions')
                ->whereIn('title', $permissions)
                ->pluck('id');

            foreach ($permissionIds as $permissionId) {
                // Check if already granted
                $exists = DB::table('permission_role')
                    ->where('permission_id', $permissionId)
                    ->where('role_id', $superuserRole->id)
                    ->exists();
                
                if (!$exists) {
                    DB::table('permission_role')->insert([
                        'permission_id' => $permissionId,
                        'role_id' => $superuserRole->id,
                        'not' => 0,
                        'filters' => null,
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
        // Remove permissions
        DB::table('permissions')->whereIn('title', [
            'alliancetax.view',
            'alliancetax.manage',
            'alliancetax.reports',
            'alliancetax.admin',
        ])->delete();
    }
};
