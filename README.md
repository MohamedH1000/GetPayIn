# Flash Sale Checkout System – README

## Project Overview & My Approach

This is a **production-grade, high-concurrency flash sale checkout API** built with **Laravel 12** that correctly solves the classic "flash sale overselling" problem that plagues most implementations.

### Why this implementation is special (and actually correct)

Most flash sale tutorials use simple `quantity--` which **fails under concurrency**.  
This project implements the real-world pattern used by Ticketmaster, Amazon Flash Sales, Shopify, etc.:

1. **Temporary Holds** (2-minute reservation) → prevents users from abandoning carts and locking stock forever  
2. **Pessimistic row locking** (`FOR UPDATE`) → 100% guarantees no overselling even with 1000+ concurrent requests  
3. **Idempotent payment webhooks** → safely handles duplicates, retries, and out-of-order delivery (critical for Stripe, PayPal, etc.)  
4. **Background hold expiry** → automatically returns stock to pool after 2 minutes  
5. **Comprehensive concurrency test suite** → proves it works under real race conditions

### Core Invariants (never broken)

- `held_stock + paid_stock ≤ total_stock` → **always true**
- No order can be created without a valid active hold
- Paid orders are immutable
- Same webhook twice → processed exactly once

### Tech Stack

- Laravel 12 (PHP 8.2+)
- MySQL 8.0+ (uses row-level locking)
- Queue system (Redis/database) for hold expiry & webhook processing
- PHPUnit + Laravel's HTTP tests with concurrency simulation

### Setup (Copy-Paste Ready)

```bash
# 1. Clone & install
git clone https://github.com/yourname/flash-sale-checkout.git
cd flash-sale-checkout
composer install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Database (edit .env first with your MySQL credentials)
php artisan migrate --seed

# 4. Start everything
php artisan serve                 # API at http://localhost:8000
php artisan queue:work --daemon   # Required for hold expiry & webhooks