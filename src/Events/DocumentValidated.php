<?php

namespace EInvoiceSdk\Events;

use EInvoiceSdk\Models\EInvoiceDocument;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentValidated
{
    use Dispatchable, SerializesModels;

    public function __construct(public EInvoiceDocument $document) {}
}
