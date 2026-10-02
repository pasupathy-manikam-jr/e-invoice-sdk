<?php

namespace EInvoiceSdk\Enums;

enum Status: string
{
    case Pending = 'pending';
    case Submitted = 'submitted';
    case Valid = 'valid';
    case Invalid = 'invalid';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    /** States from which the same source record may be (re)submitted. */
    public function canSubmit(): bool
    {
        return in_array($this, [self::Pending, self::Invalid, self::Failed], true);
    }
}
