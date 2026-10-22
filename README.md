# Order & Payment API

A Laravel 12 REST API for order management with simulated payment processing, secured by JWT authentication.

**Payment gateways are simulated.** No real transactions are performed, no external payment providers are contacted, and no credentials are used. Gateway responses are deterministic and controlled by the caller.

## Features

- **Order management** — create, list, show, update, and delete orders with line items. Totals are calculated server-side using exact decimal arithmetic (BCMath).
- **Simulated payment processing** — process payments through pluggable gateway strategies (`credit_card`, `paypal`), with controlled `success`/`failure` outcomes.
- **JWT authentication** — stateless API authentication via `php-open-source-saver/jwt-auth` (v2.8.3). Registration and login are public; everything else requires a Bearer token.
- **Payment gateway extensibility** — add a new gateway by creating one class and adding one line of configuration. No changes to services, controllers, or validation code.

## Requirements

| Requirement | Version / Notes |
|---|---|
| PHP | ^8.2 |
| Composer | 2.x |
| MySQL | 8.x (or any MySQL-compatible server) |
| PHP extension | BCMath (`ext-bcmath`, declared in `composer.json`) |
| PHP extension | PDO MySQL driver (`pdo_mysql`) |
| Laravel | 12.70.1 (installed by Composer) |

## Installation & Setup

```bash
# 1. Clone the repository
git clone https://github.com/abdullah00094/order-payment-api.git
cd order-payment-api

# 2. Install dependencies
composer install

# 3. Create your environment file
cp .env.example .env

# 4. Generate the application key
php artisan key:generate

# 5. Configure MySQL credentials in .env
#    DB_CONNECTION=mysql
#    DB_HOST=127.0.0.1
#    DB_PORT=3306
#    DB_DATABASE=<your_database>
#    DB_USERNAME=<your_user>
#    DB_PASSWORD=<your_password>

# 6. Generate the JWT signing secret (appends JWT_SECRET to .env)
php artisan jwt:secret

# 7. Run migrations
php artisan migrate

# 8. Start the development server
php artisan serve
```

The API is now available at `http://127.0.0.1:8000`.

## Authentication

| Method | Route | Auth | Purpose |
|---|---|---|---|
| POST | `/api/register` | Public | Create a user account |
| POST | `/api/login` | Public | Obtain a JWT access token |

**Register**

```json
// POST /api/register
{
    "name": "Ahmed Ali",
    "email": "ahmed@example.com",
    "password": "Password123!",
    "password_confirmation": "Password123!"
}
```

```json
// 201 Created
{
    "message": "User registered successfully.",
    "user": {
        "id": 1,
        "name": "Ahmed Ali",
        "email": "ahmed@example.com"
    }
}
```

The password is hashed (bcrypt) and never returned.

**Login**

```json
// POST /api/login
{
    "email": "ahmed@example.com",
    "password": "Password123!"
}
```

```json
// 200 OK
{
    "access_token": "<JWT_TOKEN>",
    "token_type": "bearer",
    "expires_in": 3600
}
```

`expires_in` is derived from the configured JWT TTL (`config/jwt.php`, default 60 minutes → 3600 seconds).

**Using the token** — send it as a Bearer token on every protected request:

```
Authorization: Bearer <access_token>
```

Invalid credentials return `401 {"message": "Invalid credentials."}`. There are no logout, refresh, or profile endpoints — the API implements exactly what the assignment requires.

## API Endpoints

| Method | Route | Auth | Purpose |
|---|---|---|---|
| POST | `/api/register` | Public | Register a user |
| POST | `/api/login` | Public | Authenticate and receive a JWT |
| POST | `/api/orders` | Bearer | Create an order with items |
| GET | `/api/orders` | Bearer | List own orders (paginated, optional `?status=` filter) |
| GET | `/api/orders/{id}` | Bearer | Show one own order with items |
| PATCH | `/api/orders/{id}` | Bearer | Update a pending order (details, status transition, or item replacement) |
| DELETE | `/api/orders/{id}` | Bearer | Delete an order that has no payments |
| POST | `/api/orders/{id}/payments` | Bearer | Process a simulated payment |
| GET | `/api/payments` | Bearer | List own payments across all orders |
| GET | `/api/orders/{id}/payments` | Bearer | List payments of one own order |

