<?php

use App\Http\Controllers\Dev\MockSanadController;
use Illuminate\Support\Facades\Route;

// Same paths as Sanad Desktop under a /__mock/sanad prefix: point SANAD_BASE_URL at <app>/__mock/sanad.
// Loaded from bootstrap/app.php in local and testing only, outside the web and api groups.
Route::prefix('__mock/sanad/api/v1/manufacturing')->name('mock.sanad.')->middleware('throttle:120,1')->group(function () {
    Route::middleware('sanad.mock:read')->group(function () {
        Route::get('products', [MockSanadController::class, 'products'])->name('products');
        Route::get('customers', [MockSanadController::class, 'customers'])->name('customers');
        Route::get('suppliers', [MockSanadController::class, 'suppliers'])->name('suppliers');
        Route::get('purchases', [MockSanadController::class, 'purchases'])->name('purchases');
        Route::get('stock', [MockSanadController::class, 'stock'])->name('stock');
    });

    Route::middleware(['sanad.mock:write', 'idempotent'])->group(function () {
        Route::put('products/{uuid}', [MockSanadController::class, 'upsertProduct'])->name('products.upsert');
        Route::put('products/{uuid}/wholesale-prices', [MockSanadController::class, 'updateWholesalePrices'])->name('products.wholesale-prices');
        Route::put('price-lists/{uuid}', [MockSanadController::class, 'upsertPriceList'])->whereUuid('uuid')->name('price-lists.upsert');
        Route::put('price-lists/{uuid}/items', [MockSanadController::class, 'upsertPriceListItems'])->whereUuid('uuid')->name('price-lists.items');
        Route::put('customers/{uuid}/price-list', [MockSanadController::class, 'assignCustomerPriceList'])->whereUuid('uuid')->name('customers.price-list');
        Route::post('production-orders/{external_id}/consume', [MockSanadController::class, 'consume'])->name('orders.consume');
        Route::post('production-orders/{external_id}/receive', [MockSanadController::class, 'receive'])->name('orders.receive');
    });
});
