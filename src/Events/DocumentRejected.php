<?php

namespace EInvoiceSdk\Events;

use EInvoiceSdk\Models\EInvoiceDocument;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** LHDN rejected the submission or found the document invalid. Fix the source record and submit again. */
class DocumentRejected
{
    use Dispatchable, SerializesModels;

    /** @param  list<string>  $errors */
    public function __construct(public EInvoiceDocument $document, public array $errors) {}
}
