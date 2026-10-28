<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();

            // MarketHub event id (X-Webhook-Id / payload.id). Unique -> idempotency.
            $table->string('event_id')->unique();
            $table->string('type');
            $table->timestamp('marketplace_created_at');

            $table->json('payload');
            $table->boolean('signature_valid')->default(true);

            $table->timestamp('received_at');
            // Set once the event has been fully applied; makes re-processing a no-op.
            $table->timestamp('processed_at')->nullable();

            // Processing attempts that have failed, and the last error seen.
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
