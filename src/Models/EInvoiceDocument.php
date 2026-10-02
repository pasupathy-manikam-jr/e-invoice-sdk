<?php

namespace Oriclab\EInvoice\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Oriclab\EInvoice\Contracts\EInvoiceable;
use Oriclab\EInvoice\Enums\DocumentType;
use Oriclab\EInvoice\Enums\Environment;
use Oriclab\EInvoice\Enums\Status;

/**
 * @property int $id
 * @property string $einvoiceable_type
 * @property int $einvoiceable_id
 * @property int $setting_id
 * @property Environment $environment
 * @property DocumentType $type
 * @property string $number
 * @property Status $status
 * @property string|null $uuid
 * @property string|null $submission_uid
 * @property string|null $long_id
 * @property Carbon|null $submitted_at
 * @property Carbon|null $validated_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property-read EInvoiceSetting $setting
 * @property-read Model&EInvoiceable $einvoiceable
 */
class EInvoiceDocument extends Model
{
    /** LHDN allows cancelling a validated document for this many hours. */
    public const CANCEL_WINDOW_HOURS = 72;

    protected $table = 'einvoice_documents';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'type' => DocumentType::class,
            'status' => Status::class,
            'submitted_at' => 'datetime',
            'validated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function einvoiceable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<EInvoiceSetting, $this> */
    public function setting(): BelongsTo
    {
        return $this->belongsTo(EInvoiceSetting::class, 'setting_id');
    }

    /** @return HasMany<EInvoiceLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(EInvoiceLog::class, 'document_id');
    }

    /** Public validation link; encode it as the QR code on the printed invoice. */
    public function validationUrl(): ?string
    {
        return $this->uuid && $this->long_id
            ? $this->environment->portalUrl()."/{$this->uuid}/share/{$this->long_id}"
            : null;
    }

    public function canCancel(): bool
    {
        return $this->status === Status::Valid
            && $this->validated_at?->copy()->addHours(self::CANCEL_WINDOW_HOURS)->isFuture();
    }

    /**
     * @param  array<mixed>|null  $request
     * @param  array<mixed>|null  $response
     * @param  list<string>  $errors
     */
    public function log(string $action, bool $success, ?array $request = null, ?array $response = null, array $errors = []): EInvoiceLog
    {
        return $this->logs()->create([
            'action' => $action,
            'success' => $success,
            'request' => $request,
            'response' => $response,
            'errors' => $errors ?: null,
            'created_at' => now(),
        ]);
    }
}
