<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\OrderCancelledMail;
use App\Mail\OrderPaidMail;
use App\Mail\OrderShippedMail;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailContentTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $overrides = []): Order
    {
        $order = Order::factory()->create(array_merge([
            'number' => 'MH-1048213',
            'currency' => 'RUB',
            'total' => '4909.50',
        ], $overrides));

        $order->items()->create(['sku' => 'TSHIRT-BLK-M', 'name' => 'Футболка', 'quantity' => 3, 'price' => '1290.00']);

        return $order->load('items');
    }

    public function test_paid_email_lists_items_and_total(): void
    {
        $mail = new OrderPaidMail($this->order());
        $mail->assertHasSubject('Заказ №MH-1048213 оплачен');

        $body = view('emails.order-paid', ['order' => $this->order()])->render();
        $this->assertStringContainsString('Футболка', $body);
        $this->assertStringContainsString('3870.00', $body); // 1290.00 × 3
        $this->assertStringContainsString('4909.50 RUB', $body);
    }

    public function test_shipped_email_includes_tracking(): void
    {
        $order = $this->order();
        $order->shipment()->create([
            'carrier' => 'СДЭК',
            'tracking_number' => '1098765432',
            'tracking_url' => 'https://track.example/1098765432',
        ]);

        (new OrderShippedMail($order))->assertHasSubject('Заказ №MH-1048213 передан в доставку');

        $body = view('emails.order-shipped', ['order' => $order->load('shipment')])->render();
        $this->assertStringContainsString('СДЭК', $body);
        $this->assertStringContainsString('1098765432', $body);
        $this->assertStringContainsString('https://track.example/1098765432', $body);
    }

    public function test_cancelled_email_mentions_refund_only_when_paid(): void
    {
        $paidOrder = $this->order(['cancel_reason' => 'Отменён', 'paid_at' => CarbonImmutable::now()]);
        $unpaidOrder = $this->order(['cancel_reason' => 'Отменён', 'paid_at' => null]);

        $paidBody = view('emails.order-cancelled', ['order' => $paidOrder, 'wasPaid' => true])->render();
        $unpaidBody = view('emails.order-cancelled', ['order' => $unpaidOrder, 'wasPaid' => false])->render();

        $this->assertStringContainsString('Отменён', $paidBody);
        $this->assertStringContainsString('10 дней', $paidBody);
        $this->assertStringNotContainsString('10 дней', $unpaidBody);
    }
}
