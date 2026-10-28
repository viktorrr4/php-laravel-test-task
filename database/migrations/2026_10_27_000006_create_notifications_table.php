<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // paid | shipped | cancelled
            $table->string('type', 32);
            // A temporary processing claim, distinct from sent_at so a claim never
            // masquerades as a confirmed delivery; an abandoned claim (process
            // died mid-send) is released by app:reconcile-queued-work once stale.
            $table->timestamp('claimed_at')->nullable();
            // Null while the email is queued but not yet delivered.
            $table->timestamp('sent_at')->nullable();
            // Set when the email is intentionally not sent because it no longer
            // matches the order's current state (e.g. a queued paid mail for an
            // order since cancelled). Keeps the reconciler from resurrecting it.
            $table->timestamp('suppressed_at')->nullable();

            // Delivery attempts that have failed, and the last error seen.
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();

            $table->timestamps();

            // The hard guarantee: at most one email of each type per order.
            $table->unique(['order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notifications');
    }
};
