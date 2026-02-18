<?php

namespace Rejected\SeatAllianceTax;

use Seat\Services\AbstractSeatPlugin;

class SeatAllianceTaxServiceProvider extends AbstractSeatPlugin
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        // Load migrations
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations/');

        // Load routes
        $this->loadRoutesFrom(__DIR__ . '/Http/routes.php');

        // Load views
        $this->loadViewsFrom(__DIR__ . '/resources/views', 'alliancetax');

        // Load translations
        $this->loadTranslationsFrom(__DIR__ . '/resources/lang', 'alliancetax');

        // Publish assets if needed
        $this->publishes([
            __DIR__ . '/resources/assets' => public_path('web/img/alliancetax'),
        ], 'public');

        // Register permissions
        $this->registerPermissions(__DIR__ . '/Config/Permissions/alliancetax.permissions.php', 'alliancetax');

        // Register Gates for permissions
        $this->registerGates();

        // Register sidebar menu
        $this->addSidebarMenuItem();

        // Register automatic task scheduling
        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);
            
            // Reconcile payments every 10 minutes
            $schedule->command('alliancetax:reconcile')->everyTenMinutes();

            // Refresh Jita prices every hour
            $schedule->command('alliancetax:refresh-prices')->hourly();

            // Sync recent mining data for estimates every 15 minutes
            $schedule->command('alliancetax:sync-mining')->everyFifteenMinutes();

            // Calculate taxes weekly on Mondays at 01:00
            // This will trigger automated invoice generation if enabled in settings
            $schedule->command('alliancetax:calculate')->weeklyOn(1, '01:00');
        });
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        // Merge configuration
        $this->mergeConfigFrom(
            __DIR__ . '/Config/alliancetax.config.php',
            'alliancetax'
        );
        
        $this->mergeConfigFrom(
            __DIR__ . '/Config/alliancetax.scopes.php',
            'alliancetax.scopes'
        );

        // Register commands
        $this->commands([
            \Rejected\SeatAllianceTax\Console\Commands\SyncMiningData::class,
            \Rejected\SeatAllianceTax\Commands\CalculateAllianceTax::class,
            \Rejected\SeatAllianceTax\Commands\ReconcilePayments::class,
            \Rejected\SeatAllianceTax\Commands\RefreshJitaPrices::class,
            \Rejected\SeatAllianceTax\Console\Commands\DiagnosePricing::class,
            \Rejected\SeatAllianceTax\Console\Commands\RecalculateMiningValues::class,
            \Rejected\SeatAllianceTax\Console\Commands\DiagnoseCorpTax::class,
        ]);
    }

    /**
     * Return the plugin public name as it should be displayed into settings.
     *
     * @return string
     */
    public function getName(): string
    {
        return 'Alliance Mining Tax';
    }

    /**
     * Return the plugin repository address.
     *
     * @return string
     */
    public function getPackageRepositoryUrl(): string
    {
        return 'https://github.com/1234nin4321/seat-alliance-tax';
    }

    /**
     * Return the plugin technical name as published on package manager.
     *
     * @return string
     */
    public function getPackagistPackageName(): string
    {
        return 'rejected/seat-alliance-tax';
    }

    /**
     * Return the plugin vendor tag as published on package manager.
     *
     * @return string
     */
    public function getPackagistVendorName(): string
    {
        return 'rejected';
    }

    /**
     * Register the sidebar menu items.
     *
     * @return void
     */
    private function addSidebarMenuItem()
    {
        $config = require __DIR__ . '/Config/package.sidebar.php';

        if (! config('package.sidebar.alliance_tax')) {
            config(['package.sidebar.alliance_tax' => $config['alliance_tax']]);
        }
    }

    /**
     * Register Gates for permission checks.
     *
     * @return void
     */
    private function registerGates()
    {
        $permissions = ['view', 'manage', 'reports', 'admin'];

        foreach ($permissions as $permission) {
            \Illuminate\Support\Facades\Gate::define("alliancetax.{$permission}", function ($user) use ($permission) {
                // Check if user has superuser privilege
                if (method_exists($user, 'hasSuperUser') && $user->hasSuperUser()) {
                    return true;
                }

                // Check if user has this specific permission via their roles
                $permissionTitle = "alliancetax.{$permission}";
                
                return \Illuminate\Support\Facades\DB::table('permission_role')
                    ->join('role_user', 'permission_role.role_id', '=', 'role_user.role_id')
                    ->join('permissions', 'permission_role.permission_id', '=', 'permissions.id')
                    ->where('role_user.user_id', $user->id)
                    ->where('permissions.title', $permissionTitle)
                    ->exists();
            });
        }
    }
}
