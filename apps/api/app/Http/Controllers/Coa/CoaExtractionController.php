<?php

namespace App\Http\Controllers\Coa;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Coa\ConfirmCoaExtractionRequest;
use App\Http\Requests\Coa\RejectCoaExtractionRequest;
use App\Http\Requests\Coa\StoreCoaDocumentRequest;
use App\Models\CoaExtraction;
use App\Models\TradeMaterial;
use App\Services\Coa\CoaExtractionDetailService;
use App\Services\Coa\CoaExtractionListService;
use App\Services\Coa\CoaExtractionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** P-05: certificates of analysis read by the model, and the review screen where a person confirms, edits or rejects each value before anything is saved. */
class CoaExtractionController extends Controller
{
    public function __construct(
        private CoaExtractionListService $listService,
        private CoaExtractionDetailService $detailService,
        private CoaExtractionService $extractionService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Coa/Index', [
            'list' => $this->listService->list($request, route('materials.search', [], false)),
            'upload' => [
                'url' => route('coa.store', [], false),
                'materialSearchUrl' => route('materials.search', [], false),
                'accept' => '.'.implode(',.', (array) config('coa.file.mimes')),
                'maxMb' => round((int) config('coa.file.max_kb') / 1024, 1),
            ],
        ]);
    }

    public function store(StoreCoaDocumentRequest $request): RedirectResponse
    {
        try {
            $extraction = $this->extractionService->extract($request->file('file'), TradeMaterial::query()->findOrFail($request->validated('trade_material_id')), $request->user()?->getKey());
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('coa.show', $extraction)->with('success', __('coa.extracted'));
    }

    public function show(CoaExtraction $extraction): InertiaResponse
    {
        return Inertia::render('Coa/Show', $this->detailService->page($extraction));
    }

    /** The original certificate, streamed inline from the private disk for the side-by-side review. */
    public function file(CoaExtraction $extraction): StreamedResponse
    {
        $disk = Storage::disk((string) config('coa.disk'));
        abort_unless($disk->exists($extraction->file_path), 404);

        return $disk->response($extraction->file_path, $extraction->file_name, ['Content-Type' => $extraction->file_mime, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function confirm(ConfirmCoaExtractionRequest $request, CoaExtraction $extraction): RedirectResponse
    {
        try {
            $this->extractionService->confirm($extraction, $request->confirmation(), $request->decisions(), $request->user()?->getKey());
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('coa.show', $extraction)->with('success', __('coa.confirmed'));
    }

    public function reject(RejectCoaExtractionRequest $request, CoaExtraction $extraction): RedirectResponse
    {
        try {
            $this->extractionService->reject($extraction, $request->validated('reason'), $request->user()?->getKey());
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('coa.show', $extraction)->with('success', __('coa.rejected'));
    }
}