### Create Order

```json
// POST /api/orders
{
    "customer_name": "Ahmed Ali",
    "customer_email": "ahmed@example.com",
    "items": [
        { "product_name": "Widget", "quantity": 2, "price": "19.99" },
        { "product_name": "Gadget", "quantity": 1, "price": "5.50" }
    ]
}
```

```json
// 201 Created
{
    "message": "Order created successfully.",
    "order": {
        "id": 1,
        "user_id": 1,
        "customer_name": "Ahmed Ali",
        "customer_email": "ahmed@example.com",
        "status": "pending",
        "total": "45.48",
        "items": [
            { "id": 1, "order_id": 1, "product_name": "Widget", "quantity": 2, "price": "19.99" },
            { "id": 2, "order_id": 1, "product_name": "Gadget", "quantity": 1, "price": "5.50" }
        ]
    }
}
```

**Server-side total calculation.** The client never sends a total. The server computes `total = Σ(quantity × price)` using BCMath string arithmetic (`bcmul`/`bcadd`, scale 2), so results are exact — no floating-point drift. Client-supplied `total`, `user_id`, or `status` fields are ignored. Per-item limits mirror the database columns (`quantity` ≤ 4,294,967,295; `price` ≤ 9,999,999,999.99 with at most 2 decimals), and the aggregate total must fit the `orders.total` column (`decimal(12,2)`, max `9999999999.99`) or the request is rejected with 422.

**Status filtering.** `GET /api/orders?status=pending|confirmed|cancelled` filters the list. Any other value returns 422. Results are paginated (15 per page, standard Laravel paginator JSON) and always ordered newest first.

### Update Order (PATCH)

All fields are optional; absent fields are left unchanged:

```json
// PATCH /api/orders/{id}
{
    "customer_name": "Sara Hassan",
    "customer_email": "sara@example.com"
}
```

```json
// PATCH /api/orders/{id}  — status transition (see Business Rules)
{
    "status": "confirmed"
}
```

```json
// PATCH /api/orders/{id}  — full item replacement
{
    "items": [
        { "product_name": "Keyboard", "quantity": 3, "price": "10.00" }
    ]
}
```

When `items` is present, the **entire items collection is replaced**: existing items are deleted, the supplied items are created, and the total is recalculated server-side. Absent `items` preserves existing items and the existing total. A successful update returns `200 {"message": "Order updated successfully.", "order": {...}}`.

### Delete Order

`DELETE /api/orders/{id}` succeeds with **204 No Content** (empty body) only when the order has **zero payment records** — any payment, regardless of status, blocks deletion with `409 {"message": "Orders with payments cannot be deleted."}`. Deletion is independent of order status. Items are removed by the database cascade.

### Simulated Payment Processing

```json
// POST /api/orders/{id}/payments
{
    "method": "credit_card",
    "outcome": "success"
}
```

```json
// 201 Created  (also 201 when outcome=failure — the payment record is persisted as "failed")
{
    "message": "Payment processed.",
    "payment": {
        "id": 1,
        "order_id": 1,
        "method": "credit_card",
        "status": "successful"
    }
}
```

The `outcome` field exists only to control the simulated gateway (`success` → `successful`, `failure` → `failed`). It is not a real gateway parameter.

**Payment listing.** `GET /api/payments` returns the authenticated user's payments across all their orders; `GET /api/orders/{id}/payments` returns payments for one own order. Both are paginated (15) and ordered newest first.

## Business Rules

Order statuses: `pending`, `confirmed`, `cancelled`.

