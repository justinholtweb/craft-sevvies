# Sevvies — Craft CMS 5 Plugin

## Project Overview

Sevvies turns Craft Commerce orders into sevDesk invoices. sevDesk is the German SMB accounting
product; its users are German shops with German VAT obligations, which is what makes this more than
a REST client. Distributed as `justinholtweb/craft-sevvies`. **Paid, $99, single edition.**

## Why it exists

The hard part is not the API, it is deciding *which document* an order is. One checkout produces
domestic sales, intra-community supplies with reverse charge, exports, and One Stop Shop sales, and
sevDesk will file whichever one it is told to without complaint. Sevvies derives the rule, records
the reasoning, and refuses to file a document whose arithmetic disagrees with Commerce.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, **Craft Commerce 5.0+**, Yii2, Twig
- No build step, no asset bundles, no JS beyond inline `{% js %}` blocks
- No runtime dependencies beyond Craft's own Guzzle

## Architecture

### Namespace & package

- Namespace: `justinholtweb\sevvies`
- Package: `justinholtweb/craft-sevvies`
- Handle: `sevvies`

### The three invariants

1. **`services\Invoices::build()` is the only place an order becomes a sevDesk payload.** The CP
   preview, `sevvies/sync/preview`, `craft.sevvies.preview()` and the live sync all go through it,
   so a preview is byte-identical to what sevDesk receives.
2. **`services\Invoices::sync()` is the only place an invoice is created**, and it runs behind a
   unique index on `sevvies_invoices.orderId`. An order cannot be invoiced twice, whatever a status
   flip or a queue retry does.
3. **Nothing is filed that does not reconcile.** The draft totals itself before sending and is
   checked against sevDesk's own `sumGross` afterwards. A mismatch blocks the row; it never
   silently stands.

### Data model

- `{{%sevvies_invoices}}` — one row per order; unique on `orderId`. Holds the VAT rule *and the
  reason for it*, the payload, both totals and the reconciliation verdict.
- `{{%sevvies_contacts}}` — customer → sevDesk contact. Keyed `user:<id>` for account holders and
  `email:<address>` for guests, so a changed email does not split a customer in two.
- `{{%sevvies_credits}}` — refunds mirrored as credit notes; unique on `(orderId, refundKey)`.
- `{{%sevvies_log}}` — every request, decision and skip.

### sevDesk protocol notes (read from the spec, not guessed)

The contract came from sevDesk's own OpenAPI document at `https://api.sevdesk.de/openapi.yaml`,
which is public and complete. Fetch that rather than the rendered docs site.

- **Auth is the bare token in the `Authorization` header** — not `Bearer <token>`.
- **Two bookkeeping systems exist.** 1.0 uses `taxType` (`default|eu|noteu|custom|ss`); 2.0 replaced
  it with `taxRule` (an object, ids 1–21) and dropped `custom` entirely. `GET
  /Tools/bookkeepingSystemVersion` says which an account is on. Sevvies sends **both**, so an
  account migrating mid-flight keeps working.
- **`showNet` is not a display option.** It tells sevDesk whether the position `price` you sent is
  net or gross. Get it wrong and the invoice is the same number with VAT added or removed — hence
  the reconciliation check and the specific hint it produces.
- **An invoice can only be created as a draft (status 100) in 2.0.** `changeStatus` is gone.
  Status moves via `sendViaEmail`, `sendBy`, `bookAmount`, `resetToOpen`, `resetToDraft`.
- **`bookAmount` wants a unix timestamp** for `date`, while everything else takes `dd.mm.yyyy`.
- **`sendBy` is a PUT** and requires both `sendType` and `sendDraft`.
- **`saveInvoice` needs its last four keys present and in order** — `invoicePosDelete`,
  `discountSave`, `discountDelete`, `takeDefaultAddress`. The endpoint documents this explicitly.
- **Each VAT rule allows only certain rates.** Rule 1: 0/7/19. Rules 2, 4, 11, 17: 0 only. The OSS
  rules (18–20) depend on the destination country. `services\Tax::validateRates()` enforces this
  before sending, because sevDesk's rejection names no field.
- **`/StaticCountry`, `/SevUser`, `/Category` and `/CommunicationWayKey` are referenced by the spec
  but not listed as paths.** They exist. Their ids are per-account, so they are looked up and
  cached in `services\Meta`, never hard-coded.
