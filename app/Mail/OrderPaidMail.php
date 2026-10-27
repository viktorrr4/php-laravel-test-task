<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OrderPaidMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly Order $order)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Заказ №{$this->order->number} оплачен",
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.order-paid',
            with: ['order' => $this->order->loadMissing('items')],
        );
    }
}
