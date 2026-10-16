<?php

use App\Jobs\DownloadProductImage;
use App\Models\Product;
use App\Models\User;
use App\Services\CsvImporter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

Artisan::command('inventory:import {path=inventory.csv} {--no-images : Skip queueing photo downloads}', function (CsvImporter $importer) {
    $result = $importer->import($this->argument('path'), ! $this->option('no-images'));
    $this->info(number_format($result['imported']).' products imported.');
})->purpose('Upsert a catalog CSV by SKU and queue local photo downloads');

Artisan::command('inventory:images {--category= : Only this catalog group} {--limit= : Only queue this many products}', function () {
    $query = Product::whereNotNull('image_url')->whereNull('image_path')->orderBy('name');

    if ($category = $this->option('category')) {
        $query->where('category', $category);
    }

    if ($limit = (int) $this->option('limit')) {
        $query->limit($limit);
    }

    $queued = 0;
    $query->pluck('id')->each(function ($id) use (&$queued) {
        DownloadProductImage::dispatch($id);
        $queued++;
    });

    $this->info(number_format($queued).' missing photos queued. Run a queue worker to download them.');
})->purpose('Queue downloads for products that have no local photo yet');

Artisan::command('inventory:user {email} {--admin : Allow managing the shared catalog}', function () {
    $email = $this->argument('email');
    $name = $this->ask('Name');
    $password = $this->secret('Password (at least 12 characters)');

    if (strlen($password ?? '') < 12 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $this->error('Enter a valid email and a password of at least 12 characters.');

        return 1;
    }

    if (User::where('email', $email)->exists()) {
        $this->error('Email already exists.');

        return 1;
    }

    $user = new User(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
    $user->is_admin = (bool) $this->option('admin');
    $user->save();

    $this->info("Account created for {$email}.");
})->purpose('Create another household account');

Artisan::command('inventory:bootstrap', function (CsvImporter $importer) {
    $csv = base_path('inventory.csv');

    if (Product::exists() || ! is_file($csv)) {
        return;
    }

    $result = $importer->import($csv);
    $this->info(number_format($result['imported']).' starter products imported.');
})->purpose('Import the bundled inventory.csv into an empty catalog');
