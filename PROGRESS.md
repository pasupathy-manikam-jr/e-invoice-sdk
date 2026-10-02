# LHDN MyInvois package: progress log

Status as of 2 October 2026. The original plan is in `EINVOICE_BRIEF.md`; this file records what was actually built, what we learned, and what is left.

No credentials, certificates or personal identifiers are in this repo. Sandbox credentials live in `~/.einvoice-sandbox.env` and `~/.einvoice-sandbox/` (mode 600/700).

## Decisions

| Question | Decision |
|---|---|
| Package name | `pasupathy-manikam-jr/e-invoice-sdk` (public: github.com/pasupathy-manikam-jr/e-invoice-sdk) |
| Namespace | `Oriclab\EInvoice` |
| PHP / Laravel | PHP `^8.4`, Laravel `^13.34` (latest). Local PHP is 8.4.17 via MAMP |
| SDK | `jiannius/myinvois` `^1.2` (installed v1.2.9) |
| Licence | MIT |
| Tenancy | Many companies per install: one `einvoice_settings` row per company per environment |
| Location | This folder (`~/Sites/E-invoice`). No git yet |
| Tooling | Matches the accounting app: PHPUnit 12, Pint, Larastan, Testbench 11. `composer ci:check` runs all three |

The accounting app (`~/Sites/account`) is on PHP `^8.3` / Laravel `^13.17` and has one company per install today. Servers running it will need PHP 8.4.

## What was built

```
src/
  EInvoice.php                  entry point: validate, submit, cancel, poll, validateTin
  EInvoiceServiceProvider.php   binds the driver from config('einvoice.driver'), loads migrations
  Contracts/EInvoiceable.php    host models implement toEInvoiceDocument(): Document
  Contracts/EInvoiceDriver.php  validate, submit, status, cancel, validateTin, findExisting
  Data/                         Document, Party, LineItem, Tax (ours, not the SDK's shape)
  Enums/                        Status, DocumentType (LHDN codes 01-14), Environment
  Drivers/JianniusDriver.php    real driver on top of the SDK
  Drivers/FakeDriver.php        scriptable in-memory driver for tests
  Drivers/SubmitResult.php, StatusResult.php
  Models/                       EInvoiceSetting, EInvoiceDocument, EInvoiceLog
  Jobs/                         SubmitDocument, PollDocumentStatus, CancelDocument
  Events/                       DocumentSubmitted, DocumentValidated, DocumentRejected, DocumentCancelled
  Concerns/HasEInvoices.php     optional morphMany for host models
database/migrations/            einvoice_settings, einvoice_documents, einvoice_logs
config/einvoice.php             driver (jiannius|fake), queue
tests/                          19 tests, no network or credentials needed
README.md                       install, settings, host-model example, events
```

### How it works

1. Host model returns a `Document` from `toEInvoiceDocument()`.
2. `app(EInvoice::class)->submit($model)` validates locally (throws `ValidationException` with readable errors), creates or reuses the `einvoice_documents` row, and queues `SubmitDocument`.
3. `SubmitDocument` builds UBL, signs it, posts it, logs the attempt, then queues `PollDocumentStatus`.
4. `PollDocumentStatus` re-checks until the document is valid, invalid or cancelled, stores the UUID, long ID and validated-at, and fires the matching event.
5. `$record->validationUrl()` gives the public link for the invoice QR code.

### Business rules covered

