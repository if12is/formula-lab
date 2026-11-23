<?php

namespace App\Actions\Coa;

use App\Exceptions\Domain\CoaException;
use Illuminate\Http\UploadedFile;

/** Keeps an uploaded certificate on the private disk and returns what identifies it: where, its name, type, size and SHA-256. */
final class StoreCoaFileAction
{
    /**
     * @return array{path: string, name: string, mime: string, size: int, sha256: string}
     *
     * @throws CoaException When the file cannot be read or stored.
     */
    public function __invoke(UploadedFile $file): array
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $path = $file->store((string) config('coa.directory'), (string) config('coa.disk'));

        if ($hash === false || $path === false) {
            throw CoaException::documentUnreadable();
        }

        return [
            'path' => $path,
            'name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => (string) ($file->getMimeType() ?? $file->getClientMimeType()),
            'size' => (int) $file->getSize(),
            'sha256' => $hash,
        ];
    }
}
