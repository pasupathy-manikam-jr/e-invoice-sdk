<?php

namespace EInvoiceSdk\Tests;

use EInvoiceSdk\Drivers\StatusResult;
use EInvoiceSdk\Drivers\SubmitResult;
use EInvoiceSdk\EInvoice;
use EInvoiceSdk\Enums\Environment;
use EInvoiceSdk\Enums\Status;
use EInvoiceSdk\Events\DocumentCancelled;
use EInvoiceSdk\Events\DocumentRejected;
use EInvoiceSdk\Events\DocumentSubmitted;
use EInvoiceSdk\Events\DocumentValidated;
use EInvoiceSdk\Exceptions\EInvoiceException;
use EInvoiceSdk\Models\EInvoiceDocument;
use EInvoiceSdk\Tests\Fixtures\TestInvoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class SubmissionFlowTest extends TestCase
{
    private function einvoice(): EInvoice
    {
        return app(EInvoice::class);
    }

    public function test_submit_polls_to_valid_and_fires_events(): void
    {
        Event::fake();
        $this->setting();
        $invoice = TestInvoice::create(['number' => 'INV-1']);

        $record = $this->einvoice()->submit($invoice)->refresh();

        $this->assertSame(Status::Valid, $record->status);
        $this->assertNotNull($record->uuid);
        $this->assertNotNull($record->validated_at);
        $this->assertSame("https://preprod.myinvois.hasil.gov.my/{$record->uuid}/share/LONG-{$record->uuid}", $record->validationUrl());
        $this->assertTrue($invoice->einvoiceDocuments()->first()->is($record));
        Event::assertDispatched(DocumentSubmitted::class);
        Event::assertDispatched(DocumentValidated::class);
        $this->assertSame(['submit', 'poll'], $record->logs()->pluck('action')->all());
    }

    public function test_local_validation_errors_stop_submission(): void
    {
        $this->setting();
        $this->driver->validationErrors = ['Buyer TIN is required'];

        try {
            $this->einvoice()->submit(TestInvoice::create(['number' => 'INV-1']));
            $this->fail('Expected validation exception');
        } catch (ValidationException $e) {
            $this->assertSame(['Buyer TIN is required'], $e->errors()['einvoice']);
        }

        $this->assertSame(0, EInvoiceDocument::count());
        $this->assertSame([], $this->driver->submitted);
    }

    public function test_credit_note_must_reference_original(): void
    {
        $this->setting();
        $note = TestInvoice::create(['number' => 'CN-1', 'type' => '02']);

        $this->assertNotEmpty($this->einvoice()->validate($note->toEInvoiceDocument()));

        $note->update(['original_number' => 'INV-1', 'original_uuid' => 'UUID-1']);
        $this->assertSame([], $this->einvoice()->validate($note->toEInvoiceDocument()));
    }

    public function test_rejection_marks_invalid_and_allows_resubmission(): void
    {
        Event::fake([DocumentRejected::class]);
        $this->setting();
        $invoice = TestInvoice::create(['number' => 'INV-1']);

        $this->driver->statusResult = new StatusResult(Status::Invalid, errors: ['Invoice.TaxTotal: wrong total']);
        $record = $this->einvoice()->submit($invoice)->refresh();

        $this->assertSame(Status::Invalid, $record->status);
        Event::assertDispatched(DocumentRejected::class, fn ($e) => $e->errors === ['Invoice.TaxTotal: wrong total']);

        $this->driver->statusResult = null;
        $this->assertSame(Status::Valid, $this->einvoice()->submit($invoice)->refresh()->status);
        $this->assertSame(1, EInvoiceDocument::count());
    }

    public function test_submission_rejected_by_lhdn_is_invalid(): void
    {
        $this->setting();
        $this->driver->submitResult = new SubmitResult(false, errors: ['Duplicate document']);

        $record = $this->einvoice()->submit(TestInvoice::create(['number' => 'INV-1']))->refresh();

        $this->assertSame(Status::Invalid, $record->status);
        $this->assertSame(['Duplicate document'], $record->logs()->first()->errors);
    }

    public function test_valid_document_is_never_submitted_twice(): void
    {
        $this->setting();
        $invoice = TestInvoice::create(['number' => 'INV-1']);
        $this->einvoice()->submit($invoice);

        $this->expectException(EInvoiceException::class);
        try {
            $this->einvoice()->submit($invoice);
        } finally {
            $this->assertCount(1, $this->driver->submitted);
        }
    }

    public function test_transport_failure_retry_recovers_existing_document_instead_of_duplicating(): void
    {
        $this->setting();
        $invoice = TestInvoice::create(['number' => 'INV-1']);
        $this->driver->submitResult = new RuntimeException('Connection timed out');

        try {
            $this->einvoice()->submit($invoice);
        } catch (RuntimeException) {
        }
        $record = EInvoiceDocument::sole();
        $this->assertSame(Status::Failed, $record->status);

        // LHDN had in fact received it.
        $this->driver->submitResult = null;
        $this->driver->existingUuid = 'UUID-EXISTING';
        $this->einvoice()->submit($invoice);

        $record->refresh();
        $this->assertSame(Status::Valid, $record->status);
        $this->assertSame('UUID-EXISTING', $record->uuid);
        $this->assertCount(1, $this->driver->submitted);
    }

    public function test_transport_failure_retry_submits_when_lhdn_has_nothing(): void
    {
        $this->setting();
        $invoice = TestInvoice::create(['number' => 'INV-1']);
        $this->driver->submitResult = new RuntimeException('Connection refused');
        try {
            $this->einvoice()->submit($invoice);
        } catch (RuntimeException) {
        }

        $this->driver->submitResult = null;
        $this->assertSame(Status::Valid, $this->einvoice()->submit($invoice)->refresh()->status);
        $this->assertCount(2, $this->driver->submitted);
    }

    public function test_cancel_within_window(): void
    {
        Event::fake([DocumentCancelled::class]);
        $this->setting();
        $record = $this->einvoice()->submit(TestInvoice::create(['number' => 'INV-1']))->refresh();

        $this->einvoice()->cancel($record, 'Wrong buyer');

        $record->refresh();
        $this->assertSame(Status::Cancelled, $record->status);
        $this->assertSame('Wrong buyer', $record->cancel_reason);
        $this->assertSame([$record->uuid], $this->driver->cancelled);
        Event::assertDispatched(DocumentCancelled::class);
    }

    public function test_cancel_outside_window_is_refused(): void
    {
        $this->setting();
        $record = $this->einvoice()->submit(TestInvoice::create(['number' => 'INV-1']))->refresh();
        $record->update(['validated_at' => now()->subHours(73)]);

        $this->expectExceptionMessage('credit note');
        try {
            $this->einvoice()->cancel($record, 'Too late');
        } finally {
            $this->assertSame([], $this->driver->cancelled);
        }
    }

    public function test_sandbox_and_production_documents_never_share_state(): void
    {
        $this->setting();
        $invoice = TestInvoice::create(['number' => 'INV-1']);
        $sandbox = $this->einvoice()->submit($invoice)->refresh();

        $this->setting(Environment::Production)->activate();
        $production = $this->einvoice()->submit($invoice)->refresh();

        $this->assertNotSame($sandbox->id, $production->id);
        $this->assertSame(Environment::Production, $production->environment);
        $this->assertSame('https://myinvois.hasil.gov.my', substr((string) $production->validationUrl(), 0, 29));
        $this->assertEquals($sandbox->getAttributes(), $sandbox->fresh()?->getAttributes());
    }

    public function test_missing_settings_is_a_clear_error(): void
    {
        $this->expectExceptionMessage('No active e-invoice settings for supplier TIN '.TestInvoice::SUPPLIER_TIN);
        $this->einvoice()->submit(TestInvoice::create(['number' => 'INV-1']));
    }

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $this->setting();
        $raw = DB::table('einvoice_settings')->first();

        $this->assertNotSame('secret', $raw->client_secret);
        $this->assertSame('secret', decrypt($raw->client_secret, false));
    }

    public function test_validate_tin(): void
    {
        $this->setting();
        $this->assertTrue($this->einvoice()->validateTin(TestInvoice::SUPPLIER_TIN, 'C20830570210', 'BRN', '201901000005'));

        $this->expectException(EInvoiceException::class);
        $this->einvoice()->validateTin(TestInvoice::SUPPLIER_TIN, 'C20830570210', 'FOO', '1');
    }
}
