=== Square WooCommerce Sync Pro ===
Contributors: caotechllc
Tags: square, woocommerce, inventory sync, product sync, pos
Requires at least: 5.8
Tested up to: 6.7
Stable tag: 1.13.2
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered synchronization between Square inventory and WooCommerce products with intelligent matching, batch processing, and category filtering.

== Description ==

Square WooCommerce Sync Pro bridges your Square POS system and WooCommerce store by automatically synchronizing products, inventory levels, SKUs, and prices. The plugin uses intelligent multi-stage matching to connect Square catalog items with existing WooCommerce products and can create new products from unmatched items.

**Sync Features**

* Batch processing mode that handles large catalogs (1,000+ products) without server timeouts
* Real-time progress tracking with percentage indicator and live product counts
* Category-based sync filtering for targeted updates
* Dry run mode to preview changes before applying them
* Detailed sync log with filtering and export
* Scheduled automatic sync (hourly or daily)

**Intelligent Product Matching**

Products are matched through a four-stage pipeline:

1. **SKU Match** — Direct SKU comparison for exact matches
2. **Title Match** — Normalized title comparison with fuzzy matching
3. **Category Match** — Category-aware matching for similar products
4. **AI Match** (Pro) — AI-powered verification using OpenAI or Anthropic for ambiguous matches

**Inventory Management**

* Stock level synchronization between Square and WooCommerce
* Price updates from Square catalog
* SKU assignment for matched products
* Variable product support with attribute extraction and variation matching
* Image import from Square to WooCommerce media library

**Pro Features**

* AI-powered product matching with multi-step verification using OpenAI or Anthropic
* AI-generated product descriptions for newly created items
* Advanced matching confidence scoring
* Priority support

**Square API Integration**

* Square Catalog API integration with both OAuth and API key authentication
* Handles Square catalog pagination automatically
* Category and variation data extraction
* Error handling with detailed logging

**Requirements**

* WordPress 5.8 or higher
* WooCommerce 6.0 or higher
* PHP 7.4 or higher
* Square account with API access

== Installation ==

1. Upload the `square-woo-sync` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Navigate to Square Sync in the admin menu
4. Enter your Square API credentials in Settings
5. Run a test connection to verify access
6. Start your first sync from the Dashboard

== Frequently Asked Questions ==

= Will the sync overwrite my WooCommerce product data? =

The plugin matches products intelligently and only updates fields that come from Square (stock levels, prices, SKUs). Your WooCommerce-specific data like descriptions, images, and categories remain unchanged unless you explicitly enable those sync options.

= How does it handle large catalogs? =

The plugin uses batch processing, splitting your catalog into groups of 25 products. Each batch is processed as a separate request, preventing server timeouts even on shared hosting. Real-time progress tracking shows exactly where the sync stands.

= Can I sync only specific categories? =

Yes. Use the category filter on the Dashboard to load your Square categories and select which ones to sync. This is useful for targeted updates without processing your entire catalog.

= What happens if a Square product has no WooCommerce match? =

Unmatched products can be automatically created in WooCommerce with data from Square including name, price, SKU, and stock level. Pro users get AI-generated descriptions for new products.

= Does it support variable products? =

Yes. The plugin detects Square item variations and maps them to WooCommerce variable products with proper attributes and variation data.

= Will the sync run automatically? =

Yes. Configure hourly or daily automatic sync from the Settings page. The plugin uses WP-Cron for scheduled syncs.

== Screenshots ==

1. Dashboard with sync controls, progress bar, and category filter
2. Sync results with detailed statistics
3. Settings page with Square API configuration
4. Sync log with filtering and export
5. Pro License management page

== Changelog ==

= 1.13.2 =
* A SKU WooCommerce refuses (still held by a draft or trashed product) is logged and skipped instead of stopping that product's sync. One such error on the first live Square-truth run kept the end-of-run "not in Square" step from running.

= 1.13.1 =
* Square-truth: an option that has stock on the website but isn't in its Square item is kept and listed for review instead of being retired, and that listing's name is left alone (usually a listing an old link mixed up, e.g. salt vs regular strength). Each duplicate option is counted once.

