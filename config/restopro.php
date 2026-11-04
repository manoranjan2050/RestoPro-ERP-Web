<?php

/*
 * Sidebar navigation. A `route` that does not exist yet renders as a disabled
 * "Soon" item, so modules light up automatically as each phase lands.
 */
return [
    'nav' => [
        ['group' => 'Overview', 'items' => [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'icon' => 'home'],
        ]],
        ['group' => 'Money In', 'items' => [
            ['label' => 'Cash Collection', 'route' => 'collections', 'icon' => 'cash'],
            ['label' => 'Revenue Log', 'route' => 'revenue', 'icon' => 'trending'],
        ]],
        ['group' => 'Money Out', 'items' => [
            ['label' => 'Expenses', 'route' => 'expenses', 'icon' => 'receipt'],
            ['label' => 'Vendors', 'route' => 'vendors', 'icon' => 'truck'],
        ]],
        ['group' => 'People', 'items' => [
            ['label' => 'Staff', 'route' => 'staff', 'icon' => 'users'],
            ['label' => 'Attendance', 'route' => 'attendance', 'icon' => 'check'],
        ]],
        ['group' => 'Insights', 'items' => [
            ['label' => 'Master Ledger', 'route' => 'ledger', 'icon' => 'book'],
            ['label' => 'Reports', 'route' => 'reports', 'icon' => 'doc'],
            ['label' => 'Annual Summary', 'route' => 'analytics', 'icon' => 'chart'],
        ]],
        ['group' => 'System', 'items' => [
            ['label' => 'Backup & Sync', 'route' => 'backup', 'icon' => 'cloud', 'can' => 'manage-backups'],
            ['label' => 'Settings', 'route' => 'settings', 'icon' => 'cog', 'can' => 'manage-settings'],
        ]],
    ],
];
