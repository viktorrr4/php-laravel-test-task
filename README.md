# MarketHub Integration

Сервис на Laravel 13, который принимает вебхуки маркетплейса **MarketHub**, хранит
актуальное состояние заказов, отправляет покупателю письма о ключевых изменениях и отдаёт
заказы в админку магазина через API.

Условия задания — в [`task/`](task/). Принятые решения и допущения — в
[`DECISIONS.md`](DECISIONS.md).

## Стек

- PHP 8.3+ / Laravel 13
- MySQL 8
- Очередь (`database`) + отдельный воркер
- Mailpit для просмотра писем
- Docker через Laravel Sail

## Запуск с нуля

На чистом checkout'е ещё нет каталога `vendor/`, поэтому `./vendor/bin/sail`
пока недоступен. Сначала ставим зависимости (и сам Sail) одноразовым
контейнером Composer, и только потом поднимаем окружение.

```bash
# 1. Установить PHP-зависимости (и получить Sail) без локального PHP/Composer.
#    Образ php84-composer публикуется на Docker Hub (php85 ещё нет), а
#    --ignore-platform-reqs снимает проверку версии: сборка и запуск всё равно
#    идут внутри Sail-контейнера на PHP 8.5.
docker run --rm \
  -u "$(id -u):$(id -g)" \
  -v "$(pwd):/var/www/html" \
  -w /var/www/html \
  laravelsail/php84-composer:latest \
  composer install --ignore-platform-reqs

# 2. Переменные окружения
cp .env.example .env
# Пропишите секрет подписи вебхуков (любое значение, одинаковое с отправителем):
#   MARKETPLACE_WEBHOOK_SECRET=whsec_local_dev_secret

# 3. Поднять контейнеры (app + mysql + redis + mailpit + queue worker)
./vendor/bin/sail up -d --build

# 4. Ключ приложения и миграции
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
```

Приложение по умолчанию поднимается на порту `8000` (переменная `APP_PORT`
в `.env`), адреса сервисов — в таблице ниже.

Воркеры и планировщик поднимаются отдельными контейнерами автоматически, запускать вручную
ничего не нужно:
- `queue-webhooks` — обработка событий (`queue:work --queue=webhooks`);
- `queue-mail` — отправка писем (`queue:work --queue=mail`);
- `scheduler` — `schedule:work`, каждые 5 минут гоняет `app:reconcile-queued-work` для
  восстановления зависших заданий.

### Адреса

| Сервис | URL |
|---|---|
| Приложение | http://localhost:8000 |
| Эндпоинт вебхуков | `POST http://localhost:8000/api/webhooks/marketplace` |
| Admin API | `GET http://localhost:8000/api/orders`, `GET http://localhost:8000/api/orders/{id}` |
| Mailpit (письма) | http://localhost:8025 |

Порты настраиваются в `.env` (`APP_PORT`, `FORWARD_MAILPIT_DASHBOARD_PORT` и т. д.).

## Проверка вручную

Отправьте тестовое событие скриптом из задания (он подписывает тело тем же секретом):

```bash
MARKETPLACE_WEBHOOK_SECRET=whsec_local_dev_secret \
  php task/examples/send-event.php \
  http://localhost:8000/api/webhooks/marketplace \
  task/examples/order-paid.json
```

Затем:

- откройте http://localhost:8025 — там появится письмо «Заказ №… оплачен»;
- `curl http://localhost:8000/api/orders` — заказ в списке;
- `curl http://localhost:8000/api/orders/ord_7Q2KX9M4` — карточка заказа с позициями и
  историей статусов.

Повторная отправка того же файла (тот же `id` события) не создаёт дублей и не шлёт письмо
повторно.

## Тесты

```bash
./vendor/bin/sail artisan test
```

Тесты используют отдельную SQLite-базу в памяти (см. `phpunit.xml`), Docker для них не нужен —
их можно запустить и локально: `php artisan test`.

## Как это устроено

- `app/Http/Middleware/VerifyWebhookSignature.php` — проверка HMAC-SHA256 подписи по сырому
  телу запроса и свежести `X-Webhook-Timestamp` (окно 5 минут), до любой бизнес-логики.
- `app/Http/Controllers/Webhooks/MarketplaceWebhookController.php` — тонкий контроллер:
  идемпотентно сохраняет событие и ставит задачу в очередь, отвечает `202` в пределах 5 секунд.
- `app/Actions/` — вся бизнес-логика: применение снимка заказа (`UpsertOrderFromSnapshotAction`),
  оркестрация обработки события (`ProcessWebhookEventAction`), постановка писем в очередь
  (`DispatchOrderNotificationsAction`).
- `app/Jobs/` — `ProcessWebhookEventJob` (очередь `webhooks`) и `SendCustomerNotificationJob`
  (очередь `mail`); упавшие задания видны по `attempts`/`last_error` в доменных таблицах и
  восстанавливаются командой `app:reconcile-queued-work`.
- `app/Http/Controllers/Api/OrderController.php` + `app/Http/Resources/` — admin API по
  контракту `task/openapi.yaml`.

Подробнее о том, как обрабатываются дубли и нарушенный порядок доставки — в
[`DECISIONS.md`](DECISIONS.md).
