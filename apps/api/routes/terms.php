<?php

use App\Http\Controllers\Terms\TermsController;
use Illuminate\Support\Facades\Route;

/*
| The terms of use (S-16): the screen (open to everyone, also after accepting) and the acceptance. The gate itself is the
| RequireTermsAcceptance middleware in the web group. Loaded from routes/web.php.
*/

Route::get('terms', [TermsController::class, 'show'])->name('terms.show');
Route::post('terms', [TermsController::class, 'accept'])->name('terms.accept');
