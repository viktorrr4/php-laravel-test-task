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
            // Null while the email is queued but not yet delivered.
            $table->timestamp('sent_at')->nullable();

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
