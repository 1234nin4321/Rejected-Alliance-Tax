<?php

return [
    'alliance_tax' => [
        'name'       => 'Alliance Tax',
        'icon'       => 'fas fa-coins',
        'route_segment' => 'alliance-tax',
        'entries'    => [
            [
                'name'  => 'My Taxes',
                'icon'  => 'fas fa-user-invoice',
                'route' => 'alliancetax.mytax.index',
            ],
            [
                'name'  => 'Dashboard',
                'icon'  => 'fas fa-tachometer-alt',
                'route' => 'alliancetax.dashboard',
                'permission' => 'alliancetax.view',
            ],
            [
                'name'  => 'Settings',
                'icon'  => 'fas fa-sliders-h',
                'route' => 'alliancetax.admin.settings',
                'permission' => 'alliancetax.admin',
            ],
            [
                'name'  => 'Tax Rates',
                'icon'  => 'fas fa-percentage',
                'route' => 'alliancetax.admin.rates.index',
                'permission' => 'alliancetax.admin',
            ],
            [
                'name'  => 'Exemptions',
                'icon'  => 'fas fa-shield-alt',
                'route' => 'alliancetax.admin.exemptions.index',
                'permission' => 'alliancetax.admin',
            ],
            [
                'name'  => 'Reports',
                'icon'  => 'fas fa-chart-bar',
                'route' => 'alliancetax.reports.alliance',
                'permission' => 'alliancetax.view',
            ],
            [
                'name'  => 'Corp Ratting Tax',
                'icon'  => 'fas fa-university',
                'route' => 'alliancetax.corptax.index',
                'permission' => 'alliancetax.manage',
            ],
            [
                'name'  => 'Invoices',
                'icon'  => 'fas fa-file-invoice-dollar',
                'route' => 'alliancetax.invoices.index',
                'permission' => 'alliancetax.manage',
            ],
        ],
    ],
];
