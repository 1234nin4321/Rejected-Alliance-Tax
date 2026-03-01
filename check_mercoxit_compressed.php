<?php
include 'vendor/autoload.php';
$app = include 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

$ores = ['Mercoxit', 'Magma Mercoxit', 'Vitreous Mercoxit'];
foreach ($ores as $ore) {
    $raw = DB::table('invTypes')->where('typeName', $ore)->first();
    $compressed = DB::table('invTypes')->where('typeName', 'Compressed ' . $ore)->first();
    
    if ($raw) {
        echo "Raw: {$raw->typeName} (ID: {$raw->typeID})\n";
    } else {
        echo "Raw: {$ore} NOT FOUND\n";
    }
    
    if ($compressed) {
        echo "Compressed: {$compressed->typeName} (ID: {$compressed->typeID})\n";
    } else {
        echo "Compressed: Compressed {$ore} NOT FOUND\n";
    }
    echo "-------------------\n";
}
