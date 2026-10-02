<?php

namespace Oriclab\EInvoice\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Oriclab\EInvoice\Models\EInvoiceDocument;

/** Optional convenience for host models that implement EInvoiceable. */
trait HasEInvoices
{
    /** @return MorphMany<EInvoiceDocument, $this> */
    public function einvoiceDocuments(): MorphMany
    {
        return $this->morphMany(EInvoiceDocument::class, 'einvoiceable');
    }
}
