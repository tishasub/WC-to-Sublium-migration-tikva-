# WooCommerce → Sublium Migration Bridge

A custom WordPress plugin built to migrate active WooCommerce Subscriptions (WCS) to **Sublium (Subscriptions by FunnelKit)** for [Tikvadrink.com](https://tikvadrink.com).

---

## Overview

This plugin acts as a **migration bridge** between two WordPress/WooCommerce sites:

- **Source:** `tikvadrink.com` — legacy WooCommerce Subscriptions store
- **Destination:** `tikvadrink.com` (formerly `beta.tikvadrink.com`) — new Sublium-powered store

It handles the full migration pipeline: exporting subscriptions from the old store, transforming the data, and importing it into Sublium — including product mapping, subscriber profiles, payment tokens, order linking, and renewal setup.

---

## Features

### Core Migration
- ✅ Batch migration of 900+ active subscriptions with real-time progress UI
- ✅ Proxy-based fetching to bypass Cloudflare restrictions between source and destination
- ✅ Dry Run mode — safe preview before any live changes
- ✅ Resume from offset — pick up where you left off after interruptions
- ✅ Filter by subscription ID — migrate specific records for testing
- ✅ Reset/cleanup — safely wipe test imports and start fresh

### Product Mapping
- ✅ 95+ hardcoded mappings covering all Tikva product variants
- ✅ Bundle products → variable product with flavor variations
- ✅ Simple, variable, and bundled subscription products supported
- ✅ Maps old WCS billing intervals (1, 4, 5, 6 months) to correct Sublium plan IDs
- ✅ Product-aware plan lookup — finds correct plan per product automatically
- ✅ UI override — add custom mappings via Mapping tab without touching code
- ✅ Skipped products: Happy, Brain & Focus, Tikva Club (configurable)

### Subscriber & Order Data
- ✅ Creates/links WordPress user accounts by email
- ✅ Writes `billing_details` and `shipping_details` JSON (Sublium's native format)
- ✅ Populates `wp_sublium_wcs_subscribers` table with correct user data
- ✅ Writes `plan_data`, `_sublium_payment_mode`, `retry_count` to subscription meta
- ✅ Links parent WC orders with `_sublium_wcs_subscription_id`
- ✅ Marks renewal orders with `_sublium_wcs_subscription_renewal = yes`
- ✅ Fires `sublium_wcs_subscription_created` action for MRR/ARR analytics

### Payment Token Migration
- ✅ Migrates Authorize.net CIM payment tokens (`wp_woocommerce_payment_tokens`)
- ✅ Syncs `customer_profile_id` from `wp_woocommerce_payment_tokenmeta`
- ✅ Authorize.net API fallback — retrieves missing profile IDs from Authorize.net directly
- ✅ Writes profile ID to user meta, subscription meta, and WC order meta
- ✅ Auto-injects missing profile IDs on renewal via WordPress hooks

### Bulk Fix Tool
- ✅ One-click repair of all migrated subscriptions
- ✅ Fixes Authorize.net customer profile IDs from token meta (authoritative source)
- ✅ Links parent orders to Sublium subscriptions
- ✅ Links and flags renewal orders
- ✅ Fixes `customer_id = 0` on WC orders
- ✅ Reports: total fixed, remaining issues, SQL errors

### Sync Tools
- ✅ Sync Authorize.net Payment Profile IDs from old site
- ✅ Filter by email — test one user before syncing all
- ✅ Exports from user meta, HPOS order meta, and legacy postmeta

---

## Plugin Architecture

```
woo-sublium-migration-bridge.php
│
├── REST Endpoints (namespace: wsmb/v1)
│   ├── GET  /source/subscriptions          — Export WCS subscriptions
│   ├── GET  /source/payment-profiles       — Export Authorize.net profile IDs
│   ├── POST /destination/push              — Import subscriptions into Sublium
│   ├── POST /destination/proxy-fetch       — Server-side proxy (bypass Cloudflare)
│   ├── POST /destination/sync-payment-profiles — Sync Auth.net profile IDs
│   ├── POST /destination/bulk-fix          — Bulk fix all migrated subscriptions
│   ├── POST /destination/cleanup           — Reset migration tracking
│   ├── GET  /destination/debug-plans       — Debug Sublium plan/subscription data
│   ├── GET  /destination/debug-order       — Debug WC order data
│   └── GET  /destination/debug-subscriber  — Debug subscriber/user data
│
├── Admin UI (5 tabs)
│   ├── Instructions    — Step-by-step migration guide
│   ├── Settings        — Source URL, tokens, gateway config
│   ├── Mapping         — Product ID map + billing interval map
│   ├── Batch Migrate   — Run migration, bulk fix, sync payment profiles
│   └── cURL Reference  — Pre-filled terminal commands
│
└── WordPress Hooks
    ├── woocommerce_order_get_meta          — Inject missing Auth.net profile ID
    ├── sublium_wcs_before_process_renewal  — Pre-renewal profile injection
    ├── sublium_wcs_subscription_payment_meta — Payment meta injection
    └── woocommerce_before_pay_action       — Pre-payment profile injection
```

---

## Migration Flow

```
Old Site (WCS)                          New Site (Sublium)
──────────────                          ──────────────────
GET /source/subscriptions          →    POST /destination/proxy-fetch
                                   →    POST /destination/push
                                              │
                                              ├── Create/find WP user
                                              ├── Create WC parent order
                                              ├── Create Sublium subscription
                                              ├── Add subscription items
                                              ├── Write billing/shipping meta
                                              ├── Write payment token meta
                                              ├── Link parent order
                                              ├── Link renewal orders
                                              ├── Write plan_data
                                              └── Update subscriber profile
```

---

## Product Mapping

| Old Products | New Product | Notes |
|---|---|---|
| 2802, 2803, 2804, 38975 | 643 (Tikva Heart) | Bundle flavor children |
| 5684, 21356, 28400, 41384 | 643 | Simple Tikva products |
| 63979–63996, 66387–66391 | 643 | Tikva Heart plan variants |
| 68897–68901, 71643–71647 | 643 | Tikva Heart current variants |
| 43537, 43538 | 7700 | 30 Travel Packs |
| 9574, 12801, 12802, 39277–39280, 49651, 59341–59355, 73758, 73856 | 1940 | Heart Beet Ultra / Nitric Oxide |
| 51500, 60004, 60006, 60009 | 1965 | Happy product |

---

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+ with HPOS enabled
- Sublium (Subscriptions by FunnelKit) Pro
- PHP 8.0+
- Authorize.net CIM plugin (for payment token migration)

---

## Installation

1. Upload `woo-sublium-migration-bridge.php` to `/wp-content/plugins/woo-sublium-migration-bridge/`
2. Activate on **both** old site and new site
3. Go to **WooCommerce → Sublium Migration** on the new site
4. Configure Settings tab (old store URL + bridge tokens)
5. Run Dry Run to verify mappings
6. Run Live Import in batches
7. Run **Bulk Fix** after migration completes
8. Run **Sync Payment Profiles** to fix Authorize.net profile IDs

---

## Version History

| Version | Key Changes |
|---|---|
| 0.8.9 | Authorize.net API fallback for missing profile IDs |
| 0.8.7 | Bulk fix customer_id=0 on orders |
| 0.8.3 | Bulk Fix button — one-click repair for all subscriptions |
| 0.8.1 | Official Sublium migrator keys: renewal orders, plan_data, subscription_created action |
| 0.7.1 | Sync Authorize.net Payment Profile IDs tool |
| 0.6.3 | Hardcoded product map always loaded (fixed DB override issue) |
| 0.5.5 | billing_details/shipping_details JSON — subscriber panel fix |
| 0.5.0 | Bundle item deduplication, quantity-weighted price redistribution |
| 0.4.4 | wc-prefix status fix for active subscription filtering |
| 0.3.9 | Sublium item structure fix (add_item format) |

---

## Author

Built by **Adnan** (Launch Titans) for Tikva Drink migration project.

---

## License

Private / Client Project — Not for public distribution.
