<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderCancelledMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Order $order)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Заказ №{$this->order->number} отменён",
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.order-cancelled',
            with: [
                'order' => $this->order,
                // Refund is promised only when the order had actually been paid.
                'wasPaid' => $this->order->paid_at !== null,
            ],
        );
    }
}
