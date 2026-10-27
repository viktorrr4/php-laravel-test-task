<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\OrderIndexRequest;
use App\Http\Resources\OrderListItemResource;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read-only orders API consumed by the shop admin panel (task/openapi.yaml).
 */
class OrderController extends Controller
{
    public function index(OrderIndexRequest $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->when(
                $request->filled('email'),
                // Case-insensitive match on the customer email.
                fn ($query) => $query->whereRaw('LOWER(customer_email) = ?', [mb_strtolower($request->string('email')->value())]),
            )
            ->orderByDesc('marketplace_created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return OrderListItemResource::collection($orders);
    }

    public function show(Order $order): OrderResource
    {
        $order->load(['items', 'shipment', 'statusHistory']);

        return new OrderResource($order);
    }
}
