# Seat Reservation API

A Laravel REST API for reserving limited seats at an event, built for the
Endow Tech Backend Intern technical assessment. The focus of this
implementation is correct backend behavior under concurrency and partial
failure — not just CRUD.

## Requirements

- PHP 8.2+
- Composer
- MySQL 8

This is a pure JSON API — there's no frontend, so no Node/npm/build step is
needed anywhere in this project.

## Setup

```bash
git clone <this-repo-url>
cd <repo-directory>

composer install

cp .env.example .env
php artisan key:generate
```

Edit `.env` and set your database credentials:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=seat_reservation
DB_USERNAME=your_mysql_user
DB_PASSWORD=your_mysql_password
```

Create the database, then run migrations and seed some demo data:

```bash
php artisan migrate
php artisan db:seed
```

Seeding creates two users (`demo@example.com` / `second@example.com`, both
password `password`) and three events: one wide open (capacity 100), one
almost full (4/5 seats taken), and one sold out (1/1).

Run the API:

```bash
php artisan serve
```

### Running tests

Tests run against a **separate** database so they never touch your dev data.
Create it once:

```sql
CREATE DATABASE seat_reservation_test;
```

`.env.testing` is already configured to point at it (see that file — adjust
the credentials to match your local MySQL user). Then:

```bash
php artisan test
```

25 tests / 163 assertions covering every functional requirement below,
including a test that fires 50 genuinely concurrent HTTP requests at the
last seat of an event (see [Testing the concurrency guarantee](#testing-the-concurrency-guarantee)).

## What it does

Authenticated users can reserve one seat on an event and cancel it later,
subject to these rules:

1. Reserving a nonexistent event returns `404`.
2. Reserving an event with no remaining seats returns `409`.
3. A user cannot hold two simultaneously-active (`reserved`) reservations
   for the same event — a second attempt returns `409`.
4. A user who previously cancelled their reservation can reserve the same
   event again.
5. `reserved_count` can never exceed `capacity`, under any timing.
6. Correctness holds even if 50 users try to reserve the last seat at
   exactly the same time — exactly one succeeds, the rest are rejected.
7. A failure partway through a reservation never leaves the database in an
   inconsistent state (e.g. a reservation row without a matching counter
   change).
8. Every response uses an appropriate HTTP status code and JSON body.
9. Cancelling sets the reservation's status to `cancelled` and decrements
   `reserved_count`.
10. `reserved_count` can never go negative on cancellation.
11. Only the reservation's owner can cancel it.
12. Cancelling an already-cancelled reservation is rejected (`409`), not a
    silent no-op or a double-decrement.

## API

Auth is a bearer token (Laravel Sanctum): `Authorization: Bearer {token}`.

### `POST /api/register`
Create a user. Body: `{ "name", "email", "password", "password_confirmation" }`.
Returns `201` with the created user and a token.

### `POST /api/login`
Body: `{ "email", "password" }`. Returns `200` with a token, or `401` on bad
credentials.

### `GET /api/events` / `GET /api/events/{event}`
List events, or fetch one event's `capacity` / `reserved_count`. Requires
auth.

### `POST /api/events/{event}/reserve`
Reserve one seat for the authenticated user.

| Outcome | Status | Body |
|---|---|---|
| Success | `201` | `{ "message": "Reservation confirmed.", "data": { id, event_id, user_id, status: "reserved", created_at } }` |
| Event doesn't exist | `404` | `{ "message": "Resource not found." }` |
| Event is full | `409` | `{ "message": "This event is fully booked." }` |
| Duplicate active reservation | `409` | `{ "message": "You already have an active reservation for this event." }` |
| No/invalid token | `401` | `{ "message": "Unauthenticated." }` |

### `POST /api/reservations/{reservation}/cancel`
Cancel the authenticated user's own reservation.

| Outcome | Status | Body |
|---|---|---|
| Success | `200` | `{ "message": "Reservation cancelled.", "data": { id, event_id, user_id, status: "cancelled", updated_at } }` |
| Reservation doesn't exist | `404` | `{ "message": "Resource not found." }` |
| Belongs to another user | `403` | `{ "message": "You are not authorized to cancel this reservation." }` |
| Already cancelled | `409` | `{ "message": "This reservation is already cancelled." }` |

### Quick manual test

```bash
TOKEN=$(curl -s -X POST http://127.0.0.1:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"demo@example.com","password":"password"}' \
  | php -r 'echo json_decode(file_get_contents("php://stdin"))->token;')

curl -X POST http://127.0.0.1:8000/api/events/1/reserve \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"

