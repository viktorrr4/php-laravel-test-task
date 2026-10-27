<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the envelope and order snapshot of an incoming webhook.
 * Signature/freshness are already enforced by middleware.
 */
class MarketplaceWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string'],
            'type' => ['required', 'string'],
            'created_at' => ['required', 'date'],

            'data.order' => ['required', 'array'],
            'data.order.id' => ['required', 'string'],
            'data.order.number' => ['required', 'string'],
            'data.order.status' => ['required', Rule::enum(OrderStatus::class)],
            'data.order.currency' => ['required', 'string'],

            'data.order.customer' => ['required', 'array'],
            'data.order.customer.email' => ['required', 'string'],
            'data.order.customer.name' => ['required', 'string'],
            'data.order.customer.phone' => ['nullable', 'string'],

            'data.order.items' => ['present', 'array'],
            'data.order.items.*.sku' => ['required', 'string'],
            'data.order.items.*.name' => ['required', 'string'],
            'data.order.items.*.quantity' => ['required', 'integer'],
            'data.order.items.*.price' => ['required', 'string'],

            'data.order.delivery_price' => ['required', 'string'],
            'data.order.discount' => ['required', 'string'],
            'data.order.total' => ['required', 'string'],

            'data.order.shipment' => ['nullable', 'array'],
            'data.order.shipment.carrier' => ['required_with:data.order.shipment', 'string'],
            'data.order.shipment.tracking_number' => ['required_with:data.order.shipment', 'string'],
            'data.order.shipment.tracking_url' => ['required_with:data.order.shipment', 'string'],

            'data.order.paid_at' => ['nullable', 'date'],
            'data.order.cancelled_at' => ['nullable', 'date'],
            'data.order.cancel_reason' => ['nullable', 'string'],
            'data.order.created_at' => ['required', 'date'],
            'data.order.updated_at' => ['required', 'date'],
        ];
    }
}
