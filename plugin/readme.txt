=== Parcelwise ===
Contributors: furkankadiryilmaz
Tags: woocommerce, order tracking, order status, chatbot, ai
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Verified order status chat for WooCommerce: customers ask where their order is, and never see anyone else's.

== Description ==

"Where is my order?" is the question stores get most. Parcelwise answers it in a chat box on any page, and does only that.

A general-purpose AI chatbot answers from whatever data it is handed. Parcelwise hands the AI an order only after the customer has shown it is theirs, so no wording or trick in a message can get one customer another customer's order.

* The customer gives their order number and the email address or postcode they used, in their own words and language.
* The assistant looks the order up and replies with its status, items, refunds and tracking numbers.
* An order is only shown when the number and the email or postcode both match it.

It works with the way stores are actually set up:

* HPOS and classic order storage
* Custom and sequential order numbers, such as "TR-1234-26" or "SIP-000123"
* Refunded and partially refunded orders
* Tracking numbers from WooCommerce Shipment Tracking or Advanced Shipment Tracking, or typed into an order note
* Customers who type their account email instead of the billing email, or add spaces and capitals

And it is built to be safe:

* Your API key stays on your server and is never sent to the browser.
* Wrong details always get the same "not found" answer, so the chat can't be used to find out which orders exist.
* After 10 failed lookups, a visitor is paused for 10 minutes. Each visitor can send up to 30 chat messages an hour.
* The assistant only states what the order lookup returned, and sends anything else (refund timing, discounts, cancellations) to your support contact.

== Installation ==

1. Install and activate the plugin. WooCommerce must be active.
2. Give it an AI model, in one of two ways:
   * On WordPress 7.0 or later, connect an AI provider under Settings > Connectors. Parcelwise uses it automatically.
   * Or go to WooCommerce > Parcelwise and paste an Anthropic API key. A key saved here is used even if the site has a connection.
3. Enter your support contact under WooCommerce > Parcelwise.
4. Add the `[parcelwise]` shortcode to a page, for example "Track your order".

== External services ==

Parcelwise uses an AI model to understand customer messages and write the replies. It only contacts one after a store administrator has set one up, in one of two ways:

* **Your site's AI connection** (Settings > Connectors, WordPress 7.0 or later). The data goes to the provider connected there, such as Anthropic, OpenAI or Google, under that provider's terms and privacy policy.
* **An Anthropic API key** saved under WooCommerce > Parcelwise. The data goes to Anthropic.

Each time a customer sends a message in the chat box, the plugin sends that provider:

* the conversation so far: everything the customer has typed, which usually includes their order number and email address or postcode, and the assistant's replies;
* the details of any order the customer has verified with its number plus email or postcode: status, item names and quantities, totals, refunds, order date and tracking numbers.

Beyond what the customer types themselves, it sends no names, addresses, phone numbers or payment details, and it never sends an order that wasn't verified.

* Anthropic commercial terms: https://www.anthropic.com/legal/commercial-terms
* Anthropic privacy policy: https://www.anthropic.com/legal/privacy

== Frequently Asked Questions ==

= Which model does it use? =

With an Anthropic key: Claude Opus 5.5 by default, or Claude Sonnet 5.5 or Claude Haiku 5.5 if you choose them in the settings. Haiku costs far less per message.

With your site's AI connection, the same setting is a preference. If the connection is to another provider, that provider's model is used.

= Do I need an Anthropic API key? =

Not on WordPress 7.0 or later if your site already has an AI connection under Settings > Connectors. Your own Anthropic key gives Parcelwise a few Claude-only extras: stricter tool calls, an automatic retry on another Claude model if one declines, and cached prompts that cost less.

= What does it cost to run? =

Each customer message is billed to the account behind your AI connection or API key. In our tests with Anthropic keys, a typical conversation cost about $0.009 on Opus 5.5 and $0.0002 on Haiku 5.5.

= Can a customer see someone else's order? =

No. An order is only returned when its number and the email or postcode both match, and the assistant never receives an order that failed that check.

= Can I use it without the chat? =

Yes. `POST /wp-json/parcelwise/v1/lookup` with `order_number` and `email` or `postcode` returns the same order details as JSON.

= How long are conversations kept? =

For one hour, on your own site, so the assistant can follow a conversation. They are removed when the plugin is deleted.

= Where is the source code? =

All of it is in this plugin, unminified. The development repository, with the test suite and the evaluation, is at https://github.com/FurkanKY/order-status-assistant.

== Changelog ==

= 0.3.0 =
* Works with the site's AI connection (WordPress 7.0 AI Client), so an Anthropic API key is no longer required.
* The settings page shows which connection is in use and the last failed model call.

= 0.2.0 =
* Chat assistant powered by Claude, with a settings page and the `[parcelwise]` shortcode.

= 0.1.0 =
* Order lookup by order number plus email or postcode, with tracking numbers and refunds.
