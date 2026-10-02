<?php

namespace EInvoiceSdk\Concerns;

use EInvoiceSdk\Models\EInvoiceDocument;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/** Optional convenience for host models that implement EInvoiceable. */
trait HasEInvoices
{
    /** @return MorphMany<EInvoiceDocument, $this> */
    public function einvoiceDocuments(): MorphMany
    {
        return $this->morphMany(EInvoiceDocument::class, 'einvoiceable');
    }
}
