<?php

namespace EInvoiceSdk\Data;

use DateTimeInterface;
use EInvoiceSdk\Enums\DocumentType;

/**
 * Our driver-neutral description of one e-invoice. Host models build this; drivers map it to their API.
 *
 * The supplier TIN selects which company's credentials (einvoice_settings row) are used.
 */
final readonly class Document
{
    /**
     * @param  list<LineItem>  $lines
     * @param  list<Tax>  $taxes  document-level tax subtotals, one per tax type
     */
    public function __construct(
        public DocumentType $type,
        public string $number,
        public DateTimeInterface $issuedAt,
        public Party $supplier,
        public Party $buyer,
        public array $lines,
        public array $taxes,
        /** total excluding tax */
        public float $subtotal,
        /** total including tax */
        public float $grandTotal,
        public ?float $payableTotal = null,
        public string $currency = 'MYR',
        public ?float $currencyRate = null,
        /** LHDN payment mode code, e.g. '03' bank transfer */
        public ?string $paymentMode = null,
        public ?string $paymentTerms = null,
        /** Required for credit / debit / refund notes. */
        public ?string $originalNumber = null,
        public ?string $originalUuid = null,
        /** Monthly consolidated submission for consumer sales (buyer TIN EI00000000010, lines classified '004'). */
        public bool $consolidated = false,
    ) {}
}
