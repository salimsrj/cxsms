# CX SMS for WooCommerce

A WordPress plugin that texts WooCommerce customers about their orders through the
[CloudXino](https://sms.cloudxino.com) SMS gateway. It brings the SMS features of the
m-commerce (Laravel) admin to WooCommerce.

Requires WordPress 6.2+, PHP 7.4+ and WooCommerce 7.1+. Compatible with High-Performance Order Storage (HPOS).

## Features

- **CloudXino account** (SMS › Settings): switch SMS on or off, API key and secret (saved
  encrypted with the site's salts), optional sender ID. *Check balance* and *Send test SMS*
  save the settings first.
- **One message per order event**: *Order placed* plus every WooCommerce order status, custom
  ones too (e.g. "Shipped" from another plugin). Each can be switched on and reworded.
  On by default: Processing, Shipped, Completed.
- **Placeholders** filled from the order: `{name}`, `{order}`, `{total}`, `{status}`, `{courier}`,
  `{tracking}`, `{store}`, `{phone}`. Courier and tracking come from order meta keys you set, or
  from WooCommerce Shipment Tracking.
- **Character / SMS counter** under every message (English 160 per SMS, Bangla/Unicode 70).
- **Background sending** with Action Scheduler (bundled with WooCommerce): changing a status never
  waits on the gateway; each SMS is tried 3 times (again after 1 and 5 minutes); the same order
  never gets the same event's SMS twice (e.g. processing → on-hold → processing).
- **Ask before texting**: the order page has a "Text the customer about a status change" box,
  ticked by default; untick it to change the status without an SMS.
- **SMS to customer box** on the order page: every SMS sent for the order (who sent it, failed
  ones with the reason) and a form to send one by hand, prefilled with the current status' message.
  Every SMS also adds an order note.
- **SMS log** (SMS › SMS log): every SMS sent or tried, searchable, filterable by status and message.
- **Campaigns** (SMS › Campaigns): text past customers, each mobile number once. Filter by order
  status, products bought, categories, minimum orders, ordered in the last N days or no order in
  the last N days. A draft lists the matching customers to untick; starting sends to the ticked
  ones in batches of 50. Stop, resume and retry failed. Five failures in a row (no balance, wrong
  key) stop the campaign with the reason. Numbers in "Never send campaigns to" and non-Bangladeshi
  numbers are left out.
- **Stuck queue warning** when SMS have been waiting more than 2 minutes (WP-Cron not running).

## Install

1. Copy this folder to `wp-content/plugins/cxsms` (or upload the zip) and activate it.
2. Go to **SMS › Settings**, add the CloudXino API key and secret, switch SMS on and save.
3. Send a test SMS, then pick the messages to send.

Make sure WP-Cron runs (or a real cron job calls `wp-cron.php`), since SMS go out in the background.

## Developer hooks

- `cxsms_events` — filter the events (`placed` + status slugs) customers can be texted about.
- `cxsms_should_send( bool, WC_Order, string $event )` — return false to skip an automatic SMS.
- `cxsms_order_placeholders( array, WC_Order )` — add or change placeholders.
- `cxsms_sent( int $message_id, ?WC_Order, string $event )` — after an SMS is sent.

The API URL can be changed with the `cxsms_base_url` option (default `https://sms.cloudxino.com/api/v1`).

## Build the WordPress.org zip

WordPress.org rejects hidden files such as `.gitignore`, so build the zip with `git archive`
(it leaves out everything marked `export-ignore` in `.gitattributes`):

```bash
git archive --format=zip --prefix=cx-sms-for-woocommerce/ -o ../cx-sms-for-woocommerce.zip HEAD
```

Commit first: the zip is built from the last commit, not from unsaved changes.

## WordPress.org icon and banner

`.wordpress-org/` holds the plugin page images. They are not part of the plugin zip:
WordPress.org reads them from the `assets` folder of the plugin's SVN repository, which
you get once the plugin is approved.

| File | Shown |
|---|---|
| `icon.svg`, `icon-128x128.png`, `icon-256x256.png` | Plugin icon in search results and lists |
| `banner-772x250.png`, `banner-1544x500.png` | Banner at the top of the plugin page |

The sources are `icon.svg` and `source/banner.svg`. After approval, copy them to SVN:

```bash
svn checkout https://plugins.svn.wordpress.org/cx-sms-for-woocommerce cx-sms-svn
cp .wordpress-org/icon.svg .wordpress-org/*.png cx-sms-svn/assets/
cd cx-sms-svn && svn add --force assets && svn commit -m "Plugin icon and banner"
```
