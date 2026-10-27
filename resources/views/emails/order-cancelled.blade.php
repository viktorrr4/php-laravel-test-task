Здравствуйте, {{ $order->customer_name }}!

Ваш заказ №{{ $order->number }} отменён.
@if ($order->cancel_reason)
Причина: {{ $order->cancel_reason }}
@endif
@if ($wasPaid)

Оплаченная сумма вернётся на счёт в течение 10 дней.
@endif
