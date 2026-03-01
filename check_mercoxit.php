<?php
include 'vendor/autoload.php';
$app = include 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$mercoxitTypes = DB::table('invTypes')
    ->join('invGroups', 'invTypes.groupID', '=', 'invGroups.groupID')
    ->where('typeName', 'LIKE', '%Mercoxit%')
    ->select('invTypes.typeID', 'invTypes.typeName', 'invTypes.groupID', 'invGroups.groupName', 'invGroups.categoryID')
    ->get();

foreach ($mercoxitTypes as $type) {
    echo "ID: {$type->typeID}, Name: {$type->typeName}, Group ID: {$type->groupID}, Group Name: {$type->groupName}, Category ID: {$type->categoryID}\n";
}
