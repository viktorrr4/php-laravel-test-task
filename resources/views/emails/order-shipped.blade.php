Здравствуйте, {{ $order->customer_name }}!

Ваш заказ №{{ $order->number }} передан в доставку.

Служба доставки: {{ $order->shipment->carrier }}
Трек-номер: {{ $order->shipment->tracking_number }}
Отследить: {{ $order->shipment->tracking_url }}