curl -X POST http://127.0.0.1:8000/api/reservations/1/cancel \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json"
```

## How concurrency and consistency are handled

This is the part the assessment actually grades, so here's the reasoning in
full — a shorter version is also in the demo video.

**The problem.** A naive implementation checks `reserved_count < capacity`,
then creates a reservation, then increments the counter, as three separate
steps. Under concurrent requests, two (or fifty) processes can all read the
same `reserved_count`, all pass the check before anyone writes, and all
proceed to reserve — overbooking the event. This is a classic
check-then-act race condition.

**The fix: pessimistic row locking inside a database transaction.**
`app/Services/ReservationService.php` wraps every reserve/cancel in
`DB::transaction()` and takes an exclusive lock on the event row with
`Event::lockForUpdate()` (i.e. `SELECT ... FOR UPDATE`) before reading or
changing anything. Any other transaction trying to lock the *same* event row
has to wait until the first one commits or rolls back — so reservation
attempts for one event are fully serialized, while different events are
completely unaffected by each other's locks. All of the following happen
**inside that one locked transaction**:

1. Check for an existing active (`reserved`) reservation for this user on
   this event → duplicate rejection (can't race with a concurrent request,
   because it's checked under the same lock that also gates the write).
2. Check `reserved_count < capacity` → capacity rejection.
3. Create the reservation (or flip a previously-`cancelled` row for this
   user back to `reserved`, so a user who cancelled can rebook without
   accumulating duplicate rows).
4. Increment `reserved_count`.

Because steps 1–4 are one atomic unit, a failure anywhere in that sequence
rolls back *everything* — there's no way to end up with a reservation row
that was created but never counted, or a counter that was bumped without a
matching row. `tests/Feature/ReservationConsistencyTest.php` proves this by
deliberately injecting a failure partway through (via a model event
listener) and asserting nothing was left behind.

Cancellation follows the identical pattern and — critically — locks the
**event row before the reservation row**, in the same order `reserve()`
does, so the two operations can never deadlock against each other.

`reserved_count` is decremented with a floor check so it can never go
negative, and re-cancelling an already-cancelled reservation is rejected
(409) rather than silently double-decrementing.

An alternative considered was a single atomic
`UPDATE events SET reserved_count = reserved_count + 1 WHERE id = ? AND reserved_count < capacity`
and checking the affected-row count. That's lock-free and slightly cheaper,
but doesn't give a natural transactional boundary for also creating the
reservation row and checking for duplicates in the same place — row locking
was chosen for that clarity, since it lets one transaction own the entire
check-and-write sequence.

### Testing the concurrency guarantee

`tests/Feature/ReservationConcurrencyTest.php` proves this two ways:

1. **Mechanism-level proof** — opens two independent raw PDO connections,
   has one lock the event row and hold it, and asserts the second connection
   genuinely blocks trying to acquire the same lock. This is deterministic
   and demonstrates the actual primitive the whole guarantee rests on.
2. **Outcome-level proof** — spins up a real second `php artisan serve`
   process, creates 50 users, and fires all 50 reservation requests at an
   event with exactly 1 seat left **concurrently** over real HTTP (via
   Guzzle's async request pool), so the requests genuinely overlap at the OS
   and database-connection level rather than running one at a time. The test
   asserts exactly 1 of the 50 gets a `201`, the other 49 get `409`, and
   `reserved_count` ends at exactly `1` — never higher.

## Project structure

```
├── app/
│   ├── Exceptions/
│   │   ├── EventFullException.php            # → 409
│   │   ├── DuplicateReservationException.php  # → 409
│   │   ├── AlreadyCancelledException.php      # → 409
│   │   └── UnauthorizedCancellationException.php # → 403
│   │
│   ├── Http/
│   │   ├── Controllers/
│   │   │   └── Api/
│   │   │       ├── AuthController.php         # register/login (supporting)
│   │   │       ├── EventController.php        # GET /events, GET /events/{event}
│   │   │       └── ReservationController.php  # reserve, cancel (the graded endpoints)
│   │   │
│   │   ├── Requests/
│   │   │   ├── RegisterRequest.php
│   │   │   └── LoginRequest.php
│   │   │
│   │   └── Resources/
│   │       ├── EventResource.php              # JSON shape for Event
│   │       └── ReservationResource.php        # JSON shape for Reservation
│   │
│   ├── Models/
│   │   ├── User.php
│   │   ├── Event.php
│   │   └── Reservation.php
│   │
│   ├── Services/
│   │   └── ReservationService.php             # ALL concurrency-critical logic lives here
│   │
│   └── Providers/
│       └── AppServiceProvider.php
│
├── bootstrap/
│   └── app.php                                # exception → HTTP status mapping registered here (Laravel 11 style)
│
├── config/
│   ├── database.php
│   └── sanctum.php
│
├── database/
│   ├── factories/
│   │   ├── EventFactory.php
│   │   └── UserFactory.php
│   │
│   ├── migrations/
│   │   ├── 0001_01_01_000000_create_users_table.php   # default Laravel
│   │   ├── xxxx_xx_xx_create_events_table.php
│   │   └── xxxx_xx_xx_create_reservations_table.php
│   │
│   └── seeders/
│       └── DatabaseSeeder.php                 # seeds demo users + events for the video
│
├── routes/
│   ├── api.php                                # all endpoints listed above
│   └── console.php
│
├── tests/
│   ├── Feature/
│   │   ├── ReservationTest.php                # FR1–FR4 individual behavior tests
│   │   ├── ReservationConcurrencyTest.php      # FR5, FR6 — the 50-concurrent-users test
│   │   ├── ReservationConsistencyTest.php      # FR7 — forced-failure rollback test
│   │   └── CancellationTest.php                # FR9–FR12
│   │
│   └── Unit/
│       └── ReservationServiceTest.php
│
├── .env.example                               # committed; real .env is gitignored
├── .gitignore
├── composer.json
├── phpunit.xml
└── README.md                                  # this file
```

All business and concurrency logic lives in `ReservationService`, not in
controllers or model hooks — that keeps the one thing this assessment cares
about auditable in a single file.
