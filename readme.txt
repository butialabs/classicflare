=== ClassicFlare ===
Contributors: butialabs
Tags: cloudflare, cache, purge, cdn, classicpress
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Clears the Cloudflare cache when posts, comments, feeds or sitemaps change. Works on ClassicPress and WordPress.

== Description ==

ClassicFlare does one thing: it keeps the copy of your site that Cloudflare holds at the edge in
step with your content. Publish, update or trash a post, approve a comment, and the pages that
changed are purged from Cloudflare, along with every page that shows them.

* **Posts and pages**: the permalink, and the old one too when the slug changes
* **Everything that lists the post**: the homepage and its first pages, category, tag, date,
  author and post type archives
* **Comments**: a new approved comment, or one approved, unapproved, spammed or trashed
* **Feeds**: the site feed, the comments feed, each post's comment feed and each archive's feed,
  in RSS2, Atom and RDF
* **Sitemaps**: read from the index your site publishes, so the right files are cleared, from
  core, Yoast SEO, Rank Math, All in One SEO or SEOPress
* **Media, categories, tags, menus and theme changes**, each with its own switch
* A list of extra paths, such as /llms.txt, purged alongside every automatic purge
* "Purge this page" and "Purge everything" in the toolbar, and a button on the settings screen
* A retry when Cloudflare is rate limiting or unreachable, honouring Retry-After, for only the
  URLs that failed
* Works with plain and pretty permalinks
* A public API and WP-CLI commands for deploy scripts

It does not change anything in your Cloudflare zone: no cache rules, no zone settings, no page
rules. It only ever asks Cloudflare to forget cached files.

ClassicFlare is built for ClassicPress 2 and works the same on WordPress. It is not affiliated
with or endorsed by Cloudflare, Inc. or the ClassicPress project.

== Installation ==

1. Upload the plugin and activate it.
2. In Cloudflare, go to My Profile, API Tokens, Create Token, and create a custom token with two
   permissions, limited to your site's zone: **Zone → Zone → Read** and
   **Zone → Cache Purge → Purge**.
3. Go to Settings → ClassicFlare, paste the token and press Connect. The plugin checks the token,
   finds the zone for your domain and is ready.

= Keeping the token out of the database =

Add this to `wp-config.php`:

`define( 'CLASSICFLARE_CF_API_TOKEN', 'your-token' );`

or set `CLASSICFLARE_CF_API_TOKEN` in the environment. The token is then never written to the
database, so it stays out of backups and staging copies, and the settings screen stops offering
a field for it. `CLASSICFLARE_CF_ZONE_ID` does the same for the zone, skipping discovery.

`CLASSICFLARE_DISABLE` set to true switches automatic purging off, for a staging copy that shares
the production token.

== External services ==

The plugin talks to one outside service, the Cloudflare API, to clear the copy of your pages that
Cloudflare holds. Nothing else leaves your server: no update checker, no licence check, no
analytics.

= Cloudflare API (https://api.cloudflare.com/client/v4/) =

Only contacted once you have configured an API token. Without one, nothing is sent.

What it sends, and when:

* Your API token, as an authorization header on every request.
* When you press Connect or Test connection: a token verification request, your site's hostname
  to find the matching zone, and your zone identifier.
* The URLs to purge, in batches of up to 30, whenever content changes on your site (a post
  published, updated or deleted, a comment approved, a term or menu edited, the theme switched),
  when you press a purge button, when a WP-CLI purge runs, and when a purge that failed is
  retried.

It never sends visitor data, personal data or post content.

Cloudflare, Inc. provides the service. Their terms and privacy policy:

* Terms of use: https://www.cloudflare.com/website-terms/
* Privacy policy: https://www.cloudflare.com/privacypolicy/

= Requests to your own site =

To learn which sitemap files exist, the plugin reads your own sitemap index twice a day, on
WP-Cron. That request goes to your own site and involves no third party.

== What the plugin stores ==

No custom tables. Settings live in the options table: one small autoloaded option, plus a few
that only load on the settings screen (the token, the zone details, the extra paths, the
sitemap list and the result of the last purge). WP-Cron refreshes the sitemap list and runs
retries.

Turning on "Delete data on uninstall" removes all of it when you delete the plugin. Leave it off
and nothing is removed, so deactivating to debug a conflict does not cost you your configuration.

== Frequently Asked Questions ==

= Does it work on ClassicPress? =

Yes. It is written for ClassicPress 2 and needs nothing from the block editor. On WordPress it also
catches block editor saves, after categories and tags have been written.

= Why a token and not my Global API Key? =

The Global API Key can do anything to every zone in your account and cannot be limited. A token
can be limited to purging the cache of one zone, and revoked on its own.

= A visitor's comment did not clear the page straight away =

Comments posted from the front end are purged a few seconds later, on WP-Cron, so the visitor
does not wait on a call to Cloudflare. If your site sets DISABLE_WP_CRON, make sure a system cron
calls `wp cron event run --due-now` every minute.

= Cloudflare says some URLs are "not in this zone" =

The hostname in your site address is not proxied by Cloudflare (grey cloud), or belongs to another
zone. Those URLs are reported as skipped, not failed, and are not retried.

= Which WP-CLI commands are there? =

* `wp classicflare purge <url>...`
* `wp classicflare post <id> [--dry-run]`
* `wp classicflare purge-all [--yes]`
* `wp classicflare connect [<token>]`
* `wp classicflare test`
* `wp classicflare status`

= Can other plugins trigger a purge? =

Yes, through functions or actions that do nothing if ClassicFlare is not installed:

`do_action( 'classicflare_purge_url', [ 'https://example.com/page/' ] );`
`do_action( 'classicflare_purge_post', $post_id );`
`do_action( 'classicflare_purge_all' );`

The `classicflare_purge_urls` filter adjusts the final list before it is sent, and
`classicflare_sitemap_urls` teaches it about a sitemap generator it does not know.

== Changelog ==

= 1.0.0 =
* Initial release.

== Credits ==

Licensed GPL-3.0-or-later. ClassicFlare is derived from Oh My Cache! by Butiá Labs, keeping only
its Cloudflare integration. That code in turn reimplemented ideas from App for Cloudflare by
Digital Point (GPL-3.0, https://wordpress.org/plugins/app-for-cf/) and Nginx Helper by rtCamp
(GPL-2.0-or-later, https://github.com/rtCamp/nginx-helper). Nothing third party is bundled.
