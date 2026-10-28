<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Identity as seen by MarketHub / the customer.
            $table->string('marketplace_order_id')->unique();
            $table->string('number')->index();

            $table->string('status', 32);
            $table->string('currency', 3);

            $table->string('customer_email')->index();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();

            $table->decimal('delivery_price', 12, 2);
            $table->decimal('discount', 12, 2);
            $table->decimal('total', 12, 2);

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancel_reason')->nullable();

            // Authoritative timestamps from the marketplace snapshot.
            $table->timestamp('marketplace_created_at');
            // Guards out-of-order delivery: we only overwrite state from a
            // snapshot that is at least as fresh as the one already stored.
            $table->timestamp('marketplace_updated_at');
            // Marketplace `created_at` of the event whose snapshot is currently
            // applied. Together with marketplace_updated_at it gives a stable
            // tie-break when two snapshots share an updated_at, so a late
            // redelivery cannot flip state after a restart.
            $table->timestamp('last_event_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'marketplace_created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
