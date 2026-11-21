<?php

namespace App\Services\Formula;

use App\Actions\Formula\CreateFormulaAction;
use App\Actions\Formula\UpdateFormulaIfraCategoryAction;
use App\Actions\Formula\UpdateFormulaTaxAction;
use App\Actions\Tenancy\EnsureDefaultTenantAction;
use App\Enums\TaxMode;
use App\Exceptions\Domain\InvalidFormulaVersionException;
use App\Exceptions\Domain\InvalidTaxSettingException;
use App\Models\Formula;
use App\Models\FormulaVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The single write path of the formula identity row; its first version goes through FormulaVersionService. */
final class FormulaService
{
    public function __construct(
        private CreateFormulaAction $createFormula,
        private EnsureDefaultTenantAction $ensureDefaultTenant,
        private FormulaVersionService $formulaVersionService,
        private UpdateFormulaIfraCategoryAction $updateIfraCategory,
        private UpdateFormulaTaxAction $updateTax,
    ) {}

    /** The IFRA category is a fact about the product, not about one version, so it is saved on the formula itself. */
    public function setIfraCategory(Formula $formula, ?string $category): Formula
    {
        return ($this->updateIfraCategory)($formula, $category);
    }

    /**
     * The tax of the product is a fact about the product, not about one version, so it is saved on the formula itself.
     *
     * @throws InvalidTaxSettingException When a rate is missing or not one of the allowed rates.
     */
    public function setTax(Formula $formula, TaxMode $mode, ?string $ratePct): Formula
    {
        return ($this->updateTax)($formula, $mode, $ratePct);
    }

    /**
     * Creates the formula and its first (draft) version in one transaction.
     *
     * @param  array{code: string, name: string, product_type: string, tier?: string|null}  $meta
     * @param  list<array{trade_material_id: string, percent_ww?: string|null, is_qs?: bool, phase?: string|null}>  $lines
     * @return array{formula: Formula, version: FormulaVersion}
     *
     * @throws InvalidFormulaVersionException When there are no lines.
     */
    public function create(array $meta, array $lines, ?User $user = null): array
    {
        return DB::transaction(function () use ($meta, $lines, $user): array {
            $formula = ($this->createFormula)(($this->ensureDefaultTenant)(), $meta);
            $version = $this->formulaVersionService->createVersion($formula, $lines, null, $user);

            return ['formula' => $formula, 'version' => $version];
        });
    }
}
