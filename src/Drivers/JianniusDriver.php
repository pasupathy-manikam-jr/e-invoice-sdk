<?php

namespace Oriclab\EInvoice\Drivers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Jiannius\Myinvois\Helpers\Code;
use Jiannius\Myinvois\Helpers\Signature;
use Jiannius\Myinvois\Helpers\UBL;
use Jiannius\Myinvois\Helpers\Validator;
use Oriclab\EInvoice\Codes;
use Oriclab\EInvoice\Contracts\EInvoiceDriver;
use Oriclab\EInvoice\Data\Document;
use Oriclab\EInvoice\Data\LineItem;
use Oriclab\EInvoice\Data\Party;
use Oriclab\EInvoice\Data\Tax;
use Oriclab\EInvoice\Enums\Environment;
use Oriclab\EInvoice\Enums\Status;
use Oriclab\EInvoice\Exceptions\EInvoiceException;
use Oriclab\EInvoice\Models\EInvoiceSetting;

/**
 * Wraps jiannius/myinvois. We use its UBL builder, XAdES signer, validator, token cache and rate limiter,
 * but call the endpoints ourselves: its submitDocuments() sleeps to poll inline and writes to its own
 * myinvois_documents table, and its read methods hide HTTP failures.
 */
class JianniusDriver implements EInvoiceDriver
{
    public function validate(Document $document): array
    {
        return array_values(app(Validator::class)->build($this->toArray($document))->errors()->all());
    }

    public function submit(EInvoiceSetting $setting, Document $document): SubmitResult
    {
        $json = (string) json_encode($this->build($setting, $document));

        $response = $this->call($setting, 'documentsubmissions', 'POST', [
            'documents' => [[
                'format' => 'JSON',
                'document' => base64_encode($json),
                'documentHash' => hash('sha256', $json),
                'codeNumber' => $document->number,
            ]],
        ], 60);

        // 422 = duplicate submission: an earlier identical request got through, so retry and let findExisting() recover it.
        if ($response->status() === 422) {
            $response->throw();
        }

        $body = $response->json() ?? [];

        if ($accepted = data_get($body, 'acceptedDocuments.0')) {
            return new SubmitResult(true, data_get($body, 'submissionUid'), data_get($accepted, 'uuid'), raw: $body);
        }

        return new SubmitResult(false, errors: $this->errors(data_get($body, 'rejectedDocuments.0.error') ?? data_get($body, 'error') ?? $body), raw: $body);
    }

    /**
     * UBL JSON, signed (v1.1) or, for sandbox settings marked unsigned, unsigned (v1.0).
     *
     * @return array<mixed>
     */
    protected function build(EInvoiceSetting $setting, Document $document): array
    {
        $data = $this->toArray($document);

        if ($setting->unsigned && $setting->environment === Environment::Sandbox) {
            return UBL::build([...$data, 'document_version' => '1.0']);
        }

        if (! $setting->private_key || ! $setting->certificate) {
            throw new EInvoiceException("E-invoice settings for {$setting->tin} have no signing certificate.");
        }

        return Signature::build(UBL::build($data), $setting->private_key, $setting->certificate);
    }

    public function status(EInvoiceSetting $setting, string $uuid): StatusResult
    {
        $response = $this->call($setting, "documents/{$uuid}/details", perMinute: 60);

        // LHDN answers 404 for a few seconds after accepting a submission, until the document is indexed.
        if ($response->notFound()) {
            return new StatusResult(Status::Submitted, raw: $response->json() ?? []);
        }

        $response->throw();
        $body = $response->json() ?? [];

        $errors = collect((array) data_get($body, 'validationResults.validationSteps', []))
            ->where('status', 'Invalid')
            ->flatMap(fn ($step) => $this->errors(data_get($step, 'error')))
            ->values()
            ->all();

        $validatedAt = data_get($body, 'dateTimeValidated');

        return new StatusResult(
            status: Status::tryFrom(strtolower((string) data_get($body, 'status'))) ?? Status::Submitted,
            longId: data_get($body, 'longId') ?: null,
            validatedAt: $validatedAt ? Carbon::parse($validatedAt) : null,
            errors: $errors,
            raw: $body,
        );
    }

    /** @return array<mixed> */
    public function cancel(EInvoiceSetting $setting, string $uuid, string $reason): array
    {
        $response = $this->call($setting, "documents/state/{$uuid}/state", 'PUT', ['status' => 'cancelled', 'reason' => $reason], 12);

        if ($response->failed()) {
            throw new EInvoiceException('LHDN refused the cancellation: '.implode('; ', $this->errors(data_get($response->json(), 'error') ?? $response->json())));
        }

        return $response->json() ?? [];
    }

    public function validateTin(EInvoiceSetting $setting, string $tin, string $idType, string $idValue): bool
    {
        $response = $this->call($setting, 'taxpayer/validate/'.rawurlencode($tin), data: ['idType' => $idType, 'idValue' => $idValue], perMinute: 60);

        if ($response->serverError()) {
            $response->throw();
        }

        return $response->successful();
    }