= 1.13.0 =
* Square-truth mode checks every match by SKU before changing anything. A listing is confirmed when it shares a SKU with its Square item (or was matched by SKU). Unconfirmed matches keep their name, options and variations and are listed for review.
* Names change only at the end of a run, and only when exactly one Square item matched the listing and all of the listing's SKUs belong to that item. A listing matched by two Square items is reported instead of being renamed back and forth.
* Option labels follow Square only when the option's own SKU matches the Square variation's.
* Duplicates are removed: an option linked to (or carrying the SKU of) another Square item is taken off when that item has its own listing or no longer exists in Square. A listing whose SKUs all belong to Square items other listings already have is set out of stock and hidden from the shop and search (the URL keeps working, marked _sws_duplicate_of). A listing is never left with no options.
* New stats: review, duplicates.

= 1.12.0 =
* New: Quick stock sync — every 5 minutes, only the Square inventory counts that changed since the last run are copied to their linked WooCommerce listings, so register sales reach the website within minutes. Setting: "Update stock from Square every 5 minutes".
* New: Hold stock for open online orders — website stock = Square count − quantity in pending/processing/on-hold orders from the last 14 days, until the order is completed (for pay-in-store shops where Square only learns about the sale at pickup). Used by both the quick sync and the full sync. Setting: "Hold stock for open online orders" (on by default).

