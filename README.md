# casino-be

Headless GraphQL API for **0dds.bet**: esports matches, play-money wallets and bets, live updates, and staff
administration. No UI. See [ARCHITECTURE.md](ARCHITECTURE.md) for how this fits with `casino-fe`, `casino-web`
and `casino-sim`.

## Tech stack

| Layer | What we use |
|---|---|
| Language and framework | PHP 8.3+, Laravel 13 |
| API | GraphQL with Lighthouse 6, schema first (`graphql/schema.graphql`); GraphiQL in development |
| Auth | Laravel Sanctum tokens with scopes `customer`, `admin` and `sim`; roles and permissions with spatie/laravel-permission; audit log with spatie/laravel-activitylog |
| Realtime | Laravel Reverb (self-hosted websockets) carrying GraphQL subscriptions |
| Data | MySQL 8.4 and Redis 7.4 (cache and subscription storage, predis client), both from `docker-compose.yml` |
| External data | PandaScore REST API, called server-side only |
| Tests and style | PHPUnit 12 (SQLite in memory), Laravel Pint |

## Prerequisites

- PHP 8.3 or newer and Composer 2
- Docker, for MySQL and Redis
- Optional: a PandaScore API token in `PANDASCORE_API_TOKEN`. The `esports*` queries need it, and so does casino-sim,
  which reads matchups through them. Matches, wallets and bets work without it.

Ports: **8000** API, **6001** Reverb, 3306 MySQL, 6379 Redis.

## Start it up

Every other repo needs this one running first.

```sh
docker compose up -d --wait      # 1. MySQL (database casino) and Redis
composer install                 # 2. dependencies
cp .env.example .env && php artisan key:generate
php artisan reverb:install       # 3. first time only: generates REVERB_APP_ID/KEY/SECRET into .env
php artisan migrate --seed       # 4. tables, demo accounts and 8 demo matches
php artisan serve                # 5. the API on http://localhost:8000/graphql
php artisan reverb:start         # 6. websockets on :6001 (a second terminal)
php artisan sim:token            # 7. only for casino-sim: prints its token once
```

It is working when GraphiQL (dev only) opens at http://localhost:8000/graphiql and `{ matches { teamA teamB } }`
returns the demo matches. Without step 6 everything still works, but live scores and odds stop updating in the apps.

Local demo accounts (password `Password1!`): `customer@casino.test`, `moderator@casino.test`,
`admin@casino.test`, `owner@casino.test`.

## Password policy

New passwords (registration) must be 8–64 characters with an uppercase letter, a lowercase letter, a number and a
special character, and no spaces: `App\Rules\StrongPassword`. casino-web mirrors it in Zod. Sign-in does not
re-check the policy, so older accounts keep working.

## Roles and access

| Role | Signs in to | Permissions |
|---|---|---|
| customer | web (`login`) | — |
| moderator | fe (`adminLogin`) | `users.view` |
| admin | fe | `users.view`, `activity.view` |
| owner | fe | all, incl. `users.manage-roles` |
| simulator | nothing (service account) | `matches.manage` via a `sim` token from `php artisan sim:token` |

Every `admin*` field requires an `admin`-scoped token **and** the matching policy, so a customer token
is rejected even if its user happens to be staff.

## Betting (play money)

0dds.bet's matches, wallets and bets, ported from the `casino-design` backend. Credits (CR) have no cash value.

- `matches(state, game)`, `match(id)`: public. Live first, then by start time. Stored as `App\Models\Fixture`
  (exposed as `Match`; `match` is reserved in PHP). Unrelated to `EsportsMatch`, which is raw PandaScore data.
- `wallet`, `myBets`, `placeBet`, `placeComboBet`, `cashOutBet`, `topUpWallet`: customer token only. Wallets open with 1,000 CR (on registration, or on
  first use for older accounts); top-up adds 500.
- A match offers `markets` (winner, map handicap, total rounds, pistol round…), each with `outcomes` and their odds
  (tables `markets`, `market_outcomes`). The WINNER market follows the match's `oddsA`, `oddsB` and `winner`
  (`Fixture::syncWinnerMarket`); casino-sim opens, prices, suspends and settles every other market. Only OPEN markets
  take bets.
- A bet is a stake on one or more `selections` (table `bet_selections`), each an outcome (`outcomeId`, or `side` for the
  WINNER market): `placeBet` makes a SINGLE, `placeComboBet` a COMBO (parlay) of two or more picks, each from a
  different match; two picks in one match are refused. Both run in one transaction with the wallet and match rows
  locked. Odds are read from the outcomes, never the client, and stored on each selection. Picks are accepted while a
  match is upcoming or live; a combo with any finished match is refused whole.
- Total odds multiply the picks' odds in whole cents, rounding half up after each pick, capped at 1,000,000
  (`Bet::totalOdds`). Payout is `stake × total odds` rounded half up to whole credits (`Bet::payoutFor`).
