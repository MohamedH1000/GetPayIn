<<<<<<< HEAD
# GetPayIn
=======
# Flash Sale Checkout System

A Laravel 12 API implementation demonstrating high-concurrency flash sale checkout with proper stock management, temporary holds, and idempotent payment webhooks.

## Overview

This system implements a complete flash sale checkout flow with the following features:

- **Concurrent-safe stock management** using database row locking
- **Temporary holds** (2-minute reservation) preventing overselling
- **Order creation** from valid holds
- **Idempotent payment webhooks** handling duplicates and out-of-order delivery
- **Automatic hold expiry** with background processing
- **Comprehensive testing** covering race conditions and edge cases

## Architecture

### Core Components

1. **Products**: Limited-stock items available for flash sale
2. **Holds**: Temporary stock reservations (2-minute expiry)
3. **Orders**: Pre-payment and final payment states
4. **Payment Webhooks**: Idempotent payment status updates

### Concurrency Controls

- Database row locking (`LOCK FOR UPDATE`) prevents race conditions
- Atomic transactions ensure data consistency
- Optimistic concurrency control with proper status checking
- Background jobs for periodic hold expiry

## API Endpoints

### GET `/api/products/{id}`
Returns product details with real-time available stock.

**Response:**
```json
{
  "id": 1,
  "name": "Flash Sale Widget",
  "description": "Limited edition widget...",
  "price": "29.99",
  "total_stock": 100,
  "available_stock": 87,
  "updated_at": "2024-01-01T12:00:00.000000Z"
}
```

### POST `/api/holds`
Creates a temporary stock reservation.

**Request:**
```json
{
  "product_id": 1,
  "quantity": 3
}
```

**Response (201):**
```json
{
  "hold_id": 123,
  "hold_token": "abc123def456...",
  "expires_at": "2024-01-01T12:02:00.000000Z",
  "product_id": 1,
  "quantity": 3
}
```

**Error (409):**
```json
{
  "error": "Insufficient stock available",
  "message": "Not enough items are available to create this hold"
}
```

### POST `/api/orders`
Creates an order from a valid hold.

**Request:**
```json
{
  "hold_id": 123
}
```

**Response (201):**
```json
{
  "order_id": 456,
  "product_id": 1,
  "quantity": 3,
  "total_amount": "89.97",
  "status": "pending",
  "created_at": "2024-01-01T12:01:30.000000Z",
  "payment_id": null
}
```

### POST `/api/payments/webhook`
Processes payment status updates with idempotency.

**Request:**
```json
{
  "payment_id": "pay_123456",
  "order_id": 456,
  "status": "success",
  "idempotency_key": "webhook_abc123",
  "amount": 89.97
}
```

**Response (200):**
```json
{
  "message": "Webhook processed successfully",
  "idempotency_key": "webhook_abc123",
  "payment_id": "pay_123456",
  "order_status": "paid"
}
```

## Setup Instructions

### Prerequisites

- PHP 8.2+
- MySQL 8.0+
- Composer
- Laravel cache driver (file, database, Redis, or Memcached)

### Installation

1. **Clone and install dependencies:**
   ```bash
   git clone <repository-url>
   cd flash-sale-checkout
   composer install
   ```

2. **Environment setup:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. **Configure database:**
   ```bash
   # Edit .env with your database credentials
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=flash_sale
   DB_USERNAME=your_username
   DB_PASSWORD=your_password
   ```

4. **Run migrations and seed data:**
   ```bash
   php artisan migrate
   php artisan db:seed
   ```

5. **Start the development server:**
   ```bash
   php artisan serve
   ```

6. **Start the queue worker (recommended):**
   ```bash
   php artisan queue:work
   ```

### Running Tests

```bash
# Run all tests
php artisan test

# Run specific test file
php artisan test --filter ProductApiTest

# Run with coverage
php artisan test --coverage
```

## Configuration

### Hold Duration

Default hold duration is 2 minutes. To change:

```php
// app/Models/Hold.php
const HOLD_DURATION_MINUTES = 5; // Change to 5 minutes
```

### Cache Configuration

Ensure your cache driver is configured in `.env`:

```
CACHE_DRIVER=redis  # or file, database, memcached
```

### Queue Configuration

For production, configure a proper queue driver:

```
QUEUE_CONNECTION=redis  # or database, sqs, etc.
```

## Monitoring and Logging

### Log Locations

- **Application logs**: `storage/logs/laravel.log`
- **Failed jobs**: `storage/logs/failed_jobs.log`
- **Queue logs**: Check your queue worker output

### Key Log Messages

- **Hold creation**: `"Hold created successfully"`
- **Order creation**: `"Order created successfully"`
- **Payment processing**: `"Webhook processed successfully"`
- **Hold expiry**: `"Hold expiry job completed"`
- **Concurrency events**: High-contention operations are logged with retry counts

### Metrics to Monitor

