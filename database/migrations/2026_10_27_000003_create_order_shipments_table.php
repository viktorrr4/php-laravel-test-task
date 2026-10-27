<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_shipments', function (Blueprint $table) {
            $table->id();
            // One shipment per order; appears once the order is handed to delivery.
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('carrier');
            $table->string('tracking_number');
            $table->string('tracking_url');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_shipments');
    }
};
