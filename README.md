# casino-be

Headless GraphQL API for Casino. Laravel 13 + Lighthouse + Sanctum + Redis + MySQL. No UI.
See [ARCHITECTURE.md](ARCHITECTURE.md) for how this fits with `casino-fe` and `casino-web`.

## Run locally

```sh
docker compose up -d --wait      # MySQL (casino) on 3306, Redis on 6379
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate --seed
php artisan serve                # http://localhost:8000/graphql
```

GraphiQL (dev only): http://localhost:8000/graphiql

Local demo accounts (password `password`): `customer@casino.test`, `moderator@casino.test`,
`admin@casino.test`, `owner@casino.test`.

## Roles and access

| Role | Signs in to | Permissions |
|---|---|---|
| customer | web (`login`) | — |
| moderator | fe (`adminLogin`) | `users.view` |
| admin | fe | `users.view`, `activity.view` |
| owner | fe | all, incl. `users.manage-roles` |

Every `admin*` field requires an `admin`-scoped token **and** the matching policy, so a customer token
is rejected even if its user happens to be staff.

## Commands

```sh
composer test            # unit + feature tests (SQLite in-memory)
composer schema:print    # regenerate graphql/schema.public.graphql for frontend codegen
vendor/bin/pint          # code style
```

## Tests

| Folder | Covers |
|---|---|
| `tests/Unit` | pure logic with no app or database (role → permission map) |
| `tests/Feature/GraphQL` | the API contract: auth, token scopes and expiry, rate limits, admin queries and mutations |
| `tests/Feature/Services` | `AuthService` and `UserRoleService` called directly |
| `tests/Feature/Models`, `Seeders` | user search scope, roles seeded in sync with `App\Enums\Role` |

`Tests\TestCase` seeds roles for every test that uses `RefreshDatabase` and provides `userWithRole()`,
`adminToken()`, `customerToken()` and `forgetAuth()` (call it between requests that switch tokens).

Browser tests live in `casino-web` and `casino-fe` (`npm run test:e2e`). Each starts its own copy of this API on
port 8100 / 8101 against a separate `casino_e2e_*` database.

## Layout

- `graphql/schema.graphql`: API contract
- `app/GraphQL/Mutations`: thin resolvers that delegate to `app/Services`
- `app/GraphQL/Directives/TokenCanDirective.php`: `@tokenCan` token-scope check
- `app/Policies`: authorization
- `app/Enums`: roles, permissions, token abilities

## Not yet set up

- **Horizon** needs `ext-pcntl`, which isn't available on Windows. Add it when the API is containerized.
- **Reverb** (GraphQL subscriptions): add with the first real-time feature.