1. **Hold creation rate**: Monitor `storage/logs/laravel.log` for `"Hold created successfully"`
2. **Payment webhook duplicates**: Check `"Duplicate webhook received"` messages
3. **Stock contention**: Look for concurrent hold creation failures
4. **Queue backlog**: Monitor failed jobs and queue lengths

## Business Logic and Invariants

### Stock Management

1. **No overselling**: Total held + paid stock ≤ total product stock
2. **Atomic operations**: All stock modifications use database transactions
3. **Cache consistency**: Available stock cached for 5 seconds maximum

### Hold System

1. **2-minute expiry**: Holds automatically expire after 2 minutes
2. **Unique tokens**: Each hold has a cryptographically secure token
3. **Status transitions**: `active` → `expired` or `active` → `converted`

### Order States

1. **Lifecycle**: `pending` → `paid` or `pending` → `cancelled`/`expired`
2. **Hold association**: Each order can have at most one hold
3. **Finality**: Paid orders cannot be modified

### Payment Webhooks

1. **Idempotency**: Same `idempotency_key` returns same result
2. **Order of delivery**: Handles webhooks arriving before order creation
3. **Amount validation**: Validates payment amounts when provided
4. **Deduplication**: Prevents duplicate payment processing

## Testing Strategy

### Test Coverage

- **Unit tests**: Model logic and business rules
- **Feature tests**: API endpoints and workflows
- **Integration tests**: Complete flash sale scenarios
- **Concurrency tests**: Race condition handling

### Key Test Scenarios

1. **Parallel hold creation**: 25 concurrent requests for 20 items
2. **Hold expiry**: Stock restoration after expiry
3. **Webhook idempotency**: Duplicate webhook handling
4. **Out-of-order delivery**: Webhook before order creation
5. **Payment validation**: Amount mismatch handling

### Running Performance Tests

For load testing, use tools like Apache Bench or k6:

```bash
# Basic load test for product endpoint
ab -n 1000 -c 50 http://localhost:8000/api/products/1

# Hold creation load test
ab -n 500 -c 20 -p hold_payload.json -T application/json http://localhost:8000/api/holds
```

## Production Considerations

### Database Optimization

1. **Indexes**: All foreign keys and status fields are indexed
2. **Row locks**: Use `LOCK FOR UPDATE` for critical sections
3. **Connection pooling**: Configure appropriate pool sizes
4. **Read replicas**: Consider for read-heavy endpoints

### Caching Strategy

1. **Product availability**: Cache for 5 seconds
2. **Hold lookups**: Cache active holds by token
3. **Order status**: Cache recent order lookups

### Queue Configuration

1. **Multiple workers**: Scale based on webhook volume
2. **Retry policies**: Configure exponential backoff
3. **Dead letter queue**: Handle permanently failed jobs

### Monitoring and Alerting

1. **Error rates**: Monitor 4xx/5xx responses
2. **Queue depth**: Alert on growing backlogs
3. **Stock accuracy**: Periodic stock reconciliation
4. **Performance**: Response time monitoring

## Security Considerations

### Input Validation

- All API endpoints validate input data
- SQL injection protection via Eloquent ORM
- Rate limiting recommended for production

### Data Protection

- Hold tokens are cryptographically secure
- Sensitive operations use database transactions
- Payment webhook payloads are logged for audit

## Troubleshooting

### Common Issues

1. **Stock not updating**: Check cache configuration and queue worker
2. **Duplicate orders**: Verify hold token uniqueness constraints
3. **Missing webhooks**: Check queue configuration and failed jobs
4. **Performance issues**: Review database indexes and query logs

### Debug Commands

```bash
# Check failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Clear application cache
php artisan cache:clear

# Check current holds
php artisan tinker
>>> Hold::where('status', 'active')->count()

# Check stock availability
>>> Product::find(1)->available_stock
```

## API Usage Examples

### Complete Flash Sale Flow

```bash
# 1. Check product availability
curl -X GET http://localhost:8000/api/products/1

# 2. Create hold
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "quantity": 2}'

# 3. Create order (using hold_id from step 2)
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"hold_id": 123}'

# 4. Process payment (using order_id from step 3)
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "payment_id": "pay_123",
    "order_id": 456,
    "status": "success",
    "idempotency_key": "webhook_abc",
    "amount": 59.98
  }'
```

### Bulk Testing Script

```bash
#!/bin/bash
# Test concurrent hold creation
for i in {1..20}; do
  curl -s -X POST http://localhost:8000/api/holds \
    -H "Content-Type: application/json" \
    -d '{"product_id": 1, "quantity": 1}' \
    -o "hold_$i.json" &
done

wait
echo "All hold requests completed"
```

## Contributing

1. Fork the repository
2. Create a feature branch
3. Add tests for new functionality
4. Ensure all tests pass
5. Submit a pull request

## License

This project is open-sourced software licensed under the MIT license.
>>>>>>> origin/master
