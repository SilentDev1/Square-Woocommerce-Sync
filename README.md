# Square WooCommerce Sync Pro

A custom WordPress plugin built by **Hung Cao / Cao-Tech LLC** to synchronize Square catalog and inventory data into WooCommerce, with AI-assisted matching for products whose names, SKUs, or variation labels differ between systems.

The project combines Square API integration, WooCommerce product updates, Anthropic/OpenAI requests, batch processing, and an administrative interface for reviewing matches and sync results. Its purpose is to reduce the manual work involved in keeping two catalogs aligned while giving store administrators visibility into the matching process.

## My contribution

I built the PHP synchronization engine, Square API wrapper, product and variation matching logic, AI integration, WordPress administration screens, JavaScript controls, and logging tools. The plugin also includes manual correction workflows, scheduled processing, loyalty-data import, and Twilio integration.

This repository contains the custom plugin source and a deployment archive. Production catalogs, customer records, credentials, and runtime logs are not included.

## Core functionality

| Feature | Implementation |
| --- | --- |
| Square catalog import | Retrieves catalog items, variations, categories, images, and inventory data through a dedicated API wrapper. |
| Product matching | Uses stored Square IDs, SKUs, title comparison, category candidates, and AI-assisted matching. |
| Inventory synchronization | Updates WooCommerce stock at product or variation level. |
| Optional price updates | Allows price synchronization to be configured separately. |
| Product creation | Creates missing simple or variable WooCommerce products when enabled. |
| Variation handling | Matches existing variations, creates missing ones, and supports attribute reconciliation and simple-to-variable conversion. |
| Category mapping | Admin controls connect Square categories to WooCommerce categories. |
| Batch processing | Start, process, status, and cancellation handlers support larger sync jobs. |
| Scheduling | WordPress scheduled hooks run synchronization and batch processing. |
| Review tools | Product records, match methods, confidence, reasoning, changes, history, and logs expose what the sync did. |

The implemented catalog flow is **Square → WooCommerce**. This repository does not represent a bidirectional order or payment synchronization system.

## AI features

The AI matcher supports **Anthropic Claude** and **OpenAI**, selected through plugin settings.

### Product matching and verification

When deterministic matching does not resolve a product, the plugin can compare a Square product with a set of WooCommerce candidates using AI. Matching results include confidence and reasoning, with a configurable confidence threshold.

The matching pipeline first checks a stored Square product ID, then SKU matches with a name sanity check. It gathers title and category candidates, checks exact or similar titles, and uses AI for unresolved cases. It also tracks WooCommerce products already claimed during the run to avoid assigning the same candidate repeatedly.

Additional AI verification and integrity-check methods compare matched records and identify discrepancies for review. These are review aids; confidence scores are not guarantees that two products are identical.

### Variation and attribute matching

AI methods support variation matching, attribute-label detection, and attribute extraction from SKU data. These complement the deterministic SKU, name, and existing-ID logic when the two catalogs represent options differently.

### Description generation

The plugin can generate product descriptions from Square product data when creating new WooCommerce products. This functionality is implemented separately from matching and verification in the AI service class.

## Administrative workflow

1. Configure the Square environment, token, location, and AI provider settings.
2. Test connections and configure category mappings and synchronization options.
3. Use the dry-run option to inspect planned product changes before a live run.
4. Start a manual or batch sync, or configure scheduled processing.
5. Review product records, confidence information, logs, and sync history.
6. Use single-product synchronization or manual relinking to correct individual mappings.

Dry-run branches suppress the main product writes while retaining reporting behavior; the dry run should not be described as making no database writes at all.

Maintenance handlers also cover duplicate-copy cleanup, redundant option attributes, and ambiguous variations. These are administrative repair operations, separate from normal catalog synchronization.

## Additional integrations

- **Square Loyalty:** retrieves loyalty program information, accounts, and related customer data for the admin workflow.
- **Twilio SMS:** test-message and campaign handlers, recipient selection, minimum-points filtering, phone normalization, and an administrator-managed opt-out list.
- **Licensing:** activation, validation, expiry checks, and Pro-status handling against a separate Cao-Tech service.
- **Plugin updates:** update-check integration with the external Cao-Tech backend.

The repository includes these integration clients, not the external accounts, license server, or production data. No live imports, messages, or synchronization jobs are run by this portfolio upload.

## Architecture

| File | Responsibility |
| --- | --- |
| [square-woo-sync.php](square-woo-sync.php) | Bootstrap, lifecycle, AJAX handlers, scheduling, licensing, update checks, dashboard, and supplementary integrations. |
| [class-square-api.php](includes/class-square-api.php) | Square requests, pagination, inventory batching, catalog normalization, categories, customers, and loyalty data. |
| [class-product-matcher.php](includes/class-product-matcher.php) | Product identity resolution, candidate selection, variation matching, and already-matched tracking. |
| [class-ai-matcher.php](includes/class-ai-matcher.php) | Provider calls, AI matching, verification, description generation, and attribute inference. |
| [class-sync-engine.php](includes/class-sync-engine.php) | Full and batch runs, WooCommerce updates and creation, variation reconciliation, and result recording. |
| [class-sync-logger.php](includes/class-sync-logger.php) | Structured logging, recent-log retrieval, and debug-file access. |
| [class-admin-page.php](admin/class-admin-page.php) | WordPress settings and operational UI. |
| [admin.js](assets/admin.js) | Administrative interface interactions. |
| [admin.css](assets/admin.css) | Administrative styling. |

The API wrapper normalizes Square records before they reach the matching and synchronization layers. Matching identifies the destination product; the sync engine applies the configured changes and records the outcome.

## Technology and requirements

**WordPress · WooCommerce · PHP · JavaScript · CSS · Square API · Anthropic API · OpenAI API · Twilio · WP-Cron**

The plugin header declares WordPress **5.8+**, PHP **7.4+**, and WooCommerce **6.0+**. These are declared requirements, not a compatibility matrix verified during the repository upload.

## Installation

1. Install WordPress and WooCommerce in a development environment.
2. Upload [dist/square-woo-sync.zip](dist/square-woo-sync.zip) through **Plugins → Add New → Upload Plugin**, or place the plugin source under `wp-content/plugins/square-woo-sync/`.
3. Activate **Square WooCommerce Sync Pro**.
4. Open **Square Sync** in WordPress administration and configure the required connections and settings.
5. Review mappings and dry-run output before enabling product updates.

Provider credentials, service accounts, and any required license are configured separately. The [original implementation guide](docs/original-setup-guide.md) and [WordPress readme/changelog](readme.txt) are preserved as historical documentation; the entry file declares the current plugin version.

## Repository review

The portfolio preparation checked source for common credential patterns, excluded operating-system metadata, and verified that the deployment archive matches the source. No WordPress runtime or live Square, AI, or Twilio integration tests were performed as part of this upload.

## Author

**Hung Cao — Cao-Tech LLC**

Custom WordPress plugins, WooCommerce integrations, API automation, and AI-assisted catalog tooling.