- **Idempotency**: unique index on (source record, environment). `submit()` refuses when already submitted, valid or cancelled, and allows retry after invalid or failed. Before retrying a failed submit it searches LHDN for the invoice number, so a request that got through before the connection dropped is recovered instead of duplicated.
- **Environment separation**: settings are one row per TIN per environment, and each document keeps the settings it was submitted with. Going live means creating a production row and calling `activate()`; sandbox rows are never touched.
- **Cancellation**: only valid documents, only within 72 hours of validation; otherwise a clear error telling the user to issue a credit note.
- **Credit, debit and refund notes**: must carry the original number and LHDN UUID (our own check, because the SDK's validator misses plain refund notes).
- **Consolidated invoices**: `consolidated: true`, buyer TIN `EI00000000010`, lines classified `004`.
- **TIN validation**: `validateTin(supplierTin, tin, 'BRN'|'NRIC'|'PASSPORT'|'ARMY', value)`. This one call is synchronous.
- **Credentials**: client ID, secret, certificate and private key use Laravel encrypted casts.

## What we found in the SDK

Read from the installed source, not the README.

- `submitDocuments()` sleeps up to three times for 2 seconds and writes to its own `myinvois_documents` table. Our driver calls its `UBL::build`, `Signature::build`, `callApi` (token cache and rate limiting) and `Validator` directly instead.
- Its `myinvois_documents` migration loads automatically and cannot be switched off from our package. Host apps should add `jiannius/myinvois` to `extra.laravel.dont-discover` in their `composer.json` (in the README). We never use that table.
- It sends the issue time in whatever timezone it is given. LHDN wants UTC, so our driver converts.
- `rejectDocument()` sends GET instead of PUT. We don't use it.
- Its signer converts JSON as if it were Latin-1 (`mb_convert_encoding(..., 'ISO-8859-1')`). Non-ASCII names (Chinese, accented) may break the signature digest. Untested.
- Its read methods return failed HTTP responses silently, so our driver checks status codes itself.

## Sandbox access (no company needed)

LHDN gives API credentials only to taxpayers, and getting company access needs a director. A personal tax account works for the sandbox:

1. First-time login at https://preprod-mytax.hasil.gov.my with ID type NRIC. The sandbox does not verify uploaded documents.
2. Activate the individual profile at https://preprod.myinvois.hasil.gov.my (TIN starts with `IG`). Leave SST and tourism tax blank, or `NA` if required; never invent numbers.
3. View Taxpayer Profile → Register ERP (any name) → copy the client ID and both client secrets. They are shown once.
4. Save them to `~/.einvoice-sandbox.env` (`EINVOICE_TIN`, `EINVOICE_CLIENT_ID`, `EINVOICE_CLIENT_SECRET`, `EINVOICE_NRIC`), mode 600.

## Sandbox results

| Run | Result | Lesson |
|---|---|---|
| Signed v1.1, supplier sent with `BRN: NA` | Rejected at submission: "The authenticated TIN and documents TIN is not matching" | An `IG` TIN with a BRN is treated as a business. Individuals must send NRIC, not BRN |
| Signed v1.1, supplier with NRIC, self-signed certificate | Submission accepted, then Invalid on signature rules only (DS306, DS307, DS309, DS311, DS312, DS326, DS329) | See "Signing" below |
| Status check right after submitting | HTTP 404 for a few seconds | Fixed: driver now treats 404 as still submitted (2 new tests) |
| Unsigned v1.0, same document | **Valid**, with long ID and validation link | Our document mapping (parties, lines, taxes, totals, codes) passes LHDN validation |

The login token confirmed the credentials resolve to the expected `IG` TIN (`TaxpayerTIN` claim).

### Signing

A self-signed certificate cannot pass in the sandbox either. LHDN requires:

- an organisation certificate (personal certificates are refused for ERP/API submission, DS309)
- issued by an MCMC-licensed CA such as MSC Trustgate or Pos Digicert (DS329)
- with `organizationIdentifier` (OI) = the submitter's TIN and `SERIALNUMBER` = their BRN (DS306, DS307, DS311, DS312)

DS326 (issuer name in the signed properties doesn't match the certificate) may be a formatting issue in the SDK's `getIssuerName()`, or only a side effect of the self-signed certificate. It can only be confirmed with a real certificate.

So signed v1.1 submissions need a company with a real organisation certificate. Until then, the sandbox can use unsigned v1.0.

## Verification

- `composer ci:check`: Pint, Larastan (level 6), PHPUnit, all green, 19 tests and 52 assertions.
- Offline tests cover the whole flow against the fake driver (submit, poll, reject, resubmit, no duplicates, failed-submit recovery, cancel inside and outside 72 hours, sandbox/production separation, encryption at rest, TIN validation), plus the real driver's mapping, SDK validation, UBL build and signing with a generated key, and HTTP-faked status handling.
- The sandbox runs above used one-off scripts in the session scratchpad. They are not part of the package.

## Next steps

1. **Unsigned sandbox option.** Add a sandbox-only `unsigned` flag on `einvoice_settings` that submits v1.0 without a signature. Production always signs.
2. **Wire into the accounting app** (brief step 9):
   - Company settings: TIN, BRN or NRIC, MSIC, address, MyInvois credentials
   - Customers: TIN and ID type/number, with a Validate TIN button
   - Items: classification code and tax type
   - Invoices and credit notes implement `EInvoiceable`; add a Submit to LHDN button, a status badge with errors, and cancel within 72 hours
   - QR code with `validationUrl()` on the invoice PDF
   - Queue worker
3. **Package gaps**: billing period, prepayment, charges and shipping on `Document` if the app needs them; a scheduled sweep to re-poll documents stuck in submitted.
4. **Before production**: test signed v1.1 with a real organisation certificate (settles DS326 and the non-ASCII question); create the git repo and CI; confirm whether LHDN still accepts v1.0 in production.
5. **Housekeeping**: the sandbox client secret was pasted in a chat. Rotate it in the portal (Register ERP entry) after testing; the second secret allows rotation without downtime.
