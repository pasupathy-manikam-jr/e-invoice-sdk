<?php

namespace Oriclab\EInvoice\Drivers;

final readonly class SubmitResult
{
    /**
     * @param  list<string>  $errors
     * @param  array<mixed>  $raw
     */
    public function __construct(
        public bool $accepted,
        public ?string $submissionUid = null,
        public ?string $uuid = null,
        public array $errors = [],
        public array $raw = [],
    ) {}
}
