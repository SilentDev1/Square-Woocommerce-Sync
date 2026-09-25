<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SWS_Admin_Page {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
        add_action( 'admin_post_sws_save_settings', [ $this, 'save_settings' ] );
    }

    public function register_menu() {
        add_menu_page(
            'Square WooCommerce Sync',
            'Square Sync',
            'manage_woocommerce',
            'square-woo-sync',
            [ $this, 'render_dashboard' ],
            'dashicons-update',
            56
        );

        add_submenu_page(
            'square-woo-sync',
            'Dashboard',
            'Dashboard',
            'manage_woocommerce',
            'square-woo-sync',
            [ $this, 'render_dashboard' ]
        );

        add_submenu_page(
            'square-woo-sync',
            'Products',
            'Products',
            'manage_woocommerce',
            'square-woo-sync-products',
            [ $this, 'render_products' ]
        );

        add_submenu_page(
            'square-woo-sync',
            'Category Mapping',
            'Category Mapping',
            'manage_woocommerce',
            'square-woo-sync-catmap',
            [ $this, 'render_category_mapping' ]
        );

        add_submenu_page(
            'square-woo-sync',
            'Sync Log',
            'Sync Log',
            'manage_woocommerce',
            'square-woo-sync-log',
            [ $this, 'render_sync_log' ]
        );

        add_submenu_page(
            'square-woo-sync',
            'Loyalty Customers',
            'Loyalty Customers',
            'manage_woocommerce',
            'square-woo-sync-loyalty',
            [ $this, 'render_loyalty_customers' ]
        );

        add_submenu_page(
            'square-woo-sync',
            'SMS Campaigns',
            'SMS Campaigns',
            'manage_woocommerce',
            'square-woo-sync-sms',
            [ $this, 'render_sms_campaigns' ]
        );

        add_submenu_page(
            'square-woo-sync',
            'Settings',
            'Settings',
            'manage_woocommerce',
            'square-woo-sync-settings',
            [ $this, 'render_settings' ]
        );

        $license_label = sws_is_pro()
            ? '✓ Pro License'
            : 'Pro License';

        add_submenu_page(
            'square-woo-sync',
            'Pro License',
            $license_label,
            'manage_woocommerce',
            'square-woo-sync-license',
            [ $this, 'render_license_page' ]
        );
    }

    public function enqueue_scripts( $hook ) {
        $plugin_pages = array(
            'toplevel_page_square-woo-sync',
            'square-sync_page_square-woo-sync-products',
            'square-sync_page_square-woo-sync-catmap',
            'square-sync_page_square-woo-sync-settings',
            'square-sync_page_square-woo-sync-log',
            'square-sync_page_square-woo-sync-license',
            'square-sync_page_square-woo-sync-loyalty',
            'square-sync_page_square-woo-sync-sms',
        );

        if ( ! in_array( $hook, $plugin_pages ) ) return;

        wp_enqueue_style( 'sws-admin', SWS_PLUGIN_URL . 'assets/admin.css', [], SWS_VERSION );
        wp_enqueue_script( 'sws-admin', SWS_PLUGIN_URL . 'assets/admin.js', [ 'jquery' ], SWS_VERSION, true );
        $saved_cats = get_option( 'sws_category_mapping', [] );
        $wp_tz = wp_timezone();
        $now_wp = new \DateTime( 'now', $wp_tz );
        wp_localize_script( 'sws-admin', 'SWS', [
            'nonce'   => wp_create_nonce( 'sws_nonce' ),
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'saved_categories' => is_array( $saved_cats ) ? array_keys( $saved_cats ) : [],
            'wp_utc_offset' => $now_wp->format( 'P' ),
        ]);
    }

    public function save_settings() {
        check_admin_referer( 'sws_settings' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $fields = [
            'sws_square_access_token',
            'sws_square_location_id',
            'sws_square_environment',
            'sws_ai_provider',
            'sws_ai_api_key',
            'sws_ai_model',
            'sws_ai_confidence_threshold',
            'sws_sync_interval',
            'sws_sync_time',
            'sws_sync_days',
            'sws_twilio_account_sid',
            'sws_twilio_auth_token',
            'sws_twilio_from_number',
            'sws_twilio_messaging_service_sid',
        ];

        foreach ( $fields as $field ) {
            if ( $field === 'sws_sync_days' ) {
                $days = isset( $_POST['sws_sync_days'] ) ? array_map( 'sanitize_text_field', (array) $_POST['sws_sync_days'] ) : [];
                update_option( 'sws_sync_days', implode( ',', $days ) );
            } elseif ( $field === 'sws_ai_api_key' ) {
                if ( isset( $_POST['sws_ai_key_action'] ) && $_POST['sws_ai_key_action'] === 'remove' ) {
                    delete_option( $field );
                } else {
                    $raw_key = sanitize_text_field( $_POST[ $field ] ?? '' );
                    if ( '' !== $raw_key ) {
                        update_option( $field, sws_encrypt_key( $raw_key ) );
                    }
                }
            } elseif ( $field === 'sws_twilio_auth_token' ) {
                if ( isset( $_POST['sws_twilio_token_action'] ) && $_POST['sws_twilio_token_action'] === 'remove' ) {
                    delete_option( $field );
                } else {
                    $raw_token = sanitize_text_field( $_POST[ $field ] ?? '' );
                    if ( '' !== $raw_token ) {
                        update_option( $field, sws_encrypt_key( $raw_token ) );
                    }
                }
            } else {
                update_option( $field, sanitize_text_field( $_POST[ $field ] ?? '' ) );
            }
        }

        update_option( 'sws_sync_stock',        isset( $_POST['sws_sync_stock'] ) ? '1' : '0' );
        update_option( 'sws_sync_price',        isset( $_POST['sws_sync_price'] ) ? '1' : '0' );
        update_option( 'sws_create_new',           isset( $_POST['sws_create_new'] ) ? '1' : '0' );
        update_option( 'sws_skip_out_of_stock',  isset( $_POST['sws_skip_out_of_stock'] ) ? '1' : '0' );
        update_option( 'sws_ai_generate_desc',   isset( $_POST['sws_ai_generate_desc'] ) ? '1' : '0' );
        update_option( 'sws_ai_verify_all',     isset( $_POST['sws_ai_verify_all'] ) ? '1' : '0' );
        update_option( 'sws_dry_run',           isset( $_POST['sws_dry_run'] ) ? '1' : '0' );
        update_option( 'sws_square_truth',      isset( $_POST['sws_square_truth'] ) ? '1' : '0' );

        $sync_cats = isset( $_POST['sws_sync_categories'] ) ? array_map( 'sanitize_text_field', (array) $_POST['sws_sync_categories'] ) : [];
        $all_sq_cats = array_keys( get_option( 'sws_category_mapping', [] ) );
        if ( count( $sync_cats ) === count( $all_sq_cats ) || empty( $sync_cats ) ) {
            update_option( 'sws_sync_categories', [] );
        } else {
            update_option( 'sws_sync_categories', $sync_cats );
        }

        wp_clear_scheduled_hook( 'sws_scheduled_sync' );
        $interval = get_option( 'sws_sync_interval', 'hourly' );
        if ( $interval !== 'disabled' ) {
            $sync_hour = get_option( 'sws_sync_time', '' );
            if ( $sync_hour !== '' && in_array( $interval, [ 'daily', 'twicedaily' ] ) ) {
                $tz = wp_timezone();
                $now = new \DateTime( 'now', $tz );
                $next = clone $now;
                $next->setTime( (int) $sync_hour, 0 );
                if ( $next <= $now ) {
                    $next->modify( '+1 day' );
                }
                $start = $next->getTimestamp();
            } else {
                $start = time();
            }
            wp_schedule_event( $start, $interval, 'sws_scheduled_sync' );
        }

        wp_redirect( admin_url( 'admin.php?page=square-woo-sync-settings&saved=1' ) );
        exit;
    }

    // ─── DASHBOARD PAGE ──────────────────────────────────────────────
    public function render_dashboard() {
        $last_sync_raw = get_option( 'sws_last_sync', '' );
        $last_sync     = $last_sync_raw ? date( 'M j, Y g:i A', strtotime( $last_sync_raw ) ) : 'Never';
        $last_stats    = get_option( 'sws_last_sync_stats', [] );
        $has_token     = ! empty( get_option( 'sws_square_access_token', '' ) );
        $saved_mappings = get_option( 'sws_category_mapping', [] );
        if ( ! is_array( $saved_mappings ) ) $saved_mappings = [];
        $has_mappings  = ! empty( $saved_mappings );
        $has_synced    = ! empty( $last_stats );

        $woo_cats = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);
        if ( is_wp_error( $woo_cats ) ) $woo_cats = [];
        ?>
        <div class="wrap sws-wrap">
            <div class="sws-dash-header">
                <div class="sws-dash-header-left">
                    <div class="sws-dash-icon">
                        <span class="dashicons dashicons-update"></span>
                    </div>
                    <div>
                        <h1>Square WooCommerce Sync Pro</h1>
                        <span class="sws-dash-version">v<?php echo SWS_VERSION; ?></span>
                    </div>
                </div>
                <div class="sws-dash-header-right">
                    <?php if ( sws_is_pro() ): ?>
                        <div class="sws-pro-badge">
                            <span class="dashicons dashicons-yes-alt" style="font-size:14px;width:14px;height:14px"></span>
                            PRO
                        </div>
                    <?php else: ?>
                        <div style="display:inline-flex;align-items:center;gap:6px;background:#e2e8f0;color:#475569;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600">
                            FREE
                        </div>
                        <a href="<?php echo admin_url('admin.php?page=square-woo-sync-license'); ?>" style="font-size:12px;color:#2563eb;font-weight:500;text-decoration:none">Upgrade to Pro</a>
                    <?php endif; ?>
                    <span class="sws-dash-meta">
                        by <a href="https://cao-tech.com" target="_blank">Cao-Tech LLC</a>
                    </span>
                </div>
            </div>

            <?php
            $step1_done = $has_token;
            $all_steps_done = $step1_done && $has_mappings && $has_synced;
            ?>
            <?php if ( ! $all_steps_done ): ?>
            <div class="sws-dash-flow">

                <?php
                // ─── STEP 1: CONFIGURE ─────────────────────────
                ?>
                <div class="sws-step-card <?php echo $step1_done ? 'done' : 'active'; ?>">
                    <div class="sws-step-header">
                        <span class="sws-step-number <?php echo $step1_done ? 'done' : ''; ?>">
                            <?php echo $step1_done ? '<span class="dashicons dashicons-yes"></span>' : '1'; ?>
                        </span>
                        <div class="sws-step-title">
                            <h2>Configure Square API</h2>
                            <p><?php echo $step1_done ? 'Connected' : 'Add your Square access token to get started'; ?></p>
                        </div>
                        <?php if ( $step1_done ): ?>
                            <a href="<?php echo admin_url('admin.php?page=square-woo-sync-settings'); ?>" class="button button-small">Edit Settings</a>
                        <?php else: ?>
                            <a href="<?php echo admin_url('admin.php?page=square-woo-sync-settings'); ?>" class="button button-primary button-small">Go to Settings</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php
                // ─── STEP 2: CATEGORY MAPPING ──────────────────
                $mapping_count = count( $saved_mappings );
                ?>
                <div class="sws-step-card <?php echo $has_mappings ? 'done' : ( $step1_done ? 'active' : '' ); ?>">
                    <div class="sws-step-header">
                        <span class="sws-step-number <?php echo $has_mappings ? 'done' : ''; ?>">
                            <?php echo $has_mappings ? '<span class="dashicons dashicons-yes"></span>' : '2'; ?>
                        </span>
                        <div class="sws-step-title">
                            <h2>Map Categories <span class="sws-step-optional">(Recommended)</span></h2>
                            <p>
                                <?php if ( $has_mappings ): ?>
                                    <?php echo $mapping_count; ?> categories mapped
                                <?php else: ?>
                                    Map Square categories to WooCommerce categories for accurate product placement
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="sws-step-actions">
                            <button id="sws-toggle-catmap" class="button button-small" data-testid="button-toggle-catmap">
                                <?php echo $has_mappings ? 'Edit Mappings' : 'Set Up Mappings'; ?>
                                <span class="dashicons dashicons-arrow-down-alt2" style="font-size:14px;width:14px;height:14px;margin-left:2px;vertical-align:middle"></span>
                            </button>
                        </div>
                    </div>
                    <div id="sws-catmap-panel" class="sws-step-panel" style="display:none">
                        <div class="sws-catmap-toolbar">
                            <button id="sws-load-catmap" class="button button-primary button-small" data-testid="button-load-catmap">Load Square Categories</button>
                            <button id="sws-auto-match" class="button button-small" data-testid="button-auto-match" style="display:none">Auto-Match by Name</button>
                            <button id="sws-save-catmap" class="button button-small" data-testid="button-save-catmap" style="display:none">Save Mappings</button>
                            <span id="sws-catmap-status" style="color:#6b7280;font-size:12px"></span>
                            <span id="sws-catmap-save-status" style="color:#16a34a;font-size:12px;display:none"></span>
                        </div>
                        <div id="sws-catmap-table-wrap" style="display:none">
                            <table class="widefat striped sws-catmap-table" id="sws-catmap-table">
                                <thead>
                                    <tr>
                                        <th style="width:35%">Square Category</th>
                                        <th style="width:10%;text-align:center">Products</th>
                                        <th style="width:5%;text-align:center"></th>
                                        <th style="width:40%">WooCommerce Category</th>
                                        <th style="width:10%;text-align:center">Status</th>
                                    </tr>
                                </thead>
                                <tbody id="sws-catmap-body">
                                </tbody>
                            </table>
                        </div>
                        <div class="sws-catmap-help">
                            <strong>How it works:</strong> Mapped categories tell the sync engine where to place products. Unmapped categories use name-based matching. Click "Auto-Match by Name" to quickly match identical names.
                        </div>
                    </div>
                </div>

                <?php
                // ─── STEP 3: RUN SYNC ──────────────────────────
                ?>
                <div class="sws-step-card <?php echo $has_synced ? 'done' : ( $step1_done ? 'active' : '' ); ?>">
                    <div class="sws-step-header">
                        <span class="sws-step-number <?php echo $has_synced ? 'done' : ''; ?>">
                            <?php echo $has_synced ? '<span class="dashicons dashicons-yes"></span>' : '3'; ?>
                        </span>
                        <div class="sws-step-title">
                            <h2>Run Sync</h2>
                            <p>
                                <?php if ( $has_synced ): ?>
                                    Last sync: <?php echo esc_html( $last_sync ); ?>
                                <?php else: ?>
                                    Sync your Square products to WooCommerce
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>

            </div>
            <?php endif; ?>

            <div class="sws-card" style="margin-bottom:16px">
                <div class="sws-sync-controls-row">
                    <div class="sws-category-filter-row">
                        <label for="sws-category-filter">Filter by Category</label>
                        <div class="sws-filter-input-group">
                            <select id="sws-category-filter" data-testid="select-category-filter">
                                <option value="">All Categories (Full Sync)</option>
                            </select>
                            <button id="sws-load-cats-btn" class="button button-small"><?php echo $has_mappings ? 'Refresh from Square' : 'Load Categories'; ?></button>
                            <span id="sws-cats-status" class="sws-cats-status-text"></span>
                        </div>
                    </div>
                    <div class="sws-sync-options-row" style="margin-top:8px;">
                        <label style="display:inline-flex;align-items:center;gap:4px;font-size:13px;color:#555;">
                            <input type="checkbox" id="sws-skip-oos" <?php checked(get_option('sws_skip_out_of_stock','0'),'1'); ?>>
                            Skip out-of-stock items (don't create new products if stock is 0)
                        </label>
                    </div>
                    <div class="sws-actions">
                        <button id="sws-test-btn" class="button button-secondary" data-testid="button-test-connections">
                            <span class="dashicons dashicons-networking" style="margin-top:3px;margin-right:2px;font-size:16px;width:16px;height:16px"></span>
                            Test Connections
                        </button>
                        <button id="sws-sync-btn" class="button button-primary sws-sync-hero-btn" data-testid="button-run-sync">
                            <span class="dashicons dashicons-update" style="margin-top:4px;margin-right:4px"></span>
                            Run Sync Now
                        </button>
                    </div>
                </div>

                <div id="sws-connection-results"></div>

                <div id="sws-progress" style="display:none">
                    <div class="sws-progress-bar"><div class="sws-progress-fill"></div><span class="sws-progress-pct">0%</span></div>
                    <p id="sws-progress-text" class="sws-progress-text">Initializing sync...</p>
                </div>

                <div id="sws-result" style="display:none" class="sws-result-box"></div>
            </div>

            <?php
            // ─── SINGLE PRODUCT SYNC ───────────────────────
            ?>
            <div class="sws-card" style="margin-bottom:16px">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                    <span style="font-size:20px">🔄</span>
                    <div>
                        <h2 style="margin:0;font-size:15px;font-weight:700;color:#1e293b">Single Product Sync</h2>
                        <p style="margin:4px 0 0;font-size:12px;color:#6b7280">Sync one product from Square — paste the Square Catalog Item ID to update stock, price, and variations instantly without running a full sync.</p>
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <input type="text" id="sws-single-sync-input" placeholder="Square Catalog Item ID (e.g. ABC123XYZ…)" class="regular-text" style="flex:1;max-width:420px;min-width:200px" title="Paste a Square Catalog Item ID here">
                    <button id="sws-single-sync-btn" class="button button-primary" style="white-space:nowrap">
                        <span class="dashicons dashicons-update" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span>
                        Sync This Product
                    </button>
                </div>
                <div id="sws-single-sync-result" style="display:none;margin-top:10px;padding:10px 14px;border-radius:6px;font-size:13px"></div>
                <p style="margin:8px 0 0;font-size:11px;color:#94a3b8">
                    Find the ID in your Square Dashboard under <strong>Items &amp; Orders → Items</strong>, or from the Products table below after an initial full sync.
                </p>
            </div>

            <?php
            // ─── LAST SYNC RESULTS ─────────────────────────
            if ( $has_synced ):
            ?>
            <div class="sws-card sws-results-card">
                <div class="sws-card-header">
                    <h2>Last Sync Results</h2>
                    <span class="sws-last-sync-tag" id="sws-last-sync"><?php echo esc_html($last_sync); ?></span>
                </div>
                <div class="sws-stat-row">
                    <div class="sws-stat sws-stat-accent-blue">
                        <div class="sws-stat-icon"><span class="dashicons dashicons-cloud"></span></div>
                        <div class="sws-stat-body">
                            <span class="sws-stat-value"><?php echo $last_stats['total_square'] ?? 0; ?></span>
                            <span class="sws-stat-label">Square Products</span>
                        </div>
                    </div>
                    <div class="sws-stat sws-stat-accent-green">
                        <div class="sws-stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                        <div class="sws-stat-body">
                            <span class="sws-stat-value"><?php echo $last_stats['matched'] ?? 0; ?></span>
                            <span class="sws-stat-label">Matched</span>
                        </div>
                    </div>
                    <div class="sws-stat sws-stat-accent-teal">
                        <div class="sws-stat-icon"><span class="dashicons dashicons-update"></span></div>
                        <div class="sws-stat-body">
                            <span class="sws-stat-value"><?php echo $last_stats['updated'] ?? 0; ?></span>
                            <span class="sws-stat-label">Updated</span>
                        </div>
                    </div>
                    <div class="sws-stat sws-stat-accent-indigo">
                        <div class="sws-stat-icon"><span class="dashicons dashicons-tag"></span></div>
                        <div class="sws-stat-body">
                            <span class="sws-stat-value"><?php echo $last_stats['sku_added'] ?? 0; ?></span>
                            <span class="sws-stat-label">SKUs Added</span>
                        </div>
                    </div>
                    <div class="sws-stat sws-stat-accent-purple">
                        <div class="sws-stat-icon"><span class="dashicons dashicons-plus-alt2"></span></div>
                        <div class="sws-stat-body">
                            <span class="sws-stat-value"><?php echo $last_stats['created'] ?? 0; ?></span>
                            <span class="sws-stat-label">Created</span>
                        </div>
                    </div>
                    <div class="sws-stat sws-stat-accent-red">
                        <div class="sws-stat-icon"><span class="dashicons dashicons-warning"></span></div>
                        <div class="sws-stat-body">
                            <span class="sws-stat-value"><?php echo $last_stats['errors'] ?? 0; ?></span>
                            <span class="sws-stat-label">Errors</span>
                        </div>
                    </div>
                </div>
                <div class="sws-results-links">
                    <a href="<?php echo admin_url('admin.php?page=square-woo-sync-products'); ?>">View Product Inventory &rarr;</a>
                    <a href="<?php echo admin_url('admin.php?page=square-woo-sync-log'); ?>">View Sync Log &rarr;</a>
                    <a href="#" id="sws-debug-log-btn">View Debug Log &rarr;</a>
                </div>
            </div>
            <?php endif; ?>

            <div class="sws-card" style="margin-bottom:16px">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                    <span class="dashicons dashicons-trash" style="font-size:20px;width:20px;height:20px;color:#dc2626"></span>
                    <div>
                        <h2 style="margin:0;font-size:15px;font-weight:700;color:#1e293b">Clean Up Duplicate Products</h2>
                        <p style="margin:4px 0 0;font-size:12px;color:#6b7280">Find and trash WooCommerce products with "(Copy)" in the title that share a Square Product ID with the original. This fixes sync issues caused by WooCommerce's Duplicate feature.</p>
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                    <button id="sws-cleanup-copies-btn" class="button button-secondary" style="color:#dc2626;border-color:#fca5a5">
                        <span class="dashicons dashicons-trash" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span>
                        Clean Up Duplicate Products
                    </button>
                </div>
                <div id="sws-cleanup-result" style="display:none;margin-top:10px;padding:10px 14px;border-radius:6px;font-size:13px"></div>
            </div>

            <div class="sws-card" style="margin-bottom:16px">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                    <span class="dashicons dashicons-admin-generic" style="font-size:20px;width:20px;height:20px;color:#2563eb"></span>
                    <div>
                        <h2 style="margin:0;font-size:15px;font-weight:700;color:#1e293b">Fix Duplicate "Option" Attributes</h2>
                        <p style="margin:4px 0 0;font-size:12px;color:#6b7280">Find products that have both a generic "Option" attribute and a real attribute (e.g. "Flavors"). Merges "Option" values into the real attribute and removes the duplicate.</p>
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                    <button id="sws-cleanup-option-attrs-btn" class="button button-secondary" style="color:#2563eb;border-color:#93c5fd">
                        <span class="dashicons dashicons-admin-generic" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span>
                        Fix Duplicate Option Attributes
                    </button>
                </div>
                <div id="sws-option-attrs-result" style="display:none;margin-top:10px;padding:10px 14px;border-radius:6px;font-size:13px"></div>
            </div>

            <div class="sws-card" style="margin-bottom:16px">
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
                    <span class="dashicons dashicons-dismiss" style="font-size:20px;width:20px;height:20px;color:#dc2626"></span>
                    <div>
                        <h2 style="margin:0;font-size:15px;font-weight:700;color:#1e293b">Delete "Any Flavor / Any Option" Variations</h2>
                        <p style="margin:4px 0 0;font-size:12px;color:#6b7280">Permanently deletes all variations that have empty attribute values (showing as "Any Flavor..." or "Any Option..." on the frontend). These are broken variations that should be re-created by the next sync.</p>
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                    <button id="sws-delete-any-vars-btn" class="button button-secondary" style="color:#dc2626;border-color:#fca5a5">
                        <span class="dashicons dashicons-dismiss" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span>
                        Delete "Any" Variations
                    </button>
                </div>
                <div id="sws-delete-any-vars-result" style="display:none;margin-top:10px;padding:10px 14px;border-radius:6px;font-size:13px"></div>
            </div>

            <div id="sws-debug-log-panel" class="sws-card" style="display:none">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <strong style="font-size:13px">Debug Log</strong>
                    <span id="sws-debug-log-path" style="font-size:11px;color:#9ca3af"></span>
                </div>
                <pre id="sws-debug-log-content" style="background:#1e1e2e;color:#cdd6f4;padding:16px;border-radius:8px;font-size:12px;line-height:1.6;max-height:400px;overflow:auto;white-space:pre-wrap;word-wrap:break-word;margin:0"></pre>
            </div>

        <script id="sws-woo-cats" type="application/json"><?php echo wp_json_encode( array_map( function( $t ) {
            $depth = 0;
            $parent = $t->parent;
            while ( $parent ) {
                $depth++;
                $p = get_term( $parent, 'product_cat' );
                $parent = $p ? $p->parent : 0;
            }
            return [
                'id'    => $t->term_id,
                'name'  => $t->name,
                'slug'  => $t->slug,
                'depth' => $depth,
                'count' => $t->count,
            ];
        }, $woo_cats ) ); ?></script>
        <script id="sws-saved-mappings" type="application/json"><?php echo wp_json_encode( $saved_mappings ); ?></script>
        </div>
        <?php
    }

    // ─── PRODUCT INVENTORY PAGE ─────────────────────────────────────
    public function render_products() {
        ?>
        <div class="wrap sws-wrap">
            <h1>
                <span class="dashicons dashicons-products"></span>
                Square Sync — Product Inventory
            </h1>

            <?php if ( sws_is_pro() ): ?>
                <div class="sws-pro-badge" style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#1e3a5f,#2563eb);color:#fff;padding:6px 16px;border-radius:20px;font-size:12px;font-weight:600;margin-bottom:16px">
                    ✓ PRO LICENSE ACTIVE
                </div>
            <?php endif; ?>

            <div class="sws-products-page">
                <div class="sws-card" style="margin-bottom:16px">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
                        <div style="display:flex;gap:8px;flex-wrap:wrap" id="sws-status-filters">
                            <button class="button sws-filter-btn active" data-status="all">All <span class="sws-badge" id="sws-cnt-all">0</span></button>
                            <button class="button sws-filter-btn" data-status="synced">✓ Synced <span class="sws-badge sws-badge-green" id="sws-cnt-synced">0</span></button>
                            <button class="button sws-filter-btn" data-status="updated">✏️ Updated <span class="sws-badge sws-badge-purple" id="sws-cnt-updated">0</span></button>
                            <button class="button sws-filter-btn" data-status="created">🆕 Created <span class="sws-badge sws-badge-blue" id="sws-cnt-created">0</span></button>
                            <button class="button sws-filter-btn" data-status="unmatched">❌ Unmatched <span class="sws-badge sws-badge-yellow" id="sws-cnt-unmatched">0</span></button>
                            <button class="button sws-filter-btn" data-status="error">⚠ Errors <span class="sws-badge sws-badge-red" id="sws-cnt-error">0</span></button>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <select id="sws-product-cat-filter" data-testid="select-product-cat-filter" style="max-width:200px">
                                <option value="">All Categories</option>
                            </select>
                            <input type="text" id="sws-product-search" placeholder="Search products..." class="regular-text" style="max-width:220px">
                            <button id="sws-refresh-products" class="button button-secondary">↻ Refresh</button>
                            <?php if ( sws_is_pro() ): ?>
                                <button id="sws-ai-verify-all-btn" class="button button-secondary" title="Run AI integrity check on all synced products">AI Verify All</button>
                            <?php else: ?>
                                <a href="<?php echo admin_url('admin.php?page=square-woo-sync-license'); ?>" class="button button-secondary" style="opacity:0.8" title="Upgrade to Pro to use AI Verify">
                                    <span style="display:inline-flex;align-items:center;gap:4px">AI Verify All <span style="background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700;line-height:16px">PRO</span></span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="sws-card" style="padding:0;overflow:hidden">
                    <table class="sws-products-table" id="sws-products-table">
                        <thead>
                            <tr>
                                <th style="width:30px">#</th>
                                <th>Square Product</th>
                                <th>WooCommerce Product</th>
                                <th style="width:100px">Status</th>
                                <th style="width:100px">Match</th>
                                <th style="width:100px">AI Score</th>
                                <th style="width:120px">Changes</th>
                                <th style="width:140px">Last Synced</th>
                                <th style="width:80px">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="sws-products-tbody">
                            <tr><td colspan="9" style="text-align:center;padding:40px;color:#6b7280">Loading products...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div id="sws-pagination" style="margin-top:12px;display:flex;justify-content:space-between;align-items:center">
                    <span id="sws-showing" style="font-size:13px;color:#6b7280"></span>
                    <div id="sws-page-btns" style="display:flex;gap:4px"></div>
                </div>

                <div class="sws-card" style="margin-top:20px">
                    <h2>📊 Sync History (Last 30 Runs)</h2>
                    <div id="sws-sync-history" style="overflow-x:auto">
                        <p style="color:#6b7280;text-align:center">Loading history...</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // ─── SETTINGS PAGE ───────────────────────────────────────────────
    public function render_settings() {
        ?>
        <div class="wrap sws-wrap">
            <h1>
                <span class="dashicons dashicons-admin-generic"></span>
                Square Sync — Settings
            </h1>

            <?php if ( isset( $_GET['saved'] ) ): ?>
                <div class="notice notice-success is-dismissible"><p>Settings saved successfully.</p></div>
            <?php endif; ?>

            <div style="max-width:700px">
                <div class="sws-card sws-settings-card">
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                        <?php wp_nonce_field( 'sws_settings' ); ?>
                        <input type="hidden" name="action" value="sws_save_settings">

                        <div class="sws-section">
                            <h3>🟦 Square API</h3>
                            <table class="form-table">
                                <tr>
                                    <th><label for="sws_square_environment">Environment</label></th>
                                    <td>
                                        <select name="sws_square_environment" id="sws_square_environment">
                                            <option value="sandbox" <?php selected(get_option('sws_square_environment','sandbox'),'sandbox'); ?>>Sandbox (Testing)</option>
                                            <option value="production" <?php selected(get_option('sws_square_environment'),'production'); ?>>Production (Live)</option>
                                        </select>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="sws_square_access_token">Access Token</label></th>
                                    <td>
                                        <input type="password" name="sws_square_access_token" id="sws_square_access_token"
                                               value="<?php echo esc_attr(get_option('sws_square_access_token','')); ?>"
                                               class="regular-text" placeholder="EAAAl...">
                                        <p class="description">Found in your <a href="https://developer.squareup.com/apps" target="_blank">Square Developer Dashboard</a></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="sws_square_location_id">Location ID</label></th>
                                    <td>
                                        <input type="text" name="sws_square_location_id" id="sws_square_location_id"
                                               value="<?php echo esc_attr(get_option('sws_square_location_id','')); ?>"
                                               class="regular-text" placeholder="L...">
                                        <p class="description">Leave blank to use all locations for inventory</p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="sws-section">
                            <h3>
                                AI Configuration
                                <?php if ( ! sws_is_pro() ): ?>
                                    <span style="background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;margin-left:8px;vertical-align:middle">PRO</span>
                                <?php endif; ?>
                            </h3>
                            <?php if ( sws_is_pro() ): ?>

                            <div style="background:#f0f6fc;border:1px solid #c3d3e0;border-radius:8px;padding:18px 20px;margin:0 0 16px;display:flex;gap:14px;align-items:flex-start;">
                                <div style="font-size:28px;line-height:1;">&#129302;</div>
                                <div>
                                    <h4 style="margin:0 0 6px;font-size:14px;">Supported AI Providers</h4>
                                    <p style="margin:0 0 10px;line-height:1.6;color:#374151;font-size:13px;">
                                        Choose the AI provider that works best for you. Square WooCommerce Sync supports Anthropic Claude and OpenAI for smart product matching and AI description generation. Your API key is stored securely in your WordPress database (AES-256 encrypted) and never shared with third parties. Usage costs are billed directly by your provider.
                                    </p>
                                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                                        <span style="font-size:12px;background:#fdf4ff;color:#7e22ce;padding:4px 10px;border-radius:6px;font-weight:600;">Anthropic Claude (Haiku) &mdash; Default</span>
                                        <span style="font-size:12px;background:#f0fdf4;color:#166534;padding:4px 10px;border-radius:6px;font-weight:500;">OpenAI (GPT-4o-mini)</span>
                                    </div>
                                </div>
                            </div>

                            <?php
                            $sws_provider = get_option('sws_ai_provider', 'anthropic');
                            $sws_provider_links = array(
                                'anthropic' => 'https://console.anthropic.com/settings/keys',
                                'openai'    => 'https://platform.openai.com/api-keys',
                            );
                            $sws_provider_names = array(
                                'anthropic' => 'Anthropic',
                                'openai'    => 'OpenAI',
                            );
                            $sws_link = isset($sws_provider_links[$sws_provider]) ? $sws_provider_links[$sws_provider] : $sws_provider_links['anthropic'];
                            $sws_pname = isset($sws_provider_names[$sws_provider]) ? $sws_provider_names[$sws_provider] : 'Anthropic';
                            ?>

                            <table class="form-table">
                                <tr>
                                    <th><label for="sws_ai_provider">AI Provider</label></th>
                                    <td>
                                        <select name="sws_ai_provider" id="sws_ai_provider" style="min-width:220px;height:36px;font-size:13px;">
                                            <option value="anthropic" <?php selected($sws_provider,'anthropic'); ?>>Anthropic (Claude Haiku)</option>
                                            <option value="openai" <?php selected($sws_provider,'openai'); ?>>OpenAI (GPT-4o-mini)</option>
                                        </select>
                                        <p class="description" style="margin-top:6px;">
                                            <a id="sws-provider-link" href="<?php echo esc_url($sws_link); ?>" target="_blank" rel="noopener noreferrer" style="color:#059669;text-decoration:none;font-weight:500;">
                                                &rarr; Get <span id="sws-provider-link-name"><?php echo esc_html($sws_pname); ?></span> API Key
                                            </a>
                                        </p>
                                        <script>
                                        (function(){
                                            var sel = document.getElementById('sws_ai_provider');
                                            var linkEl = document.getElementById('sws-provider-link');
                                            var nameEl = document.getElementById('sws-provider-link-name');
                                            var links = {anthropic:'https://console.anthropic.com/settings/keys', openai:'https://platform.openai.com/api-keys'};
                                            var names = {anthropic:'Anthropic', openai:'OpenAI'};
                                            sel.addEventListener('change', function(){
                                                linkEl.href = links[sel.value] || links.anthropic;
                                                nameEl.textContent = names[sel.value] || 'Anthropic';
                                            });
                                        })();
                                        </script>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="sws_ai_api_key">API Key</label></th>
                                    <td>
                                        <?php
                                        $sws_stored_key = get_option('sws_ai_api_key', '');
                                        $sws_has_key = ! empty($sws_stored_key);
                                        if ($sws_has_key):
                                            $sws_decrypted = sws_decrypt_key($sws_stored_key);
                                            $sws_masked = str_repeat('&#8226;', 8) . esc_html(substr($sws_decrypted, -4));
                                        ?>
                                        <div id="sws-key-status" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                            <span style="display:inline-flex;align-items:center;gap:6px;background:#f0fdf4;border:1px solid #bbf7d0;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;color:#166534;">
                                                <span style="width:8px;height:8px;background:#16a34a;border-radius:50%;display:inline-block;"></span>
                                                Connected
                                            </span>
                                            <span style="font-family:monospace;font-size:13px;color:#475569;letter-spacing:1px;"><?php echo $sws_masked; ?></span>
                                            <button type="button" id="sws-change-key-btn" class="button button-small" style="font-size:12px;">Change Key</button>
                                            <button type="button" id="sws-remove-key-btn" class="button button-small" style="font-size:12px;color:#991b1b;">Remove Key</button>
                                        </div>
                                        <input type="hidden" name="sws_ai_key_action" id="sws_ai_key_action" value="">
                                        <div id="sws-key-input-wrap" style="display:none;margin-top:10px;">
                                            <input type="password" name="sws_ai_api_key" id="sws_ai_api_key" class="regular-text" value="" placeholder="Paste new API key here" autocomplete="new-password">
                                            <p class="description" style="margin-top:4px;">Leave blank to keep the existing key. Keys are AES-256 encrypted before storage.</p>
                                        </div>
                                        <script>
                                        (function(){
                                            document.getElementById('sws-change-key-btn').addEventListener('click', function(){
                                                document.getElementById('sws-key-status').style.display = 'none';
                                                document.getElementById('sws-key-input-wrap').style.display = '';
                                                document.getElementById('sws_ai_api_key').focus();
                                            });
                                            document.getElementById('sws-remove-key-btn').addEventListener('click', function(){
                                                if (confirm('Remove the stored API key? AI features will be disabled.')) {
                                                    document.getElementById('sws_ai_key_action').value = 'remove';
                                                    this.closest('form').submit();
                                                }
                                            });
                                        })();
                                        </script>
                                        <?php else: ?>
                                        <div style="margin-bottom:8px;">
                                            <span style="display:inline-flex;align-items:center;gap:6px;background:#fef2f2;border:1px solid #fecaca;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;color:#991b1b;">
                                                <span style="width:8px;height:8px;background:#dc2626;border-radius:50%;display:inline-block;"></span>
                                                Not connected
                                            </span>
                                        </div>
                                        <input type="password" name="sws_ai_api_key" id="sws_ai_api_key" class="regular-text" value="" placeholder="Paste your API key here" autocomplete="new-password">
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="sws_ai_model">Model</label></th>
                                    <td>
                                        <input type="text" name="sws_ai_model" id="sws_ai_model"
                                               value="<?php echo esc_attr(get_option('sws_ai_model','claude-3-haiku-20240307')); ?>"
                                               class="regular-text">
                                        <?php $current_provider = get_option('sws_ai_provider', 'anthropic'); ?>
                                        <div id="sws-model-help-anthropic" class="sws-model-help" style="margin-top:6px;<?php echo $current_provider !== 'anthropic' ? 'display:none;' : ''; ?>">
                                            <p class="description" style="margin:0">
                                                <strong>Recommended:</strong> <code>claude-3-haiku-20240307</code> (fast &amp; affordable)<br>
                                                <strong>Best quality:</strong> <code>claude-3-5-sonnet-20241022</code> (more accurate matching)<br>
                                                <a href="https://docs.anthropic.com/en/docs/about-claude/models" target="_blank" rel="noopener" style="font-size:12px">View all Claude models &rarr;</a>
                                            </p>
                                        </div>
                                        <div id="sws-model-help-openai" class="sws-model-help" style="margin-top:6px;<?php echo $current_provider !== 'openai' ? 'display:none;' : ''; ?>">
                                            <p class="description" style="margin:0">
                                                <strong>Recommended:</strong> <code>gpt-4o-mini</code> (fast &amp; affordable)<br>
                                                <strong>Best quality:</strong> <code>gpt-4o</code> (more accurate matching)<br>
                                                <a href="https://platform.openai.com/docs/models" target="_blank" rel="noopener" style="font-size:12px">View all OpenAI models &rarr;</a>
                                            </p>
                                        </div>
                                        <script type="text/javascript">
                                        (function(){
                                            var sel = document.getElementById('sws_ai_provider');
                                            function swsToggleProvider(){
                                                var p = sel.value;
                                                document.getElementById('sws-model-help-anthropic').style.display = p==='anthropic' ? '' : 'none';
                                                document.getElementById('sws-model-help-openai').style.display = p==='openai' ? '' : 'none';
                                            }
                                            swsToggleProvider();
                                            sel.addEventListener('change', swsToggleProvider);
                                        })();
                                        </script>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="sws_ai_confidence_threshold">Match Confidence Threshold</label></th>
                                    <td>
                                        <input type="number" name="sws_ai_confidence_threshold" id="sws_ai_confidence_threshold"
                                               value="<?php echo esc_attr(get_option('sws_ai_confidence_threshold','0.75')); ?>"
                                               min="0.5" max="1.0" step="0.05" class="small-text">
                                        <p class="description">
                                            <strong>0.75</strong> recommended. Lower = more matches but possible false positives. Higher = fewer but more accurate matches.<br>
                                            The AI compares Square and WooCommerce products by name, SKU, and attributes. Products scoring below this threshold won't be matched.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            <?php else: ?>
                            <div style="background:#f8f9fa;border:1px solid #e2e8f0;border-radius:6px;padding:20px 24px;">
                                <p style="margin:0 0 8px;color:#374151;">Configure your AI provider for smart product matching and integrity verification between Square and WooCommerce.</p>
                                <p style="margin:0;"><a href="<?php echo esc_url(admin_url('admin.php?page=square-woo-sync-license')); ?>" style="display:inline-flex;align-items:center;gap:6px;background:linear-gradient(135deg,#d4a017 0%,#b8860b 100%);color:#fff;padding:8px 16px;border-radius:4px;text-decoration:none;font-weight:600;font-size:13px;"><span class="dashicons dashicons-lock" style="font-size:14px;width:14px;height:14px;line-height:14px;"></span>Upgrade to Pro to configure AI settings</a></p>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="sws-section">
                            <h3>
                                Sync Schedule
                                <?php if ( ! sws_is_pro() ): ?>
                                    <span style="background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;padding:2px 8px;border-radius:8px;font-size:11px;font-weight:700;margin-left:8px;vertical-align:middle">PRO</span>
                                <?php endif; ?>
                            </h3>
                            <?php if ( sws_is_pro() ): ?>
                            <table class="form-table">
                                <tr>
                                    <th>Sync Frequency</th>
                                    <td>
                                        <select name="sws_sync_interval">
                                            <?php
                                            $current = get_option('sws_sync_interval','hourly');
                                            $options = [
                                                'disabled'   => 'Disabled (Manual only)',
                                                'hourly'     => 'Every Hour',
                                                'twicedaily' => 'Twice Daily (every 12 hours)',
                                                'daily'      => 'Once Daily',
                                            ];
                                            foreach ($options as $val => $label) {
                                                echo "<option value='{$val}'" . selected($current,$val,false) . ">{$label}</option>";
                                            }
                                            ?>
                                        </select>
                                        <p class="description">How often Square should check for updates and sync to WooCommerce.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Preferred Sync Time</th>
                                    <td>
                                        <select name="sws_sync_time">
                                            <option value="" <?php selected(get_option('sws_sync_time',''),''); ?>>Any time (run whenever scheduled)</option>
                                            <?php
                                            for ($h = 0; $h < 24; $h++) {
                                                $fmt = sprintf('%02d:00', $h);
                                                $label_time = date('g:i A', strtotime($fmt));
                                                echo "<option value='{$h}'" . selected(get_option('sws_sync_time',''), (string)$h, false) . ">{$label_time}</option>";
                                            }
                                            ?>
                                        </select>
                                        <p class="description">For daily/twice-daily sync: preferred time to run. Uses your WordPress timezone setting.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Active Sync Days</th>
                                    <td>
                                        <?php
                                        $saved_days = get_option('sws_sync_days', 'mon,tue,wed,thu,fri,sat,sun');
                                        $active_days = explode(',', $saved_days);
                                        $day_labels = ['mon'=>'Monday','tue'=>'Tuesday','wed'=>'Wednesday','thu'=>'Thursday','fri'=>'Friday','sat'=>'Saturday','sun'=>'Sunday'];
                                        foreach ($day_labels as $dv => $dl): ?>
                                            <label style="display:inline-block;margin-right:12px;margin-bottom:4px">
                                                <input type="checkbox" name="sws_sync_days[]" value="<?php echo $dv; ?>" <?php checked(in_array($dv, $active_days)); ?>>
                                                <?php echo $dl; ?>
                                            </label>
                                        <?php endforeach; ?>
                                        <p class="description">Which days of the week should the automatic sync run? Uncheck days when your store is closed.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Scheduled Sync Categories</th>
                                    <td>
                                        <?php
                                        $saved_mappings = get_option( 'sws_category_mapping', [] );
                                        $sq_cats = is_array( $saved_mappings ) ? array_keys( $saved_mappings ) : [];
                                        $sched_cats = get_option( 'sws_sync_categories', [] );
                                        if ( ! is_array( $sched_cats ) ) {
                                            $sched_cats = array_filter( array_map( 'trim', explode( ',', $sched_cats ) ) );
                                        }
                                        if ( ! empty( $sq_cats ) ): ?>
                                            <fieldset style="max-height:200px;overflow-y:auto;border:1px solid #ddd;padding:8px 12px;border-radius:4px;">
                                                <label style="display:block;margin-bottom:6px;font-weight:600;">
                                                    <input type="checkbox" id="sws-sched-cats-all" <?php checked( empty( $sched_cats ) ); ?>>
                                                    All Categories
                                                </label>
                                                <hr style="margin:4px 0 8px;">
                                                <?php foreach ( $sq_cats as $cat ): ?>
                                                    <label style="display:block;margin-bottom:4px;">
                                                        <input type="checkbox" name="sws_sync_categories[]" class="sws-sched-cat-cb" value="<?php echo esc_attr( $cat ); ?>" <?php checked( empty( $sched_cats ) || in_array( $cat, $sched_cats ) ); ?>>
                                                        <?php echo esc_html( $cat ); ?>
                                                    </label>
                                                <?php endforeach; ?>
                                            </fieldset>
                                            <p class="description">Choose which Square categories to include in scheduled syncs. Uncheck "All" to pick specific ones.</p>
                                            <script>
                                            (function(){
                                                var allCb = document.getElementById('sws-sched-cats-all');
                                                var cbs = document.querySelectorAll('.sws-sched-cat-cb');
                                                allCb.addEventListener('change', function(){
                                                    cbs.forEach(function(cb){ cb.checked = allCb.checked; });
                                                });
                                                cbs.forEach(function(cb){
                                                    cb.addEventListener('change', function(){
                                                        var allChecked = Array.from(cbs).every(function(c){ return c.checked; });
                                                        var noneChecked = Array.from(cbs).every(function(c){ return !c.checked; });
                                                        allCb.checked = allChecked || noneChecked;
                                                    });
                                                });
                                            })();
                                            </script>
                                        <?php else: ?>
                                            <p class="description" style="color:#999;">No Square categories loaded yet. Go to the Dashboard and load categories from Square first, then return here to configure which ones to include in scheduled syncs.</p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php
                                $next = wp_next_scheduled('sws_scheduled_sync');
                                if ($next): ?>
                                <tr>
                                    <th>Next Scheduled Sync</th>
                                    <td>
                                        <code><?php echo get_date_from_gmt(date('Y-m-d H:i:s', $next), 'F j, Y g:i A'); ?></code>
                                        <p class="description">Based on your current WordPress timezone and schedule settings.</p>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </table>
                            <?php else: ?>
                            <p style="color:#6b7280;font-size:13px;margin-bottom:12px">
                                Set up automatic background sync between Square and WooCommerce on a schedule.
                            </p>
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px 20px;text-align:center">
                                <span style="color:#6b7280;font-size:13px">
                                    <a href="<?php echo admin_url('admin.php?page=square-woo-sync-license'); ?>" style="color:#2563eb;font-weight:500;text-decoration:none">Upgrade to Pro</a> to configure sync scheduling
                                </span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="sws-section">
                            <h3>📱 Twilio SMS</h3>
                            <p style="margin:0 0 12px;color:#6b7280;font-size:13px">Configure Twilio to send promotional SMS campaigns to your Square loyalty customers.</p>
                            <table class="form-table">
                                <tr>
                                    <th><label for="sws_twilio_account_sid">Account SID</label></th>
                                    <td>
                                        <input type="text" name="sws_twilio_account_sid" id="sws_twilio_account_sid"
                                               value="<?php echo esc_attr(get_option('sws_twilio_account_sid','')); ?>"
                                               class="regular-text" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                                        <p class="description">Found in your <a href="https://console.twilio.com/" target="_blank">Twilio Console</a></p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="sws_twilio_auth_token">Auth Token</label></th>
                                    <td>
                                        <?php
                                        $sws_twilio_token = get_option('sws_twilio_auth_token', '');
                                        $sws_has_twilio_token = ! empty($sws_twilio_token);
                                        if ($sws_has_twilio_token):
                                            $sws_tw_decrypted = sws_decrypt_key($sws_twilio_token);
                                            $sws_tw_masked = str_repeat('&#8226;', 8) . esc_html(substr($sws_tw_decrypted, -4));
                                        ?>
                                        <div id="sws-twilio-token-status" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                                            <span style="display:inline-flex;align-items:center;gap:6px;background:#f0fdf4;border:1px solid #bbf7d0;padding:6px 14px;border-radius:6px;font-size:13px;font-weight:600;color:#166534;">
                                                <span style="width:8px;height:8px;background:#16a34a;border-radius:50%;display:inline-block;"></span>
                                                Connected
                                            </span>
                                            <span style="font-family:monospace;font-size:13px;color:#475569;letter-spacing:1px;"><?php echo $sws_tw_masked; ?></span>
                                            <button type="button" id="sws-twilio-change-btn" class="button button-small" style="font-size:12px;">Change Token</button>
                                            <button type="button" id="sws-twilio-remove-btn" class="button button-small" style="font-size:12px;color:#991b1b;">Remove</button>
                                        </div>
                                        <input type="hidden" name="sws_twilio_token_action" id="sws_twilio_token_action" value="">
                                        <div id="sws-twilio-token-input" style="display:none;margin-top:10px;">
                                            <input type="password" name="sws_twilio_auth_token" id="sws_twilio_auth_token" class="regular-text" value="" placeholder="Paste new auth token" autocomplete="new-password">
                                            <p class="description" style="margin-top:4px;">Leave blank to keep the existing token. Tokens are AES-256 encrypted before storage.</p>
                                        </div>
                                        <script>
                                        (function(){
                                            document.getElementById('sws-twilio-change-btn').addEventListener('click', function(){
                                                document.getElementById('sws-twilio-token-status').style.display = 'none';
                                                document.getElementById('sws-twilio-token-input').style.display = '';
                                                document.getElementById('sws_twilio_auth_token').focus();
                                            });
                                            document.getElementById('sws-twilio-remove-btn').addEventListener('click', function(){
                                                if (confirm('Remove the stored Twilio auth token?')) {
                                                    document.getElementById('sws_twilio_token_action').value = 'remove';
                                                    this.closest('form').submit();
                                                }
                                            });
                                        })();
                                        </script>
                                        <?php else: ?>
                                        <input type="password" name="sws_twilio_auth_token" id="sws_twilio_auth_token" class="regular-text" value="" placeholder="Paste your auth token" autocomplete="new-password">
                                        <p class="description">AES-256 encrypted before storage.</p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="sws_twilio_from_number">From Phone Number</label></th>
                                    <td>
                                        <input type="text" name="sws_twilio_from_number" id="sws_twilio_from_number"
                                               value="<?php echo esc_attr(get_option('sws_twilio_from_number','')); ?>"
                                               class="regular-text" placeholder="+1234567890">
                                        <p class="description">Your Twilio phone number in E.164 format (e.g. +1234567890). Must be SMS-enabled.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th><label for="sws_twilio_messaging_service_sid">Messaging Service SID <span style="font-weight:400;color:#6b7280">(optional)</span></label></th>
                                    <td>
                                        <input type="text" name="sws_twilio_messaging_service_sid" id="sws_twilio_messaging_service_sid"
                                               value="<?php echo esc_attr(get_option('sws_twilio_messaging_service_sid','')); ?>"
                                               class="regular-text" placeholder="MGxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx">
                                        <p class="description">
                                            If set, messages will be sent via this Messaging Service instead of the From number directly.
                                            This improves deliverability and is <strong>recommended</strong> to avoid carrier filtering (error 30007).
                                            <br>Find it in <a href="https://console.twilio.com/us1/develop/sms/services" target="_blank">Twilio Console → Messaging → Services</a>.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <div class="sws-section">
                            <h3>⚙️ Sync Options</h3>
                            <table class="form-table">
                                <tr>
                                    <th>Data to Sync</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="sws_sync_stock" value="1" <?php checked(get_option('sws_sync_stock','1'),'1'); ?>>
                                            Sync inventory/stock quantities from Square
                                        </label><br>
                                        <label>
                                            <input type="checkbox" name="sws_sync_price" value="1" <?php checked(get_option('sws_sync_price','0'),'1'); ?>>
                                            Sync prices from Square (off by default — enable if Square is your price source)
                                        </label><br>
                                        <label>
                                            <input type="checkbox" name="sws_square_truth" value="1" <?php checked(get_option('sws_square_truth','0'),'1'); ?>>
                                            Square is the source of truth — product names, option labels and SKUs follow Square; options Square doesn't have are disabled (order history kept); every Square item is matched, the category filter only limits new products; listings not in Square are set out of stock
                                        </label><br>
                                        <label>
                                            <input type="checkbox" name="sws_create_new" value="1" <?php checked(get_option('sws_create_new','1'),'1'); ?>>
                                            Auto-create WooCommerce products for Square-only items
                                        </label><br>
                                        <label style="margin-left:24px;">
                                            <input type="checkbox" name="sws_skip_out_of_stock" value="1" <?php checked(get_option('sws_skip_out_of_stock','0'),'1'); ?>>
                                            Skip out-of-stock items — don't create new products if stock is 0
                                        </label><br>
                                        <label>
                                            <input type="checkbox" name="sws_ai_generate_desc" value="1" <?php checked(get_option('sws_ai_generate_desc','1'),'1'); ?> <?php echo ! sws_is_pro() ? 'disabled' : ''; ?>>
                                            Generate AI descriptions for newly created products
                                            <?php if ( ! sws_is_pro() ): ?>
                                                <span style="background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700;margin-left:4px;vertical-align:middle">PRO</span>
                                            <?php endif; ?>
                                        </label>
                                    </td>
                                </tr>
                                <tr>
                                    <th>AI Integrity Checks</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="sws_ai_verify_all" value="1" <?php checked(get_option('sws_ai_verify_all','1'),'1'); ?> <?php echo ! sws_is_pro() ? 'disabled' : ''; ?>>
                                            AI cross-checks every sync operation for data integrity
                                            <?php if ( ! sws_is_pro() ): ?>
                                                <span style="background:linear-gradient(135deg,#7c3aed,#6d28d9);color:#fff;padding:1px 6px;border-radius:8px;font-size:10px;font-weight:700;margin-left:4px;vertical-align:middle">PRO</span>
                                            <?php endif; ?>
                                        </label>
                                        <p class="description">After syncing each product, AI verifies the match is correct and data wasn't corrupted. Uses additional AI API calls per sync.</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Safety Mode</th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="sws_dry_run" value="1" <?php checked(get_option('sws_dry_run','0'),'1'); ?>>
                                            🧪 Dry Run Mode — log everything but make zero changes to WooCommerce
                                        </label>
                                        <p class="description">Perfect for testing. See exactly what would happen without modifying any products.</p>
                                    </td>
                                </tr>
                            </table>
                        </div>

                        <?php submit_button( 'Save Settings', 'primary', 'submit', true ); ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    // ─── SYNC LOG PAGE ───────────────────────────────────────────────
    public function render_sync_log() {
        ?>
        <div class="wrap sws-wrap">
            <h1>
                <span class="dashicons dashicons-list-view"></span>
                Square Sync — Sync Log
            </h1>

            <div style="max-width:900px">
                <div class="sws-card sws-log-card">
                    <div class="sws-log-header">
                        <h2>📋 Sync Log</h2>
                        <div>
                            <button id="sws-refresh-log-btn" class="button button-secondary button-small">↻ Refresh</button>
                            <button id="sws-clear-log-btn" class="button button-small">Clear Log</button>
                        </div>
                    </div>
                    <div id="sws-log-container">
                        <p class="sws-log-empty">Loading log...</p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // ─── PRO LICENSE PAGE (4-state pattern) ──────────────────────────
    public function render_license_page() {
        if ( isset( $_POST['sws_license_key'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'sws_pro_activate' ) ) {
            $key = sanitize_text_field( $_POST['sws_license_key'] );
            if ( sws_check_key( $key ) ) {
                echo '<div class="notice notice-success"><p>Pro license activated successfully!</p></div>';
            } else {
                $msg = get_option( 'sws_license_status_message', '' );
                echo '<div class="notice notice-error"><p>License activation failed.' . ( $msg ? ' ' . esc_html( $msg ) : '' ) . '</p></div>';
            }
        }

        if ( isset( $_POST['sws_deactivate_license'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'sws_pro_deactivate' ) ) {
            sws_deactivate_license();
            delete_option( 'sws_license_key' );
            delete_option( 'sws_license_plan' );
            delete_option( 'sws_license_max_sites' );
            delete_option( 'sws_license_expires' );
            delete_option( 'sws_license_reason' );
            delete_option( 'sws_license_status_message' );
            echo '<div class="notice notice-info"><p>License deactivated and removed.</p></div>';
        }

        if ( isset( $_POST['sws_signup_email'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'sws_pro_signup' ) ) {
            $signup_name  = sanitize_text_field( $_POST['sws_signup_name'] ?? '' );
            $signup_email = sanitize_email( $_POST['sws_signup_email'] ?? '' );
            if ( $signup_email ) {
                $response = wp_remote_post( sws_license_api_url() . '/wp-json/sre-license/v1/signup', array(
                    'timeout' => 15,
                    'headers' => array( 'Content-Type' => 'application/json' ),
                    'body'    => wp_json_encode( array(
                        'name'    => $signup_name,
                        'email'   => $signup_email,
                        'site_url' => home_url(),
                        'product' => SWS_PRODUCT_SLUG,
                    ) ),
                ) );
                if ( ! is_wp_error( $response ) ) {
                    $body = json_decode( wp_remote_retrieve_body( $response ), true );
                    if ( ! empty( $body['success'] ) ) {
                        echo '<div class="notice notice-success"><p>' . esc_html( $body['message'] ?? 'Signup request submitted! Check your email.' ) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>' . esc_html( $body['message'] ?? 'Signup request failed.' ) . '</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error"><p>Could not connect to the license server. Please try again later.</p></div>';
                }
            }
        }

        echo '<div class="wrap sws-wrap">';
        echo '<h1><span class="dashicons dashicons-admin-network"></span> Square Sync — Pro License</h1>';

        $has_key     = ! empty( get_option( 'sws_license_key', '' ) );
        $is_expired  = sws_license_is_expired();

        if ( $has_key && ! $is_expired && sws_is_pro() ) {
            $plan    = get_option( 'sws_license_plan', 'single' );
            $max     = get_option( 'sws_license_max_sites', 1 );
            $expires = get_option( 'sws_license_expires', '' );

            echo '<div style="background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%);border-radius:12px;padding:28px 32px;color:#fff;margin:20px 0 24px">';
            echo '<h2 style="color:#fff;margin:0 0 6px;font-size:22px">Pro License Active</h2>';
            echo '<div style="display:flex;gap:24px;flex-wrap:wrap;margin-top:12px;font-size:14px">';
            echo '<span>Plan: <strong>' . esc_html( ucfirst( $plan ) ) . '</strong></span>';
            echo '<span>Key: <code style="background:rgba(255,255,255,.2);padding:2px 8px;border-radius:4px">' . esc_html( get_option( 'sws_license_key', '' ) ) . '</code></span>';
            echo '<span>Sites: <strong>' . intval( $max ) . '</strong></span>';
            if ( $expires ) {
                $exp_ts   = strtotime( $expires );
                $exp_fmt  = date( 'F j, Y', $exp_ts );
                $days_left = max( 0, (int) ceil( ( $exp_ts - time() ) / 86400 ) );
                echo '<span>Expires: <strong>' . esc_html( $exp_fmt ) . '</strong>';
                if ( $days_left <= 30 ) {
                    echo ' <span style="background:#fbbf24;color:#000;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600">⚠ ' . $days_left . ' days left</span>';
                } else {
                    echo ' <span style="background:rgba(255,255,255,.2);padding:2px 8px;border-radius:4px;font-size:12px">' . $days_left . ' days left</span>';
                }
                echo '</span>';
            } else {
                echo '<span>Expires: <strong>Never</strong></span>';
            }
            echo '</div>';
            echo '</div>';

        } elseif ( $has_key && ! $is_expired && ! sws_is_pro() && get_option( 'sws_license_reason', '' ) === 'suspended' ) {
            $status_msg = get_option( 'sws_license_status_message', '' );
            echo '<div style="background:linear-gradient(135deg,#92400e 0%,#f59e0b 100%);border-radius:12px;padding:28px 32px;color:#fff;margin:20px 0 24px">';
            echo '<h2 style="color:#fff;margin:0 0 6px;font-size:22px">⚠ License Suspended</h2>';
            echo '<p style="margin:0 0 6px;font-size:14px;color:#fef3c7">Your Pro license has been suspended. Pro features are disabled.</p>';
            if ( $status_msg ) {
                echo '<div style="background:rgba(0,0,0,.2);border-radius:8px;padding:14px 18px;margin:12px 0 16px;">';
                echo '<p style="margin:0;font-size:13px;color:#fef3c7;"><strong>Reason from Cao-Tech LLC:</strong></p>';
                echo '<p style="margin:6px 0 0;font-size:14px;color:#fff;">' . esc_html( $status_msg ) . '</p>';
                echo '</div>';
            }
            echo '<div style="display:flex;gap:24px;flex-wrap:wrap;font-size:14px;margin-bottom:16px">';
            echo '<span>Key: <code style="background:rgba(255,255,255,.2);padding:2px 8px;border-radius:4px">' . esc_html( get_option( 'sws_license_key', '' ) ) . '</code></span>';
            echo '</div>';
            echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
            echo '<a href="mailto:info@cao-tech.com?subject=Square%20WooCommerce%20Sync%20Pro%20License%20Inquiry&body=Hi%20Cao-Tech%20LLC%2C%0A%0AMy%20license%20has%20been%20suspended.%20I%20would%20like%20to%20resolve%20this.%0A%0ASite%3A%20' . rawurlencode( home_url() ) . '%0AKey%3A%20' . rawurlencode( get_option( 'sws_license_key', '' ) ) . '%0A%0AThank%20you!" class="button" style="padding:8px 28px;font-size:14px;background:#fff;color:#92400e;border:none;font-weight:600">📧 Contact Cao-Tech LLC</a>';
            echo '<a href="https://cao-tech.com/square-woo-sync" target="_blank" class="button" style="padding:8px 28px;font-size:14px;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3)">🌐 Visit Cao-Tech.com</a>';
            echo '</div>';
            echo '</div>';

            echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;margin-bottom:20px">';
            echo '<h3 style="margin:0 0 8px;font-size:15px">Deactivate License</h3>';
            echo '<p style="margin:0 0 12px;color:#6b7280;font-size:13px">Remove this license key from your site.</p>';
            echo '<form method="post">';
            wp_nonce_field( 'sws_pro_deactivate' );
            echo '<input type="hidden" name="sws_deactivate_license" value="1">';
            echo '<button type="submit" class="button" onclick="return confirm(\'Remove this license key?\')">Remove License Key</button>';
            echo '</form>';
            echo '</div>';

        } elseif ( $has_key && $is_expired ) {
            $expires = get_option( 'sws_license_expires', '' );
            $exp_fmt = $expires ? date( 'F j, Y', strtotime( $expires ) ) : '';

            echo '<div style="background:linear-gradient(135deg,#7f1d1d 0%,#dc2626 100%);border-radius:12px;padding:28px 32px;color:#fff;margin:20px 0 24px">';
            echo '<h2 style="color:#fff;margin:0 0 6px;font-size:22px">⚠ Pro License Expired</h2>';
            echo '<p style="margin:0 0 12px;font-size:14px;color:#fecaca">Your Pro license expired on <strong>' . esc_html( $exp_fmt ) . '</strong>. Pro features are disabled until you renew.</p>';
            echo '<div style="display:flex;gap:24px;flex-wrap:wrap;font-size:14px;margin-bottom:16px">';
            echo '<span>Key: <code style="background:rgba(255,255,255,.2);padding:2px 8px;border-radius:4px">' . esc_html( get_option( 'sws_license_key', '' ) ) . '</code></span>';
            echo '</div>';
            echo '<div style="display:flex;gap:12px;flex-wrap:wrap">';
            echo '<a href="https://cao-tech.com/square-woo-sync" target="_blank" class="button button-primary" style="padding:8px 28px;font-size:14px;background:#fff;color:#dc2626;border:none;font-weight:600">Renew License</a>';
            echo '<a href="mailto:info@cao-tech.com?subject=Square%20WooCommerce%20Sync%20Pro%20License%20Renewal&body=Hi%20Cao-Tech%20LLC%2C%0A%0AMy%20Pro%20license%20has%20expired.%20I%20would%20like%20to%20renew.%0A%0ASite%3A%20' . rawurlencode( home_url() ) . '%0AKey%3A%20' . rawurlencode( get_option( 'sws_license_key', '' ) ) . '%0A%0AThank%20you!" class="button" style="padding:8px 28px;font-size:14px;background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3)">📧 Contact Cao-Tech LLC</a>';
            echo '</div>';
            echo '</div>';

            echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;margin-bottom:20px">';
            echo '<h3 style="margin:0 0 8px;font-size:15px">Deactivate License</h3>';
            echo '<p style="margin:0 0 12px;color:#6b7280;font-size:13px">This will free up the site slot so you can use your license on a different site.</p>';
            echo '<form method="post">';
            wp_nonce_field( 'sws_pro_deactivate' );
            echo '<input type="hidden" name="sws_deactivate_license" value="1">';
            echo '<button type="submit" class="button" onclick="return confirm(\'Deactivate Pro on this site?\')">Deactivate License</button>';
            echo '</form>';
            echo '</div>';

        } elseif ( ! $has_key ) {
            echo '<div style="background:linear-gradient(135deg,#f8fafc 0%,#e2e8f0 100%);border:2px solid #2563eb;border-radius:12px;padding:28px 32px;margin:20px 0 24px">';
            echo '<h2 style="margin:0 0 8px;font-size:20px;color:#1e3a5f">Upgrade to Square WooCommerce Sync Pro</h2>';
            echo '<p style="margin:0 0 16px;color:#475569;font-size:14px">Unlock advanced AI-powered synchronization features, priority support, and multi-site licensing.</p>';
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;margin-bottom:16px">';
            $pro_features = array(
                'AI Product Matching Engine',
                'AI-Generated Descriptions',
                'Multi-Location Square Sync',
                'Scheduled Automatic Sync',
                'Priority Support',
                'Multi-Site Support',
                'Variable Product Sync',
                'Image Import from Square',
            );
            foreach ( $pro_features as $f ) {
                echo '<div style="display:flex;align-items:center;gap:6px;font-size:13px"><span style="color:#22c55e;font-weight:bold">✓</span> ' . esc_html( $f ) . '</div>';
            }
            echo '</div>';
            echo '<a href="https://cao-tech.com/square-woo-sync" target="_blank" class="button button-primary" style="padding:8px 28px;font-size:14px">Get Pro License</a>';
            echo '</div>';

            echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;margin-bottom:20px">';
            echo '<h2 style="margin:0 0 6px">Request a Pro License</h2>';
            echo '<p style="margin:0 0 16px;color:#6b7280;font-size:13px">Fill in your details to request a Pro license. We will review your request and send your license key by email.</p>';
            echo '<form method="post">';
            wp_nonce_field( 'sws_pro_signup' );
            echo '<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">';
            echo '<div><label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Name</label>';
            echo '<input type="text" name="sws_signup_name" style="padding:8px 12px;border:2px solid #e5e7eb;border-radius:6px;font-size:14px;width:200px" value="' . esc_attr( wp_get_current_user()->display_name ) . '"></div>';
            echo '<div><label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Email</label>';
            echo '<input type="email" name="sws_signup_email" required style="padding:8px 12px;border:2px solid #e5e7eb;border-radius:6px;font-size:14px;width:260px" value="' . esc_attr( wp_get_current_user()->user_email ) . '"></div>';
            echo '<button type="submit" class="button button-primary" style="padding:8px 28px;font-size:14px">Request Pro License</button>';
            echo '</div>';
            echo '</form>';
            echo '</div>';

            echo '<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:8px;padding:20px 24px;margin-bottom:20px">';
            echo '<h3 style="margin:0 0 6px;font-size:15px">Or Contact Cao-Tech LLC Directly</h3>';
            echo '<p style="margin:0 0 12px;color:#6b7280;font-size:13px">Prefer to reach out directly? Contact us by email or visit our website.</p>';
            echo '<div style="display:flex;gap:16px;flex-wrap:wrap;align-items:center">';
            echo '<a href="mailto:info@cao-tech.com?subject=Square%20WooCommerce%20Sync%20Pro%20License%20Request&body=Hi%20Cao-Tech%20LLC%2C%0A%0AI%20would%20like%20to%20request%20a%20Pro%20license%20for%20Square%20WooCommerce%20Sync%20Pro.%0A%0ASite%3A%20' . rawurlencode( home_url() ) . '%0AName%3A%20' . rawurlencode( wp_get_current_user()->display_name ) . '%0AEmail%3A%20' . rawurlencode( wp_get_current_user()->user_email ) . '%0A%0AThank%20you!" class="button" style="padding:8px 28px;font-size:14px">📧 Email Cao-Tech LLC</a>';
            echo '<a href="https://cao-tech.com/square-woo-sync" target="_blank" class="button" style="padding:8px 28px;font-size:14px">🌐 Visit Cao-Tech.com</a>';
            echo '</div>';
            echo '<p style="margin:12px 0 0;color:#94a3b8;font-size:12px">Email: <a href="mailto:info@cao-tech.com">info@cao-tech.com</a> · Website: <a href="https://cao-tech.com" target="_blank">cao-tech.com</a></p>';
            echo '</div>';
        }

        echo '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:20px 24px;margin-bottom:20px">';
        $key_heading = $has_key ? 'Change License Key' : 'Already Have a License Key?';
        $key_desc    = $has_key ? 'Replace with a different license key.' : 'Enter your activation code to unlock Pro instantly.';
        echo '<h2 style="margin:0 0 6px">' . esc_html( $key_heading ) . '</h2>';
        echo '<p style="margin:0 0 16px;color:#6b7280;font-size:13px">' . esc_html( $key_desc ) . '</p>';
        echo '<form method="post">';
        wp_nonce_field( 'sws_pro_activate' );
        echo '<div style="display:flex;gap:12px;align-items:center">';
        echo '<input type="text" name="sws_license_key" placeholder="SWS-2026-XXXX-XXXX-XXXX" style="flex:1;min-width:230px;padding:10px 14px;border:2px solid #e5e7eb;border-radius:6px;font-size:15px;font-family:monospace;letter-spacing:1px" value="' . esc_attr( get_option( 'sws_license_key', '' ) ) . '">';
        echo '<button type="submit" class="button button-primary" style="padding:10px 28px;font-size:14px">Activate</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';

        echo '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px 20px;margin-top:8px">';
        echo '<p style="margin:0;font-size:12px;color:#94a3b8">License Server: <code>' . esc_html( sws_license_api_url() ) . '</code> · Product: <code>' . esc_html( SWS_PRODUCT_SLUG ) . '</code></p>';
        echo '</div>';

        echo '</div>';
    }

    // ─── LOYALTY CUSTOMERS PAGE ─────────────────────────────────────
    public function render_loyalty_customers() {
        $imported = get_option( 'sws_loyalty_customers', [] );
        if ( ! is_array( $imported ) ) $imported = [];
        $last_import = get_option( 'sws_loyalty_last_import', '' );
        ?>
        <div class="wrap sws-wrap">
            <h1>
                <span class="dashicons dashicons-groups"></span>
                Square Sync — Loyalty Customers
            </h1>

            <div class="sws-card" style="margin-bottom:20px">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:16px">
                    <div>
                        <h2 style="margin:0;border:none;padding:0">Square Loyalty Program Customers</h2>
                        <p style="margin:4px 0 0;font-size:13px;color:#6b7280">
                            Import customers enrolled in your Square Loyalty program. Their name and phone number will be available for SMS campaigns.
                        </p>
                    </div>
                    <div style="display:flex;gap:8px;align-items:center">
                        <button id="sws-import-loyalty-btn" class="button button-primary">
                            <span class="dashicons dashicons-download" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span>
                            Import from Square
                        </button>
                        <?php if ( $last_import ): ?>
                            <span style="font-size:12px;color:#6b7280">Last import: <?php echo esc_html( date( 'M j, Y g:i A', strtotime( $last_import ) ) ); ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div id="sws-loyalty-import-status" style="display:none;margin-bottom:16px;padding:10px 14px;border-radius:6px;font-size:13px"></div>

                <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;flex-wrap:wrap">
                    <input type="text" id="sws-loyalty-search" placeholder="Search by name or phone..." class="regular-text" style="max-width:300px">
                    <span id="sws-loyalty-count" style="font-size:13px;color:#6b7280"><?php echo count( $imported ); ?> customer(s) imported</span>
                </div>

                <div class="sws-card" style="padding:0;overflow:hidden">
                    <table class="sws-products-table" id="sws-loyalty-table">
                        <thead>
                            <tr>
                                <th style="width:30px">
                                    <input type="checkbox" id="sws-loyalty-select-all" title="Select all">
                                </th>
                                <th style="width:30px">#</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Email</th>
                                <th style="width:80px">Points</th>
                                <th style="width:100px">Lifetime</th>
                                <th style="width:140px">Enrolled</th>
                            </tr>
                        </thead>
                        <tbody id="sws-loyalty-tbody">
                            <?php if ( empty( $imported ) ): ?>
                                <tr><td colspan="8" style="text-align:center;padding:40px;color:#6b7280">No loyalty customers imported yet. Click "Import from Square" to get started.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <script id="sws-loyalty-data" type="application/json"><?php echo wp_json_encode( $imported ); ?></script>
        <?php
    }

    // ─── SMS CAMPAIGNS PAGE ─────────────────────────────────────────
    public function render_sms_campaigns() {
        $has_twilio = ! empty( get_option( 'sws_twilio_account_sid', '' ) )
                   && ! empty( get_option( 'sws_twilio_auth_token', '' ) )
                   && ! empty( get_option( 'sws_twilio_from_number', '' ) );
        $customers  = get_option( 'sws_loyalty_customers', [] );
        if ( ! is_array( $customers ) ) $customers = [];
        $campaigns  = get_option( 'sws_sms_campaigns', [] );
        if ( ! is_array( $campaigns ) ) $campaigns = [];
        $opt_outs   = get_option( 'sws_sms_opt_outs', [] );
        if ( ! is_array( $opt_outs ) ) $opt_outs = [];
        $opted_out_count = count( $opt_outs );
        ?>
        <div class="wrap sws-wrap">
            <h1>
                <span class="dashicons dashicons-megaphone"></span>
                Square Sync — SMS Campaigns
            </h1>

            <?php if ( ! $has_twilio ): ?>
                <div class="notice notice-warning" style="margin-bottom:20px">
                    <p>Twilio is not configured. <a href="<?php echo admin_url('admin.php?page=square-woo-sync-settings'); ?>">Go to Settings</a> to add your Twilio Account SID, Auth Token, and From Phone Number.</p>
                </div>
            <?php endif; ?>

            <?php if ( empty( $customers ) ): ?>
                <div class="notice notice-info" style="margin-bottom:20px">
                    <p>No loyalty customers imported. <a href="<?php echo admin_url('admin.php?page=square-woo-sync-loyalty'); ?>">Import loyalty customers</a> first to send SMS campaigns.</p>
                </div>
            <?php endif; ?>

            <div class="sws-card" style="margin-bottom:20px">
                <h2 style="margin:0 0 16px;border:none;padding:0">Create New Campaign</h2>

                <div style="margin-bottom:16px">
                    <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px" for="sws-sms-campaign-name">Campaign Name</label>
                    <input type="text" id="sws-sms-campaign-name" class="regular-text" placeholder="e.g. Summer Sale 2026" style="width:100%;max-width:400px">
                </div>

                <div style="margin-bottom:16px">
                    <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px" for="sws-sms-message">Message</label>
                    <textarea id="sws-sms-message" rows="4" class="large-text" placeholder="Hi {first_name}! Don't miss our summer sale — 20% off all items this weekend at our store. Reply STOP to opt out." style="width:100%;max-width:600px"></textarea>
                    <p class="description" style="margin-top:4px">
                        <strong>Available placeholders:</strong> <code>{first_name}</code>, <code>{last_name}</code>, <code>{full_name}</code>, <code>{points}</code><br>
                        <span id="sws-sms-char-count" style="color:#6b7280">0 / 160 characters</span>
                        <span id="sws-sms-segment-count" style="color:#6b7280;margin-left:8px">(1 segment)</span>
                    </p>
                    <label style="display:flex;align-items:center;gap:6px;margin-top:8px;font-size:13px;cursor:pointer">
                        <input type="checkbox" id="sws-sms-append-stop" checked>
                        Auto-append <strong>"Reply STOP to opt out"</strong> to each message
                    </label>
                    <?php if ( $opted_out_count > 0 ): ?>
                        <p style="margin-top:6px;font-size:12px;color:#d97706">
                            <span class="dashicons dashicons-warning" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom"></span>
                            <?php echo $opted_out_count; ?> number(s) opted out — they will be automatically skipped.
                        </p>
                    <?php endif; ?>
                </div>

                <div style="margin-bottom:16px">
                    <label style="display:block;font-weight:600;margin-bottom:4px;font-size:13px">Recipients</label>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-start">
                        <label style="font-size:13px;cursor:pointer">
                            <input type="radio" name="sws_sms_recipients" value="all" checked> All loyalty customers with phone numbers (<span id="sws-sms-total-count"><?php
                                echo count( array_filter( $customers, function( $c ) { return ! empty( $c['phone'] ); } ) );
                            ?></span>)
                        </label>
                        <label style="font-size:13px;cursor:pointer">
                            <input type="radio" name="sws_sms_recipients" value="min_points"> Customers with at least
                        </label>
                        <div id="sws-sms-min-points-wrap" style="display:inline-flex;align-items:center;gap:4px">
                            <input type="number" id="sws-sms-min-points" value="100" min="1" step="1" style="width:80px;height:28px;font-size:13px;padding:2px 6px">
                            <span style="font-size:13px;color:#374151">points</span>
                            <span id="sws-sms-points-count" style="font-size:12px;color:#6b7280;margin-left:4px"></span>
                        </div>
                        <label style="font-size:13px;cursor:pointer">
                            <input type="radio" name="sws_sms_recipients" value="selected"> Selected from loyalty list
                        </label>
                    </div>
                </div>

                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    <button id="sws-send-sms-btn" class="button button-primary" <?php echo ( ! $has_twilio || empty( $customers ) ) ? 'disabled' : ''; ?>>
                        <span class="dashicons dashicons-email-alt" style="margin-top:3px;margin-right:4px;font-size:16px;width:16px;height:16px"></span>
                        Send Campaign
                    </button>
                    <span style="color:#d1d5db">|</span>
                    <input type="text" id="sws-test-sms-phone" placeholder="+1234567890" style="width:150px;height:30px;font-size:13px;padding:2px 8px">
                    <button id="sws-test-sms-btn" class="button button-secondary" <?php echo ! $has_twilio ? 'disabled' : ''; ?>>
                        Send Test SMS
                    </button>
                    <span id="sws-sms-send-status" style="font-size:13px;color:#6b7280"></span>
                </div>

                <div id="sws-sms-progress" style="display:none;margin-top:16px">
                    <div class="sws-progress-bar"><div class="sws-progress-fill" id="sws-sms-progress-fill"></div><span class="sws-progress-pct" id="sws-sms-progress-pct">0%</span></div>
                    <p id="sws-sms-progress-text" class="sws-progress-text">Sending messages...</p>
                </div>

                <div id="sws-sms-result" style="display:none;margin-top:12px;padding:12px 16px;border-radius:6px;font-size:13px"></div>
            </div>

            <!-- Opt-Out Management -->
            <div class="sws-card" style="margin-bottom:20px">
                <h2 style="margin:0 0 4px;border:none;padding:0">Opt-Out List</h2>
                <p style="margin:0 0 16px;font-size:13px;color:#6b7280">
                    Phone numbers that replied STOP. These numbers are automatically excluded from all campaigns.
                </p>

                <div style="display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;margin-bottom:16px">
                    <div>
                        <input type="text" id="sws-optout-phone" placeholder="+1234567890" style="width:180px;height:30px;font-size:13px;padding:2px 8px">
                    </div>
                    <button id="sws-optout-add-btn" class="button button-secondary">Add to Opt-Out List</button>
                    <span style="color:#d1d5db;line-height:30px">|</span>
                    <button id="sws-optout-paste-btn" class="button button-secondary">Paste Multiple Numbers</button>
                </div>

                <div id="sws-optout-paste-area" style="display:none;margin-bottom:16px">
                    <textarea id="sws-optout-paste-input" rows="3" style="width:100%;max-width:400px;font-size:13px" placeholder="Paste phone numbers, one per line or comma-separated&#10;+12025551234&#10;+12025555678"></textarea>
                    <div style="margin-top:6px">
                        <button id="sws-optout-paste-save" class="button button-primary button-small">Add All</button>
                        <button id="sws-optout-paste-cancel" class="button button-small">Cancel</button>
                    </div>
                </div>

                <div id="sws-optout-status" style="display:none;margin-bottom:12px;padding:8px 14px;border-radius:6px;font-size:13px"></div>

                <?php if ( ! empty( $opt_outs ) ): ?>
                <div class="sws-card" style="padding:0;overflow:hidden;max-height:300px;overflow-y:auto">
                    <table class="sws-products-table" id="sws-optout-table">
                        <thead>
                            <tr>
                                <th>Phone Number</th>
                                <th style="width:160px">Date Added</th>
                                <th style="width:80px">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $opt_outs as $phone => $date ): ?>
                            <tr id="sws-optout-row-<?php echo esc_attr( md5( $phone ) ); ?>">
                                <td><code><?php echo esc_html( $phone ); ?></code></td>
                                <td style="font-size:12px;color:#6b7280"><?php echo esc_html( $date ); ?></td>
                                <td><button class="button button-small sws-optout-remove-btn" data-phone="<?php echo esc_attr( $phone ); ?>" style="color:#991b1b;font-size:11px">Remove</button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p style="margin:8px 0 0;font-size:12px;color:#6b7280"><?php echo $opted_out_count; ?> number(s) opted out</p>
                <?php else: ?>
                <p style="color:#6b7280;font-size:13px;margin:0">No opted-out numbers yet.</p>
                <?php endif; ?>
            </div>

            <?php
            // Split campaigns into active (sent within the last 24 hours) and past
            $active_campaigns = [];
            $past_campaigns   = [];
            $now              = time();
            foreach ( array_reverse( $campaigns ) as $c ) {
                $campaign_time = strtotime( $c['date'] ?? 'now' );
                $c['_status']  = 'completed';
                if ( $now - $campaign_time < 86400 ) {
                    // Sent within last 24h — consider "active"
                    $c['_status'] = 'active';
                    $active_campaigns[] = $c;
                } else {
                    $past_campaigns[] = $c;
                }
            }
            ?>

            <?php if ( ! empty( $active_campaigns ) ): ?>
            <div class="sws-card" style="margin-bottom:20px;border-left:4px solid #16a34a">
                <h2 style="margin:0 0 16px;border-bottom:1px solid #f0f0f0;padding-bottom:12px">
                    <span style="display:inline-block;width:10px;height:10px;background:#16a34a;border-radius:50%;margin-right:8px;animation:sws-pulse 2s infinite"></span>
                    Active Campaigns
                    <span style="font-size:13px;font-weight:400;color:#6b7280;margin-left:8px">(sent within the last 24 hours)</span>
                </h2>
                <table class="sws-products-table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th style="width:80px">Status</th>
                            <th style="width:80px">Sent</th>
                            <th style="width:80px">Delivered</th>
                            <th style="width:80px">Failed</th>
                            <th style="width:80px">Rate</th>
                            <th style="width:140px">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $active_campaigns as $c ):
                            $sent_n = intval( $c['sent'] ?? 0 );
                            $del_n  = intval( $c['delivered'] ?? 0 );
                            $fail_n = intval( $c['failed'] ?? 0 );
                            $rate   = $sent_n > 0 ? round( ( $del_n / $sent_n ) * 100 ) : 0;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $c['name'] ?? 'Untitled' ); ?></strong>
                                <br><span style="font-size:11px;color:#6b7280"><?php echo esc_html( mb_strimwidth( $c['message'] ?? '', 0, 80, '...' ) ); ?></span>
                            </td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;border:1px solid #bbf7d0;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;color:#166534;">
                                    <span style="width:6px;height:6px;background:#16a34a;border-radius:50%;display:inline-block"></span>
                                    Active
                                </span>
                            </td>
                            <td><?php echo $sent_n; ?></td>
                            <td style="color:#16a34a;font-weight:600"><?php echo $del_n; ?></td>
                            <td style="color:<?php echo $fail_n > 0 ? '#dc2626' : '#6b7280'; ?>;font-weight:600"><?php echo $fail_n; ?></td>
                            <td>
                                <span style="font-weight:600;color:<?php echo $rate >= 90 ? '#16a34a' : ( $rate >= 70 ? '#d97706' : '#dc2626' ); ?>"><?php echo $rate; ?>%</span>
                            </td>
                            <td style="font-size:12px;color:#6b7280"><?php echo esc_html( date( 'M j, Y g:i A', strtotime( $c['date'] ?? 'now' ) ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <style>@keyframes sws-pulse{0%,100%{opacity:1}50%{opacity:.4}}</style>
            <?php endif; ?>

            <?php if ( ! empty( $past_campaigns ) ): ?>
            <div class="sws-card">
                <h2 style="margin:0 0 16px;border-bottom:1px solid #f0f0f0;padding-bottom:12px">
                    Past Campaigns
                    <span style="font-size:13px;font-weight:400;color:#6b7280;margin-left:8px">(<?php echo count( $past_campaigns ); ?> total)</span>
                </h2>
                <table class="sws-products-table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th style="width:80px">Status</th>
                            <th style="width:80px">Sent</th>
                            <th style="width:80px">Delivered</th>
                            <th style="width:80px">Failed</th>
                            <th style="width:80px">Rate</th>
                            <th style="width:140px">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $past_campaigns as $c ):
                            $sent_n = intval( $c['sent'] ?? 0 );
                            $del_n  = intval( $c['delivered'] ?? 0 );
                            $fail_n = intval( $c['failed'] ?? 0 );
                            $rate   = $sent_n > 0 ? round( ( $del_n / $sent_n ) * 100 ) : 0;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html( $c['name'] ?? 'Untitled' ); ?></strong>
                                <br><span style="font-size:11px;color:#6b7280"><?php echo esc_html( mb_strimwidth( $c['message'] ?? '', 0, 80, '...' ) ); ?></span>
                            </td>
                            <td>
                                <?php if ( $fail_n > 0 && $fail_n >= $sent_n ): ?>
                                    <span style="display:inline-flex;align-items:center;gap:4px;background:#fef2f2;border:1px solid #fecaca;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;color:#991b1b;">Failed</span>
                                <?php elseif ( $fail_n > 0 ): ?>
                                    <span style="display:inline-flex;align-items:center;gap:4px;background:#fffbeb;border:1px solid #fde68a;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;color:#92400e;">Partial</span>
                                <?php else: ?>
                                    <span style="display:inline-flex;align-items:center;gap:4px;background:#f0f0f0;border:1px solid #d1d5db;padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600;color:#374151;">Completed</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo $sent_n; ?></td>
                            <td style="color:#16a34a;font-weight:600"><?php echo $del_n; ?></td>
                            <td style="color:<?php echo $fail_n > 0 ? '#dc2626' : '#6b7280'; ?>;font-weight:600"><?php echo $fail_n; ?></td>
                            <td>
                                <span style="font-weight:600;color:<?php echo $rate >= 90 ? '#16a34a' : ( $rate >= 70 ? '#d97706' : '#dc2626' ); ?>"><?php echo $rate; ?>%</span>
                            </td>
                            <td style="font-size:12px;color:#6b7280"><?php echo esc_html( date( 'M j, Y g:i A', strtotime( $c['date'] ?? 'now' ) ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ( empty( $campaigns ) ): ?>
            <div class="sws-card" style="text-align:center;padding:40px;color:#6b7280">
                <span class="dashicons dashicons-megaphone" style="font-size:48px;width:48px;height:48px;color:#d1d5db;margin-bottom:12px;display:block"></span>
                <p style="margin:0;font-size:14px">No campaigns sent yet. Create your first campaign above.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_category_mapping() {
        $saved_mappings = get_option( 'sws_category_mapping', [] );
        if ( ! is_array( $saved_mappings ) ) $saved_mappings = [];

        $woo_cats = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);
        if ( is_wp_error( $woo_cats ) ) $woo_cats = [];
        ?>
        <div class="wrap sws-wrap">
            <h1>Category Mapping</h1>
            <p style="color:#6b7280;margin-bottom:20px">
                Map your Square categories to WooCommerce categories so products sync into the correct category even when names differ.
                This prevents duplicate products and ensures accurate matching.
            </p>

            <div class="sws-card" style="margin-bottom:20px">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
                    <button id="sws-load-catmap" class="button button-primary" data-testid="button-load-catmap">Load Square Categories</button>
                    <span id="sws-catmap-status" style="color:#6b7280;font-size:13px"></span>
                </div>

                <div id="sws-catmap-table-wrap" style="display:none">
                    <table class="widefat striped" id="sws-catmap-table">
                        <thead>
                            <tr>
                                <th style="width:35%">Square Category</th>
                                <th style="width:10%;text-align:center">Products</th>
                                <th style="width:5%;text-align:center"></th>
                                <th style="width:40%">WooCommerce Category</th>
                                <th style="width:10%;text-align:center">Status</th>
                            </tr>
                        </thead>
                        <tbody id="sws-catmap-body">
                        </tbody>
                    </table>
                    <div style="margin-top:16px;display:flex;gap:12px;align-items:center">
                        <button id="sws-save-catmap" class="button button-primary" data-testid="button-save-catmap">Save Mappings</button>
                        <button id="sws-auto-match" class="button" data-testid="button-auto-match">Auto-Match by Name</button>
                        <span id="sws-catmap-save-status" style="color:#16a34a;font-size:13px;display:none"></span>
                    </div>
                </div>
            </div>

            <div class="sws-card" style="background:#f0f9ff;border-color:#bfdbfe">
                <h3 style="margin:0 0 8px;font-size:14px;color:#1e40af">How Category Mapping Works</h3>
                <ul style="margin:0;padding-left:20px;color:#1e3a5f;font-size:13px;line-height:1.8">
                    <li><strong>Matching:</strong> When syncing, the plugin checks your mappings first. If a Square product belongs to a mapped category, the plugin searches for WooCommerce products in that mapped category.</li>
                    <li><strong>Creating:</strong> When a new product is created from Square, it gets placed in the mapped WooCommerce category instead of creating a new one.</li>
                    <li><strong>Unmapped:</strong> Categories without a mapping will use name-based matching as before.</li>
                    <li><strong>Auto-Match:</strong> Click "Auto-Match by Name" to automatically map categories that have the same or very similar names.</li>
                </ul>
            </div>

            <script id="sws-woo-cats" type="application/json"><?php echo wp_json_encode( array_map( function( $t ) {
                $depth = 0;
                $parent = $t->parent;
                while ( $parent ) {
                    $depth++;
                    $p = get_term( $parent, 'product_cat' );
                    $parent = $p ? $p->parent : 0;
                }
                return [
                    'id'    => $t->term_id,
                    'name'  => $t->name,
                    'slug'  => $t->slug,
                    'depth' => $depth,
                    'count' => $t->count,
                ];
            }, $woo_cats ) ); ?></script>
            <script id="sws-saved-mappings" type="application/json"><?php echo wp_json_encode( $saved_mappings ); ?></script>
        </div>
        <?php
    }
}
