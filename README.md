# Kachow - a personal AI assistant

Kachow is a self-hosted, bilingual (English + Danish) personal assistant built around
Google Gemini tool-calling. You talk to it in plain language and it *does things* -
logs a workout, reads a receipt photo, checks your calendar, drafts an email, tracks a
cycle - rendering the result as an interactive card in the chat rather than a wall of
text.

This repository (`kachow-app`) is the **brain**: the domain logic, data layer, tool
definitions and the Gemini loop. The web front-end / PWA lives in a companion repo,
[`kachow-web`](https://github.com/christianmorkeberg/kachow-web), which consumes these
classes.

> A personal project - built to actually use daily, not as a demo. Two real users
> (one English, one Danish), which is why bilingual routing and per-user data are
> first-class concerns.

## Screenshots

| Ask for your plan | Snap a receipt | Summarise email |
|---|---|---|
| ![Workout plan](docs/screenshot-workout.png) | ![Receipt capture](docs/screenshot-receipt.png) | ![Email summary](docs/screenshot-email.png) |
| *“What should I bench today?” → pulls your training plan as a tickable checklist card.* | *A receipt photo → Gemini reads it into an editable expense card (vendor, total, VAT, line items) you confirm in one tap.* | *“What’s my newest email about?” → fetches + summarises, with the full message rendered safely.* |

## What makes it interesting

- **Tool-calling with 80+ tools, kept precise.** Sending every tool on every request
  hurts model accuracy, so a deterministic `ToolSelector` narrows ~80 tools across 18
  domains down to the handful relevant to each message (bilingual keyword routing),
  falling back to everything only when genuinely ambiguous. Routing is guarded by a
  **self-test** (`bin/routing-test.php`, 100+ fixtures) so a missed Danish phrase is
  caught before deploy, not in production.
- **“Card in chat” mechanism.** Any tool can return a `_render` payload; the loop
  captures it, strips it from the model’s context (so the model summarises rather than
  re-lists), and the web layer draws it as an interactive widget - a tickable workout
  plan, an editable receipt, an animated weather card, a menstrual-cycle ring, etc.
- **Hand-rolled data-viz, no chart library.** The interactive charts - a workout-progression
  line chart (est-1RM / top-set / volume, tested maxes vs Epley estimates), a work-hours bar
  chart (per day / week / month), the cycle ring - are drawn as inline SVG in vanilla JS,
  tap-to-inspect on mobile and `prefers-reduced-motion` aware. Each chart is a `Data` method
  that shapes points server-side + a small render function, reused across cards.
- **Vision + structured extraction.** Receipt photos go to Gemini in JSON mode and come
  back as typed expense fields (with line items, multi-currency and duplicate
  detection) that the user confirms before anything is booked.
- **Self-written IMAP + SMTP clients.** PHP 8.4 removed `ext-imap`, so the mail stack is
  pure-PHP (TLS sockets, literal-aware IMAP reader, dot-stuffing SMTP) behind a single
  `EmailProvider` interface - alongside Gmail (API) and Outlook (MS Graph) providers.
- **Security by construction.** Every `Data/` query is hard-scoped to the acting user;
  the *only* cross-user reads go through one audited `ConnectionAccess` gate (an
  accepted connection + an explicit share scope). OAuth refresh tokens are encrypted at
  rest with libsodium `secretbox`. Secrets live in `.env`, never in the repo.

## Features

- **Fitness** - workout logging, dynamic training plans as tickable checklists, and a
  progression chart per exercise (with per-user name canonicalisation so “squat/squats/backsquat”
  or “deadlift/dødløft” don’t fragment your history).
- **Lists** - shared shopping / to-do lists with real checkboxes and loose name-matching.
- **Calendar** - Google Calendar agenda cards, availability answers, event creation.
- **Weather** - DMI forecasts as animated symbol cards.
- **Expenses** - receipt photo → editable expense card, line items, CSV export for the accountant.
- **Work** - geofenced clock-in/out hours, a bar chart of hours over a period (day/week/month),
  and a free-text “what I did” work log per job.
- **Cycle** - menstrual-cycle tracking (inner-seasons visualisation), predictions, mood/energy logging, opt-in partner sharing.
- **Email** - Gmail / Outlook / IMAP: read, search, draft, confirm-to-send, safe HTML rendering.
- **Music** - vinyl collection with Discogs enrichment and taste-based recommendations.
- **Personalisation** - durable memory + standing instructions injected into the system prompt.
- **Notifications** - Web Push (PWA), a modular notification-type registry, cron-driven nudges + a daily AI pass that personalises the home-screen starters.

## Architecture

A deliberately framework-free, layered PHP app (PSR-4, `App\` → `src/`):

```
Database (PDO/MySQL, utf8mb4)
   └── Data/       SQL only, hard per-user scoping        (Workouts, Receipts, CycleTracker, …)
        └── Tools/  thin wrappers implementing Tool        (LogWorkout, AddExpense, GetCycleStatus, …)
             └── Assistant/  the Gemini tool-call loop     (AssistantLoop, GeminiClient, ToolSelector)
```

The `AssistantLoop` builds the conversation, asks `ToolSelector` for the relevant tool
declarations, calls Gemini, executes any requested tools (each `Data`-backed and
user-scoped), feeds results back, and repeats until the model produces a final reply -
capturing any `_render` card along the way.

## Tech stack

PHP 8.4 · MySQL/MariaDB · Google Gemini (raw cURL, function-calling + JSON mode) ·
libsodium (token encryption) · Web Push / VAPID · Composer · a vanilla-JS PWA
(`kachow-web`). No web framework - the layering *is* the structure.

## Repository layout

```
src/
  Assistant/   AssistantLoop, GeminiClient, ToolSelector, generators
  Data/        per-user data access (one class per domain)
  Tools/       one thin Tool per capability + the ToolRegistry
  Email/       EmailProvider interface + Gmail / Outlook / IMAP + SMTP clients
  Auth/        sessions, remember-me, Google/Microsoft OAuth
  Notify/      Web Push + modular notification types
  Receipts/    image storage + Gemini receipt reader
bin/           cron jobs (notifications, daily starters) + routing self-test
migrations/    plain .sql, applied by hand per environment
```

## Running it

```bash
composer install
cp .env.example .env          # then fill in the values
# create the DB, then apply migrations/ in date order
php bin/routing-test.php      # sanity-check tool routing (no DB/API needed)
```

Serve the [`kachow-web`](https://github.com/christianmorkeberg/kachow-web) front-end
(its `bootstrap.php` autoloads this repo). Schedule the crons:

```cron
0  *  * * *  php /path/to/kachow-app/bin/notify-cron.php        >/dev/null 2>&1   # hourly
30 4  * * *  php /path/to/kachow-app/bin/quick-actions-cron.php >/dev/null 2>&1   # daily
```

## Tests

```bash
php bin/routing-test.php   # deterministic tool-routing regression suite (EN + DA)
```

---

Built by [Christian Mørkeberg](https://github.com/christianmorkeberg) as a personal
project. Not affiliated with any company; the “Kachow” name is just for fun.
