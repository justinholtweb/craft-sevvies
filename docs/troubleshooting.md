---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: What each blocked or failed state means, and how to clear it.
---

## "sevDesk booked €119.00 but Commerce charged €100.00"

Your **Position prices** setting does not match your sevDesk account. Sevvies says so explicitly when
the difference is exactly VAT — that is the whole point of the message.

Flip **Position prices** between *Net* and *Gross* in **Sevvies → Settings → Document**, then fix the
invoice already in sevDesk by hand, forget the link on the order (**Forget this link**), and file it
again.

## "This order charged 19% VAT, but sevDesk only accepts 0% under Ausfuhren"

Commerce charged VAT on an order Sevvies has determined is an export. One of the two is wrong, and
Sevvies will not guess which.

Usually it is the Commerce tax rules: a tax rate is applying to a destination it should not. Check
**Commerce → Tax → Tax Rates** and the zone the rate is bound to. If the order genuinely should have
carried VAT, the billing address country is wrong.

## "The customer's VAT ID country (AT) does not match the billing country (FR)"

The customer typed a VAT ID from a different country than their billing address. Correct one of them
on the order, then file it again.

## "VAT ID … is not a valid FR VAT number"

The number does not match the structural format for that country. This is a format check, not a VIES
lookup — a well-formed number that is not registered will pass here.

If the customer genuinely has no VAT ID, clear the field. The order then invoices as a consumer sale,
which is correct.

## "sevDesk does not recognise the country 'CH' on the billing address"

Sevvies could not find a matching country in sevDesk's own country list. Run
`php craft sevvies/tools/flush-cache` and try again — the list is cached for a day, and a country
added to your account recently may not be in the cached copy.

## "No sevDesk contact person could be found"

Every sevDesk invoice needs a contact person. Set **Contact person id** in
**Sevvies → Settings → Document**; **Test connection** lists your sevDesk users with their ids.

## "sevDesk rejected the API token"

The token is wrong, expired, or belongs to a user who has lost API access. Get a fresh one from
**Settings → User → your user** in sevDesk.

If you are using an environment variable, check the name is spelled the same in `.env` and in the
setting. An environment variable that does not exist reads as empty, not as an error.

## "Could not reach sevDesk"

A network problem or a sevDesk outage. These are retried automatically — up to the **Retries**
setting — with the queue backing off between attempts. Nothing is lost, and nothing is duplicated.

## An order is stuck on "Pending"

The queue job has not run. Check **Utilities → Queue Manager** for a failed job, and make sure your
queue runner is actually running — Craft's default web-request-driven queue can stall on a site with
no traffic.

To bypass the queue for one order, open it in **Sevvies → Invoices** and press **Create the
invoice**.

## Invoices are created but stay drafts

That is sevDesk's behaviour, not a fault: every invoice is created as a draft, and it becomes a
bookkeeping document when it is sent.

Set **Sending** in **Sevvies → Settings → After invoicing** to *Mark as sent* or *Have sevDesk email
it*, or finish each one by hand in sevDesk.

## Payments are not being booked

Check all of:

- **Book payments** is on
- A **check account id** is set — booking will not guess one
- The order is actually paid in Commerce (`order.isPaid`)
- The invoice is out of draft. Sevvies marks it sent automatically before booking, since sevDesk will
  not book an amount against a draft.

## The same order was invoiced twice

It cannot be. A unique database index on the order id enforces one invoice row per order, so a
duplicate is refused by the database rather than by a check that could race.

If you genuinely see two documents in sevDesk, one of them was created outside Sevvies — or the
order was **forgotten** and then filed again, which deliberately does not touch the first document.

## Deleting things

Sevvies never deletes or alters a bookkeeping document in sevDesk. **Forget this link** removes only
Sevvies' record of an order, and leaves sevDesk untouched — after which that order can be filed
again as if new.

## Reading the log

**Sevvies → Log** records every request with its endpoint, status code and duration, and every
decision and skip. Filter to **Failures only** when something is wrong.

Each entry keeps the request and response bodies, unless you have turned **Log request bodies** off.
For a rejected invoice, the response body is sevDesk's own explanation, which is usually more
specific than anything Sevvies can say on its behalf.
