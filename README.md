# laravel-einvoice

Submit e-invoices to Malaysia's LHDN MyInvois from any Laravel 13 app (PHP 8.4+). Each company submits under its own TIN, credentials and signing certificate.

Under the hood it uses [`jiannius/myinvois`](https://github.com/jiannius/myinvois) for UBL building, XAdES signing and auth, behind our own `EInvoiceDriver` interface so the SDK can be swapped without touching host apps.

## Install

```bash
composer require pasupathy-manikam-jr/laravel-einvoice
php artisan migrate
```

Recommended: stop the SDK auto-loading its own `myinvois_documents` migration (this package never uses that table). In the host app's `composer.json`:

```json
"extra": { "laravel": { "dont-discover": ["jiannius/myinvois"] } }
```

If the app uses Laravel Fortify (or anything else needing `bacon/bacon-qr-code` ^3), Composer will refuse to install: the SDK pulls in `simplesoftwareio/simple-qrcode`, which needs bacon ^2, only for a model trait this package never uses. Tell Composer the app provides it:

```json
"replace": { "simplesoftwareio/simple-qrcode": "*" }
```

Run a queue worker. Everything that talks to LHDN runs in queued jobs.

## Company settings

One `einvoice_settings` row per company per environment. Credentials are stored with Laravel's encrypted casts.

```php
use Oriclab\EInvoice\Models\EInvoiceSetting;

EInvoiceSetting::create([
    'tin' => 'C12345678901',
    'environment' => 'sandbox',          // default; production is a separate row
    'client_id' => '...',
    'client_secret' => '...',
    'certificate' => file_get_contents('cert.pem'),   // PEM
    'private_key' => file_get_contents('key.pem'),    // PEM
]);
```

LHDN issues the signing certificate as a `.p12`. Convert it once: `openssl pkcs12 -in cert.p12 -clcerts -nokeys -out cert.pem` and `openssl pkcs12 -in cert.p12 -nocerts -nodes -out key.pem`. Never commit either file.

No CA-issued certificate yet? A **sandbox** row can set `'unsigned' => true` (certificate and key may then be null) to submit document version 1.0 without a signature. Production rows refuse this.

To go live for a company, create its `production` row and call `$setting->activate()`. Documents already submitted keep using the settings they were submitted with, so sandbox results never touch production state.

## Host model

```php
use Oriclab\EInvoice\Concerns\HasEInvoices;
use Oriclab\EInvoice\Contracts\EInvoiceable;
use Oriclab\EInvoice\Data\{Document, LineItem, Party, Tax};
use Oriclab\EInvoice\Enums\DocumentType;

class Invoice extends Model implements EInvoiceable
{
    use HasEInvoices; // $invoice->einvoiceDocuments()

    public function toEInvoiceDocument(): Document
    {
        $tax = new Tax(code: '01', amount: 30, taxableAmount: 500, rate: 6);

        return new Document(
            type: DocumentType::Invoice,
            number: $this->number,
            issuedAt: $this->issued_at,
            supplier: new Party(name: 'Acme Sdn Bhd', tin: 'C12345678901', brn: '202101001341',
                phone: '+60123456789', addressLine1: 'Lot 66', city: 'Kuala Lumpur', state: '14',
                msicCode: '62010', msicDescription: 'Computer programming activities'),
            buyer: new Party(name: $this->customer->name, tin: $this->customer->tin, brn: $this->customer->brn,
                phone: $this->customer->phone, addressLine1: $this->customer->address, city: 'George Town', state: '07'),
            lines: [new LineItem('Consulting', quantity: 1, unitPrice: 500, subtotal: 500,
                classificationCodes: ['022'], taxes: [$tax], unit: 'C62')],
            taxes: [$tax],
            subtotal: 500,
            grandTotal: 530,
        );
    }
}
```

Codes (states, countries, taxes, classifications, units, MSIC) follow the LHDN code tables. For forms, `Oriclab\EInvoice\Codes` gives `classifications()`, `taxTypes()` and `states()` as `code => label`, and `Codes::state('W.P. Kuala Lumpur')` resolves loosely written state names (the driver does this for you).

- **Credit / debit / refund notes**: set `originalNumber` and `originalUuid` (the original's `EInvoiceDocument::uuid`).
- **Consolidated monthly submission**: a host model for the month's batch, buyer TIN `EI00000000010`, every line classified `004`, `consolidated: true`.

## Usage

```php
use Oriclab\EInvoice\EInvoice;

$einvoice = app(EInvoice::class);

$einvoice->validate($invoice->toEInvoiceDocument()); // list of readable errors, no network
$record = $einvoice->submit($invoice);               // validates, records, queues; throws ValidationException on errors
$einvoice->cancel($record, 'Wrong buyer');           // within 72h of validation, else EInvoiceException
$einvoice->poll($record);                            // re-check a document still "submitted"
$einvoice->validateTin('C12345678901', $buyerTin, 'BRN', $buyerBrn); // synchronous LHDN call

$record->status;          // Status enum: pending, submitted, valid, invalid, cancelled, failed
$record->validationUrl(); // put this in the invoice PDF's QR code
$record->logs;            // every attempt with request summary, raw response and errors
```

`submit()` is idempotent: one LHDN document per source record per environment. It refuses when the document is already submitted, valid or cancelled, and allows a retry after `invalid` or `failed`. Before retrying a `failed` submission it searches LHDN for the document number, so a request that reached LHDN before the connection dropped is recovered rather than submitted twice.

## Events

| Event | When |
|---|---|
| `DocumentSubmitted` | LHDN accepted the submission (validation pending) |
| `DocumentValidated` | Document is valid; `uuid`, `long_id`, `validationUrl()` available |
| `DocumentRejected` | LHDN rejected it; `$event->errors` lists why |
| `DocumentCancelled` | Cancelled via the package or the MyInvois portal |

All are in `Oriclab\EInvoice\Events` and carry `$event->document` (an `EInvoiceDocument`).

## Testing in host apps

Set `EINVOICE_DRIVER=fake`, or bind `Oriclab\EInvoice\Drivers\FakeDriver` to `Oriclab\EInvoice\Contracts\EInvoiceDriver` and script its public properties (`submitResult`, `statusResult`, `existingUuid`, ...).

## Development

```bash
composer ci:check   # pint, larastan, phpunit (no network or credentials needed)
```
