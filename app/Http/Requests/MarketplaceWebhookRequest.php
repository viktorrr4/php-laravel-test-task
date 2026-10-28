<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\OrderStatus;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Validates the envelope and order snapshot of an incoming webhook.
 * Signature/freshness are already enforced by middleware.
 */
class MarketplaceWebhookRequest extends FormRequest
{
    /**
     * A marketplace `amount`: a non-negative decimal string with exactly two
     * fraction digits (`"1499.90"`), as the contract defines it, and up to ten
     * integer digits so it fits the `DECIMAL(12,2)` columns. This rejects
     * negatives, over-precise values (`"1.999"`), bare integers (`"100"`) and any
     * non-numeric string before the snapshot is stored or a job is queued.
     */
    private const MONEY_REGEX = 'regex:/^\d{1,10}\.\d{2}$/';

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
            'data.order.items.*.price' => ['required', 'string', self::MONEY_REGEX],

            'data.order.delivery_price' => ['required', 'string', self::MONEY_REGEX],
            'data.order.discount' => ['required', 'string', self::MONEY_REGEX],
            'data.order.total' => ['required', 'string', self::MONEY_REGEX],

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

    /**
     * Log which fields a rejected payload failed on (names only, never values),
     * then let the framework return the 422 — nothing is stored and no job is
     * queued for an invalid webhook.
     */
    protected function failedValidation(Validator $validator): void
    {
        Log::warning('webhook.rejected', [
            'event_id' => (string) $this->input('id'),
            'fields' => array_keys($validator->errors()->messages()),
        ]);

        parent::failedValidation($validator);
    }
}
