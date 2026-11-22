<?php

namespace App\Exceptions\Domain;

/** Thrown when a price list, its lines, its merchants or its publication to Sanad would break the price list rules. */
class PriceListException extends BusinessRuleViolationException
{
    /** @param  list<string>  $codes */
    public static function productsNotPublished(array $codes): self
    {
        return new self(__('exceptions.price_list_products_not_published', ['products' => implode(', ', $codes)]));
    }

    /** @param  list<string>  $codes */
    public static function pricesIncomplete(array $codes): self
    {
        return new self(__('exceptions.price_list_prices_incomplete', ['products' => implode(', ', $codes)]));
    }

    public static function tooManyItems(int $count, int $max): self
    {
        return new self(__('exceptions.price_list_too_many_items', ['count' => $count, 'max' => $max]));
    }

    public static function noSanadLink(): self
    {
        return new self(__('exceptions.price_list_no_sanad_link'));
    }

    public static function productAlreadyOnList(string $code): self
    {
        return new self(__('exceptions.price_list_product_already_on_list', ['product' => $code]));
    }

    public static function unknownRule(string $key): self
    {
        return new self(__('exceptions.price_list_unknown_rule', ['rule' => $key]));
    }

    public static function customerAlreadyAssigned(string $customer, string $list): self
    {
        return new self(__('exceptions.price_list_customer_already_assigned', ['customer' => $customer, 'list' => $list]));
    }

    public static function customerUnavailable(string $customer): self
    {
        return new self(__('exceptions.price_list_customer_unavailable', ['customer' => $customer]));
    }

    public static function crossTenant(): self
    {
        return new self(__('exceptions.price_list_cross_tenant'), 403);
    }

    public function __construct(string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
