<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            $table->string('status', 32);
            // Marketplace moment the order entered this status.
            $table->timestamp('occurred_at');

            $table->timestamps();

            // Each status is recorded once per order (idempotent on replays).
            $table->unique(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
