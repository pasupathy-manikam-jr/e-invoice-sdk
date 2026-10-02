<?php

namespace Oriclab\EInvoice\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Oriclab\EInvoice\Concerns\HasEInvoices;
use Oriclab\EInvoice\Contracts\EInvoiceable;
use Oriclab\EInvoice\Data\Document;
use Oriclab\EInvoice\Data\LineItem;
use Oriclab\EInvoice\Data\Party;
use Oriclab\EInvoice\Data\Tax;
use Oriclab\EInvoice\Enums\DocumentType;

/**
 * @property string $number
 * @property string $type
 * @property string|null $original_number
 * @property string|null $original_uuid
 * @property string $buyer_tin
 */
class TestInvoice extends Model implements EInvoiceable
{
    use HasEInvoices;

    public const SUPPLIER_TIN = 'C26561325060';

    public $timestamps = false;

    protected $guarded = [];

    protected $attributes = ['type' => '01', 'buyer_tin' => 'C20830570210'];

    public function toEInvoiceDocument(): Document
    {
        $tax = new Tax('01', 30, 500, 6);

        return new Document(
            type: DocumentType::from($this->type),
            number: $this->number,
            issuedAt: Carbon::now('Asia/Kuala_Lumpur'),
            supplier: new Party(
                name: 'Oriclab Sdn Bhd', tin: self::SUPPLIER_TIN, brn: '202101001341', phone: '+60123456789',
                addressLine1: 'Lot 66', postcode: '50480', city: 'Kuala Lumpur', state: '14',
                msicCode: '62010', msicDescription: 'Computer programming activities',
            ),
            buyer: new Party(
                name: 'Buyer Sdn Bhd', tin: $this->buyer_tin, brn: '201901000005', phone: '+60123456780',
                addressLine1: 'Jalan 1', postcode: '10000', city: 'George Town', state: '07',
            ),
            lines: [new LineItem('Consulting', 1, 500, 500, ['022'], [$tax], 'C62')],
            taxes: [$tax],
            subtotal: 500,
            grandTotal: 530,
            originalNumber: $this->original_number,
            originalUuid: $this->original_uuid,
        );
    }
}
