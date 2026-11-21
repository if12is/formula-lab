<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ComingSoonController extends Controller
{
    public function __invoke(string $section): InertiaResponse
    {
        return Inertia::render('ComingSoon', ['section' => $section]);
    }
}
