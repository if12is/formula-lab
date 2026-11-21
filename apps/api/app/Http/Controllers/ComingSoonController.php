<?php

namespace App\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class ComingSoonController extends Controller
{
    public function __invoke(string $section): Response
    {
        return Inertia::render('ComingSoon', ['section' => $section]);
    }
}
