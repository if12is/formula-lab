<?php

namespace App\Http\Controllers\Packaging;

use App\Exceptions\Domain\PackagingBomException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Packaging\StorePackagingBomImageRequest;
use App\Models\PackagingBom;
use App\Services\Packaging\PackagingBomService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PackagingBomImageController extends Controller
{
    public function __construct(private PackagingBomService $bomService) {}

    /** The pack image, streamed from the private disk. */
    public function show(PackagingBom $bom): StreamedResponse
    {
        $disk = Storage::disk((string) config('packaging.image.disk'));
        abort_unless($bom->hasImage() && $disk->exists((string) $bom->image_path), 404);

        return $disk->response((string) $bom->image_path, $bom->image_name, [
            'Content-Type' => $bom->image_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function store(StorePackagingBomImageRequest $request, PackagingBom $bom): RedirectResponse
    {
        try {
            $this->bomService->replaceImage($bom, $request->file('image'));
        } catch (PackagingBomException $exception) {
            return back()->withErrors(['image' => $exception->getMessage()]);
        }

        return back()->with('success', __('packaging.bom.image_saved'));
    }

    public function destroy(PackagingBom $bom): RedirectResponse
    {
        $this->bomService->removeImage($bom);

        return back()->with('success', __('packaging.bom.image_removed'));
    }
}
