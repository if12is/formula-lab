<?php

use App\Http\Controllers\Formula\FormulaCostReportController;
use App\Http\Controllers\Formula\FormulaPublishController;
use Illuminate\Support\Facades\Route;

/*
| The cost report with its approval (I-08) and the publish of the finished good to Sanad (G-09), per formula.
| Loaded from routes/web.php, so it shares the web middleware group.
*/

Route::prefix('formulas/{formula}')->name('formulas.')->whereUuid('formula')->group(function (): void {
    Route::get('cost-report', [FormulaCostReportController::class, 'show'])->name('cost-report');
    Route::post('cost-approvals', [FormulaCostReportController::class, 'approve'])->name('cost-approvals.store');
    Route::get('publish', [FormulaPublishController::class, 'show'])->name('publish');
    Route::post('publish', [FormulaPublishController::class, 'store'])->name('publish.store');
});
