# Brief: LHDN MyInvois e-invoicing package for Laravel

You are picking up a project that was planned in another conversation. This file is everything you need to start. Read it fully, then ask me the questions in "Ask me first" before writing code.

## What we are building

A standalone, reusable Laravel package that submits e-invoices to Malaysia's LHDN MyInvois system. It lives in its own public repo and is installed via Composer into our other apps.

We have a suite of public Laravel repos: HRMS, LMS, accounting, a manufacturing mini ERP, and legal management. Accounting is the first consumer, but other apps (the mini ERP, a future POS or CRM) will issue invoices too. That is why this is a separate package instead of code inside accounting: the LHDN logic should exist once.

Suggested folder: `~/Sites/laravel-einvoice` (create it if it does not exist).

## Ask me first

1. **Vendor and package name** for `composer.json` (for example `<vendor>/laravel-einvoice`) and the root PHP namespace.
2. **Laravel and PHP versions** of the accounting app. This decides which SDK release line we can use (see "Underlying SDK").
3. **Multi-tenancy**: does one install of the accounting app serve many companies, each with its own TIN and MyInvois credentials? The design below assumes yes. If it is one company per install, the settings table can collapse to config.
4. **Licence** for the repo (the SDK we wrap is MIT).

## How it works end to end

1. The host app finalises an invoice.
2. The host model maps itself to our document format (supplier, buyer, lines, taxes, classification codes).
3. Our package builds, signs, and submits the document to the MyInvois API.
4. LHDN validates asynchronously. We poll for the result.
5. We store the LHDN UUID, submission UID, status, and validation link, and fire an event so the host app can update its invoice and print the QR code on the PDF.

Submissions are made under each business's own TIN, with that business's client ID, client secret, and signing certificate. They are never made under ours.

## Architecture

**Contract for host models.** An `EInvoiceable` interface that any model (accounting invoice, POS sale, credit note) implements. It returns a plain data object describing the document. Keep this data object ours, not the SDK's array shape, so the host apps never depend on the SDK.

**Driver layer.** This is the most important design rule. Define our own `EInvoiceDriver` interface (submit, get status, cancel, validate TIN). Ship one implementation that wraps the SDK below, plus a fake driver for tests. Host apps and our jobs talk only to the interface. The LHDN spec changes often and the community SDKs are small, so we must be able to swap the SDK or write our own client without touching host apps.

**Our own tables**, linked to the source record polymorphically so host invoice tables stay clean:
- `einvoice_documents`: morph to source, document type, internal number, LHDN UUID, submission UID, long ID for the validation link, status, environment (sandbox or production), validated-at, cancelled-at, cancel reason.
- `einvoice_logs`: per-attempt request summary, raw response, and validation errors, for support and retry.
- `einvoice_settings`: per-company credentials and certificate, stored with Laravel encrypted casts, plus the sandbox/production switch.

**Queued jobs.** `SubmitDocument`, `PollDocumentStatus`, `CancelDocument`. Nothing that talks to LHDN runs in a web request.

**Events.** `DocumentSubmitted`, `DocumentValidated`, `DocumentRejected`, `DocumentCancelled`. Host apps listen to these.

**Statuses.** `pending`, `submitted`, `valid`, `invalid`, `cancelled`, plus `failed` for transport errors that never reached validation.

**Optional, later.** A small admin screen for submission history, errors, and retry. Do not build it in the first pass.

## Business rules the package must support

- **Document types**: invoice, credit note, debit note, refund note, and the self-billed variants. Credit, debit, and refund notes must reference the original document's LHDN UUID.
- **Cancellation**: allowed within 72 hours of validation. Refuse outside the window with a clear error. After that, the fix is a credit note.
- **Consolidated invoices**: for consumer sales where no e-invoice was requested, one consolidated submission per month. Design the contract so a host app can submit these.
- **TIN validation**: expose a way to validate a buyer's TIN against their BRN or NRIC before submission.
- **Pre-submission validation**: validate the document locally and return readable errors before calling LHDN.
- **Idempotency**: a retry must never create a second valid document for the same source record.
- **Environment separation**: sandbox results must never overwrite production state.

## Underlying SDK: `jiannius/myinvois`

Repo: https://github.com/jiannius/myinvois (MIT). The facts below come from its README as of 2 October 2026. Nobody has run it yet, so verify each one against the installed source before relying on it.

