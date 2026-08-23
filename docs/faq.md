---
title: FAQ
slug: faq
order: 50
summary: Pricing, VAT, VIES, duplicate documents, and what happens when sevDesk is down.
---

## What does Sevvies cost?

$99 per Craft installation, one edition, everything included. Development and testing installs are
free.

There is no free tier on purpose. A shop selling only domestically is exactly the customer who would
take one — and the moment they sell one thing to an Austrian business, a cut-down edition would file
it wrongly. A plugin whose free edition can produce a wrong bookkeeping document is worse than no
free edition.

## Do I need a paid sevDesk plan?

You need a sevDesk plan that includes API access. Sevvies uses the standard sevDesk API; there is no
separate integration fee.

## Does it work with sevDesk 1.0 and 2.0?

Both. sevDesk replaced tax types with VAT rules in its 2.0 bookkeeping system. Sevvies asks your
account which system it is on and sends both forms, so an account migrating between them keeps
working through the migration.

## Does it validate VAT IDs against VIES?

No. It checks that a VAT ID is structurally valid for its country — the right length, the right
shape — which catches typos and pasted rubbish.

Confirming that a number is actually *registered* means calling VIES, which is frequently slow and
occasionally down. Making your checkout depend on that is a bad trade, and treating a VIES timeout as
"not registered" would charge VAT to a customer who should not pay it. Whether to verify registration
is a decision with legal weight, and it stays yours.

## Can it invoice the same order twice?

No. A unique database index on the order id means one invoice row per order, enforced by the
database rather than by a check that could race with a retry or a status change.

## What happens if sevDesk is down?

Nothing that affects your customers. Every trigger fails open — a sevDesk outage cannot stop a
customer paying, and cannot stop Commerce recording that they did.

The invoice goes onto the queue and is retried. Sevvies distinguishes a temporary failure, which is
worth retrying, from a rejected document, which would be rejected identically forever and is
surfaced to you instead.

## What if my VAT setup is unusual?

Turn off **Work out the VAT rule per order** and set a default rule. Every invoice is then issued
under that one rule, and Sevvies still does the reconciliation and duplicate protection.

## Does it handle One Stop Shop?

Yes, if you are registered for OSS. Turn it on and say whether you sell goods, electronic services or
other services — sevDesk has a different rule for each. The destination country travels with the
document, so sevDesk applies the destination rate.

Sevvies does not decide whether you *should* be registered for OSS, or track the distance-selling
threshold. That is your tax adviser's job.

## Does it handle refunds?

Yes, as credit notes, which is what reversing an invoice actually looks like in bookkeeping. A full
refund reverses the invoice; a partial refund gets its own credit note filed against the original at
the same VAT rate. Each Commerce refund is mirrored exactly once.

## Can I invoice orders that predate the plugin?

Yes — `php craft sevvies/sync/pending` backfills every completed order without an invoice. Do a dry
run first.

## Will it delete anything in sevDesk?

No. Sevvies creates documents and never deletes or alters one. **Forget this link** removes only
Sevvies' own record of an order.

## Can customers see their invoices on the site?

Yes, through `craft.sevvies` in Twig — the invoice number, and the archived PDF if you have PDF
archiving on. The variable is read-only, so a front-end template can display an invoice but never
issue one.

## Does it work with Commerce's tax engine, or replace it?

It works with it. Commerce calculates the tax; Sevvies reads what was charged and files it under the
right sevDesk rule. If the two disagree — an export that charged 19% — Sevvies stops and tells you,
because that is a Commerce tax-rule problem it should not paper over.

## Is Sevvies affiliated with sevDesk?

No. It is an independent integration built against sevDesk's public API.

## Is this tax advice?

No. Sevvies files what your Commerce setup charged, under the rule its configuration implies.
Whether that treatment is correct for your business is between you and your tax adviser.
