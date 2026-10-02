<?php

namespace EInvoiceSdk;

use EInvoiceSdk\Contracts\EInvoiceable;
use EInvoiceSdk\Contracts\EInvoiceDriver;
use EInvoiceSdk\Data\Document;
use EInvoiceSdk\Enums\Status;
use EInvoiceSdk\Exceptions\EInvoiceException;
use EInvoiceSdk\Jobs\CancelDocument;
use EInvoiceSdk\Jobs\PollDocumentStatus;
use EInvoiceSdk\Jobs\SubmitDocument;
use EInvoiceSdk\Models\EInvoiceDocument;
use EInvoiceSdk\Models\EInvoiceSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Entry point for host apps. Resolve with app(EInvoice::class). Nothing here calls LHDN except validateTin(). */
class EInvoice
{
    public function __construct(protected EInvoiceDriver $driver) {}

    /**
     * Readable problems that would stop submission.
     *
     * @return list<string>
     */
    public function validate(Document $document): array
    {
        $errors = [];

        if ($document->type->requiresOriginal() && (! $document->originalUuid || ! $document->originalNumber)) {
            $errors[] = 'Credit, debit and refund notes must reference the original document number and LHDN UUID.';
        }

        return [...$errors, ...$this->driver->validate($document)];
    }

    /**
     * Validate locally, record the document and queue the submission.
     * Safe to call repeatedly: a source record gets one LHDN document per environment.
     *
     * @throws ValidationException when the document fails local validation
     * @throws EInvoiceException when it is already submitted, valid or cancelled
     */
    public function submit(Model&EInvoiceable $model): EInvoiceDocument
    {
        $document = $model->toEInvoiceDocument();

        if ($errors = $this->validate($document)) {
            throw ValidationException::withMessages(['einvoice' => $errors]);
        }

        $setting = EInvoiceSetting::activeFor($document->supplier->tin);

        $record = EInvoiceDocument::firstOrCreate([
            'einvoiceable_type' => $model->getMorphClass(),
            'einvoiceable_id' => $model->getKey(),
            'environment' => $setting->environment,
        ], [
            'setting_id' => $setting->id,
            'type' => $document->type,
            'number' => $document->number,
            'status' => Status::Pending,
        ]);

        if (! $record->status->canSubmit()) {
            throw new EInvoiceException("E-invoice {$record->number} is already {$record->status->value}.");
        }

        $record->update(['setting_id' => $setting->id, 'type' => $document->type, 'number' => $document->number]);
        SubmitDocument::dispatch($record->id);

        return $record;
    }

    /** @throws EInvoiceException outside the 72-hour window; issue a credit note instead */
    public function cancel(EInvoiceDocument $record, string $reason): void
    {
        if (! $record->canCancel()) {
            throw new EInvoiceException($record->status === Status::Valid
                ? 'The 72-hour cancellation window has passed. Issue a credit note instead.'
                : "Only valid documents can be cancelled; this one is {$record->status->value}.");
        }

        CancelDocument::dispatch($record->id, $reason);
    }

    /** Re-check a document still waiting on LHDN. */
    public function poll(EInvoiceDocument $record): void
    {
        PollDocumentStatus::dispatch($record->id);
    }

    /**
     * Check a buyer's TIN against their ID before issuing. Calls LHDN synchronously.
     *
     * $idType is one of BRN, NRIC, PASSPORT, ARMY.
     */
    public function validateTin(string $supplierTin, string $tin, string $idType, string $idValue): bool
    {
        if (! in_array($idType, ['BRN', 'NRIC', 'PASSPORT', 'ARMY'], true)) {
            throw new EInvoiceException("Unknown ID type {$idType}.");
        }

        return $this->driver->validateTin(EInvoiceSetting::activeFor($supplierTin), $tin, $idType, $idValue);
    }
}
