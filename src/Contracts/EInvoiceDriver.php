<?php

namespace Oriclab\EInvoice\Contracts;

use Oriclab\EInvoice\Data\Document;
use Oriclab\EInvoice\Drivers\StatusResult;
use Oriclab\EInvoice\Drivers\SubmitResult;
use Oriclab\EInvoice\Models\EInvoiceSetting;

/**
 * The only seam that talks to LHDN. Jobs and host apps never touch an SDK directly.
 * Transport and auth problems throw; LHDN business rejections are returned in the result.
 */
interface EInvoiceDriver
{
    /**
     * @return list<string> readable errors; empty when the document can be submitted
     */
    public function validate(Document $document): array;

    public function submit(EInvoiceSetting $setting, Document $document): SubmitResult;

    public function status(EInvoiceSetting $setting, string $uuid): StatusResult;

    /**
     * Throws when LHDN refuses the cancellation.
     *
     * @return array<mixed> raw response
     */
    public function cancel(EInvoiceSetting $setting, string $uuid, string $reason): array;

    /** @param  'BRN'|'NRIC'|'PASSPORT'|'ARMY'  $idType */
    public function validateTin(EInvoiceSetting $setting, string $tin, string $idType, string $idValue): bool;

    /** UUID of a submitted or valid document with this internal number, if LHDN already has one. Used before retrying a failed submit. */
    public function findExisting(EInvoiceSetting $setting, Document $document): ?string;
}
