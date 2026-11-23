<?php

use App\Http\Controllers\Exports\ExportLogController;
use Illuminate\Support\Facades\Route;

/*
| The export log (U-03): the list of every printed document, and the endpoint a print preview calls to log a print (S-17).
| Loaded from routes/web.php.
*/

Route::get('exports', [ExportLogController::class, 'index'])->name('exports.index');
Route::post('exports', [ExportLogController::class, 'store'])->name('exports.store');
