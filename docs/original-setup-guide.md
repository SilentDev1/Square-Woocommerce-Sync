# Square WooCommerce Sync Pro

AI-powered synchronization between Square inventory and WooCommerce products.

---

## 📋 Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+
- Square Developer Account
- Anthropic API key (Claude) OR OpenAI API key

---

## 🚀 Installation

1. Upload the `square-woo-sync` folder to `/wp-content/plugins/`
2. Activate the plugin from **Plugins → Installed Plugins**
3. Navigate to **Square Sync** in your WordPress admin sidebar
4. Configure your API keys and sync settings
5. Click **Test Connections** to verify everything works
6. Run your first sync!

---

## ⚙️ Configuration

### Square API Setup
1. Go to [Square Developer Dashboard](https://developer.squareup.com/apps)
2. Create or select your application
3. Go to **Credentials** tab
4. Copy your **Access Token** (use Sandbox for testing, Production for live)
5. Go to **Locations** to find your Location ID (optional)

### AI Setup (Anthropic Claude — Recommended)
1. Go to [console.anthropic.com](https://console.anthropic.com)
2. Create an API key
3. Paste it in **AI API Key** field
4. Use model `claude-3-haiku-20240307` for fast/cheap, or `claude-3-5-sonnet-20241022` for best accuracy

### AI Setup (OpenAI)
1. Go to [platform.openai.com](https://platform.openai.com)
2. Create an API key
3. Set provider to **OpenAI GPT**
4. Use `gpt-4o-mini` (fast/cheap) or `gpt-4o` (best)

---

## 🔄 How the Sync Works

### Product Matching (5-Stage Process)
1. **SKU Match** — Fastest, most reliable. Finds WC products with identical SKUs from Square variations
2. **Title Search** — WordPress full-text search on product names (handles slight differences)
3. **Category Search** — Narrows candidates by Square category → WooCommerce category
4. **AI Matching** — Claude/GPT compares names, categories, attributes and returns a confidence score
5. **AI Verification** — Final cross-check to prevent false matches

### What Gets Updated
- ✅ Stock quantities (per variation)
- ✅ SKUs added to WooCommerce products missing them
- ✅ Product prices (optional)
- ✅ Square Product/Variation IDs stored as meta fields

### Variation Matching
For variable products, variations are matched by:
1. Exact SKU match
2. String similarity on variation names/attributes (>80% similarity)
3. AI attribute matching
4. Single-variation fallback

### New Products (Square → WooCommerce)
When a Square product has no WooCommerce match:
- Creates simple or variable product automatically
- Generates an AI product description using Claude's knowledge
- Downloads and attaches the Square product image
- Creates WooCommerce categories if needed

---

## 🔧 Advanced Settings

### Confidence Threshold (default: 0.75)
- Controls how confident the AI must be before making a match
- **0.9+** = Very conservative, fewer matches, almost no false positives
- **0.75** = Balanced (recommended)
- **0.6** = More aggressive matching, may have occasional false matches
- Always test with **Dry Run Mode** first

### Dry Run Mode
Enable this to test what WOULD happen without making any changes. All actions are logged but nothing is saved to the database.

---

## 📊 Meta Fields Added to WooCommerce Products

| Meta Key | Description |
|----------|-------------|
| `_square_product_id` | Square Catalog Item ID |
| `_square_variation_id` | Square Catalog Item Variation ID |

---

## 🔌 Hooks & Filters

```php
// Filter Square products before processing
add_filter('sws_square_products', function($products) {
    return $products; // modify or filter the list
});

// Hook after a product is synced
add_action('sws_product_synced', function($square_product, $woo_product_id, $action) {
    // $action: 'updated' | 'created' | 'skipped'
}, 10, 3);

// Customize AI confidence per operation
add_filter('sws_ai_confidence_threshold', function($threshold, $context) {
    if ($context === 'create') return 0.9; // stricter for new products
    return $threshold;
}, 10, 2);
```

---

## 🐛 Troubleshooting

**"No match found" for products that should match**
- Try lowering the confidence threshold slightly
- Check that your Square product names are similar to WooCommerce names
- Enable Dry Run and check the log for AI reasoning

**Square API errors**
- Verify you're using the correct environment (Sandbox vs Production)
- Check your Access Token is valid and not expired
- Ensure your app has `INVENTORY_READ` and `ITEMS_READ` permissions

**Sync timing out**
- For large catalogs (500+ products), increase PHP `max_execution_time`
- Add to wp-config.php: `set_time_limit(600);`

---

## 📝 Changelog

### 1.0.0
- Initial release
- Square Catalog API integration with full pagination
- 5-stage AI-powered product matching
- Variation-level sync for variable products
- Automatic SKU population
- New product creation with AI descriptions
- Dry run mode
- Scheduled automatic sync
- Real-time log viewer
