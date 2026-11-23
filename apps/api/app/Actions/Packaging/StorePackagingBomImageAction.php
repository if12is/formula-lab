<?php

namespace App\Actions\Packaging;

use App\Exceptions\Domain\PackagingBomException;
use Illuminate\Http\UploadedFile;

/** Keeps an uploaded pack image on the private disk and returns what identifies it: where, its name, type, size and SHA-256. */
final class StorePackagingBomImageAction
{
    /**
     * @return array{image_path: string, image_name: string, image_mime: string, image_size: int, image_sha256: string}
     *
     * @throws PackagingBomException When the file cannot be read or stored.
     */
    public function __invoke(UploadedFile $file): array
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $path = $file->store((string) config('packaging.image.directory'), (string) config('packaging.image.disk'));

        if ($hash === false || $path === false) {
            throw PackagingBomException::imageNotStored();
        }

        return [
            'image_path' => $path,
            'image_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'image_mime' => (string) ($file->getMimeType() ?? $file->getClientMimeType()),
            'image_size' => (int) $file->getSize(),
            'image_sha256' => $hash,
        ];
    }
}
