<?php

namespace Tests\Feature\Packaging;

use App\Actions\Packaging\CreatePackagingBomAction;
use App\Actions\Packaging\CreatePackagingBomLineAction;
use App\Actions\Packaging\DeletePackagingBomAction;
use App\Actions\Packaging\DeletePackagingBomLineAction;
use App\Actions\Packaging\DiscardPackagingBomImageFileAction;
use App\Actions\Packaging\StorePackagingBomImageAction;
use App\Actions\Packaging\UpdatePackagingBomAction;
use App\Actions\Packaging\UpdatePackagingBomLineAction;
use App\Enums\PackLevel;
use App\Exceptions\Domain\PackagingBomException;
use App\Models\PackagingBom;
use App\Models\PackagingBomLine;
use App\Models\TradeMaterial;
use App\Services\Packaging\PackagingBomService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** S-12: each action of the packaging BOM does its one thing; the service keeps a line to its own BOM. */
class PackagingBomActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('packaging.image.disk'));
    }

    public function test_the_bom_actions_create_update_and_delete_with_the_lines(): void
    {
        $bom = app(CreatePackagingBomAction::class)(['name' => 'SKU one', 'units_per_carton' => 6]);
        $this->assertSame('SKU one', $bom->fresh()->name);

        app(UpdatePackagingBomAction::class)($bom, ['name' => 'SKU two']);
        $this->assertSame('SKU two', $bom->fresh()->name);

        PackagingBomLine::factory()->for($bom, 'bom')->create();
        app(DeletePackagingBomAction::class)($bom->load('lines'));

        $this->assertDatabaseCount('packaging_boms', 0);
        $this->assertDatabaseCount('packaging_bom_lines', 0);
    }

    public function test_the_line_actions_append_update_and_delete(): void
    {
        $bom = PackagingBom::factory()->create();
        $first = app(CreatePackagingBomLineAction::class)($bom, ['trade_material_id' => TradeMaterial::factory()->packaging()->create()->id, 'level' => PackLevel::Unit->value]);
        $second = app(CreatePackagingBomLineAction::class)($bom, ['trade_material_id' => TradeMaterial::factory()->packaging()->create()->id, 'level' => PackLevel::Carton->value]);

        $this->assertSame([0, 1], [$first->position, $second->position]);

        app(UpdatePackagingBomLineAction::class)($second, ['level' => PackLevel::Pallet->value]);
        $this->assertSame(PackLevel::Pallet, $second->fresh()->level);

        app(DeletePackagingBomLineAction::class)($first);
        $this->assertSame(1, $bom->lines()->count());
    }

    public function test_the_image_actions_store_with_a_hash_and_discard_the_file(): void
    {
        $stored = app(StorePackagingBomImageAction::class)(UploadedFile::fake()->image('pack.png', 20, 20));
        $disk = Storage::disk((string) config('packaging.image.disk'));

        $disk->assertExists($stored['image_path']);
        $this->assertSame('pack.png', $stored['image_name']);
        $this->assertSame(hash_file('sha256', $disk->path($stored['image_path'])), $stored['image_sha256']);

        app(DiscardPackagingBomImageFileAction::class)($stored['image_path']);
        $disk->assertMissing($stored['image_path']);
        app(DiscardPackagingBomImageFileAction::class)(null);
    }

    public function test_the_service_refuses_a_line_of_another_bom(): void
    {
        $bom = PackagingBom::factory()->create();
        $line = PackagingBomLine::factory()->for(PackagingBom::factory()->create(), 'bom')->create();

        $this->expectException(PackagingBomException::class);
        $this->expectExceptionMessage(__('packaging.bom.exceptions.line_of_another_bom'));

        app(PackagingBomService::class)->removeLine($bom, $line);
    }
}
