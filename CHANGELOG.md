# Release Notes for Sevvies

## 5.0.0 - 2026-08-20

Initial release.

### Added

- Craft Commerce orders become sevDesk invoices, on order completion, on payment, on an order
  status, or by hand.
- Per-order VAT rule determination: domestic sales, exports outside the EU, intra-community
  supplies with reverse charge, §19 Kleinunternehmer and One Stop Shop — with the reasoning recorded
  on the invoice.
- Reconciliation: every invoice is totalled before it is sent and checked against sevDesk's own
  total afterwards. A mismatch blocks the row and names the likely cause.
- Rate validation against the chosen VAT rule, so a rate sevDesk cannot accept is caught before it
  is sent rather than rejected without explanation.
- sevDesk contact resolution, creation and address upkeep, with a local mapping so a returning
  customer never produces a second contact.
- Support for both sevDesk bookkeeping systems — `taxRule` and `taxType` are sent together, and the
  account is asked which it is on.
- Dry run: build and log every invoice without sending anything.
- Payload preview in the control panel, on the Commerce order screen, and from the console.
- Sending via sevDesk email, or marking as sent.
- Payment booking and credit notes for refunds.
- PDF archiving into a Craft volume.
- Bulk backfill and order conditions.
- A connection log with every request, decision and skip.
- Console commands for checking the connection, previewing, syncing and housekeeping.
- `craft.sevvies` Twig variable — read-only.
- Full German translation.
