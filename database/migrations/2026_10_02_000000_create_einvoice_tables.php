<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per company per environment. Production must be a deliberate extra row, never a flag flip on sandbox credentials.
        Schema::create('einvoice_settings', function (Blueprint $table) {
            $table->id();
            $table->string('tin');
            $table->string('environment')->default('sandbox');
            $table->boolean('active')->default(true);
            $table->text('client_id');
            $table->text('client_secret');
            // Unsigned (document version 1.0) is a sandbox-only escape hatch for testing without a CA-issued certificate.
            $table->boolean('unsigned')->default(false);
            $table->text('certificate')->nullable();
            $table->text('private_key')->nullable();
            $table->timestamps();

            $table->unique(['tin', 'environment']);
        });

        Schema::create('einvoice_documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('einvoiceable');
            $table->foreignId('setting_id')->constrained('einvoice_settings');
            $table->string('environment');
            $table->string('type', 2);
            $table->string('number');
            $table->string('status')->default('pending');
            $table->string('uuid')->nullable()->index();
            $table->string('submission_uid')->nullable();
            $table->string('long_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            // Idempotency + environment separation: one LHDN document per source record per environment.
            $table->unique(['einvoiceable_type', 'einvoiceable_id', 'environment'], 'einvoice_documents_source_env_unique');
        });

        Schema::create('einvoice_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('einvoice_documents')->cascadeOnDelete();
            $table->string('action');
            $table->boolean('success');
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->json('errors')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoice_logs');
        Schema::dropIfExists('einvoice_documents');
        Schema::dropIfExists('einvoice_settings');
    }
};