- **Versions**: the `^1.0` line needs PHP ^8.3 and Laravel ^13.0. Apps on Laravel 12 must pin the `^0.1` line.
- **Resolve**: `app('myinvois')` returns a fresh instance.
- **Per-call credentials** via fluent setters: `setClientId`, `setClientSecret`, `setPrivateKey($pem)`, `setCertificate($pem)`, `setPreprod($bool)`, `setOnBehalfOf($tin, $brn)`, `setFailedCallback(fn)`. This is how we support one set of credentials per company.
- **Submit**: `submitDocuments(array $documents)` builds UBL 2.1, signs with XAdES, and posts. It returns `['myinvois_documents' => Collection, 'response' => raw]`. It also polls up to 3 times at 2-second intervals inside the call, so it blocks for several seconds. Call it only from a queue job.
- **Sample**: `submitDocuments('sample')` submits a bundled test invoice. Use this as the first sandbox smoke test.
- **Local validation**: `validator($document)` returns a Laravel `Validator`.
- **Read**: `getSubmission($uid)`, `getDocument($uid)`, `getDocumentDetails($uid)`, `getRecentDocuments()`, `searchDocuments()`.
- **Cancel and reject**: `cancelDocument($uid, reason: '...')`, `rejectDocument($uid, reason: '...')`.
- **TIN**: `searchTaxpayerTIN(idType:, idValue:, taxpayerName:)`, `validateTaxpayerTIN($tin, brn: ...)`.
- **Code tables**: `Jiannius\Myinvois\Helpers\Code` exposes countries, states, currencies, MSIC, units, taxes, classifications, payment modes, document types and versions.
- **Special TINs**: `TinType` enum for general public, foreign buyer, foreign supplier, and government.
- **Consolidated detection**: it treats a submission as consolidated when every line item has only classification code `004`.
- **Built-in behaviour**: rate limiting per LHDN's published limits (60/min and 12/min endpoint families) and OAuth token caching for 50 minutes.

**Overlap to resolve.** The SDK auto-loads its own `myinvois_documents` migration and model, and offers a `HasMyinvoisDocument` trait for host models. That overlaps with our tables. My preference is to treat its table as an internal detail of the driver, keep our tables as the source of truth, and never use its trait in host apps. Check whether its migration can be disabled, and tell me if you think reusing its table is the better trade.

**Risk.** The repo has 70 commits and no stars. Read the signing and submission code before trusting it. If it turns out to be unsuitable, fall back to `klsheng/myinvois-php-sdk` (framework-agnostic PHP) behind the same driver interface.

## LHDN references

- SDK portal (API docs, schemas, code tables, sample JSON and XML): https://sdk.myinvois.hasil.gov.my/
- Sandbox portal: https://preprod.myinvois.hasil.gov.my/
- Sandbox API base: https://preprod-api.myinvois.hasil.gov.my
- Production API base: https://api.myinvois.hasil.gov.my
- Community docs with a Postman collection, 44 sample payloads, and an error cookbook: https://github.com/deadboy18/myinvois-docs

The spec moves. The SDK portal lists a sandbox update effective 25 September 2026, so check the release notes page before finalising field mappings.

## Build order

1. Package skeleton: `composer.json`, service provider with auto-discovery, config file, Orchestra Testbench, Pest or PHPUnit, Pint, and a GitHub Actions workflow running tests and style checks.
2. Contracts and data objects: `EInvoiceable`, `EInvoiceDriver`, the document data object, status enum.
3. Migrations and models for the three tables.
4. Fake driver and tests for the full flow (submit, poll, validate, reject, cancel, retry without duplicates).
5. Jiannius driver, mapping our data object to its array shape.
6. Jobs and events.
7. Sandbox smoke test with real sandbox credentials, starting with the bundled sample.
8. README with install steps, a host-model example, and the event list.
9. Wire it into the accounting app: extra fields (TIN, BRN, MSIC on the company; TIN and ID on customers; classification and tax codes on items) and the QR code on the invoice PDF. This step happens in the accounting repo, not here.

## Working rules

- This repo will be public. Never commit credentials, certificates, private keys, or `.env` files. Add them to `.gitignore` from the first commit.
- Sandbox only until I say otherwise. Production must be an explicit per-company switch.
- Write tests against the fake driver so the suite runs without network access or credentials.
- Stop and ask me before any decision that is hard to reverse: package name, table names, the public contract.
- When something in this brief disagrees with the SDK source or the LHDN docs, trust the source and tell me what changed.
