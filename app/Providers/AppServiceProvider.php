<?php

namespace App\Providers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $tmp = storage_path('app/tmp');
        File::ensureDirectoryExists($tmp);

        // Helps PHP create upload temp files when system TEMP is broken/locked.
        putenv('TMP='.$tmp);
        putenv('TEMP='.$tmp);
        $_ENV['TMP'] = $tmp;
        $_ENV['TEMP'] = $tmp;
        $_SERVER['TMP'] = $tmp;
        $_SERVER['TEMP'] = $tmp;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
