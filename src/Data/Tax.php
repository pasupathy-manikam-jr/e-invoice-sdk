<?php

namespace Oriclab\EInvoice\Data;

/** One tax subtotal. `code` is the LHDN tax type code ('01' sales tax, '02' service tax, 'E' exempt, ...). */
final readonly class Tax
{
    public function __construct(
        public string $code,
        public float $amount,
        public float $taxableAmount,
        public ?float $rate = null,
        public ?string $exemptionReason = null,
    ) {}
}
