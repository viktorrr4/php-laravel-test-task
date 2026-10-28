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

            // Keyed on the moment of the transition: each distinct transition is
            // kept (a manual re-ship, or a status recurring at a new time), while
            // a replay of the same event (same status at the same moment) stays
            // idempotent.
            $table->unique(['order_id', 'status', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