    public function findExisting(EInvoiceSetting $setting, Document $document): ?string
    {
        $issued = Carbon::instance($document->issuedAt)->utc();

        $response = $this->call($setting, 'documents/search', data: [
            'issueDateFrom' => $issued->copy()->subDay()->format('Y-m-d\TH:i:s\Z'),
            'issueDateTo' => $issued->copy()->addDay()->format('Y-m-d\TH:i:s\Z'),
            'invoiceDirection' => 'Sent',
            'searchQuery' => $document->number,
            'pageSize' => 100,
        ], perMinute: 12);
        $response->throw();

        $match = collect((array) data_get($response->json(), 'result', []))
            ->first(fn ($doc) => data_get($doc, 'internalId') === $document->number
                && in_array(data_get($doc, 'status'), ['Valid', 'Submitted'], true));

        return data_get($match, 'uuid');
    }

    /** @param  array<mixed>  $data */
    protected function call(EInvoiceSetting $setting, string $uri, string $method = 'GET', array $data = [], ?int $perMinute = null): Response
    {
        $client = (new MyinvoisClient)
            ->setClientId($setting->client_id)
            ->setClientSecret($setting->client_secret)
            ->setPrivateKey($setting->private_key)
            ->setCertificate($setting->certificate)
            ->setPreprod($setting->environment !== Environment::Production);

        /** @var Response $response */
        $response = $client->callApi($uri, $method, $data, $perMinute);

        // Auth, rate limit and server errors are transport problems: throw so the job retries.
        if ($response->serverError() || in_array($response->status(), [401, 429], true)) {
            $response->throw();
        }

        return $response;
    }

    /**
     * Flatten LHDN's nested {message|error, details|innerError} error shapes into readable lines.
     *
     * @return list<string>
     */
    protected function errors(mixed $error): array
    {
        if (! is_array($error)) {
            return $error ? [(string) $error] : [];
        }

        $children = data_get($error, 'details') ?? data_get($error, 'innerError');
        if (is_array($children) && $children) {
            return collect($children)->flatMap(fn ($child) => $this->errors($child))->values()->all();
        }

        $message = data_get($error, 'message') ?? data_get($error, 'error');
        $target = data_get($error, 'propertyPath') ?? data_get($error, 'target');

        return $message ? [($target ? "{$target}: " : '').$message] : [(string) json_encode($error)];
    }

    /**
     * Map our document to the SDK's array shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(Document $document): array
    {
        $label = Code::documentTypes()->label($document->type->value);

        return [
            'number' => $document->number,
            // LHDN requires the issue time in UTC.
            'issued_at' => Carbon::instance($document->issuedAt)->utc(),
            'document_type' => $document->type->value,
            'document_version' => Code::documentVersions()->value($label),
            'currency' => $document->currency,
            'currency_rate' => $document->currencyRate,
            'payment_mode' => $document->paymentMode,
            'payment_term' => $document->paymentTerms,
            'original_number' => $document->originalNumber,
            'original_document_uuid' => $document->originalUuid,
            'is_consolidate' => $document->consolidated,
            'supplier' => $this->party($document->supplier),
            'buyer' => $this->party($document->buyer),
            'taxes' => array_map($this->tax(...), $document->taxes),
            'subtotal' => $document->subtotal,
            'grand_total' => $document->grandTotal,
            'payable_total' => $document->payableTotal ?? $document->grandTotal,
            'line_items' => array_map(fn (LineItem $line) => [
                'description' => $line->description,
                'qty' => $line->quantity,
                'uom' => $line->unit,
                'unit_price' => $line->unitPrice,
                'subtotal' => $line->subtotal,
                'classifications' => array_map(fn ($code) => ['code' => $code], $line->classificationCodes),
                'taxes' => array_map($this->tax(...), $line->taxes),
                'discount' => ['amount' => $line->discount, 'description' => $line->discountDescription, 'rate' => null],
            ], $document->lines),
        ];
    }

    /** @return array<string, mixed> */
    protected function party(Party $party): array
    {
        return [
            'name' => $party->name,
            'tin' => $party->tin,
            'brn' => $party->brn,
            'nric' => $party->nric,
            'passport' => $party->passport,
            'army' => $party->army,
            'sst' => $party->sstNumber,
            'email' => $party->email,
            'phone' => $party->phone,
            'address_line_1' => $party->addressLine1,
            'address_line_2' => $party->addressLine2,
            'address_line_3' => $party->addressLine3,
            'postcode' => $party->postcode,
            'city' => $party->city,
            'state' => Codes::state($party->state) ?? $party->state,
            'country' => $party->country,
            'msic_code' => $party->msicCode,
            'msic_description' => $party->msicDescription,
        ];
    }

    /** @return array<string, mixed> */
    protected function tax(Tax $tax): array
    {
        return [
            'code' => $tax->code,
            'name' => Code::taxes()->label($tax->code),
            'amount' => $tax->amount,
            'taxable_amount' => $tax->taxableAmount,
            'rate' => $tax->rate,
            'exemption_reason' => $tax->exemptionReason,
        ];
    }
}