- New orders start as `pending`.
- Only `pending` orders can be updated — confirmed and cancelled orders are immutable (`409`).
- Orders are confirmed via the normal PATCH endpoint with `{"status": "confirmed"}`. There is no separate status endpoint.
- Only `confirmed` orders can be paid (`409` for pending/cancelled orders).
- Payment statuses: `pending`, `successful`, `failed`. New payment records start as the gateway's returned status.
- Simulated outcomes: `success` and `failure`.
- A failed payment can be retried — failures never block new attempts.
- A successful payment blocks all subsequent payment attempts for that order (`409`).
- Orders with **any** payment record cannot be deleted (`409`); paymentless orders can be deleted regardless of status.
- Users can only access their own orders and payments. Foreign-owned IDs return the same `404` as missing ones — no existence leak.

**Project assumptions** (documented during development, not explicit PDF requirements): the status transition rules (pending → pending/confirmed/cancelled), the "one successful payment per order" rule, the delete-blocking behavior of any payment record regardless of its status, and the aggregate-total validation against the column limit.

## Payment Architecture

Request flow:

```
HTTP Request
  → ProcessPaymentRequest          (validation: method + outcome against config)
    → PaymentController@store      (ownership lookup, delegates)
      → PaymentService::process()  (business rules, DB transaction, row lock, persistence)
        → PaymentGatewayResolver   (reads config/payment.php, container resolution)
          → CreditCardGateway | PaypalGateway   (PaymentGatewayInterface strategies)
            → Payment persisted via Order::payments()
```

Files: `app/Http/Requests/ProcessPaymentRequest.php`, `app/Http/Controllers/PaymentController.php`, `app/Services/PaymentService.php`, `app/Payments/PaymentGatewayInterface.php`, `app/Payments/PaymentGatewayResolver.php`, `app/Payments/CreditCardGateway.php`, `app/Payments/PaypalGateway.php`, `config/payment.php`.

**Why the Strategy Pattern:** gateway behavior varies per payment method while the surrounding workflow (rules, locking, persistence) is identical. Isolating the varying part behind `PaymentGatewayInterface` keeps the service independent of concrete gateways and makes new providers a configuration-only change.

**Centralization:** valid payment methods, outcome mappings, the gateway class registry, per-gateway enable flags, and the maximum storable order total (`max_total`) all live in `config/payment.php`. `ProcessPaymentRequest` validates `method` against the currently **enabled** gateway keys, and `PaymentGatewayResolver` reads the same configuration — one source of truth.

**Concurrency safety:** `PaymentService` wraps rule checks and persistence in a database transaction with a row-level lock (`lockForUpdate()`) on the order, so simultaneous payment attempts on the same order serialize. `OrderController@destroy` uses the same locking pattern when checking payments before deletion.

## Adding a New Payment Gateway

1. Create the gateway class implementing the interface (`app/Payments/PaymentGatewayInterface.php` defines the single method `process(string $outcome): string`, returning `"successful"` or `"failed"`):

```php
<?php

namespace App\Payments;

class StripeGateway implements PaymentGatewayInterface
{
    public function process(string $outcome): string
    {
        return $outcome === 'success' ? 'successful' : 'failed';
    }
}
```

2. Register it in `config/payment.php` — one line in `gateways`, one in `enabled`:

```php
'gateways' => [
    'credit_card' => CreditCardGateway::class,
    'paypal' => PaypalGateway::class,
    'stripe' => StripeGateway::class,          // add
],

'enabled' => [
    'credit_card' => env('PAYMENT_GATEWAY_CREDIT_CARD_ENABLED', true),
    'paypal' => env('PAYMENT_GATEWAY_PAYPAL_ENABLED', true),
    'stripe' => env('PAYMENT_GATEWAY_STRIPE_ENABLED', true),   // add
],
```

3. Optionally add `PAYMENT_GATEWAY_STRIPE_ENABLED=true` to `.env` / `.env.example`.
4. If you use the config cache, rebuild it: `php artisan config:clear` (or `php artisan config:cache`).
5. Run the relevant tests: `php artisan test --filter=PaymentGatewayTest`.

No changes are required in `PaymentService`, `PaymentController`, or `ProcessPaymentRequest`. The resolver verifies each configured class implements `PaymentGatewayInterface` at construction time; unregistered or disabled methods are rejected.

## Environment Configuration

