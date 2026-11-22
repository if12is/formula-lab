<?php

namespace App\Services\Pricing;

use App\Actions\Pricing\AssignPriceListCustomerAction;
use App\Actions\Pricing\CreatePriceListAction;
use App\Actions\Pricing\PublishPriceListToSanadAction;
use App\Actions\Pricing\RemovePriceListItemAction;
use App\Actions\Pricing\SavePriceListItemAction;
use App\Actions\Pricing\UnassignPriceListCustomerAction;
use App\Actions\Pricing\UpdatePriceListAction;
use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Exceptions\Domain\PriceListException;
use App\Exceptions\Domain\SanadIntegrationException;
use App\Models\Formula;
use App\Models\PriceList;
use App\Models\PriceListCustomer;
use App\Models\PriceListItem;
use App\Models\SanadCustomer;
use App\Models\SanadDevice;
use App\Support\DTOs\PriceListPublishResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single write path of price lists, their lines and their merchants. It owns the transactions; each change is one Action.
 * Publishing goes through PublishPriceListToSanadAction with the tenant's linked Sanad device (or LAN when there is none).
 */
final class PriceListService
{
    public function __construct(
        private readonly EnsureDefaultTenantAction $ensureDefaultTenant,
        private readonly CreatePriceListAction $createList,
        private readonly UpdatePriceListAction $updateList,
        private readonly SavePriceListItemAction $saveItem,
        private readonly RemovePriceListItemAction $removeItem,
        private readonly AssignPriceListCustomerAction $assignCustomer,
        private readonly UnassignPriceListCustomerAction $unassignCustomer,
        private readonly PublishPriceListToSanadAction $publishList,
    ) {}

    /**
     * @param  array{name: string, is_active?: bool}  $data
     *
     * @throws QueryException When the name is already used.
     */
    public function create(array $data): PriceList
    {
        return ($this->createList)(($this->ensureDefaultTenant)(), $data);
    }

    /**
     * @param  array{name?: string, is_active?: bool}  $data
     *
     * @throws QueryException When the name is already used.
     */
    public function update(PriceList $list, array $data): PriceList
    {
        return ($this->updateList)($list, $data);
    }

    /**
     * Adds a product line, or changes how an existing one is priced.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws PriceListException
     */
    public function saveItem(PriceList $list, Formula $formula, array $data, ?PriceListItem $item = null): PriceListItem
    {
        return DB::transaction(fn (): PriceListItem => ($this->saveItem)($list, $formula, $data, $item));
    }

    public function removeItem(PriceListItem $item): void
    {
        DB::transaction(fn () => ($this->removeItem)($item));
    }

    /** @throws PriceListException */
    public function assignCustomer(PriceList $list, SanadCustomer $customer): PriceListCustomer
    {
        return DB::transaction(fn (): PriceListCustomer => ($this->assignCustomer)($list, $customer));
    }

    public function unassignCustomer(PriceListCustomer $assignment): void
    {
        DB::transaction(fn () => ($this->unassignCustomer)($assignment));
    }

    /**
     * @throws PriceListException When the list is not ready or no Sanad link exists.
     * @throws SanadIntegrationException In LAN mode, when Sanad refuses a request.
     */
    public function publish(PriceList $list): PriceListPublishResult
    {
        $device = SanadDevice::query()->where('tenant_id', $list->tenant_id)->orderBy('created_at')->first();

        return ($this->publishList)($list, $device);
    }
}
