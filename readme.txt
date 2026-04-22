=== Kriti Bangla Fonts ===
Contributors: abusayed0206
Tags: bangla font, bengali fonts, typography, font cdn, custom fonts
Requires at least: 5.8
Tested up to: 6.9
Stable tag: 1.0.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A plugin to integrate high-quality Bangla fonts into your WordPress website seamlessly via CDN or locally hosted files.

== Description ==

Kriti Fonts provides a seamless way to integrate beautiful, high-quality Bangla fonts into your WordPress website. Choose from a rich catalog of Bangla fonts and apply them instantly to your site using our lightning-fast CDN, or download and host them locally on your own server for maximum privacy and performance control.

= Features =
* **Font Catalog**: Browse and search through a rich collection of Bangla fonts.
* **Live Preview**: Type and preview fonts directly from the WordPress admin dashboard before applying them.
* **Delivery Methods**: Serve fonts via the Kriti CDN or host the font files locally on your server.
* **Targeted Assignments**: Apply fonts globally (all text), or target specific elements like Headings (H1-H6) and Paragraphs (P).
* **Lightweight**: Optimized for speed, utilizing modern `woff2` formats.

== External services ==

This plugin connects to the Kriti service at kriti.app to provide the font catalog, metadata, and downloadable font files.

It uses the service for:
* Loading the searchable font index and font list in the admin screen.
* Loading per-font metadata when an admin opens the Metadata tab.
* Downloading the selected `.woff2` file only when the admin chooses `Host Locally` and saves a font.

Data sent and when:
* Standard HTTP request data (including your server IP address and user agent) is sent when requests are made.
* Requested font slug/path is sent when loading metadata or downloading font files.
* Requests happen only while an administrator uses the plugin settings page and related actions.

Service provider:
* Kriti (https://kriti.app)

Service terms and privacy:
* Terms of Service: https://kriti.app/terms-of-service
* Privacy Policy: https://kriti.app/privacy-policy

== Installation ==

1. Upload the `kriti-bangla-fonts` folder to the `/wp-content/plugins/` directory, or install via the WordPress Plugins menu.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to the new 'Kriti Fonts' menu in your WordPress dashboard.
4. Browse the font catalog, select your preferred font, and assign it to your desired HTML elements.

== Frequently Asked Questions ==

= Can I use multiple fonts at once? =
Yes! You can assign one font for Headings and a completely different font for Paragraphs.

= Are the fonts free? =
Please refer to kriti.app for font licensing and usage rights.

= Does this impact my website speed? =
Kriti uses highly optimized WOFF2 font files. If you use the CDN, they are served rapidly. Local hosting is also supported for further performance tuning.

== Screenshots ==

1. Kriti Fonts admin interface and font catalog.
2. Font preview and assignment modal.

== Changelog ==

= 1.0.1 =
* Updated plugin naming and text domain for WordPress.org review compliance.
* Added external service disclosure details in this readme.
* Hardened remote request and input validation.

= 1.0.0 =
* Initial stable release.
