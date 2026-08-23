---
title: Configuration
slug: configuration
order: 20
summary: Every setting, what it changes, and the two that decide whether your books are right.
---

## Connection

**API token** — your 32-character sevDesk token. Use an environment variable.

**API base URL** — leave it alone unless you are pointing Sevvies at a mock server.

**Bookkeeping system** — sevDesk 2.0 uses VAT rules; 1.0 uses tax types. Sevvies asks your account
which it is on and sends both, so you should not need to set this. It exists for the rare case where
an account is mid-migration and answers inconsistently.

## Position prices

**This is the setting most worth understanding.**

sevDesk decides for itself whether the position prices it receives are net or gross. Its API cannot
tell you which your account expects. Sevvies sends a `showNet` flag saying what it meant, and then
checks the total that comes back.

If your account read the prices the other way round, the invoice total will be your real total with
VAT added or removed — €119.00 where you charged €100.00, or the reverse. Nothing errors. The
document looks perfectly normal.

Sevvies recognises that specific shape. When the total sevDesk booked is the expected total with VAT
added or removed, it says so and names this setting, rather than leaving you to find it on a
quarterly return.

## VAT

**Tax scheme** — *Regelbesteuerung* or *Kleinunternehmer (§19 UStG)*.

**Home country** — the country you are taxed in.

**Work out the VAT rule per order** — on by default. With it on, Sevvies derives the rule from the
billing country and the customer's VAT ID. With it off, every invoice is issued under the default
rule below, which is the right answer for a shop that only ever sells domestically.

**VAT ID field** — the handle of the field holding the customer's VAT ID. Leave it empty and Sevvies
uses the billing address's *Organization tax ID*, which is where Craft's own address field puts it.

**Require a VAT ID for reverse charge** — on by default. Reverse charge without a VAT ID on the
document is not reverse charge.

**One Stop Shop** — turn on if you are registered for OSS and charge destination-country VAT to EU
consumers. Then say whether you sell goods, electronic services or other services; the three have
different VAT rules.

**Default VAT rule** — used when automatic rules are off, or when nothing else matches.

**Tax text override** — leave empty and Sevvies prints the sentence the chosen rule requires, such
as *Steuerfreie innergemeinschaftliche Lieferung — Reverse Charge, Steuerschuldnerschaft des
Leistungsempfängers* on a reverse-charge invoice. Set it only if you have wording your tax adviser
prefers.

## Document

**Payment term** — days until the invoice is due.

**Shipping position name** — what the shipping line is called on the document. *Versandkosten* by
default.

**Show discounts as discounts** — on, an order-level discount becomes a discount line on the sevDesk
document. Off, it is spread proportionally across the positions.

**Include SKUs** — puts the line item's SKU in the position text.

**Header, intro text, footer text** — Twig, rendered against the order. Leave empty for sevDesk's
defaults.

**sevDesk unit id** — the Unity id positions are measured in. `1` is *Stück*.

**Contact person id** — the sevDesk user shown on the document. Leave empty to use the first one;
**Test connection** lists them with their ids.

## Contacts

**Create contacts** — create a sevDesk contact for a customer who does not have one.

**Match by email** — reuse an existing sevDesk contact whose email address matches the order's.
Sevvies verifies that a search result genuinely matches before using it, so a customer can never be
attached to a stranger's contact.

**Assign customer numbers** — ask sevDesk for the next free customer number when creating a contact.

**Keep addresses up to date** — write the billing address to the sevDesk contact when a returning
customer's address has changed.

## After invoicing

sevDesk creates every invoice as a **draft**. Sending it — or marking it sent — is what turns it into
a bookkeeping document.

**Sending**:

- *Leave it as a draft* — you finish each one by hand in sevDesk
- *Mark as sent, but don't email* — for shops that send the invoice from Craft and only want
  sevDesk's books to agree
- *Have sevDesk email it to the customer* — sevDesk sends it, so it arrives from your accounting
  address with your letterhead

**Book payments** — when Commerce marks an order paid, book the amount against the sevDesk invoice
so it closes. Needs a **check account id**; **Test connection** lists yours.

**Refunds** — *Create a credit note* mirrors Commerce refunds into sevDesk. A full refund reverses
the invoice; a partial one gets its own credit note filed against the original.

**Archive the PDF** — store sevDesk's PDF as a Craft asset, so your archive outlives the
subscription. Choose a volume and, optionally, a subfolder path rendered as Twig against the order.

## Housekeeping

**Keep the log for** — days of connection log to retain. `0` keeps everything.

**Log request bodies** — keeps the full payload for every call. Turn it off if you would rather not
store customer addresses twice.

**Retries** — how many times a failed send is retried. Only timeouts and sevDesk errors are retried;
a rejected invoice never is, because it would be rejected identically.
