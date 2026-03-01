<?php
include 'vendor/autoload.php';
$app = include 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$groups = DB::table('invGroups')
    ->where('groupName', 'LIKE', '%mercoxit%')
    ->get();

foreach ($groups as $group) {
    echo "Group ID: {$group->groupID}, Name: {$group->groupName}, Category ID: {$group->categoryID}\n";
}
