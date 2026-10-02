<?php

namespace Oriclab\EInvoice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $document_id
 * @property string $action submit|poll|cancel
 * @property bool $success
 * @property array<mixed>|null $request
 * @property array<mixed>|null $response
 * @property list<string>|null $errors
 */
class EInvoiceLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'einvoice_logs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'request' => 'array',
            'response' => 'array',
            'errors' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<EInvoiceDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(EInvoiceDocument::class, 'document_id');
    }
}