| Variable | Purpose |
|---|---|
| `APP_KEY` | Laravel application key (`php artisan key:generate`) |
| `DB_*` | MySQL connection (`DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) |
| `JWT_SECRET` | JWT signing key (`php artisan jwt:secret`) |
| `JWT_TTL` | Token lifetime in minutes (default 60) |
| `PAYMENT_GATEWAY_CREDIT_CARD_ENABLED` | Enables/disables the `credit_card` gateway (default `true`) |
| `PAYMENT_GATEWAY_PAYPAL_ENABLED` | Enables/disables the `paypal` gateway (default `true`) |

Environment flags are read **only inside config files** (`config/payment.php`), following Laravel configuration-caching conventions. Disabling a gateway removes it from validation (422) and from the resolver at runtime; re-enabling restores it. Never commit real credentials — `.env` is gitignored.

## Postman Collection

- `postman/Order-Payment-API.postman_collection.json` — the full collection (Postman v2.1 schema)
- `postman/Local.postman_environment.json` — the `Local` environment

**Import:** Postman → *Import* → select both files. **Environment:** select `Local` from the environment selector (top right).

**Automatic capture:**

- After a successful **Login**, a test script stores `access_token` into `{{jwt_token}}`. All protected requests send it as a Bearer token automatically. Failed responses never overwrite stored variables.
- After a successful **Create Order**, `{{order_id}}` is stored and used by Show/Update/Payment requests.
- After a successful **Process Payment** (Success or Retry variant), `{{payment_id}}` is stored.
- `{{paymentless_order_id}}` must be set manually to an order with **no payments** — deletion is blocked (409) for orders with any payment record, so the Delete example must not reuse the paid order.

**Manual execution order:**

1. Register
2. Login
3. Create Order
4. Update Order (or the "Confirm" variant) — required before payment
5. Process Payment (Failure → Retry → Success variants are provided)
6. List Payments

## Testing

```bash
# Full suite
php artisan test

# Single class
php artisan test --filter=OrderTest
php artisan test --filter=PaymentTest
php artisan test --filter=PaymentGatewayTest

# Code style
./vendor/bin/pint --test
```

The suite runs against the isolated MySQL database configured in `phpunit.xml` (`DB_DATABASE=order_management_test`) — separate from your development database. `RefreshDatabase` migrates that test database automatically; no destructive commands should ever be run against the development database.

Current suite: **60 tests, 329 assertions, passing.**

Note on concurrency: the duplicate-payment test issues repeated **sequential** requests. It proves the one-successful-payment rule but does not prove true simultaneous-execution safety — that guarantee comes from the `lockForUpdate()` row lock inside the transaction, which a sequential test cannot exercise.

## Error Handling

| Status | When |
|---|---|
| 200 OK | Successful read or update |
| 201 Created | Order or payment created (both successful *and* failed payment outcomes) |
| 204 No Content | Order deleted (empty body) |
| 401 Unauthorized | Missing/invalid/expired JWT, or wrong login credentials |
| 404 Not Found | Missing or foreign-owned order (identical response), malformed non-numeric IDs |
| 409 Conflict | Updating a non-pending order, paying a non-confirmed order, duplicate successful payment, deleting an order with payments |
| 422 Unprocessable Entity | Validation failures (fields, statuses, methods, out-of-range numbers, aggregate total overflow) |

Validation errors follow Laravel's standard format:

```json
{
    "message": "The selected method is invalid.",
    "errors": {
        "method": ["The selected method is invalid."]
    }
}
```

## Design Decisions & Limitations

- **Simulated gateways only.** No SDKs, no external HTTP calls, no credentials. The `outcome` request field drives the result deterministically.
- **No refunds, payment callbacks, or webhooks.** Out of assignment scope.
- **Server-side totals.** Client-sent totals are ignored; all money math uses BCMath string arithmetic (no floats).
- **Transactions + row-level locking** protect payment processing and order deletion against conflicting concurrent operations.
- **No true parallel concurrency tests.** See the note under Testing.
- **Gateway configuration through env/config** — enabled flags are read via Laravel configuration; rebuild the config cache after changes.
- **No endpoints beyond the assignment.** No logout/refresh/profile, no payment update/delete, no refunds.
