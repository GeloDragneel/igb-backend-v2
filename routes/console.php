<?php

use App\Support\Passwords;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('passwords:hash', function () {
    foreach (Passwords::TABLES as $table) {
        [$hashed, $skipped, $empty, $note] = Passwords::hashTable($table);
        $this->info("{$table}: {$hashed} hashed, {$skipped} already hashed, {$empty} empty  ({$note})");
    }
})->purpose('Hash plain-text passwords in the user tables');