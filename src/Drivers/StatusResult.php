<?php

namespace Oriclab\EInvoice\Drivers;

use DateTimeInterface;
use Oriclab\EInvoice\Enums\Status;

final readonly class StatusResult
{
    /**
     * @param  list<string>  $errors
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public Status $status,
        public ?string $longId = null,
        public ?DateTimeInterface $validatedAt = null,
        public array $errors = [],
        public array $raw = [],
    ) {}
}
