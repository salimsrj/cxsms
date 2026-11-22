=== CX SMS for WooCommerce ===
Contributors: salimsrj
Tags: woocommerce, sms, order notification, bangladesh, cloudxino
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Texts WooCommerce customers about their orders through the CloudXino SMS gateway.

== Description ==

* One SMS per order event (order placed and every order status), each switched on and worded in the admin, with placeholders filled from the order.
* Sent in the background with retries, never twice for the same order and status.
* Send an SMS by hand from the order page, and see every SMS sent for the order.
* Your CloudXino SMS balance at the top of the settings page, SMS log and test SMS.
* SMS campaigns to past customers, filtered by orders, products and categories.

Requires WooCommerce.

== External services ==

This plugin connects to the CloudXino SMS API (https://sms.cloudxino.com/api/v1), provided by CloudXino, to send SMS to your customers. It needs a CloudXino account, and nothing is sent until you add your own API key and secret on the SMS › Settings page.

What is sent, and when:

* When an SMS goes out (an order is placed or changes status and that message is switched on, a store admin sends one from the order page or a campaign, or a test SMS is sent): the customer's mobile number and the message text. The text can include the customer's name, order number, order total, courier and tracking code, depending on how you word the message. Your API key and secret, and your sender ID if you set one, are sent with every request.
* When you open the SMS › Settings page, or click "Refresh" on its balance card: only your API key and secret, to show your SMS balance. The balance is kept for five minutes, so opening the page again doesn't ask CloudXino each time.

CloudXino Terms of Service: https://cloudxino.com/terms
CloudXino Privacy Policy: https://cloudxino.com/privacy

== Installation ==

1. Upload the plugin and activate it.
2. If you don't have a CloudXino account yet, register at https://cloudxino.com/register?ref=R2SD92GZ (also linked from SMS › Settings). This link includes the plugin author's referral code.
3. Go to SMS › Settings, add your CloudXino API key and secret (from your CloudXino account › Settings), and switch SMS on.

== Changelog ==

= 1.0.0 =
* First release.
