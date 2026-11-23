<?php

namespace App\Services\Packaging;

use App\Actions\Packaging\CreatePackagingBomAction;
use App\Actions\Packaging\CreatePackagingBomLineAction;
use App\Actions\Packaging\DeletePackagingBomAction;
use App\Actions\Packaging\DeletePackagingBomLineAction;
use App\Actions\Packaging\DiscardPackagingBomImageFileAction;
use App\Actions\Packaging\StorePackagingBomImageAction;
use App\Actions\Packaging\UpdatePackagingBomAction;
use App\Actions\Packaging\UpdatePackagingBomLineAction;
use App\Exceptions\Domain\PackagingBomException;
use App\Models\PackagingBom;
use App\Models\PackagingBomLine;
use Illuminate\Http\UploadedFile;

/** The single write path of packaging BOMs, their lines and their pack image. */
final class PackagingBomService
{
    public function __construct(
        private CreatePackagingBomAction $createBom,
        private UpdatePackagingBomAction $updateBom,
        private DeletePackagingBomAction $deleteBom,
        private StorePackagingBomImageAction $storeImage,
        private DiscardPackagingBomImageFileAction $discardImage,
        private CreatePackagingBomLineAction $createLine,
        private UpdatePackagingBomLineAction $updateLine,
        private DeletePackagingBomLineAction $deleteLine,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function create(array $data): PackagingBom
    {
        return ($this->createBom)($data);
    }

    /** @param  array<string, mixed>  $data */
    public function update(PackagingBom $bom, array $data): PackagingBom
    {
        return ($this->updateBom)($bom, $data);
    }

    public function delete(PackagingBom $bom): void
    {
        $path = $bom->image_path;
        ($this->deleteBom)($bom);
        ($this->discardImage)($path);
    }

    /** Replaces the pack image: the new file is kept first, so a failure leaves the old image in place. */
    public function replaceImage(PackagingBom $bom, UploadedFile $file): PackagingBom
    {
        $previous = $bom->image_path;
        $stored = ($this->storeImage)($file);

        try {
            ($this->updateBom)($bom, $stored);
        } catch (\Throwable $exception) {
            ($this->discardImage)($stored['image_path']);

            throw $exception;
        }

        ($this->discardImage)($previous);

        return $bom;
    }

    public function removeImage(PackagingBom $bom): PackagingBom
    {
        $previous = $bom->image_path;
        ($this->updateBom)($bom, ['image_path' => null, 'image_name' => null, 'image_mime' => null, 'image_size' => null, 'image_sha256' => null]);
        ($this->discardImage)($previous);

        return $bom;
    }

    /** @param  array<string, mixed>  $data */
    public function addLine(PackagingBom $bom, array $data): PackagingBomLine
    {
        return ($this->createLine)($bom, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws PackagingBomException When the line is not of this BOM.
     */
    public function changeLine(PackagingBom $bom, PackagingBomLine $line, array $data): PackagingBomLine
    {
        $this->assertOwned($bom, $line);

        return ($this->updateLine)($line, $data);
    }

    /** @throws PackagingBomException When the line is not of this BOM. */
    public function removeLine(PackagingBom $bom, PackagingBomLine $line): void
    {
        $this->assertOwned($bom, $line);
        ($this->deleteLine)($line);
    }

    private function assertOwned(PackagingBom $bom, PackagingBomLine $line): void
    {
        if ($line->packaging_bom_id !== $bom->getKey()) {
            throw PackagingBomException::lineOfAnotherBom();
        }
    }
}
