<?php

namespace App\Http\Controllers\Fragrance;

use App\Exceptions\Domain\IfraCertificateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fragrance\StoreIfraCertificateRequest;
use App\Models\IfraCertificate;
use App\Models\TradeMaterial;
use App\Services\Fragrance\IfraCertificateService;
use Illuminate\Http\RedirectResponse;

class IfraCertificateController extends Controller
{
    public function __construct(private IfraCertificateService $ifraCertificateService) {}

    public function store(StoreIfraCertificateRequest $request, TradeMaterial $material): RedirectResponse
    {
        $this->ifraCertificateService->add($material, $request->validated());

        return redirect()->route('materials.show', ['material' => $material, 'tab' => 'fragrance'])->with('success', __('fragrance.certificate_added'));
    }

    public function destroy(TradeMaterial $material, IfraCertificate $ifraCertificate): RedirectResponse
    {
        try {
            $this->ifraCertificateService->remove($ifraCertificate);
        } catch (IfraCertificateException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('materials.show', ['material' => $material, 'tab' => 'fragrance'])->with('success', __('fragrance.certificate_removed'));
    }
}
