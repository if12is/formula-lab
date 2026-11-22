<?php

use App\Http\Controllers\Pricing\PriceListController;
use App\Http\Controllers\Pricing\PriceListCustomerController;
use App\Http\Controllers\Pricing\PriceListItemController;
use App\Http\Controllers\Pricing\PriceListOptionController;
use Illuminate\Support\Facades\Route;

/*
| Per-merchant price lists (contract section 8): the lists, their product lines and their merchants, published to Sanad.
| Loaded from routes/web.php, so it shares the web middleware group.
*/

Route::prefix('price-lists')->name('price-lists.')->group(function (): void {
    Route::get('/', [PriceListController::class, 'index'])->name('index');
    Route::post('/', [PriceListController::class, 'store'])->name('store');
    Route::get('customers/search', [PriceListOptionController::class, 'customers'])->name('customers.search');
    Route::get('products/search', [PriceListOptionController::class, 'products'])->name('products.search');

    Route::prefix('{priceList}')->whereUuid('priceList')->scopeBindings()->group(function (): void {
        Route::get('/', [PriceListController::class, 'show'])->name('show');
        Route::get('merchants', [PriceListController::class, 'merchants'])->name('merchants');
        Route::put('/', [PriceListController::class, 'update'])->name('update');
        Route::post('publish', [PriceListController::class, 'publish'])->name('publish');

        Route::post('items', [PriceListItemController::class, 'store'])->name('items.store');
        Route::put('items/{item}', [PriceListItemController::class, 'update'])->whereUuid('item')->name('items.update');
        Route::delete('items/{item}', [PriceListItemController::class, 'destroy'])->whereUuid('item')->name('items.destroy');

        Route::post('customers', [PriceListCustomerController::class, 'store'])->name('customers.store');
        Route::delete('customers/{customer}', [PriceListCustomerController::class, 'destroy'])->whereUuid('customer')->name('customers.destroy');
    });
});
