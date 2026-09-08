=== Xenios KB Bot ===
Contributors: krateosbv, xeniatech
Tags: chatbot, ai, support, knowledge base, helpdesk
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 2026.09.08
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered chatbot driven entirely by your own knowledge base. Bring your own AI provider API key — no external subscription bundled with the plugin.

== Description ==

Xenios KB Bot adds a floating AI support chat to your WordPress site that answers
visitor questions from a knowledge base you control. The knowledge base itself
lives in your own WordPress database and is never used to train any third-party
model.

To generate a reply, the plugin sends the visitor's message and the relevant
knowledge base content to the AI provider endpoint you configure with your own
API key. That request is the only data that leaves your server, and it goes only
to the provider you chose — not to Krateos BV or any other third party.

Bring your own API key for the language model provider of your choice. You stay in
control of the model, the costs, and where that request is sent.

Features:

* Floating chat widget, injected site-wide on the front end
* Up to 5 knowledge base entries in the free version, stored in your WordPress database (unlimited entries with Xenios KnowBot)
* Bring-your-own AI provider API key (any OpenAI-compatible chat completion endpoint)
* Multilingual replies
* Lightweight — no bloated dependencies

== Installation ==

1. Upload the `xenios-kb-bot` folder to `/wp-content/plugins/`, or install the
   plugin through the WordPress Plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to Settings → Xenios KB Bot and add your AI provider endpoint and API key.
4. Add your knowledge base entries.
5. The chat widget appears automatically on the front end of your site.

== Frequently Asked Questions ==

= Do I need an OpenAI account? =

No. Xenios KB Bot is provider-agnostic — bring your own API key for any
OpenAI-compatible chat completion endpoint. You are not locked into a single AI
vendor.

= Does my data leave my server? =

Your knowledge base is stored only in your WordPress database. When the bot
answers a visitor, the visitor's message and the relevant knowledge base content
are sent to the AI provider endpoint you configured with your own API key — that
outbound request is the only data that leaves your server, and it's under your
control, going only to the provider you chose.

= Is there an entry limit? =

Up to 5 question-and-answer pairs in this free version. If you need more,
[Xenios KnowBot](https://xeniacloud.eu) offers unlimited knowledge base entries.

== Changelog ==

= 2026.09.08 =
* Switched the plugin's versioning scheme from semantic versioning to date-based (YYYY.MM.DD). No functional changes.

= 1.1.0 =
* Knowledge base editor now provides 5 fixed Q&A entry fields (free version limit), clearly disclosed in the editor and FAQ, with a link to the unlimited paid version.

= 1.0.0 =
* Initial public release.

== Upgrade Notice ==

= 2026.09.08 =
Versioning scheme change only (semver → YYYY.MM.DD). No functional changes; safe to update.

= 1.1.0 =
Free version now limited to 5 Q&A entries (disclosed in-editor/FAQ). If you had more than 5 saved, only the first 5 show, and re-saving settings removes the rest — back up your knowledge base first.

= 1.0.0 =
Initial public release.
