<?php

namespace Oriclab\EInvoice\Enums;

/** LHDN e-invoice type codes. */
enum DocumentType: string
{
    case Invoice = '01';
    case CreditNote = '02';
    case DebitNote = '03';
    case RefundNote = '04';
    case SelfBilledInvoice = '11';
    case SelfBilledCreditNote = '12';
    case SelfBilledDebitNote = '13';
    case SelfBilledRefundNote = '14';

    /** Credit, debit and refund notes must reference the original document. */
    public function requiresOriginal(): bool
    {
        return ! in_array($this, [self::Invoice, self::SelfBilledInvoice], true);
    }
}
