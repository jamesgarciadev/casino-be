# Architecture Guide for Agents

This project is an **API-first, multi-client architecture** organized as a **polyrepo**. This guide covers three repos:

| Repo | Role | Stack |
|---|---|---|
| `casino-be` | Headless backend. Pure API, no UI. Single source of truth for data, auth, roles, and validation. | Laravel, Lighthouse (GraphQL), Redis, Sanctum |
| `casino-fe` | Admin console for **moderators, admins, and owners**. Internal-facing. | Next.js (App Router), Zustand, Zod, GraphQL client |
| `casino-web` | Customer-facing client app. Public-facing. | Next.js (App Router), Zustand, Zod, GraphQL client |

`fe` and `web` never talk to each other. Both talk only to `be` through GraphQL.

---

## Request Flow

```
fe / web (Next.js)
  ├─ Zustand ............ UI state only
  ├─ Zod ................ form + Server Action input validation (UX layer)
  ├─ Server Components .. fetch GraphQL server-side (initial data, SEO)
  ├─ Client Components .. urql (via same-origin /api/graphql proxy) for interactive queries and mutations
  ▼
be (Laravel + Lighthouse)
  ├─ @rules / validators  final, authoritative validation
  ├─ Policies / Gates ... authorization (roles enforced here)
  ├─ @tokenCan .......... Sanctum token scope (admin vs customer)
  ├─ @cache ............. resolver caching → Redis
  ├─ @throttle .......... rate limiting → Redis
  ├─ Queues ............. background jobs → Redis → Horizon
  ├─ Broadcasting ....... events → Redis → Reverb (subscriptions)
  ▼
Database (MySQL via Eloquent)
```

---

## Responsibilities by Tool

### Laravel (`be`)
- Owns all business logic, data access, authentication, and authorization.
- Exposes **no HTML views**. GraphQL is the only interface.
- Roles (moderator, admin, owner, customer) are enforced with Policies/Gates and `spatie/laravel-permission`.
- Admin operations are logged (who, what, when, before/after) with `spatie/laravel-activitylog`.

### GraphQL (Lighthouse in `be`)
- Schema-first: `graphql/schema.graphql` is the API contract.
- Admin-only fields are prefixed `admin` and guarded by `@guard` + `@tokenCan(ability: "admin")` + a `@can*` policy check. A customer token must never be able to call admin mutations.
- Use relationship directives (`@hasMany`, `@belongsTo`, `@with`) to avoid N+1 queries.
- Never remove fields used by clients; mark them `@deprecated` first.
- After any schema change run `composer schema:print` and commit `graphql/schema.public.graphql`.

### Redis (`be` only)
- Used through Laravel drivers: `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`.
- Jobs: resolver caching, rate limiting, queues (Horizon), broadcasting fan-out (Reverb).
- Frontends never connect to Redis directly.
- Cached data must be invalidated when a related mutation changes it.

### Next.js (`fe` and `web`)
- App Router with Cache Components. Server Components for initial data; Client Components for interactivity.
- Anything reading the session cookie renders inside `<Suspense>`.
- Server-side GraphQL calls forward the user's auth token and client IP.
- Never put secrets or admin-only logic in client bundles.
- `fe` should be deployable behind access restrictions (SSO, 2FA, IP allowlist).

### Zustand (`fe` and `web`)
- **Client UI state only**: modals, filters, selected tabs, wizard steps, theme, sidebar state.
- **Never store server data** in Zustand. Server data lives in the GraphQL client cache.
- Only used in Client Components (`"use client"`).
- No global module-level stores. Stores are created per provider mount (`createStore` + React context).

### Zod (`fe` and `web`)
- Validates form input (React Hook Form + `zodResolver`) and Server Action input.
- Zod is the **UX layer**, not the security layer. Laravel validation in `be` is authoritative.
- Base schemas are generated from the GraphQL schema (`src/gql/validation.ts`) and extended with rules that mirror `@rules` in `be`.
- Lighthouse validation errors (`extensions.validation`, keyed like `input.email`) are mapped back to form fields.

---

## Auth

- `be` issues Sanctum tokens with exactly one ability: `admin` (staff, 8h) or `customer` (30d).
- `fe` and `web` store tokens in **httpOnly cookies** set by Server Actions and send them as `Authorization: Bearer <token>`.
- Token-issuing mutations (`login`, `register`, `adminLogin`) only run from Server Actions; the `/api/graphql` proxy refuses them.
- Never store tokens in `localStorage`.
- Hiding UI in `fe` is not security. Every protected operation is authorized in `be`.

---

## Contract and Types

- The GraphQL schema in `be` is the single source of truth.
- `fe` and `web` run **GraphQL Codegen** (`npm run codegen`) against `be`'s `graphql/schema.public.graphql` to generate TypeScript types and Zod base schemas.
- Codegen runs in frontend CI so breaking schema changes fail the build.

---

## Rules for Agents

1. Business logic, validation, and authorization belong in `be`. Never only in `fe` or `web`.
2. Do not add UI, Blade views, or Inertia to `be`.
3. Do not put server data in Zustand.
4. Every Zod schema that validates data sent to `be` must have matching Laravel validation.
5. Do not access Redis or the database from `fe` or `web`.
6. Do not import code from `fe` into `web` or vice versa. Shared code goes in a shared package.
7. Admin features go in `fe`. Customer features go in `web`.
8. Schema changes in `be` must be backward compatible: add and deprecate, never remove or rename in place.
9. After changing `schema.graphql`, run `composer schema:print` in `be`, then `npm run codegen` in `fe` and `web`.
10. Admin mutations must be authorized and audit-logged.
