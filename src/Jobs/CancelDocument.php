<?php

namespace Oriclab\EInvoice\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Oriclab\EInvoice\Contracts\EInvoiceDriver;
use Oriclab\EInvoice\Enums\Status;
use Oriclab\EInvoice\Events\DocumentCancelled;
use Oriclab\EInvoice\Models\EInvoiceDocument;
use Throwable;

class CancelDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $documentId, public string $reason)
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

        if (! $record || ! $record->uuid) {
            return;
        }

        if (! $record->canCancel()) {
            $record->log('cancel', false, ['reason' => $this->reason], null, ['Outside the 72-hour cancellation window or not valid. Issue a credit note instead.']);

            return;
        }

        try {
            $response = $driver->cancel($record->setting, $record->uuid, $this->reason);
        } catch (Throwable $e) {
            $record->log('cancel', false, ['reason' => $this->reason], null, [$e->getMessage()]);

            throw $e;
        }

        $record->log('cancel', true, ['reason' => $this->reason], $response);
        $record->update(['status' => Status::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $this->reason]);
        DocumentCancelled::dispatch($record);
    }
}
