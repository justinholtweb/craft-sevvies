---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and getting your first invoice into sevDesk safely.
---

## Requirements

- Craft CMS 5.3 or later
- Craft Commerce 5.0 or later
- PHP 8.2 or later
- A sevDesk account with API access

## Install

From your project root:

```sh
composer require justinholtweb/craft-sevvies
php craft plugin/install sevvies
```

Or find **Sevvies** in the Plugin Store and install it from there.

Sevvies is $99 per Craft installation. Development and testing installs are free.

## Your API token

sevDesk gives every administrator one API token — a 32-character hexadecimal string. In sevDesk, go
to **Settings → User → your user**, and copy the API token.

Put it in your `.env` rather than typing it into the database:

```
SEVDESK_TOKEN="a1b2c3d4e5f6..."
```

Then in **Sevvies → Settings**, set the API token field to `$SEVDESK_TOKEN`.

## Test the connection

Press **Test connection** on the settings screen. A working token reports which sevDesk bookkeeping
system your account is on, and lists your check accounts and sevDesk users — you will need their ids
for two other settings, so this is the quickest way to find them.

From the command line:

```sh
php craft sevvies/tools/check
```

## Set up the essentials

Three settings decide whether your documents are correct. Everything else can wait.

**Tax scheme** — *Regelbesteuerung* or *Kleinunternehmer (§19 UStG)*. A Kleinunternehmer charges no
VAT and every invoice is issued under that one rule.

**Home country** — the two-letter code of the country you are taxed in. `DE` unless you know
otherwise.

**Position prices** — whether your sevDesk account reads the prices Sevvies sends as net or gross.
This one is worth understanding; see [Configuration](../configuration) for why, and what Sevvies does
when you get it wrong.

## Do a dry run first

Turn on **Dry run** before you turn on anything else.

In dry run, Sevvies builds every invoice exactly as it would for real and writes the complete payload
to the log — and sends nothing. This is how you check your VAT settings against your own orders
before a single document reaches your books.

Open a completed order, use the **Sevvies** panel, and press **Preview and file**. You will see the
positions, the totals, the VAT rule chosen and the reason for it.

When several real orders look right, turn Dry run off.

## Choose when invoices are created

**Sevvies → Settings → When to invoice**:

- **The order is paid** — the default, and the right answer for most shops
- **The order is completed** — invoice at checkout, before payment settles
- **The order reaches a status** — invoice when you move an order to *Shipped*, say
- **Never** — file by hand from the order screen

Leave **Send in the background** on. Checkout never waits for sevDesk, and a sevDesk outage can
never stop a customer paying.
