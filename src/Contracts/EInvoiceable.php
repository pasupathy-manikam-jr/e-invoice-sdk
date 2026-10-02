<?php

namespace Oriclab\EInvoice\Contracts;

use Oriclab\EInvoice\Data\Document;

/** Implemented by any Eloquent model that can be issued as an e-invoice (invoice, credit note, POS sale, consolidated batch). */
interface EInvoiceable
{
    public function toEInvoiceDocument(): Document;
}
