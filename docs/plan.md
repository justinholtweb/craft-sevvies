# Sevvies — plan

## The problem

A German Craft Commerce shop has to get its orders into sevDesk. Doing that badly is easy and
invisible: sevDesk accepts whatever document you describe, and the shop finds out at the
Umsatzsteuervoranmeldung.

Three things can go wrong, in ascending order of expense:

1. **The wrong VAT rule.** One checkout produces domestic sales, exports, intra-community supplies
   with reverse charge, and (if registered) One Stop Shop sales at destination rates. Each is a
   different `taxRule` and a different sentence printed on the invoice.
2. **The wrong money.** sevDesk decides for itself whether the position prices it receives are net
   or gross, controlled by `showNet`. Send the wrong one and the invoice is the same total with 19%
   added or removed. Nothing errors.
3. **The same order twice.** Duplicate bookkeeping documents are a mess to unwind and a problem
   under GoBD.

Everything below is arranged around those three.

## Design decisions

### One builder, one creator

`Invoices::build()` is the only place an order becomes a payload; `Invoices::sync()` is the only
place an invoice is created. The preview a merchant approves is therefore the literal request, and
the duplicate guard is a unique index rather than a convention.

### The tax engine is separate from the API client

`services\Tax` takes an order and returns a `TaxDecision`: rule, legacy type, label, the sentence
the document must carry, the rates the rule permits, and **the reason**. The reason is stored on the
invoice row and shown in the CP, because "why is this one zero-rated?" is the question an accountant
will actually ask.

It also *refuses*: a VAT ID from the wrong country, a malformed VAT ID where reverse charge was
expected, or a rate the rule cannot legally carry, all block the invoice rather than producing a
document that looks fine.

### Reconciliation is not optional

The draft totals itself before sending (catching a Commerce-side surprise) and is checked against
sevDesk's returned `sumGross` afterwards (catching a sevDesk-side misreading). A mismatch sets
`STATE_BLOCKED` and keeps the sevDesk id so the document can be found and fixed.

When the mismatch is exactly the expected total with VAT added or removed, Sevvies says so and
names the setting — the difference between a five-minute fix and a quarter of wrong books.

### Fail open at checkout, closed at the ledger

Triggers catch everything and return; the queue owns retries. But `ApiException::isTransient()`
draws a hard line: a 4xx is the merchant's data and will fail identically forever, so it is never
retried, only surfaced.

### Nothing is destroyed

"Forget this link" drops Sevvies' row. The sevDesk document is never deleted or altered. A refund
produces a credit note, which is what reversing an invoice actually looks like in bookkeeping.

## Editions

**Lite (free)** — invoices from orders under one configured VAT rule, contacts, dry run, preview,
reconciliation, log, send/mark-sent. This is the whole job for a shop selling domestically.

**Pro** — the per-order VAT rule engine (the reason to buy it), payment booking, credit notes,
PDF archiving, bulk backfill, order conditions.

## Build order

1. Data model and the two invariants — done
2. `Api` with a swappable transport, so everything above it is testable — done
3. `Meta`: cached discovery of the ids sevDesk expects by number — done
4. `Tax`: the rule engine and rate validation — done
5. `Invoices`: build, sync, reconcile — done
6. `Contacts`, `Documents`, `Payments` — done
7. CP: settings, invoices index and detail, log, Commerce order panel — done
8. Console, Twig variable, queue job — done
9. German translation — done
10. 155 integration checks against a stub that also misbehaves on purpose — done

## Still to do

- GitHub repo, tag, Packagist, Plugin Store submission
- Marketing site in the `craft-*-website` family
- Registry entry
- A run against a real sevDesk sandbox account, which is the one thing the stub cannot prove
