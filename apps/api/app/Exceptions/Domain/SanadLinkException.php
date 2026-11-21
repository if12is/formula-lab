<?php

namespace App\Exceptions\Domain;

/** Thrown when an inbound Sanad request or a material link would break the link rules. */
class SanadLinkException extends BusinessRuleViolationException
{
    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public static function productionOrderImmutable(string $externalId): self
    {
        return new self(__('exceptions.sanad_production_order_immutable', ['order' => $externalId]));
    }

    public static function materialAlreadyLinked(string $materialId): self
    {
        return new self(__('exceptions.sanad_material_already_linked', ['material' => $materialId]));
    }

    public static function productAlreadyLinked(string $productUuid): self
    {
        return new self(__('exceptions.sanad_product_already_linked', ['product' => $productUuid]));
    }

    public static function productNotLinkable(string $kind): self
    {
        return new self(__('exceptions.sanad_product_not_linkable', ['kind' => $kind]));
    }

    public static function productDeleted(string $productUuid): self
    {
        return new self(__('exceptions.sanad_product_deleted', ['product' => $productUuid]));
    }

    public static function tenantMismatch(): self
    {
        return new self(__('exceptions.sanad_tenant_mismatch'), 403);
    }

    public static function uomRequired(): self
    {
        return new self(__('exceptions.sanad_uom_required'));
    }

    public static function kindNotPublishable(string $kind): self
    {
        return new self(__('exceptions.sanad_kind_not_publishable', ['kind' => $kind]));
    }

    public static function tenantRequired(): self
    {
        return new self(__('exceptions.sanad_tenant_required'));
    }
}
