<?php

namespace Oriclab\EInvoice\Drivers;

use Illuminate\Support\Str;
use Oriclab\EInvoice\Contracts\EInvoiceDriver;
use Oriclab\EInvoice\Data\Document;
use Oriclab\EInvoice\Enums\Status;
use Oriclab\EInvoice\Exceptions\EInvoiceException;
use Oriclab\EInvoice\Models\EInvoiceSetting;
use Throwable;

/** In-memory driver for tests and local development. Set the public properties to script LHDN's answers. */
class FakeDriver implements EInvoiceDriver
{
    /** @var list<string> */
    public array $validationErrors = [];

    public SubmitResult|Throwable|null $submitResult = null;

    public ?StatusResult $statusResult = null;

    public ?string $existingUuid = null;

    public bool $tinValid = true;

    public ?string $cancelError = null;

    /** @var list<Document> */
    public array $submitted = [];

    /** @var list<string> */
    public array $cancelled = [];

    public function validate(Document $document): array
    {
        return $this->validationErrors;
    }

    public function submit(EInvoiceSetting $setting, Document $document): SubmitResult
    {
        $this->submitted[] = $document;

        if ($this->submitResult instanceof Throwable) {
            throw $this->submitResult;
        }

        return $this->submitResult ?? new SubmitResult(true, 'SUB-'.Str::upper(Str::random(10)), 'UUID-'.Str::upper(Str::random(10)));
    }

    public function status(EInvoiceSetting $setting, string $uuid): StatusResult
    {
        return $this->statusResult ?? new StatusResult(Status::Valid, 'LONG-'.$uuid, now());
    }

    /** @return array<mixed> */
    public function cancel(EInvoiceSetting $setting, string $uuid, string $reason): array
    {
        if ($this->cancelError) {
            throw new EInvoiceException($this->cancelError);
        }

        $this->cancelled[] = $uuid;

        return ['uuid' => $uuid, 'status' => 'Cancelled'];
    }

    public function validateTin(EInvoiceSetting $setting, string $tin, string $idType, string $idValue): bool
    {
        return $this->tinValid;
    }

    public function findExisting(EInvoiceSetting $setting, Document $document): ?string
    {
        return $this->existingUuid;
    }
}
