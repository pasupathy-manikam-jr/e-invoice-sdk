<?php

namespace EInvoiceSdk\Data;

/**
 * Supplier or buyer. Provide the TIN plus one of BRN / NRIC / passport / army ID.
 * State and country take LHDN codes (e.g. '14', 'MYS') or their labels.
 */
final readonly class Party
{
    public function __construct(
        public string $name,
        public string $tin,
        public ?string $brn = null,
        public ?string $nric = null,
        public ?string $passport = null,
        public ?string $army = null,
        public ?string $sstNumber = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $addressLine1 = null,
        public ?string $addressLine2 = null,
        public ?string $addressLine3 = null,
        public ?string $postcode = null,
        public ?string $city = null,
        public ?string $state = null,
        public string $country = 'MYS',
        /** Supplier only. */
        public ?string $msicCode = null,
        public ?string $msicDescription = null,
    ) {}
}
