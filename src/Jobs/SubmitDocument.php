<?php

namespace Oriclab\EInvoice\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Oriclab\EInvoice\Contracts\EInvoiceDriver;
use Oriclab\EInvoice\Enums\Status;
use Oriclab\EInvoice\Events\DocumentRejected;
use Oriclab\EInvoice\Events\DocumentSubmitted;
use Oriclab\EInvoice\Models\EInvoiceDocument;
use Throwable;

class SubmitDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public int $documentId)
    {
        $this->onQueue(config('einvoice.queue'));
    }

    public function uniqueId(): string
    {
        return (string) $this->documentId;
    }

    public function handle(EInvoiceDriver $driver): void
    {
        $record = EInvoiceDocument::with('setting', 'einvoiceable')->find($this->documentId);

        if (! $record?->status->canSubmit()) {
            return;
        }

        $document = $record->einvoiceable->toEInvoiceDocument();
        $summary = ['number' => $document->number, 'type' => $document->type->value, 'grand_total' => $document->grandTotal, 'environment' => $record->environment->value];

        try {
            // A failed attempt may have reached LHDN before the connection dropped. Never create a second document.
            if ($record->status === Status::Failed && $uuid = $driver->findExisting($record->setting, $document)) {
                $record->update(['status' => Status::Submitted, 'uuid' => $uuid, 'submitted_at' => now()]);
                $record->log('recover', true, $summary, ['uuid' => $uuid]);
                PollDocumentStatus::dispatch($record->id)->delay(now()->addSeconds(10));

                return;
            }

            $result = $driver->submit($record->setting, $document);
        } catch (Throwable $e) {
            $record->update(['status' => Status::Failed]);
            $record->log('submit', false, $summary, null, [$e->getMessage()]);

            throw $e;
        }

        $record->log('submit', $result->accepted, $summary, $result->raw, $result->errors);

        if (! $result->accepted) {
            $record->update(['status' => Status::Invalid]);
            DocumentRejected::dispatch($record, $result->errors);

            return;
        }

        $record->update([
            'status' => Status::Submitted,
            'uuid' => $result->uuid,
            'submission_uid' => $result->submissionUid,
            'submitted_at' => now(),
            'long_id' => null,
            'validated_at' => null,
        ]);
        DocumentSubmitted::dispatch($record);
        PollDocumentStatus::dispatch($record->id)->delay(now()->addSeconds(10));
    }
}
