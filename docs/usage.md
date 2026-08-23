---
title: Usage
slug: usage
order: 30
summary: How VAT rules are decided, what the reconciliation guard does, and working from the CP, Twig and the console.
---

## How a VAT rule is decided

This is what Sevvies is for. One checkout produces several legally different documents, and sevDesk
will file whichever it is told to without complaint.

For each order, Sevvies works through the billing country and the customer's VAT ID:

| Situation | Rule | What the document says |
| --- | --- | --- |
| Billing country is your home country | `taxRule 1` Umsatzsteuerpflichtige Umsätze | Umsatzsteuer at the charged rate |
| Outside the EU | `taxRule 2` Ausfuhren | Steuerfreie Ausfuhrlieferung, zero-rated |
| EU business with a valid VAT ID | `taxRule 3` Innergemeinschaftliche Lieferungen | Steuerfreie innergemeinschaftliche Lieferung — Reverse Charge |
| EU consumer, OSS on | `taxRule 18/19/20` One Stop Shop | Besteuerung im Bestimmungsland |
| EU consumer, OSS off | `taxRule 1` | Domestic VAT |
| Kleinunternehmer, any order | `taxRule 11` §19 UStG | Gemäß §19 UStG wird keine Umsatzsteuer berechnet |

**The reason is recorded on every invoice.** Open the order in the CP and you will see something
like *"EU business customer in AT with VAT ID ATU12345678 — intra-community supply, reverse
charge."* When an accountant asks why a particular invoice is zero-rated, the answer is on the
invoice.

### When Sevvies refuses

Some orders cannot be invoiced correctly as they stand, and Sevvies stops rather than filing
something wrong:

- **A VAT ID from the wrong country.** An Austrian VAT ID on a French billing address is one or the
  other being wrong, and reverse charge on the wrong one is a problem.
- **A malformed VAT ID** where reverse charge was expected. Sevvies checks the structure of the
  number against the format for its country.
- **A rate the rule cannot carry.** sevDesk accepts only certain rates under each rule — 0, 7 or 19
  under rule 1; 0 only under an export. An export that somehow charged 19% means your Commerce tax
  rules and the destination disagree, and Sevvies names that rather than letting sevDesk reject the
  document with an error that mentions no field.
- **A country sevDesk does not recognise** on the billing address.

Each of these marks the order **Needs attention** with the reason, and nothing is sent.

Sevvies checks the *format* of a VAT ID, not whether it is registered. Confirming registration means
calling VIES, which would make your checkout depend on someone else's uptime and is a decision with
legal weight. That one stays yours.

## The reconciliation guard

Every invoice is totalled twice.

**Before sending**, Sevvies adds up the positions and discounts it is about to send and compares that
to what Commerce actually charged. If they disagree, nothing is sent.

**After sending**, it compares sevDesk's own `sumGross` to the same figure. If *that* disagrees, the
order is marked **Needs attention**, the sevDesk id is kept so you can find the document, and the
reason is on the row.

An invoice for the wrong amount is worse than a missing one, so this is not a warning you can
dismiss.

## Working from the control panel

**Sevvies → Invoices** lists every order Sevvies has touched, filterable by state: Created, Sent,
Booked, Needs attention, Failed, Dry run, Pending.

Opening one shows the sevDesk invoice, the VAT rule and its reasoning, every position with its net,
tax and gross, both totals side by side, and the literal JSON payload that would be sent. From
there you can create the invoice, mark it sent, email it, book the payment, download the PDF, or
forget the link.

Every Commerce order screen also carries a **sevDesk** panel — whether it is filed, for how much,
and a link straight to the detail.

**Sevvies → Log** has every request, decision and skip, with the request and response bodies.

## Templating

`craft.sevvies` is read-only. A template can show an invoice; it cannot issue one.

```twig
{% if craft.sevvies.isInvoiced(order) %}
    <p>Rechnung {{ craft.sevvies.invoiceNumber(order) }}</p>
{% endif %}

{% set pdf = craft.sevvies.pdf(order) %}
{% if pdf %}
    <a href="{{ pdf.url }}">Rechnung herunterladen</a>
{% endif %}
```

Showing a B2B customer what VAT treatment their cart will get, before they buy:

```twig
{% set vat = craft.sevvies.taxRule(cart) %}
{% if vat.zeroRated %}
    <p class="notice">{{ vat.text }}</p>
{% endif %}
```

`taxRule()` returns `rule`, `label`, `reason`, `text` and `zeroRated`.

## Console

```sh
php craft sevvies/tools/check              # test the token, list check accounts and users
php craft sevvies/sync/preview <orderId>   # print the payload and the VAT reasoning
php craft sevvies/sync/order <orderId>     # file one order
php craft sevvies/sync/pending --limit=50  # backfill every un-invoiced completed order
php craft sevvies/tools/prune-log
php craft sevvies/tools/flush-cache        # forget cached sevDesk ids
```

`sync/preview` builds the payload through the same code path as a real send, so what it prints is
what sevDesk would receive:

```
VAT rule: Innergemeinschaftliche Lieferungen (taxRule 3)
Reason:   EU business customer in AT with VAT ID ATU12345678 — intra-community supply, reverse charge.
Commerce: 238.00 EUR
sevDesk:  238.00 EUR
```

`sync/pending --dry-run` builds and logs every pending order without sending anything — the safe way
to check a backfill before running it.

## Backfilling existing orders

1. Turn on **Dry run**.
2. `php craft sevvies/sync/pending --limit=25`
3. Read **Sevvies → Log**, and fix anything marked Needs attention.
4. Turn Dry run off and run it again without `--dry-run`.

Orders that already have an invoice are skipped, so the command is safe to run repeatedly.