- Settlement marks each pick WON or LOST as soon as its market settles, so a pistol-round pick pays at that round, not
  at the end of the match. A bet is LOST as soon as one pick loses and WON (paid) once every pick has won; until then a
  combo stays PENDING.
- `cashOutBet` withdraws an open single (not a combo) while its market is open, for
  `stake × placed odds ÷ current odds`, rounded half up
  (`Bet::cashOutValue`, no fee: the odds already carry the margin). The client sends the value it showed; a lower
  current value is refused ("Cash-out value changed to N CR."), an equal or higher one is paid. The bet and its pick
  become CASHED_OUT, so settlement skips them. `CashOutService` locks fixture → bet → wallet, the same order as a feed
  update that settles, so the two can't both pay.
- `myBets(open, first, before)`: `open` true/false splits pending from settled (WON, LOST, CASHED_OUT); `first` and
  `before` (last id held) page newest first by id. Seeded demo matches never finish, so combos that include
  one stay pending.
- `simCreateMatch`, `simUpdateMatch`: casino-sim's feed (`sim` token + `matches.manage`). State only moves forward
  (upcoming → live → finished); finishing needs a winner and every market settled, and settles the match's bets
  (`SettlementService`, idempotent). `simUpdateMatch` also takes `maps` (per-map scores) and `markets`
  (`MarketFeedService`): a market keeps its type, map, half, round, line and outcomes for life, settles with exactly
  one WON outcome, and never changes once settled.
- Local seed: the design's 8 demo matches (2 live), static. Re-running `db:seed` refreshes their start times.

Get a token for casino-sim (shown once; `--revoke` replaces old ones):

```sh
php artisan sim:token
```

## Live updates (GraphQL subscriptions over Reverb)

`subscription { matchUpdated(game) { ... } }` pushes a match every time casino-sim creates or updates it. Reverb is
Laravel's self-hosted websocket server (no cloud service). How a client subscribes:

1. POST the subscription to `/graphql` like any query. The response carries
   `extensions.lighthouse_subscriptions.channel` (a `private-lighthouse-…` channel).
2. Connect to Reverb with a Pusher client (`ws://localhost:6001`, app key `REVERB_APP_KEY`), authorising the channel at
   `POST /graphql/subscriptions/auth`.
3. Each push arrives as a `lighthouse-subscription` event with the query result.

Only public match data goes over sockets. Wallets and bets are re-queried when a pushed match is `FINISHED`.

Each subscription is stored (Redis) and pushed to individually, so every update costs one Reverb call per open
subscription. Subscriptions expire after `LIGHTHOUSE_SUBSCRIPTION_STORAGE_TTL` (1 hour) and clients re-subscribe
before that, which keeps closed tabs from piling up. For many concurrent viewers, switch the public feed to a single
broadcast channel (or queue broadcasts) rather than per-subscriber pushes.
Broadcast failures (e.g. Reverb not running) are logged and never fail the sim's mutation. Tests use the log
broadcaster and array storage (see `phpunit.xml`).

## Esports data (PandaScore)

Public queries `videogames`, `esportsMatches(window, game, page, perPage)` and `esportsMatch(id)` serve PandaScore
data through `App\Services\PandaScore\EsportsCatalog`:

- Clients never call PandaScore and never see the token (`PANDASCORE_API_TOKEN`, server-side only).
- Responses are cached in Redis: running 15s, upcoming 1 min, past 10 min, single match 30s, videogames 1 day. This
  keeps us well under the plan's ~1000 requests/hour. If PandaScore fails, the last good copy (up to a day old) is
  served; with no copy, clients get "Esports data is temporarily unavailable." and the cause is logged.
- `game` is a `Videogame.slug` (e.g. `cs-go`, `dota-2`); unknown slugs return no matches without an upstream call.
- The current plan has **no odds**: `/odds` and `/bets` return 404.

On Windows, PHP needs a CA bundle to reach PandaScore over HTTPS (`cURL error 60` otherwise). Set `curl.cainfo` and
`openssl.cafile` in `php.ini`, e.g. to Git's `C:/Program Files/Git/mingw64/etc/ssl/certs/ca-bundle.crt`.

## Commands

```sh
composer test            # unit + feature tests (SQLite in-memory, PandaScore faked)
composer test:live       # contract tests against the real PandaScore API (uses the .env token, ~25 requests)
composer schema:print    # regenerate graphql/schema.public.graphql for frontend codegen
vendor/bin/pint          # code style
```

## Tests

| Folder | Covers |
|---|---|
| `tests/Unit` | pure logic with no app or database (role → permission map) |
| `tests/Feature/GraphQL` | the API contract: auth, token scopes and expiry, rate limits, admin queries and mutations |
| `tests/Feature/Services` | `AuthService`, `UserRoleService`, `PandaScoreClient` and `EsportsCatalog` called directly |
| `tests/Live` | real PandaScore endpoints and field contract; excluded from `composer test` |
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
  Until then broadcasts run inline (`LIGHTHOUSE_QUEUE_BROADCASTS=false`).