= 1.11.0 =
* New: "Square is the source of truth" setting. Product names, option labels and SKUs follow Square (a SKU held by a retired or unlinked listing moves to the Square-linked one); options Square doesn't have are disabled and set out of stock, keeping their order history; every Square item is matched and updated, and the category filter only limits which new products are created; at the end of a full run, live listings that aren't in Square at all are set out of stock (listings that look like a Square item but didn't match are only reported). Honours dry run.

= 1.10.2 =
* Fixed: One Square variation is linked to exactly one WooCommerce option. When a sync pairs a Square variation with an option, any other option of the same product still carrying that Square variation ID (a retired duplicate, or one an earlier sync mis-paired) is unlinked, so it can't be mistaken for the same Square item by tools that push stock (StockDeck)
* Fixed: Variable products no longer keep a leftover Square variation link from when they were simple products

= 1.10.1 =
* Fixed: A variation whose stored Square link is correct but whose label is worded differently ("Red Carbon Fiber" vs "Red", "0.5" vs ".5 Ohm") no longer gets a duplicate variation created next to it — the stored link wins when no stricter stage finds a better match
* Fixed: Option comparison ignores word order and treats Iced/Freeze/Frozen as Ice ("Iced 6mg" = "6mg-ice"); taxonomy slugs like "0-5" read as 0.5

= 1.10.0 =
* Changed: The scheduled-sync category filter now only limits which NEW products are created. Square items already linked to a published WooCommerce product always sync — live listings whose Square item sat in an unselected category ("dispo pods", "Tanks", "Charger/Battery", "Mods", uncategorized) kept stale stock and price indefinitely

= 1.9.9 =
* Fixed: Square catalogs with two items for one product (e.g. two "Pod Juice Clear" entries) created a duplicate draft every sync — a WooCommerce product whose variations are already linked to a Square item's variations now counts as serving that item too
* Fixed: ".5 Ohm" option values now match "0.5 Ohm" variations

= 1.9.8 =
* Fixed: Disabled (private) variations are no longer considered when matching Square variations. Exact-name matching was re-linking retired duplicates ("New 3mg", "Regular") so the enabled option customers see stopped receiving stock updates
* Fixed: "New 3mg"-style reformulation labels are compared as "3mg"; Square's placeholder "Regular" variation now trusts its stored variation link

= 1.9.7 =
* Fixed: Duplicate variations — Square option values are now matched to existing WooCommerce variations after normalising strength formatting ("3" = "3mg", "0" = "omg", "3 ice" = "3mg-ice"). Previously the name check failed (<65% similar) and the sync created a second "3" variation next to the original "3mg" one on 135 variations; the original (with the order history) then kept stale stock

= 1.9.6 =
* Fixed: SKU, title-similarity and AI product matches now require the product names to describe the same item (word-level check). similar_text() alone let shared brand words carry a match — e.g. "Custard Monster Salts Pumpkin Spice" was synced into "Custard Monster Salt – Butterscotch", "Bo Pods"/"Juno Pods" into "Airis Pods" — so Square stock and prices were written onto the wrong WooCommerce products every night
* Fixed: A WooCommerce product already linked (via _square_product_id) to one Square item can no longer be matched to a different Square item. The per-run "already matched" list reset every 5-product batch, so up to 152 products were being overwritten by two or more Square items in turn
* Fixed: AI variation matches are only accepted when the returned ID is one of the product's own unclaimed variations

= 1.7.1 =
* Fixed: Scheduled sync silently skipped forever when a previous batch sync was interrupted (e.g. WP-Cron never received enough traffic to finish all batch iterations) — the stale batch lock (sws_batch_offset / sws_batch_total) is now automatically cleared after 4 hours so the next scheduled run proceeds normally instead of perpetually reporting "already in progress"
* Fixed: Plugin activation (activate()) now honours the saved sws_sync_time preference when computing the first scheduled run time, matching the logic already used in save_settings() — previously it always used time() so the preferred nightly hour was ignored after a deactivation/reactivation
* Fixed: activate() no longer schedules the sws_scheduled_sync WP-Cron event when the saved interval is "disabled"

= 1.7.0 =
* Fixed: Stage 0 (Square variation ID match) now includes a name-sanity check — if the stored ID matches but the Square variation name is < 65% similar to the WC variation's attribute values, the match is rejected and later stages are tried. This prevents a corrupted _square_variation_id (written by a prior buggy sync) from permanently locking a WC variation to the wrong Square variation.
* Fixed: New Stage 0.5 added — exact case-insensitive attribute-value match across all WC variations. Recovers correct pairings even when _square_variation_id is stale/corrupt, without relying on fuzzy matching.
* Fixed: Stage 1 (SKU match) now also applies the same name-sanity check (>= 65% similarity) to guard against corrupt SKUs written by prior buggy syncs.
* Fixed: "Any Flavor" on newly created variations — variation attribute values are now written via direct update_post_meta() after save(), using the same post-save write pattern already used for stock and price. This prevents WooCommerce internal hooks from wiping attribute values during save().

= 1.6.9 =
* Fixed: New combo-named variations (e.g. "Miami Mint / Winter Mint") were being falsely matched by AI/fuzzy matching to already-existing single-flavor variations (e.g. "Miami Mint") — causing the existing variation's SKU/stock/price to be overwritten with wrong values and the new variation to never get created
* Fixed: find_woo_variation() Stages 2–4 (fuzzy name, AI, single-fallback) now only consider WC variations that are NOT already claimed by a different Square variation ID — variations with a stored _square_variation_id pointing to a different Square variation are excluded from fuzzy/AI matching and can only be matched via Stage 0 (exact ID) or Stage 1 (exact SKU)

= 1.6.8 =
* Fixed: All variations showing the same SKU — removed the sibling-variation loophole in sku_is_available() that let multiple variations within the same product claim an identical SKU (only the exact post ID is now considered an owner, so siblings are correctly rejected)
* Fixed: Variation SKU sync now runs whenever the WC SKU differs from the Square SKU (previously "set only when empty") — this auto-corrects duplicates left by the prior bug on the very next sync without any manual intervention
* Improved: SKU update log line now shows the old value → new value so changes are visible in the sync log

= 1.6.7 =
* Fixed: New variations created by the sync now use the product's ACTUAL attribute names instead of generic placeholders ("Option", "Size", etc.) — previously variations were created with the wrong attribute key (e.g., attribute_option instead of attribute_flavor) causing them to not appear under the correct attribute in WooCommerce
* Fixed: Added remap_option_labels_to_product_attrs() which maps each Square option dimension to the product's existing variation-enabled attribute by position — so "Miami Mint" correctly gets attribute_flavor=Miami Mint not attribute_option=Miami Mint
* Fixed: Taxonomy attributes properly handled when adding new option values — inserts term via wp_insert_term() and uses term slug for variation attribute value
* Fixed: New variation save() now followed by direct update_post_meta() for stock/price + wp_cache_delete() — same hook-interference fix applied to create path as the update path (v1.6.6)
* Fixed: Replaced deprecated WC_Product_Variable::sync() with $product->get_data_store()->sync_price($product) — the static sync() call was deprecated in WC and could throw in WC 10.x
* Fixed: New variations now explicitly set status='publish' so they are visible immediately
* Fixed: Error log message when variation->save() returns 0 instead of silently recording a #0 variation
* Improved: Changes column now shows "🆕 new variation" entries for newly created variations so the user can see what was added

= 1.6.6 =
* Fixed: Stock and price changes now written AFTER WC save() completes — eliminates the root cause where save()'s internal wp_update_post() hook chain (save_post_product_variation, etc.) could fire third-party code that reverted our direct postmeta writes
* Fixed: For variable products, save() is now called first for SKU/meta only (no stock/price in get_changes()), then stock/price are written directly after save() returns — guarantees these are the final writes for the request
* Fixed: For simple products, same post-save pattern applied via update_existing_product() after parent save() completes
* Fixed: wp_cache_delete() called after direct writes to bust the WordPress post_meta object cache, ensuring subsequent in-process reads see the new values
* Fixed: Variation stock comparison now uses 'edit' context (get_stock_quantity('edit') / get_manage_stock('edit')) to read the variation's own stock rather than the parent-inherited value
* Removed: [DBG] diagnostic log lines from 1.6.5

= 1.6.5 =
* Debug: Added [DBG] log lines after every direct postmeta write and after save() — shows var_id, written value, and immediate readback so we can confirm whether writes are sticking or being reverted

= 1.6.4 =
* Fixed: Stock and price changes now persist unconditionally — use direct update_post_meta() for _stock, _manage_stock, _stock_status, _regular_price, and _price on both variations and simple products, bypassing the WooCommerce object pipeline that could silently revert writes (e.g. woocommerce_manage_stock disabled globally, competing plugins hooking save_post, or persistent object cache issues)
* Fixed: In-memory WC object props are synced after direct postmeta writes so the subsequent save() for SKU/meta never overwrites the values we just set
* Fixed: wc_delete_product_transients() called explicitly after each variation save to ensure the admin and frontend always show fresh data

= 1.6.2 =
* Fixed: "Invalid or duplicated SKU" error crashing single product sync and full sync — all set_sku() calls now check availability first via wc_get_product_id_by_sku() before assigning
* Fixed: Duplicate SKU check accounts for the product's own ID and child variations so re-syncing a product with an existing SKU no longer fails
* Improved: When a SKU is already taken by another product, the sync logs a warning and continues instead of throwing a fatal exception

= 1.6.1 =
* Added: "✏️ Relink" button on every row in the Products table — click to open an inline search panel and reassign any Square product to the correct WooCommerce product
* Added: Live WooCommerce product search by name or ID (autocomplete dropdown) in the relink panel
* Added: "✗ Clear Link" option in the relink panel to fully unlink a Square product from its WooCommerce product (marks it as unmatched without deleting anything)
* Added: Relinking updates _square_product_id meta on both the old and new WooCommerce products and sets match_method to "manual" in the tracking table

= 1.6.0 =
* Added: Single Product Sync card on the main Dashboard — paste any Square Catalog Item ID and sync just that one product without running a full catalog sync
* Improved: Single product sync result shown inline (green/red) instead of browser alert popups
* Improved: Button and input bindings use event delegation so the sync UI works correctly on both Dashboard and Products pages
* Fixed: Removed duplicate sync input from Products page toolbar — Dashboard card is now the canonical place

= 1.5.9 =
* Fixed: Category mapping now uses case-insensitive lookup — Square categories returning different casing than saved mappings now correctly match
* Fixed: Added slug-based WC category fallback so categories with special characters or capitalization differences no longer create duplicates
* Fixed: Variation inventory sync for products with multiple variations — normalized name matching strips "/" separators before similarity comparison, preventing mis-matched variations from being re-created instead of updated
* Added: Per-option-value variation matching stage — each Square option value is individually compared against WC attribute values for more reliable variation resolution
* Added: Single product sync — new 🔄 button on each row in the Products table syncs just that one product from Square (live inventory + category + variation update)
* Added: "Sync Product" toolbar input on the Products page — enter any Square Catalog Item ID to sync/create it on demand without running a full catalog sync

= 1.3.5 =
* Added: Scheduled Sync Categories setting — choose which Square categories to include in automatic syncs
* Fixed: Scheduled sync now uses batch mode (cron chaining) instead of processing all products in one request
* Fixed: Scheduled sync no longer times out on WP Engine or hosts with short PHP execution limits
* Added: Category filter now supports multiple categories at once

= 1.3.4 =
* Added: AI now generates both Product Description and Short Description for new products
* Improved: Short descriptions are punchy 1-2 sentence summaries for listing cards and search results

= 1.3.3 =
* Changed: Newly created products are set to Draft status instead of Published (allows adding images before going live)

= 1.3.2 =
* Improved: AI product matching now much better at recognizing similar names as the same product
* Improved: Title search casts a wider net (brand+model, first 2 words, first 3 words)
* Improved: AI prompts now explicitly handle puff counts, model numbers, and capacity suffixes
* Improved: AI verification step is more lenient with minor name differences
* Fixed: Products like "Foger Switch Pro" now correctly match to "Foger Switch Pro 30k"

= 1.3.1 =
* Fixed: Dashboard now uses full available width (removed max-width constraint)

= 1.3.0 =
* Redesigned: Dashboard now uses a clear 3-step guided flow (Configure → Map Categories → Run Sync)
* New: Category Mapping integrated directly into the Dashboard (expandable panel)
* Simplified: Menu reduced from 6 items to 4 (Dashboard, Products, Settings, Sync Log, Pro License)
* Improved: Step indicators show completion status with green checkmarks
* Improved: Last Sync Results shown as a separate card with direct links to Products and Sync Log
* Fixed: Product Inventory table showing empty after plugin update (dbDelta table creation bug)

= 1.2.5 =
* Fixed: Separated dbDelta calls per table for reliable creation on all hosts
* Fixed: Added two-space PRIMARY KEY format required by WordPress dbDelta parser
* Fixed: Removed IF NOT EXISTS from CREATE TABLE (incompatible with dbDelta)

= 1.2.4 =
* Fixed: Category Mapping page now shows saved mappings immediately on load (no re-fetch required)
* Fixed: Previously appeared empty after plugin update even though data was preserved
* Improved: Loading categories from Square now merges with saved mappings (won't lose mapped-only categories)

= 1.2.3 =
* Improved: Dashboard category dropdown auto-populates from saved category mappings on page load
* Improved: No need to re-fetch categories from Square each time — mapped categories are ready instantly
* Changed: "Load Categories" button renamed to "Refresh from Square" when mappings exist

= 1.2.2 =
* Improved: Dashboard uses full-width two-column grid layout (stats left, quick links right)
* Improved: Run Sync section spans full width with inline controls
* Improved: Responsive layout collapses to single column on smaller screens

= 1.2.1 =
* Improved: Redesigned dashboard with modern stat cards, colored icons, and cleaner layout
* Improved: Quick Links section now uses icon grid layout for easier navigation
* Improved: Category mapping save endpoint validates WooCommerce term IDs exist before saving

= 1.2.0 =
* New: Category Mapping admin page — map Square categories to WooCommerce categories
* New: Auto-Match by Name button for quick mapping of identically-named categories
* New: Visual mapping status indicators (Mapped, Auto-match available, Unmapped)
* Improved: Product matcher checks category mappings before name-based lookup
* Improved: Sync engine uses mapped categories when creating or updating products
* Prevents duplicate products when Square and WooCommerce use different category names

= 1.1.1 =
* Fixed category detection: now supports legacy category_id field and newer categories array from Square API
* Added separate CATEGORY object fetching as fallback when related objects do not include categories
* Added reporting_category support as additional fallback
* Categories now load correctly for all Square account types

= 1.1.0 =
* Category-based sync filtering for targeted updates
* Dynamic sync button text reflecting selected category
* Improved state management for category controls during sync
* Consistent control re-enabling across all sync completion paths

= 1.0.0 =
* Initial release
* Batch processing mode with real-time progress tracking
* Four-stage product matching pipeline (SKU, title, category, AI)
* Inventory, price, and SKU synchronization
* Variable product support with variation matching
* Image import from Square catalog
* Scheduled automatic sync
* Dry run mode for previewing changes
* Detailed sync log with filtering

== Upgrade Notice ==

= 1.1.0 =
Adds category-based sync filtering for faster, targeted product updates.

= 1.0.0 =
First public release.
