<?php

use App\Http\Controllers\ComingSoonController;
use App\Http\Controllers\Dev\ListDemoController;
use App\Http\Controllers\SettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', fn () => Inertia::render('Dashboard'))->name('home');

Route::put('/settings/theme', [SettingsController::class, 'theme'])->name('settings.theme');
Route::put('/settings/nav-layout', [SettingsController::class, 'navLayout'])->name('settings.nav-layout');
Route::put('/settings/locale', [SettingsController::class, 'locale'])->name('settings.locale');
Route::put('/settings/display-currency', [SettingsController::class, 'displayCurrency'])->name('settings.display-currency');

require __DIR__.'/formulation.php';
require __DIR__.'/lab.php';
require __DIR__.'/compliance.php';
require __DIR__.'/calculators.php';
require __DIR__.'/settings.php';

// Sidebar destinations that are not built yet. A section leaves this list when it gets its own route.
Route::get('/{section}', ComingSoonController::class)
    ->whereIn('section', config('ui.placeholder_sections'))
    ->name('coming-soon');

// Test-only playground for the shared list building blocks (S-02). Never registered in production.
if (app()->environment('local', 'testing')) {
    Route::prefix('__dev/list-demo')->name('dev.list-demo.')->group(function () {
        Route::get('/', [ListDemoController::class, 'index'])->name('index');
        Route::get('/materials', [ListDemoController::class, 'materials'])->name('materials');
        Route::get('/functions', [ListDemoController::class, 'functions'])->name('functions');
    });
}
