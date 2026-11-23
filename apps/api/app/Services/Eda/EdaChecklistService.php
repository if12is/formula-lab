<?php

namespace App\Services\Eda;

use App\Actions\Eda\ClearEdaChecklistEntryAction;
use App\Actions\Eda\RecordEdaChecklistEntryAction;
use App\Enums\EdaItemStatus;
use App\Exceptions\Domain\EdaChecklistException;
use App\Models\EdaChecklistEntry;
use App\Models\Formula;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/** The single write path of a product's EDA notification file (N-08): which items exist is the engine's answer; what is recorded for them is written here. */
final class EdaChecklistService
{
    public function __construct(
        private readonly EdaReadinessService $readiness,
        private readonly RecordEdaChecklistEntryAction $record,
        private readonly ClearEdaChecklistEntryAction $clear,
    ) {}

    /**
     * What the file page shows for one product: the engine's checklist and readiness with what was recorded beside each item.
     *
     * @return array<string, mixed>
     */
    public function page(Formula $formula): array
    {
        $formula->load('productFamily');
        $readiness = $this->readiness->forFormula($formula);
        $entries = $formula->edaChecklistEntries()->get()->keyBy('item_code');

        return [
            'product' => [
                'id' => $formula->getKey(),
                'code' => $formula->code,
                'name' => $formula->name,
                'family' => $formula->productFamily?->displayName(),
                'href' => route('formulas.show', $formula, false),
            ],
            'readiness' => [
                'state' => $readiness['state'],
                'counts' => $readiness['counts'],
                'regulator' => $readiness['regulator'],
                'reference' => $readiness['reference'] === null ? null : [
                    'title' => $readiness['reference']['title'][app()->getLocale() === 'en' ? 'en' : 'ar'],
                ],
                'orphans' => $readiness['orphans'],
            ],
            'items' => array_map(fn (array $item): array => [
                'code' => $item['code'],
                'name' => $item['name'][app()->getLocale() === 'en' ? 'en' : 'ar'],
                'status' => $item['status'],
                'url' => route('eda.items.save', [$formula, $item['code']], false),
                ...$this->entryView($formula, $entries->get($item['code'])),
            ], $readiness['items']),
            'urls' => ['index' => route('eda.index', [], false)],
        ];
    }

    /**
     * @param  array{status: string, document_title?: string|null, document_reference?: string|null, note?: string|null}  $data
     *
     * @throws EdaChecklistException When the product's checklist has no such item (or no items at all).
     */
    public function record(Formula $formula, string $itemCode, array $data, ?UploadedFile $file, ?User $user): EdaChecklistEntry
    {
        $status = EdaItemStatus::from($data['status']);
        $this->assertItem($formula, $itemCode);

        if ($status === EdaItemStatus::Missing) {
            $existing = EdaChecklistEntry::query()->where('formula_id', $formula->getKey())->where('item_code', $itemCode)->first();
            if ($existing !== null) {
                ($this->clear)($existing);
            }

            return $existing ?? new EdaChecklistEntry(['formula_id' => $formula->getKey(), 'item_code' => $itemCode, 'status' => $status]);
        }

        return ($this->record)($formula, $itemCode, [...$data, 'status' => $status], $file, $user);
    }

    /** @throws EdaChecklistException */
    private function assertItem(Formula $formula, string $itemCode): void
    {
        $readiness = $this->readiness->forFormula($formula);

        if ($readiness['state'] === 'items_not_sourced') {
            throw EdaChecklistException::itemsNotSourced();
        }

        if (! in_array($itemCode, array_column($readiness['items'], 'code'), true)) {
            throw EdaChecklistException::itemUnknown($itemCode);
        }
    }

    /** @return array<string, mixed> */
    private function entryView(Formula $formula, ?EdaChecklistEntry $entry): array
    {
        return [
            'document_title' => $entry?->document_title,
            'document_reference' => $entry?->document_reference,
            'note' => $entry?->note,
            'file_name' => $entry?->file_name,
            'file_url' => $entry?->file_path === null ? null : route('eda.items.file', [$formula, $entry->item_code], false),
            'updated_at' => $entry?->updated_at?->toIso8601String(),
        ];
    }
}