- **CommunicationWay has no documented `value` filter.** Sevvies uses it anyway to find a contact
  by email — and then *verifies the returned rows actually match*, so an account where the filter is
  ignored cannot attach an order to a stranger's contact.

### Failing open, and failing closed

Checkout fails **open**: a sevDesk outage must never stop a customer paying, so every trigger
catches, logs, and returns.

Bookkeeping fails **closed**: a document that would be wrong is not written. `STATE_BLOCKED` means
"a human has to decide"; `STATE_FAILED` means "worth retrying". `errors\ApiException::isTransient()`
is what keeps the queue from hammering sevDesk with a payload it will reject identically forever.

## Traps found while building this

- **`$errors` is Yii's own surface.** A public `array $errors` on a Model shadows `getErrors()` and
  breaks validation in ways that only appear later. `InvoiceDraft` calls it `$blockers`.
- **`array_map([$this, 'privateMethod'], …)` is not reliable**; a closure that calls it is.
- **`Order::EVENT_AFTER_ORDER_PAID` fires for orders already invoiced under another trigger**, so
  the paid handler has to distinguish "invoice this" from "book the payment on the invoice".
- **`createTransaction()` needs a gateway** and throws on a fixture order that has none. In tests,
  build the `Transaction` model and `setTransactions()` it onto the order — `getTotalPaid()` reads
  the in-memory list.
- **Commerce refuses an address element it does not own.** Pass an array of attributes and let
  Commerce build the owned element.
- **A unit price rounded to two places loses money on quantities.** Three at a net 33.61 is
  11.203333 each; positions carry four decimal places and the reconciliation catches the rest.
- **Project config only flushes reliably once per process.** A test suite that switches plugin
  editions repeatedly gets stale answers from `is()`. Switch `$plugin->edition` in memory for the
  run and persist only once, in the cleanup.
- **`App::parseEnv()` returns null for an env var that is not set**, and `EnvAttributeParserBehavior`
  assigns it straight onto the property — so a typed `string` setting fatals the settings screen on
  a typo'd variable name. Env-backed settings are `?string`.
- **`craft-lexies` (sibling plugin in the shared harness) 500s Commerce's order edit screen** — its
  order panel uses `{% for x in y if … %}`, removed in Twig 3. Nothing to do with Sevvies; disable
  it if the order screen needs testing.

See also `[[craft-plugin-gotchas]]` for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
cd ~/Sites/plugin-testing
ddev exec php /var/www/craft-sevvies/tests/integration/checks.php   # 151 checks
ddev exec bash -c 'find /var/www/craft-sevvies/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

There is no sevDesk account here, so `services\Api::transport` is swapped for a stub that answers
like the real thing — **including the ways it can answer wrongly**: an account that reads prices as
gross, and a search filter it silently ignores. Those two stubs are the point; they are what proves
the reconciliation check and the contact-matching guard actually fire.

The suite restores the settings and every fixture in a `finally`.

Sevvies ships as **one edition**. There is no `editions()`, no `isPro()`, and no feature gating —
every capability is reachable from a setting. If a capability ever needs gating again, note that the
edition lives in project config, which only flushes reliably once per process (see the traps).

## The icon

`src/icon.svg` follows the family convention, and `web/images/plugins/sevvies.svg` on
justinholt.com is a **byte-identical copy** of it — abacus and bird are too, and the marketing-site
skill copies rather than redraws.

- `viewBox="0 0 100 100"`, no `width`/`height`
- `<rect x="0" y="0" width="100" height="100" rx="22.44" fill="#F85B45"/>` — the 22.44% tile radius
  the whole family uses
- the glyph in `#FEFEFE`, built from **filled** geometry. Not strokes: `src/icon-mask.svg` is the
  glyph path alone and has to be a solid silhouette, and stroked outlines do not survive that.
- the rules and the tick are **holes** in one `fill-rule="evenodd"` path, so the mask is that path
  copied across unchanged

`promos/assets/icon.svg` is another copy of the same file.

## Coding conventions

- `Craft::t('sevvies', '…')` for user-facing strings; **both** `src/translations/en/sevvies.php`
  and `src/translations/de/sevvies.php` are kept complete. German is not optional here.
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template — post with `Craft.sendActionRequest`
- Never mark plugin settings `required`
- Where sevDesk needs an id, look it up and cache it. Never hard-code one.
