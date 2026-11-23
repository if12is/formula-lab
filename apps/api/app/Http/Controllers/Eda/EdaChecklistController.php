<?php

namespace App\Http\Controllers\Eda;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Eda\RecordEdaChecklistEntryRequest;
use App\Models\EdaChecklistEntry;
use App\Models\Formula;
use App\Services\Eda\EdaChecklistService;
use App\Services\Eda\EdaFileListService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** N-08: the EDA notification files of the products EDA regulates, and one product's checklist with what is attached. */
class EdaChecklistController extends Controller
{
    public function __construct(
        private EdaFileListService $edaFileListService,
        private EdaChecklistService $edaChecklistService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Eda/Index', [
            'list' => $this->edaFileListService->list($request),
            'summary' => $this->edaFileListService->summary(),
        ]);
    }

    public function show(Formula $formula): InertiaResponse
    {
        return Inertia::render('Eda/Show', $this->edaChecklistService->page($formula));
    }

    public function save(RecordEdaChecklistEntryRequest $request, Formula $formula, string $itemCode): RedirectResponse
    {
        try {
            $this->edaChecklistService->record($formula, $itemCode, $request->safe()->except('file'), $request->file('file'), $request->user());
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('eda.saved'));
    }

    public function file(Formula $formula, string $itemCode): StreamedResponse
    {
        $entry = EdaChecklistEntry::query()->where('formula_id', $formula->getKey())->where('item_code', $itemCode)->whereNotNull('file_path')->firstOrFail();

        return Storage::disk(config('eda.disk'))->download($entry->file_path, $entry->file_name);
    }
}
