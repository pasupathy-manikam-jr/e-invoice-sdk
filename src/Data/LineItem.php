<?php

namespace Oriclab\EInvoice\Data;

final readonly class LineItem
{
    /**
     * @param  list<string>  $classificationCodes  LHDN classification codes, e.g. ['022']; consolidated lines use ['004']
     * @param  list<Tax>  $taxes
     */
    public function __construct(
        public string $description,
        public float $quantity,
        public float $unitPrice,
        /** quantity × unit price, before discount and tax */
        public float $subtotal,
        public array $classificationCodes,
        public array $taxes = [],
        /** LHDN unit of measure code, e.g. 'C62' (one) */
        public ?string $unit = null,
        public float $discount = 0,
        public ?string $discountDescription = null,
    ) {}
}
