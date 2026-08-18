<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 1. Force Google to find your credentials
        $path = base_path('storage/firebase-credentials.json');
        putenv("GOOGLE_APPLICATION_CREDENTIALS={$path}");
        $_ENV['GOOGLE_APPLICATION_CREDENTIALS'] = $path;

        // 2. FORCE FIRESTORE TO USE REST INSTEAD OF GRPC
        // This stops the 30-second freezing bug completely!
        putenv('FIRESTORE_TRANSPORT=rest');
        $_ENV['FIRESTORE_TRANSPORT'] = 'rest';
    }
}