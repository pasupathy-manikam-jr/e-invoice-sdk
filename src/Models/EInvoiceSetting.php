<?php

namespace EInvoiceSdk\Models;

use EInvoiceSdk\Enums\Environment;
use EInvoiceSdk\Exceptions\EInvoiceException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $tin
 * @property Environment $environment
 * @property bool $active
 * @property string $client_id
 * @property string $client_secret
 * @property bool $unsigned sandbox only: submit document version 1.0 without a signature
 * @property string|null $certificate PEM
 * @property string|null $private_key PEM
 */
class EInvoiceSetting extends Model
{
    protected $table = 'einvoice_settings';

    protected $guarded = ['id'];

    protected $hidden = ['client_id', 'client_secret', 'certificate', 'private_key'];

    protected function casts(): array
    {
        return [
            'environment' => Environment::class,
            'active' => 'boolean',
            'unsigned' => 'boolean',
            'client_id' => 'encrypted',
            'client_secret' => 'encrypted',
            'certificate' => 'encrypted',
            'private_key' => 'encrypted',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $setting) {
            if ($setting->unsigned && $setting->environment === Environment::Production) {
                throw new EInvoiceException('Production submissions must be signed.');
            }
        });
    }

    /** The settings new submissions for this TIN go through. */
    public static function activeFor(string $tin): self
    {
        return static::where('tin', $tin)->where('active', true)->first()
            ?? throw new EInvoiceException("No active e-invoice settings for supplier TIN {$tin}.");
    }

    /** Make this row the one used for new submissions of its TIN (e.g. switching a company to production). */
    public function activate(): void
    {
        DB::transaction(function () {
            static::where('tin', $this->tin)->whereKeyNot($this->id)->update(['active' => false]);
            $this->update(['active' => true]);
        });
    }
}
