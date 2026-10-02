<?php

namespace Oriclab\EInvoice\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Oriclab\EInvoice\Contracts\EInvoiceDriver;
use Oriclab\EInvoice\Enums\Status;
use Oriclab\EInvoice\Events\DocumentCancelled;
use Oriclab\EInvoice\Events\DocumentRejected;
use Oriclab\EInvoice\Events\DocumentValidated;
use Oriclab\EInvoice\Models\EInvoiceDocument;

/** Polls LHDN until the document leaves "submitted". Re-dispatch it (EInvoice::poll) if all attempts run out. */
class PollDocumentStatus implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 10;

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
        $record = EInvoiceDocument::with('setting')->find($this->documentId);

        if ($record?->status !== Status::Submitted || ! $record->uuid) {
            return;
        }

        $result = $driver->status($record->setting, $record->uuid);

        if ($result->status === Status::Submitted) {
            $this->release(min(30 * $this->attempts(), 300));

            return;
        }

        $record->log('poll', $result->status === Status::Valid, ['uuid' => $record->uuid], $result->raw, $result->errors);

        match ($result->status) {
            Status::Valid => $this->valid($record, $result->longId, $result->validatedAt),
            Status::Cancelled => $this->cancelled($record),
            default => $this->invalid($record, $result->errors),
        };
    }

    protected function valid(EInvoiceDocument $record, ?string $longId, ?\DateTimeInterface $validatedAt): void
    {
        $record->update(['status' => Status::Valid, 'long_id' => $longId, 'validated_at' => $validatedAt ?? now()]);
        DocumentValidated::dispatch($record);
    }

    protected function cancelled(EInvoiceDocument $record): void
    {
        $record->update(['status' => Status::Cancelled, 'cancelled_at' => now()]);
        DocumentCancelled::dispatch($record);
    }

    /** @param  list<string>  $errors */
    protected function invalid(EInvoiceDocument $record, array $errors): void
    {
        $record->update(['status' => Status::Invalid]);
        DocumentRejected::dispatch($record, $errors);
    }
}
