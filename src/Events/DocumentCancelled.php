<?php

namespace Oriclab\EInvoice\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Oriclab\EInvoice\Models\EInvoiceDocument;

class DocumentCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(public EInvoiceDocument $document) {}
}
