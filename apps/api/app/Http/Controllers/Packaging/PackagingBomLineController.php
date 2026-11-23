<?php

namespace App\Http\Controllers\Packaging;

use App\Exceptions\Domain\PackagingBomException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Packaging\StorePackagingBomLineRequest;
use App\Models\PackagingBom;
use App\Models\PackagingBomLine;
use App\Services\Packaging\PackagingBomService;
use Illuminate\Http\RedirectResponse;

class PackagingBomLineController extends Controller
{
    public function __construct(private PackagingBomService $bomService) {}

    public function store(StorePackagingBomLineRequest $request, PackagingBom $bom): RedirectResponse
    {
        $this->bomService->addLine($bom, $request->lineData());

        return back()->with('success', __('packaging.bom.line_added'));
    }

    public function update(StorePackagingBomLineRequest $request, PackagingBom $bom, PackagingBomLine $line): RedirectResponse
    {
        try {
            $this->bomService->changeLine($bom, $line, $request->lineData());
        } catch (PackagingBomException $exception) {
            return back()->withErrors(['trade_material_id' => $exception->getMessage()]);
        }

        return back()->with('success', __('packaging.bom.line_updated'));
    }

    public function destroy(PackagingBom $bom, PackagingBomLine $line): RedirectResponse
    {
        try {
            $this->bomService->removeLine($bom, $line);
        } catch (PackagingBomException $exception) {
            return back()->withErrors(['line' => $exception->getMessage()]);
        }

        return back()->with('success', __('packaging.bom.line_removed'));
    }
}
