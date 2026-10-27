Здравствуйте, {{ $order->customer_name }}!

Ваш заказ №{{ $order->number }} оплачен.

Состав заказа:
@foreach ($order->items as $item)
- {{ $item->name }} — {{ $item->quantity }} × {{ $item->price }} {{ $order->currency }} = {{ $item->subtotal() }} {{ $order->currency }}
@endforeach

Итого: {{ $order->total }} {{ $order->currency }}

Спасибо за покупку!
