<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SendsWebhooks;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;
    use SendsWebhooks;

    public function test_index_returns_paginated_shape_sorted_newest_first(): void
    {
        $old = Order::factory()->create(['marketplace_created_at' => CarbonImmutable::parse('2026-10-01T10:00:00+00:00')]);
        $new = Order::factory()->create(['marketplace_created_at' => CarbonImmutable::parse('2026-10-20T10:00:00+00:00')]);

        $response = $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'number', 'status', 'customer' => ['email', 'name'], 'total', 'currency', 'created_at', 'updated_at']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        $this->assertSame($new->marketplace_order_id, $response->json('data.0.id'));
        $this->assertSame($old->marketplace_order_id, $response->json('data.1.id'));
    }

    public function test_index_filters_by_status(): void
    {
        Order::factory()->paid()->create();
        Order::factory()->cancelled()->create();

        $this->getJson('/api/orders?status=paid')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'paid');
    }

    public function test_index_filters_by_email_case_insensitively(): void
    {
        Order::factory()->create(['customer_email' => 'Anna.Smirnova@example.com']);
        Order::factory()->create(['customer_email' => 'other@example.com']);

        $this->getJson('/api/orders?email=anna.smirnova@EXAMPLE.com')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_respects_per_page(): void
    {
        Order::factory()->count(5)->create();

        $this->getJson('/api/orders?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_show_returns_full_order_with_history_in_order(): void
    {
        $created = $this->orderSnapshot(['status' => 'created', 'updated_at' => '2026-10-21T13:02:11+03:00']);
        $paid = $this->orderSnapshot(['status' => 'paid', 'paid_at' => '2026-10-21T13:05:47+03:00', 'updated_at' => '2026-10-21T13:05:47+03:00']);
        $shipped = $this->orderSnapshot([
            'status' => 'shipped',
            'paid_at' => '2026-10-21T13:05:47+03:00',
            'updated_at' => '2026-10-22T06:40:03+00:00',
            'shipment' => ['carrier' => 'СДЭК', 'tracking_number' => '1098765432', 'tracking_url' => 'https://track/1'],
        ]);

        $this->postWebhook($this->event('e1', 'order.created', $created));
        $this->postWebhook($this->event('e2', 'order.paid', $paid));
        $this->postWebhook($this->event('e3', 'order.shipped', $shipped));

        $response = $this->getJson('/api/orders/ord_7Q2KX9M4')->assertOk();

        $response->assertJsonPath('data.id', 'ord_7Q2KX9M4')
            ->assertJsonPath('data.status', 'shipped')
            ->assertJsonPath('data.customer.phone', '+79161234567')
            ->assertJsonPath('data.shipment.tracking_number', '1098765432')
            ->assertJsonPath('data.items.0.subtotal', '3870.00')
            ->assertJsonPath('data.history.0.status', 'created')
            ->assertJsonPath('data.history.1.status', 'paid')
            ->assertJsonPath('data.history.2.status', 'shipped');
    }

    public function test_show_unknown_order_returns_404(): void
    {
        $this->getJson('/api/orders/ord_UNKNOWN')->assertNotFound();
    }
}
