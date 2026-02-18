<?php

use Illuminate\Support\Facades\Route;

// Alliance Tax Routes
Route::group([
    'namespace' => 'Rejected\SeatAllianceTax\Http\Controllers',
    'middleware' => ['web', 'auth'],
    'prefix' => 'alliance-tax',
], function () {

    // Dashboard - View permission required
    Route::get('/', [
        'as' => 'alliancetax.dashboard',
        'uses' => 'DashboardController@index',
        'middleware' => 'can:alliancetax.view',
    ]);

    // Personal Tax Summary - No special permission required (just auth)
    Route::group([
        'prefix' => 'my-taxes',
    ], function () {
        Route::get('/', [
            'as' => 'alliancetax.mytax.index',
            'uses' => 'MyTaxController@index',
        ]);
        
        Route::get('/details/{id}', [
            'as' => 'alliancetax.mytax.details',
            'uses' => 'MyTaxController@taxDetails',
        ]);
        
        Route::get('/character/{character}', [
            'as' => 'alliancetax.mytax.character',
            'uses' => 'MyTaxController@character',
        ]);
    });

    // Character Mining Activity
    Route::group([
        'prefix' => 'character',
        'middleware' => 'can:alliancetax.view',
    ], function () {
        Route::get('/{character_id}', [
            'as' => 'alliancetax.character.show',
            'uses' => 'CharacterTaxController@show',
        ]);
        Route::get('/{character_id}/history', [
            'as' => 'alliancetax.character.history',
            'uses' => 'CharacterTaxController@history',
        ]);
    });

    // Corporation Mining Activity
    Route::group([
        'prefix' => 'corporation',
        'middleware' => 'can:alliancetax.view',
    ], function () {
        Route::get('/{corporation_id}', [
            'as' => 'alliancetax.corporation.show',
            'uses' => 'CorporationTaxController@show',
        ]);
        Route::get('/{corporation_id}/members', [
            'as' => 'alliancetax.corporation.members',
            'uses' => 'CorporationTaxController@members',
        ]);
    });

    // Reports
    Route::group([
        'prefix' => 'reports',
        'middleware' => 'can:alliancetax.reports',
    ], function () {
        Route::get('/alliance', [
            'as' => 'alliancetax.reports.alliance',
            'uses' => 'ReportsController@alliance',
        ]);
        Route::get('/export', [
            'as' => 'alliancetax.reports.export',
            'uses' => 'ReportsController@export',
        ]);
        Route::get('/period/{period_id}', [
            'as' => 'alliancetax.reports.period',
            'uses' => 'ReportsController@period',
        ]);
    });

    // Invoices
    Route::group([
        'prefix' => 'invoices',
        'middleware' => 'can:alliancetax.manage',
    ], function () {
        Route::get('/', [
            'as' => 'alliancetax.invoices.index',
            'uses' => 'InvoiceController@index',
        ]);
        Route::post('/generate', [
            'as' => 'alliancetax.invoices.generate',
            'uses' => 'InvoiceController@generate',
        ]);
        Route::post('/send-notifications', [
            'as' => 'alliancetax.invoices.send-notifications',
            'uses' => 'InvoiceController@sendNotifications',
        ]);
        Route::post('/{id}/mark-paid', [
            'as' => 'alliancetax.invoices.mark-paid',
            'uses' => 'InvoiceController@markPaid',
        ]);
        Route::post('/bulk-paid', [
            'as' => 'alliancetax.invoices.bulk-paid',
            'uses' => 'InvoiceController@bulkMarkPaid',
        ]);
        Route::post('/bulk-delete', [
            'as' => 'alliancetax.invoices.bulk-delete',
            'uses' => 'InvoiceController@bulkDelete',
        ]);
        Route::delete('/{id}', [
            'as' => 'alliancetax.invoices.destroy',
            'uses' => 'InvoiceController@destroy',
        ]);
        Route::match(['get', 'post'], '/reconcile', [
            'as' => 'alliancetax.invoices.reconcile',
            'uses' => 'InvoiceController@reconcile',
        ]);
    });

    // Administration Routes
    Route::group([
        'prefix' => 'admin',
        'middleware' => 'can:alliancetax.admin',
    ], function () {
        // Tax Rates Management
        Route::get('/rates', [
            'as' => 'alliancetax.admin.rates.index',
            'uses' => 'AdminController@rates',
        ]);
        Route::post('/rates', [
            'as' => 'alliancetax.admin.rates.store',
            'uses' => 'AdminController@storeRate',
        ]);
        Route::put('/rates/{id}', [
            'as' => 'alliancetax.admin.rates.update',
            'uses' => 'AdminController@updateRate',
        ]);
        Route::delete('/rates/{id}', [
            'as' => 'alliancetax.admin.rates.destroy',
            'uses' => 'AdminController@destroyRate',
        ]);

        // Exemptions Management
        Route::get('/exemptions', [
            'as' => 'alliancetax.admin.exemptions.index',
            'uses' => 'AdminController@exemptions',
        ]);
        Route::post('/exemptions', [
            'as' => 'alliancetax.admin.exemptions.store',
            'uses' => 'AdminController@storeExemption',
        ]);
        Route::delete('/exemptions/{id}', [
            'as' => 'alliancetax.admin.exemptions.destroy',
            'uses' => 'AdminController@destroyExemption',
        ]);

        // Settings
        Route::get('/settings', [
            'as' => 'alliancetax.admin.settings',
            'uses' => 'AdminController@settings',
        ]);
        Route::post('/settings', [
            'as' => 'alliancetax.admin.settings.update',
            'uses' => 'AdminController@updateSettings',
        ]);

        // Taxed Systems
        Route::post('/systems', [
            'as' => 'alliancetax.admin.systems.store',
            'uses' => 'AdminController@storeTaxedSystem',
        ]);
        Route::delete('/systems/{id}', [
            'as' => 'alliancetax.admin.systems.destroy',
            'uses' => 'AdminController@destroyTaxedSystem',
        ]);

        // Recalculate Taxes
        Route::post('/recalculate', [
            'as' => 'alliancetax.admin.recalculate',
            'uses' => 'AdminController@recalculate',
        ]);

        Route::delete('/calculations/{id}', [
            'as' => 'alliancetax.admin.calculations.destroy',
            'uses' => 'AdminController@destroyCalculation',
        ]);
        
        // Scope Authorization
        Route::get('/scope/authorize/{character}', [
            'as' => 'alliancetax.scope.authorize',
            'uses' => 'ScopeAuthController@authorize',
        ]);
    });

    // Corporate Ratting Tax
    Route::group([
        'prefix' => 'corp-tax',
        'middleware' => 'can:alliancetax.manage',
    ], function () {
        Route::get('/', [
            'as' => 'alliancetax.corptax.index',
            'uses' => 'CorpRattingTaxController@index',
        ]);
        Route::post('/generate', [
            'as' => 'alliancetax.corptax.generate',
            'uses' => 'CorpRattingTaxController@generate',
        ]);
        Route::post('/settings', [
            'as' => 'alliancetax.corptax.settings.update',
            'uses' => 'CorpRattingTaxController@updateSettings',
        ]);
        Route::post('/{id}/mark-paid', [
            'as' => 'alliancetax.corptax.mark-paid',
            'uses' => 'CorpRattingTaxController@markPaid',
        ]);
        Route::delete('/settings/{id}', [
            'as' => 'alliancetax.corptax.settings.destroy',
            'uses' => 'CorpRattingTaxController@destroySettings',
        ]);
        Route::delete('/{id}', [
            'as' => 'alliancetax.corptax.destroy',
            'uses' => 'CorpRattingTaxController@destroy',
        ]);
    });
    
    // Scope callback (no auth required)
    Route::get('/scope/callback', [
        'as' => 'alliancetax.scope.callback',
        'uses' => 'ScopeAuthController@callback',
    ]);

    // API Routes
    Route::get('/api/characters/search', [
        'as' => 'alliancetax.api.characters.search',
        'uses' => 'CharacterSearchController@search',
        'middleware' => 'can:alliancetax.admin',
    ]);
    
    Route::get('/api/corporations/search', [
        'as' => 'alliancetax.api.corporations.search',
        'uses' => 'CharacterSearchController@searchCorporations',
        'middleware' => 'can:alliancetax.admin',
    ]);

    Route::get('/api/systems/search', [
        'as' => 'alliancetax.api.systems.search',
        'uses' => 'CharacterSearchController@searchSystems',
        'middleware' => 'can:alliancetax.admin',
    ]);
});
