<?php

namespace Oriclab\EInvoice\Tests;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Jiannius\Myinvois\Helpers\Signature;
use Jiannius\Myinvois\Helpers\UBL;
use Oriclab\EInvoice\Drivers\JianniusDriver;
use Oriclab\EInvoice\Enums\Environment;
use Oriclab\EInvoice\Enums\Status;
use Oriclab\EInvoice\Exceptions\EInvoiceException;
use Oriclab\EInvoice\Tests\Fixtures\TestInvoice;

/** Offline: our mapping must satisfy the SDK's validator and survive UBL build + signing. */
class JianniusDriverTest extends TestCase
{
    public function test_mapping_passes_sdk_validation(): void
    {
        $document = TestInvoice::make(['number' => 'INV-1', 'type' => '01', 'buyer_tin' => 'C20830570210'])->toEInvoiceDocument();

        $this->assertSame([], (new JianniusDriver)->validate($document));
    }

    public function test_sdk_rejects_incomplete_mapping(): void
    {
        $document = TestInvoice::make(['number' => 'CN-1', 'type' => '02', 'buyer_tin' => 'C20830570210'])->toEInvoiceDocument();

        $this->assertContains('Credit Note / Debit Note must have original document UUID', (new JianniusDriver)->validate($document));
    }

    public function test_mapped_document_builds_and_signs(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test', 'organizationName' => 'Oriclab', 'countryName' => 'MY'], $key), null, $key, 1);
        openssl_pkey_export($key, $keyPem);
        openssl_x509_export($cert, $certPem);

        $array = (new JianniusDriver)->toArray(TestInvoice::make(['number' => 'INV-1', 'type' => '01', 'buyer_tin' => 'C20830570210'])->toEInvoiceDocument());
        $signed = Signature::build(UBL::build($array), $keyPem, $certPem);

        $this->assertSame('INV-1', data_get($signed, 'Invoice.0.ID.0._'));
        $this->assertStringEndsWith('Z', data_get($signed, 'Invoice.0.IssueTime.0._'));
        $this->assertSame('530', (string) data_get($signed, 'Invoice.0.LegalMonetaryTotal.0.PayableAmount.0._'));
        $this->assertNotEmpty(data_get($signed, 'Invoice.0.UBLExtensions.0.UBLExtension.0.ExtensionContent.0.UBLDocumentSignatures.0.SignatureInformation.0.Signature.0.SignatureValue.0._'));
    }

    public function test_status_is_still_submitted_while_lhdn_returns_404(): void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/documents/UUID-1/details' => Http::response(['error' => ['code' => 'NotFound']], 404),
        ]);

        $this->assertSame(Status::Submitted, (new JianniusDriver)->status($this->setting(), 'UUID-1')->status);
    }

    public function test_status_maps_valid_details(): void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/documents/UUID-1/details' => Http::response(['uuid' => 'UUID-1', 'status' => 'Valid', 'longId' => 'LONG', 'dateTimeValidated' => '2026-10-02T07:15:40Z']),
        ]);

        $result = (new JianniusDriver)->status($this->setting(), 'UUID-1');

        $this->assertSame(Status::Valid, $result->status);
        $this->assertSame('LONG', $result->longId);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://preprod-api.myinvois.hasil.gov.my/'));
    }

    public function test_unsigned_sandbox_setting_submits_version_1_0_without_signature(): void
    {
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 't', 'expires_in' => 3600]),
            '*/documentsubmissions' => Http::response(['submissionUid' => 'SUB', 'acceptedDocuments' => [['uuid' => 'U1', 'invoiceCodeNumber' => 'INV-1']], 'rejectedDocuments' => []], 202),
        ]);
        $setting = $this->setting();
        $setting->update(['unsigned' => true, 'certificate' => null, 'private_key' => null]);

        $result = (new JianniusDriver)->submit($setting, TestInvoice::make(['number' => 'INV-1'])->toEInvoiceDocument());

        $this->assertTrue($result->accepted);
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/documentsubmissions')) {
                return false;
            }
            $ubl = json_decode(base64_decode($request['documents'][0]['document']), true);

            return data_get($ubl, 'Invoice.0.InvoiceTypeCode.0.listVersionID') === '1.0'
                && ! isset($ubl['Invoice'][0]['UBLExtensions']);
        });
    }

    public function test_signed_setting_without_certificate_is_a_clear_error(): void
    {
        $setting = $this->setting();
        $setting->update(['certificate' => null, 'private_key' => null]);

        $this->expectExceptionMessage('no signing certificate');
        (new JianniusDriver)->submit($setting, TestInvoice::make(['number' => 'INV-1'])->toEInvoiceDocument());
    }

    public function test_production_cannot_be_unsigned(): void
    {
        $this->expectException(EInvoiceException::class);
        $this->setting(Environment::Production)->update(['unsigned' => true]);
    }

    public function test_token_is_cached_as_a_string_so_hardened_caches_can_read_it(): void
    {
        config(['cache.default' => 'file', 'cache.serializable_classes' => false]);
        Cache::flush(); // the file store outlives the test run
        Http::fake([
            '*/connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/documents/UUID-1/details' => Http::response(['status' => 'Submitted']),
        ]);
        $setting = $this->setting();

        (new JianniusDriver)->status($setting, 'UUID-1');
        (new JianniusDriver)->status($setting, 'UUID-1');

        Http::assertSentCount(3); // one token request, two status calls
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer tok'));
    }
}
