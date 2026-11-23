<?php

namespace App\Http\Controllers\Production;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Production\StartProductionBatchRequest;
use App\Http\Requests\Production\StoreProductionOrderRequest;
use App\Models\FormulaVersion;
use App\Models\ProductionOrder;
use App\Services\Production\ProductionBatchService;
use App\Services\Production\ProductionLookupService;
use App\Services\Production\ProductionOrderDetailService;
use App\Services\Production\ProductionOrderListService;
use App\Services\Production\ProductionOrderService;
use App\Services\Production\ProductionSheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class ProductionOrderController extends Controller
{
    public function __construct(
        private ProductionOrderService $orderService,
        private ProductionBatchService $batchService,
        private ProductionOrderListService $listService,
        private ProductionOrderDetailService $detailService,
        private ProductionLookupService $lookupService,
        private ProductionSheetService $sheetService,
    ) {}

    public function index(Request $request): InertiaResponse
    {
        return Inertia::render('Production/Orders/Index', [
            'list' => $this->listService->list($request, route('production.formulas.options', [], false)),
        ]);
    }

    /** The entry form; `?formula_version=<id>` (from the formula editor) preselects the approved version. */
    public function create(Request $request): InertiaResponse
    {
        $id = $request->query('formula_version');
        $version = is_string($id) && Str::isUuid($id) ? FormulaVersion::query()->find($id) : null;

        return Inertia::render('Production/Orders/Create', [
            'version' => $version === null ? null : $this->lookupService->versionOption($version),
            'batchUnits' => array_map(fn (string $unit): array => ['value' => $unit, 'label' => $unit], config('production.batch_units')),
            'resolutions' => array_map(fn (string $step): array => ['value' => $step, 'label' => $step], config('production.scale_resolutions_g')),
            'defaultResolution' => config('production.default_scale_resolution_g'),
            'today' => now()->toDateString(),
            'urls' => [
                'store' => route('production.orders.store', [], false),
                'versions' => route('production.versions.search', [], false),
                'packaging' => route('production.packaging.search', [], false),
                'index' => route('production.orders.index', [], false),
            ],
        ]);
    }

    public function store(StoreProductionOrderRequest $request): RedirectResponse
    {
        try {
            $order = $this->orderService->create($request->formulaVersion(), $request->orderData(), $request->user());
        } catch (BusinessRuleViolationException $exception) {
            return back()->withErrors(['formula_version_id' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('production.orders.show', $order)->with('success', __('production.orders.created'));
    }

    public function show(Request $request, ProductionOrder $order): InertiaResponse
    {
        return Inertia::render('Production/Orders/Show', $this->detailService->forOrder($order, $request->user()));
    }

    public function approve(Request $request, ProductionOrder $order): RedirectResponse
    {
        try {
            $this->orderService->approve($order, $request->user());
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('production.orders.approved'));
    }

    public function send(ProductionOrder $order): RedirectResponse
    {
        try {
            $this->orderService->send($order);
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', __('production.orders.sent'));
    }

    /** The operator's weighing sheet: weights only, laid out for print. */
    public function weighing(ProductionOrder $order): InertiaResponse
    {
        $order->loadMissing('formulaVersion.formula');
        $formula = $order->formulaVersion->formula;

        return Inertia::render('Production/Orders/Weighing', [
            'order' => [
                'id' => $order->getKey(),
                'code' => $order->code,
                'batch_number' => $order->batch_number,
                'status' => $order->status->value,
                'batch_size' => $order->batch_size,
                'batch_unit' => $order->batch_unit,
                'planned_on' => $order->planned_on?->toDateString(),
                'formula_code' => $formula->code,
                'formula_name' => $formula->name,
                'version' => $order->formulaVersion->version,
                'formula_hash' => $order->formula_hash,
            ],
            'sheet' => $this->sheetService->forOrder($order),
            'steps' => $order->snapshot['steps'],
            'materials' => array_column($order->snapshot['lines'], 'material', 'line_no'),
            'urls' => ['order' => route('production.orders.show', $order, false)],
        ]);
    }

    public function startBatch(StartProductionBatchRequest $request, ProductionOrder $order): RedirectResponse
    {
        try {
            $batch = $this->batchService->start($order, $request->validated('operator'), $request->validated('planned_waste_pct'), $request->user());
        } catch (BusinessRuleViolationException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('production.batches.show', $batch)->with('success', __('production.batches.started'));
    }

    /** JSON options for the formula filter: [{ value, label }]. */
    public function formulaOptions(): JsonResponse
    {
        return response()->json($this->listService->formulaOptions());
    }

    /** JSON typeahead of APPROVED formula versions for the entry form: GET ?q=text. */
    public function versionSearch(Request $request): JsonResponse
    {
        return response()->json($this->lookupService->approvedVersions((string) $request->query('q', '')));
    }

    /** JSON typeahead of packaging items for the entry form: GET ?q=text. */
    public function packagingSearch(Request $request): JsonResponse
    {
        return response()->json($this->lookupService->packagingItems((string) $request->query('q', '')));
    }
}
