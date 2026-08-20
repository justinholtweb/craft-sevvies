# Sevvies

**sevDesk invoicing for Craft Commerce.** Paid orders become correct German bookkeeping documents —
with the right VAT rule, the right total, and a record of why.

Requires Craft CMS 5.3+, Craft Commerce 5.0+ and PHP 8.2+.

---

## Why this exists

Posting an order to sevDesk is a POST request. Posting the *right* order to sevDesk is not.

A German shop selling across the EU issues at least four different kinds of invoice from the same
checkout: a domestic sale at 19%, an intra-community supply to an Austrian business with a VAT ID
(zero-rated, reverse charge, with a sentence the invoice is legally required to carry), an export
outside the EU, and — if you are registered for it — a One Stop Shop sale at the destination
country's rate. sevDesk will file whichever one you tell it to. It has no way of knowing you told
it the wrong one, and neither will you, until the Umsatzsteuervoranmeldung.

Sevvies decides that per order, records the reasoning on the invoice, and refuses to file anything
whose numbers do not match what Commerce actually charged.

## What it does

- **Works out the VAT rule per order** — domestic, export, intra-community supply with reverse
  charge, §19 Kleinunternehmer, or One Stop Shop — from the billing country and the customer's VAT
  ID, and writes the reason onto the invoice so an accountant can see it later.
- **Refuses to file a document that disagrees with Commerce.** Every invoice is totalled before it
  is sent and checked against what sevDesk booked afterwards. A mismatch blocks the row and names
  the likely cause instead of leaving a wrong invoice standing in your books.
- **Refuses a rate the rule cannot carry.** An export that somehow charged 19% is stopped with an
  explanation, not filed and forgotten.
- **Never invoices an order twice.** A unique index on the order id is the guarantee — not a
  convention, not a check-then-write.
- **Never blocks checkout.** Everything runs on the queue by default, and a sevDesk outage cannot
  stop a customer paying.
- **Shows you exactly what would be sent**, before you send it: the positions, the totals, the VAT
  reasoning and the literal JSON payload, from the CP or the command line.
- **Speaks German.** The interface, the tax texts and the document wording are all translated,
  because this is a German product.

Pro adds payment booking, credit notes for refunds, sevDesk-sent email, PDF archiving into a Craft
volume, bulk backfill, and the per-order VAT rule engine itself.

## Getting started

1. Install the plugin and open **Sevvies → Settings**.
2. Paste your sevDesk API token — 32 hexadecimal characters, from **Settings → User → your user**
   in sevDesk. Use an environment variable (`$SEVDESK_TOKEN`) rather than the database.
3. Press **Test connection**. It reports which bookkeeping system your account is on and lists your
   check accounts and users, whose ids the other settings ask for.
4. Set **Tax scheme** and **Home country**, and check **Position prices** against your sevDesk
   account.
5. Turn on **Dry run**, and sync a few real orders. Each one is built and written to the log without
   anything being sent — this is how you check your VAT settings against real data before it counts.
6. Turn Dry run off.

### Position prices

sevDesk decides whether the prices you send are net or gross, and the API cannot tell you which
your account expects. Sevvies sends `showNet` to say what it meant, and then checks the total that
comes back. If your account read the prices the other way, the invoice total will be the same
number with VAT added or removed — Sevvies recognises that specific shape and tells you which
setting to change, rather than leaving you to find it on a quarterly return.

## Templating

```twig
{% if craft.sevvies.isInvoiced(order) %}
    <p>Rechnung {{ craft.sevvies.invoiceNumber(order) }}</p>
{% endif %}

{% set pdf = craft.sevvies.pdf(order) %}
{% if pdf %}<a href="{{ pdf.url }}">Rechnung herunterladen</a>{% endif %}

{# What VAT treatment will this cart get? Useful on a B2B checkout summary. #}
{% set vat = craft.sevvies.taxRule(cart) %}
{% if vat.zeroRated %}<p>{{ vat.text }}</p>{% endif %}
```

`craft.sevvies` is read-only. A front-end template can show an invoice; it cannot issue one.

## Console

```sh
php craft sevvies/tools/check              # test the token, list check accounts and users
php craft sevvies/sync/preview <orderId>   # print the payload and the VAT reasoning
php craft sevvies/sync/order <orderId>     # file one order
php craft sevvies/sync/pending --limit=50  # backfill (Pro)
php craft sevvies/tools/prune-log
php craft sevvies/tools/flush-cache
```

`sync/preview` builds the payload through exactly the same code path as a real send, so what it
prints is what sevDesk would receive.

## Editions

| | Lite | Pro |
|---|---|---|
| Invoices from orders, contacts, log, reconciliation | ✓ | ✓ |
| Dry run and payload preview | ✓ | ✓ |
| Send, or mark as sent | ✓ | ✓ |
| VAT rule worked out per order (export, reverse charge, OSS) | — | ✓ |
| Book payments against the invoice | — | ✓ |
| Credit notes for refunds | — | ✓ |
| PDF archiving into a Craft volume | — | ✓ |
| Bulk backfill and order conditions | — | ✓ |

Lite issues every invoice under one configured VAT rule, which is the right answer for a shop that
only sells domestically.

## What Sevvies will not do

- **Delete or alter a bookkeeping document in sevDesk.** "Forget this link" removes Sevvies'
  record of an order and leaves sevDesk untouched. Bookkeeping documents are not ours to destroy.
- **Validate a VAT ID against VIES.** It checks the format, which is all that can be done without
  making checkout depend on someone else's uptime. Confirming registration is a decision with legal
  weight and belongs to you.
- **Guess.** Where sevDesk needs an id — a country, a unit, a contact person, a check account —
  Sevvies looks it up and caches it, or tells you it could not.

## Support

justin@justinholt.com
