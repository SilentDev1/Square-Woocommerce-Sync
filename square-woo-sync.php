<?php
/**
 * Plugin Name: Square WooCommerce Sync Pro
 * Plugin URI:  https://cao-tech.com/square-woo-sync
 * Description: AI-powered synchronization between Square inventory and WooCommerce products. Automatically matches products by title, category, and variation, updates SKUs, stock levels, and creates new products with AI-generated descriptions.
 * Version:     1.10.2
 * Author:      Cao-Tech LLC
 * Author URI:  https://cao-tech.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: square-woo-sync
 * Requires at least: 5.8
 * Tested up to: 6.7
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 10.5.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'SWS_VERSION', '1.10.2' );
define( 'SWS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SWS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SWS_PLUGIN_FILE', __FILE__ );
define( 'SWS_PRODUCT_SLUG', 'square-woo-sync' );

// ----------------------------------------------------------------
// LICENSE — cao-tech.com License Manager Integration
// ----------------------------------------------------------------
function sws_license_api_url() {
    return 'https://cao-tech.com';
}

function sws_is_pro() {
    if ( get_option( 'sws_license_status' ) !== 'pro' ) {
        return false;
    }
    $expires = get_option( 'sws_license_expires', '' );
    if ( $expires && strtotime( $expires ) < time() ) {
        return false;
    }
    return true;
}

function sws_license_is_expired() {
    $expires = get_option( 'sws_license_expires', '' );
    return $expires && strtotime( $expires ) < time();
}

function sws_check_key( $key ) {
    $key = strtoupper( trim( $key ) );
    if ( empty( $key ) ) return false;
    $response = wp_remote_post( sws_license_api_url() . '/wp-json/sre-license/v1/activate', array(
        'timeout' => 15,
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( array(
            'license_key' => $key,
            'site_url'    => home_url(),
            'product'     => SWS_PRODUCT_SLUG,
        ) ),
    ) );
    if ( is_wp_error( $response ) ) return false;
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! empty( $body['success'] ) ) {
        update_option( 'sws_license_status', 'pro' );
        update_option( 'sws_license_key', $key );
        update_option( 'sws_license_plan', sanitize_text_field( $body['plan'] ?? 'single' ) );
        update_option( 'sws_license_max_sites', intval( $body['max_sites'] ?? 1 ) );
        if ( ! empty( $body['expires_at'] ) ) {
            update_option( 'sws_license_expires', sanitize_text_field( $body['expires_at'] ) );
        }
        return true;
    }
    update_option( 'sws_license_status', 'free' );
    if ( ! empty( $body['status_message'] ) ) {
        update_option( 'sws_license_status_message', sanitize_text_field( $body['status_message'] ) );
    } else {
        delete_option( 'sws_license_status_message' );
    }
    return false;
}

function sws_validate_license() {
    $key = get_option( 'sws_license_key', '' );
    if ( empty( $key ) ) {
        update_option( 'sws_license_status', 'free' );
        delete_option( 'sws_license_status_message' );
        return;
    }
    $response = wp_remote_post( sws_license_api_url() . '/wp-json/sre-license/v1/validate', array(
        'timeout' => 15,
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( array(
            'license_key' => $key,
            'site_url'    => home_url(),
            'product'     => SWS_PRODUCT_SLUG,
        ) ),
    ) );
    if ( is_wp_error( $response ) ) return;
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! empty( $body['valid'] ) ) {
        update_option( 'sws_license_status', 'pro' );
        delete_option( 'sws_license_status_message' );
        delete_option( 'sws_license_reason' );
    } else {
        update_option( 'sws_license_status', 'free' );
        $reason = ! empty( $body['reason'] ) ? sanitize_text_field( $body['reason'] ) : '';
        if ( $reason ) {
            update_option( 'sws_license_reason', $reason );
        } else {
            delete_option( 'sws_license_reason' );
        }
        if ( ! empty( $body['status_message'] ) ) {
            update_option( 'sws_license_status_message', sanitize_text_field( $body['status_message'] ) );
        } else {
            delete_option( 'sws_license_status_message' );
        }
    }
}

function sws_deactivate_license() {
    $key = get_option( 'sws_license_key', '' );
    if ( empty( $key ) ) return;
    wp_remote_post( sws_license_api_url() . '/wp-json/sre-license/v1/deactivate', array(
        'timeout' => 15,
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( array(
            'license_key' => $key,
            'site_url'    => home_url(),
            'product'     => SWS_PRODUCT_SLUG,
        ) ),
    ) );
    update_option( 'sws_license_status', 'free' );
}

// ----------------------------------------------------------------
// UPDATE CHECKER — cao-tech.com SRE License Manager
// ----------------------------------------------------------------
function sws_update_checker_init() {
    add_filter( 'pre_set_site_transient_update_plugins', 'sws_check_for_plugin_update' );
    add_filter( 'plugins_api', 'sws_plugin_update_info', 10, 3 );
    add_action( 'upgrader_process_complete', 'sws_clear_update_cache', 10, 0 );
}
add_action( 'init', 'sws_update_checker_init' );

function sws_fetch_update_data() {
    $cached = get_transient( 'sws_plugin_update_check' );
    if ( is_array( $cached ) ) {
        return $cached;
    }
    $url = add_query_arg( array(
        'slug'        => SWS_PRODUCT_SLUG,
        'version'     => SWS_VERSION,
        'license_key' => get_option( 'sws_license_key', '' ),
        'site_url'    => home_url(),
    ), sws_license_api_url() . '/wp-json/sre-license/v1/update-check' );

    $response = wp_remote_get( $url, array( 'timeout' => 15, 'sslverify' => true ) );
    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return null;
    }
    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $body ) || ! isset( $body['slug'] ) ) {
        return null;
    }
    set_transient( 'sws_plugin_update_check', $body, 12 * HOUR_IN_SECONDS );
    return $body;
}

function sws_check_for_plugin_update( $transient ) {
    if ( empty( $transient->checked ) ) {
        return $transient;
    }
    $remote = sws_fetch_update_data();
    if ( ! $remote || empty( $remote['update'] ) ) {
        return $transient;
    }
    $plugin_basename = plugin_basename( SWS_PLUGIN_FILE );
    $transient->response[ $plugin_basename ] = (object) array(
        'slug'         => SWS_PRODUCT_SLUG,
        'plugin'       => $plugin_basename,
        'new_version'  => $remote['new_version'],
        'url'          => $remote['url'] ?? 'https://cao-tech.com',
        'package'      => $remote['package'] ?? '',
        'tested'       => $remote['tested'] ?? '',
        'requires'     => $remote['requires'] ?? '',
        'requires_php' => $remote['requires_php'] ?? '',
    );
    return $transient;
}

function sws_plugin_update_info( $result, $action, $args ) {
    if ( $action !== 'plugin_information' || ! isset( $args->slug ) || $args->slug !== SWS_PRODUCT_SLUG ) {
        return $result;
    }
    $remote = sws_fetch_update_data();
    if ( ! $remote ) {
        return $result;
    }
    return (object) array(
        'name'          => $remote['name'] ?? 'Square WooCommerce Sync Pro',
        'slug'          => SWS_PRODUCT_SLUG,
        'version'       => $remote['new_version'],
        'author'        => '<a href="https://cao-tech.com">Cao-Tech LLC</a>',
        'homepage'      => $remote['url'] ?? 'https://cao-tech.com',
        'requires'      => $remote['requires'] ?? '',
        'requires_php'  => $remote['requires_php'] ?? '',
        'tested'        => $remote['tested'] ?? '',
        'download_link' => $remote['package'] ?? '',
        'sections'      => array(
            'changelog'   => $remote['changelog'] ?? '<p>See <a href="https://cao-tech.com">cao-tech.com</a> for details.</p>',
            'description' => '<p>AI-powered synchronization between Square inventory and WooCommerce products.</p>',
        ),
    );
}

function sws_clear_update_cache() {
    delete_transient( 'sws_plugin_update_check' );
}

// ----------------------------------------------------------------
// AI API Key Encryption
// ----------------------------------------------------------------
function sws_cipher_key() {
    if ( defined( 'SWS_CIPHER_KEY' ) ) {
        return SWS_CIPHER_KEY;
    }
    return substr( hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ) ), 0, 32 );
}

function sws_encrypt_key( $plain ) {
    if ( empty( $plain ) ) {
        return '';
    }
    if ( ! function_exists( 'openssl_encrypt' ) ) {
        return base64_encode( $plain );
    }
    $iv  = openssl_random_pseudo_bytes( 16 );
    $enc = openssl_encrypt( $plain, 'AES-256-CBC', sws_cipher_key(), 0, $iv );
    return base64_encode( $iv . $enc );
}

function sws_decrypt_key( $stored ) {
    if ( empty( $stored ) ) {
        return '';
    }
    if ( ! function_exists( 'openssl_decrypt' ) ) {
        return base64_decode( $stored );
    }
    $raw = base64_decode( $stored );
    if ( strlen( $raw ) < 16 ) {
        return $stored;
    }
    $iv  = substr( $raw, 0, 16 );
    $enc = substr( $raw, 16 );
    $dec = openssl_decrypt( $enc, 'AES-256-CBC', sws_cipher_key(), 0, $iv );
    return $dec !== false ? $dec : $stored;
}

// Autoload classes
spl_autoload_register( function( $class ) {
    $prefix = 'SWS_';
    if ( strpos( $class, $prefix ) !== 0 ) return;
    $file = SWS_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', substr( $class, strlen( $prefix ) ) ) ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
});

class Square_Woo_Sync {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
        register_activation_hook( SWS_PLUGIN_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( SWS_PLUGIN_FILE, [ $this, 'deactivate' ] );
    }

    public function init() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error"><p><strong>Square WooCommerce Sync Pro</strong> requires WooCommerce to be installed and active.</p></div>';
            });
            return;
        }

        require_once SWS_PLUGIN_DIR . 'includes/class-square-api.php';
        require_once SWS_PLUGIN_DIR . 'includes/class-ai-matcher.php';
        require_once SWS_PLUGIN_DIR . 'includes/class-product-matcher.php';
        require_once SWS_PLUGIN_DIR . 'includes/class-sync-engine.php';
        require_once SWS_PLUGIN_DIR . 'includes/class-sync-logger.php';
        require_once SWS_PLUGIN_DIR . 'admin/class-admin-page.php';

        $stored_version = get_option( 'sws_plugin_version', '0' );
        if ( version_compare( $stored_version, SWS_VERSION, '<' ) ) {
            $this->activate();
            update_option( 'sws_plugin_version', SWS_VERSION );
        }

        new SWS_Admin_Page();

        add_action( 'wp_dashboard_setup', 'sws_register_dashboard_widget' );

        // Register AJAX handlers
        add_action( 'wp_ajax_sws_run_sync',          [ $this, 'ajax_run_sync' ] );
        add_action( 'wp_ajax_sws_batch_start',     [ $this, 'ajax_batch_start' ] );
        add_action( 'wp_ajax_sws_batch_process',   [ $this, 'ajax_batch_process' ] );
        add_action( 'wp_ajax_sws_sync_status',      [ $this, 'ajax_sync_status' ] );
        add_action( 'wp_ajax_sws_cancel_sync',      [ $this, 'ajax_cancel_sync' ] );
        add_action( 'wp_ajax_sws_get_categories',    [ $this, 'ajax_get_categories' ] );
        add_action( 'wp_ajax_sws_save_catmap',       [ $this, 'ajax_save_catmap' ] );
        add_action( 'wp_ajax_sws_test_connections',  [ $this, 'ajax_test_connections' ] );
        add_action( 'wp_ajax_sws_get_log',           [ $this, 'ajax_get_log' ] );
        add_action( 'wp_ajax_sws_clear_log',         [ $this, 'ajax_clear_log' ] );
        add_action( 'wp_ajax_sws_get_debug_log',     [ $this, 'ajax_get_debug_log' ] );
        add_action( 'wp_ajax_sws_get_products',      [ $this, 'ajax_get_products' ] );
        add_action( 'wp_ajax_sws_get_sync_history',  [ $this, 'ajax_get_sync_history' ] );
        add_action( 'wp_ajax_sws_ai_verify_product', [ $this, 'ajax_ai_verify_product' ] );
        add_action( 'wp_ajax_sws_check_inventory',   [ $this, 'ajax_check_inventory' ] );
        add_action( 'wp_ajax_sws_single_product_sync',  [ $this, 'ajax_single_product_sync' ] );
        add_action( 'wp_ajax_sws_search_woo_products',  [ $this, 'ajax_search_woo_products' ] );
        add_action( 'wp_ajax_sws_relink_product',        [ $this, 'ajax_relink_product' ] );

        // Loyalty & SMS AJAX handlers
        add_action( 'wp_ajax_sws_import_loyalty_customers', [ $this, 'ajax_import_loyalty_customers' ] );
        add_action( 'wp_ajax_sws_get_loyalty_customers',    [ $this, 'ajax_get_loyalty_customers' ] );
        add_action( 'wp_ajax_sws_send_sms_campaign',        [ $this, 'ajax_send_sms_campaign' ] );
        add_action( 'wp_ajax_sws_send_test_sms',            [ $this, 'ajax_send_test_sms' ] );
        add_action( 'wp_ajax_sws_add_opt_out',              [ $this, 'ajax_add_opt_out' ] );
        add_action( 'wp_ajax_sws_remove_opt_out',           [ $this, 'ajax_remove_opt_out' ] );
        add_action( 'wp_ajax_sws_cleanup_copy_products',     [ $this, 'ajax_cleanup_copy_products' ] );
        add_action( 'wp_ajax_sws_cleanup_option_attrs',      [ $this, 'ajax_cleanup_option_attrs' ] );
        add_action( 'wp_ajax_sws_delete_any_variations',     [ $this, 'ajax_delete_any_variations' ] );

        // Scheduled sync (batch mode via cron chaining)
        add_action( 'sws_scheduled_sync',       [ $this, 'run_scheduled_sync' ] );
        add_action( 'sws_cron_batch_process',    [ $this, 'cron_batch_process' ] );

        // Validate license daily
        add_action( 'sws_daily_license_check', 'sws_validate_license' );
        if ( ! wp_next_scheduled( 'sws_daily_license_check' ) ) {
            wp_schedule_event( time(), 'daily', 'sws_daily_license_check' );
        }
    }

    public function activate() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$wpdb->prefix}sws_sync_log (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sync_time datetime DEFAULT CURRENT_TIMESTAMP,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext,
            PRIMARY KEY  (id),
            KEY level (level),
            KEY sync_time (sync_time)
        ) $charset_collate;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}sws_products (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            square_id varchar(100) NOT NULL,
            square_name varchar(500) NOT NULL DEFAULT '',
            square_categories text,
            square_image_url text,
            woo_product_id bigint(20) DEFAULT NULL,
            woo_product_name varchar(500) DEFAULT '',
            sync_status varchar(30) NOT NULL DEFAULT 'pending',
            match_method varchar(50) DEFAULT '',
            match_confidence decimal(5,4) DEFAULT 0,
            ai_reasoning text,
            ai_verified tinyint(1) DEFAULT 0,
            ai_integrity_score decimal(5,4) DEFAULT 0,
            ai_integrity_notes text,
            variations_json longtext,
            changes_json longtext,
            last_synced_at datetime DEFAULT NULL,
            first_seen_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY square_id (square_id),
            KEY sync_status (sync_status),
            KEY woo_product_id (woo_product_id),
            KEY last_synced_at (last_synced_at)
        ) $charset_collate;" );

        dbDelta( "CREATE TABLE {$wpdb->prefix}sws_sync_history (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sync_date datetime DEFAULT CURRENT_TIMESTAMP,
            total_square int DEFAULT 0,
            matched int DEFAULT 0,
            updated int DEFAULT 0,
            created int DEFAULT 0,
            skipped int DEFAULT 0,
            errors int DEFAULT 0,
            sku_added int DEFAULT 0,
            ai_checks int DEFAULT 0,
            ai_issues int DEFAULT 0,
            elapsed_seconds decimal(10,2) DEFAULT 0,
            is_dry_run tinyint(1) DEFAULT 0,
            PRIMARY KEY  (id),
            KEY sync_date (sync_date)
        ) $charset_collate;" );

        if ( ! wp_next_scheduled( 'sws_scheduled_sync' ) ) {
            $interval = get_option( 'sws_sync_interval', 'daily' );
            if ( $interval !== 'disabled' ) {
                // Honour the preferred sync-time setting (same logic used in save_settings()).
                $sync_hour = get_option( 'sws_sync_time', '' );
                if ( $sync_hour !== '' && in_array( $interval, [ 'daily', 'twicedaily' ] ) ) {
                    $tz   = wp_timezone();
                    $now  = new \DateTime( 'now', $tz );
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
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook( 'sws_scheduled_sync' );
        wp_clear_scheduled_hook( 'sws_cron_batch_process' );
        wp_clear_scheduled_hook( 'sws_daily_license_check' );
        delete_option( 'sws_cron_sync_active' );
    }

    public function ajax_run_sync() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $logger = new SWS_Sync_Logger();
        $logger->info( '--- AJAX sync request received ---' );
        $logger->info( 'PHP version: ' . PHP_VERSION . ' | Memory limit: ' . ini_get( 'memory_limit' ) . ' | Max execution: ' . ini_get( 'max_execution_time' ) . 's' );
        $logger->info( 'WordPress: ' . get_bloginfo( 'version' ) . ' | WooCommerce: ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : 'N/A' ) );

        ignore_user_abort( true );
        set_time_limit( 300 );

        update_option( 'sws_sync_running', true );
        update_option( 'sws_sync_started', current_time( 'mysql' ) );
        delete_option( 'sws_sync_error' );

        register_shutdown_function( function() use ( $logger ) {
            $error = error_get_last();
            if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ] ) ) {
                delete_option( 'sws_sync_running' );
                $msg = sprintf( 'PHP Fatal Error: %s in %s on line %d', $error['message'], basename( $error['file'] ), $error['line'] );
                $logger->error( 'SHUTDOWN HANDLER: ' . $msg );
                update_option( 'sws_sync_error', $msg );
                update_option( 'sws_last_sync_result', [ 'success' => false, 'error' => $msg ] );

                if ( ! headers_sent() ) {
                    header( 'Content-Type: application/json; charset=utf-8' );
                    echo wp_json_encode([
                        'success' => false,
                        'data'    => [
                            'message' => $msg,
                            'details' => 'A PHP fatal error occurred during sync. Check wp-content/uploads/sws-logs/sync-debug.log for the full trace.',
                        ],
                    ]);
                }
            }
        });

        try {
            $logger->info( 'Creating SWS_Sync_Engine...' );
            $engine = new SWS_Sync_Engine();
            $logger->info( 'Starting run_full_sync...' );
            $result = $engine->run_full_sync();
            $logger->info( 'run_full_sync completed.', $result );

            delete_option( 'sws_sync_running' );
            update_option( 'sws_last_sync_result', $result );

            if ( ! empty( $result['success'] ) ) {
                $logger->info( '--- Sync finished successfully ---' );
                wp_send_json_success( $result );
            } else {
                $error_msg = $result['error'] ?? 'Unknown sync error';
                $logger->error( 'Sync returned failure: ' . $error_msg );
                update_option( 'sws_sync_error', $error_msg );
                wp_send_json_error([
                    'message' => $error_msg,
                    'details' => $this->get_error_details( $error_msg ),
                ]);
            }
        } catch ( \Throwable $e ) {
            delete_option( 'sws_sync_running' );
            $error_msg = $e->getMessage() . ' (in ' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
            $logger->error( 'EXCEPTION in ajax_run_sync: ' . $error_msg );
            $logger->error( 'Stack trace: ' . $e->getTraceAsString() );
            update_option( 'sws_sync_error', $error_msg );
            update_option( 'sws_last_sync_result', [ 'success' => false, 'error' => $error_msg ] );

            wp_send_json_error([
                'message' => $error_msg,
                'details' => $this->get_error_details( $error_msg ),
            ]);
        }
    }

    public function ajax_get_categories() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        try {
            $square = new SWS_Square_Api();
            $products = $square->get_normalized_products();
            if ( is_wp_error( $products ) ) {
                wp_send_json_error([ 'message' => $products->get_error_message() ]);
                return;
            }

            $cat_counts = [];
            $uncategorized = 0;
            foreach ( $products as $p ) {
                if ( empty( $p['categories'] ) ) {
                    $uncategorized++;
                } else {
                    foreach ( $p['categories'] as $cat ) {
                        $cat_counts[ $cat ] = ( $cat_counts[ $cat ] ?? 0 ) + 1;
                    }
                }
            }
            ksort( $cat_counts );
            if ( $uncategorized > 0 ) {
                $cat_counts['Uncategorized'] = $uncategorized;
            }

            wp_send_json_success([
                'categories'    => $cat_counts,
                'total_products' => count( $products ),
            ]);
        } catch ( \Throwable $e ) {
            wp_send_json_error([ 'message' => $e->getMessage() ]);
        }
    }

    public function ajax_save_catmap() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $raw = isset( $_POST['mappings'] ) ? $_POST['mappings'] : '{}';
        $mappings = json_decode( stripslashes( $raw ), true );
        if ( ! is_array( $mappings ) ) {
            wp_send_json_error([ 'message' => 'Invalid mapping data.' ]);
            return;
        }

        $clean   = [];
        $invalid = 0;
        foreach ( $mappings as $sq_cat => $wc_term_id ) {
            $sq_cat     = sanitize_text_field( $sq_cat );
            $wc_term_id = intval( $wc_term_id );
            if ( $sq_cat === '' || $wc_term_id <= 0 ) continue;
            $term = get_term( $wc_term_id, 'product_cat' );
            if ( ! $term || is_wp_error( $term ) ) {
                $invalid++;
                continue;
            }
            $clean[ $sq_cat ] = $wc_term_id;
        }

        update_option( 'sws_category_mapping', $clean );
        wp_send_json_success([ 'saved' => count( $clean ), 'skipped' => $invalid ]);
    }

    public function ajax_batch_start() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $logger = new SWS_Sync_Logger();
        $category_filter = isset( $_POST['category'] ) ? sanitize_text_field( $_POST['category'] ) : '';

        if ( isset( $_POST['skip_out_of_stock'] ) ) {
            update_option( 'sws_skip_out_of_stock', sanitize_text_field( $_POST['skip_out_of_stock'] ) === '1' ? '1' : '0' );
        }

        $running = get_option( 'sws_sync_running', false );
        if ( $running ) {
            wp_send_json_error([ 'message' => 'A sync is already running.' ]);
            return;
        }

        update_option( 'sws_sync_running', true );
        update_option( 'sws_sync_started', current_time( 'mysql' ) );
        delete_option( 'sws_sync_error' );
        delete_option( 'sws_last_sync_result' );

        try {
            $logger->info( '--- AJAX batch_start request received' . ( $category_filter ? ' [Category: ' . $category_filter . ']' : '' ) . ' ---' );
            $engine = new SWS_Sync_Engine();
            $result = $engine->batch_start( $category_filter );

            if ( ! empty( $result['success'] ) ) {
                wp_send_json_success( $result );
            } else {
                $this->cleanup_batch_state();
                $error_msg = $result['error'] ?? 'Failed to fetch catalog';
                update_option( 'sws_sync_error', $error_msg );
                wp_send_json_error([ 'message' => $error_msg, 'details' => $this->get_error_details( $error_msg ) ]);
            }
        } catch ( \Throwable $e ) {
            $this->cleanup_batch_state();
            $error_msg = $e->getMessage();
            $logger->error( 'batch_start exception: ' . $error_msg );
            update_option( 'sws_sync_error', $error_msg );
            wp_send_json_error([ 'message' => $error_msg, 'details' => $this->get_error_details( $error_msg ) ]);
        }
    }

    public function ajax_batch_process() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $logger = new SWS_Sync_Logger();

        if ( ! get_option( 'sws_sync_running', false ) ) {
            wp_send_json_error([ 'message' => 'No sync is currently running. Start a new sync first.' ]);
            return;
        }

        try {
            $engine = new SWS_Sync_Engine();
            $result = $engine->batch_process( 5 );

            if ( ! empty( $result['success'] ) ) {
                if ( ! empty( $result['done'] ) ) {
                    delete_option( 'sws_sync_running' );
                    update_option( 'sws_last_sync_result', $result );
                    $logger->info( '--- Batch sync finished successfully ---' );
                }
                wp_send_json_success( $result );
            } else {
                $this->cleanup_batch_state();
                $error_msg = $result['error'] ?? 'Batch processing failed';
                update_option( 'sws_sync_error', $error_msg );
                wp_send_json_error([ 'message' => $error_msg, 'details' => $this->get_error_details( $error_msg ) ]);
            }
        } catch ( \Throwable $e ) {
            $this->cleanup_batch_state();
            $error_msg = $e->getMessage();
            $logger->error( 'batch_process exception: ' . $error_msg );
            update_option( 'sws_sync_error', $error_msg );
            wp_send_json_error([ 'message' => $error_msg, 'details' => $this->get_error_details( $error_msg ) ]);
        }
    }

    private function cleanup_batch_state() {
        delete_option( 'sws_sync_running' );
        delete_option( 'sws_batch_total' );
        delete_option( 'sws_batch_offset' );
        delete_option( 'sws_batch_stats' );
        delete_option( 'sws_batch_start_time' );
        $upload_dir = wp_upload_dir();
        $cache_file = $upload_dir['basedir'] . '/sws-logs/batch-catalog.json';
        if ( file_exists( $cache_file ) ) @unlink( $cache_file );
    }

    private function get_error_details( $error_msg ) {
        $lower = strtolower( $error_msg );

        if ( strpos( $lower, 'unauthorized' ) !== false || strpos( $lower, '401' ) !== false || strpos( $lower, 'invalid token' ) !== false ) {
            return 'Your Square access token is invalid or expired. Go to Settings and enter a valid access token from your Square Developer Dashboard.';
        }
        if ( strpos( $lower, 'not found' ) !== false || strpos( $lower, '404' ) !== false || strpos( $lower, 'location' ) !== false ) {
            return 'Square location not found. Double-check your Location ID in Settings. You can find it in your Square Dashboard under Locations.';
        }
        if ( strpos( $lower, 'sync timed out' ) !== false ) {
            return 'The sync process was running too long and was automatically stopped. The PHP process likely crashed or was killed by the server. Click the "Debug Log" button on the dashboard to see what happened, or check wp-content/uploads/sws-logs/sync-debug.log on your server.';
        }
        if ( strpos( $lower, 'timeout' ) !== false || strpos( $lower, 'timed out' ) !== false ) {
            return 'The connection to Square timed out. This usually means Square\'s servers are slow or your server has connectivity issues. Try again in a few minutes.';
        }
        if ( strpos( $lower, 'curl' ) !== false || strpos( $lower, 'connection' ) !== false || strpos( $lower, 'resolve' ) !== false ) {
            return 'Could not connect to Square\'s API. Check that your server has outbound internet access and that Square\'s API is not down.';
        }
        if ( strpos( $lower, 'api key' ) !== false || strpos( $lower, 'api_key' ) !== false ) {
            return 'AI API key issue. Go to Settings > AI Configuration and verify your API key is correct and has billing enabled.';
        }
        if ( strpos( $lower, 'rate limit' ) !== false || strpos( $lower, '429' ) !== false ) {
            return 'Too many requests. Square or your AI provider is rate-limiting you. Wait a few minutes and try again.';
        }
        if ( strpos( $lower, 'forbidden' ) !== false || strpos( $lower, '403' ) !== false ) {
            return 'Access denied by Square. Your access token may not have the required permissions (ITEMS_READ, INVENTORY_READ). Check your Square app permissions.';
        }

        return 'Check the Sync Log page for detailed error information. If the problem persists, verify your Square and AI settings are correct.';
    }

    public function ajax_sync_status() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $running = get_option( 'sws_sync_running', false );
        $started = get_option( 'sws_sync_started', '' );
        $result  = get_option( 'sws_last_sync_result', null );
        $error   = get_option( 'sws_sync_error', '' );

        if ( $running && $started ) {
            $elapsed = time() - strtotime( $started );
            if ( $elapsed > 600 ) {
                delete_option( 'sws_sync_running' );
                $running = false;
                if ( ! $error ) {
                    $error = 'Sync timed out after ' . round( $elapsed / 60 ) . ' minutes. The PHP process likely crashed or was killed by the server. Check wp-content/debug.log for details.';
                    update_option( 'sws_sync_error', $error );
                }
            }
        }

        $started_12h = $started ? date( 'M j, Y g:i A', strtotime( $started ) ) : '';

        $progress = $running ? get_option( 'sws_sync_progress', null ) : null;

        wp_send_json_success([
            'running' => (bool) $running,
            'started' => $started_12h,
            'result'  => $running ? null : $result,
            'error'   => $error,
            'error_details' => $error ? $this->get_error_details( $error ) : '',
            'progress' => $progress,
        ]);
    }

    public function ajax_cancel_sync() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $logger = new SWS_Sync_Logger();
        $logger->info( 'Sync manually cancelled by admin.' );
        $this->cleanup_batch_state();
        delete_option( 'sws_sync_progress' );
        update_option( 'sws_sync_error', 'Sync was manually cancelled.' );
        wp_send_json_success([ 'cancelled' => true ]);
    }

    public function ajax_get_debug_log() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $logger = new SWS_Sync_Logger();
        wp_send_json_success([
            'log'  => $logger->get_debug_log( 200 ),
            'path' => str_replace( ABSPATH, '', $logger->get_log_file_path() ),
        ]);
    }

    public function ajax_test_connections() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $square = new SWS_Square_Api();
        $ai     = new SWS_Ai_Matcher();

        wp_send_json_success([
            'square' => $square->test_connection(),
            'ai'     => $ai->test_connection(),
        ]);
    }

    public function ajax_get_log() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $logger = new SWS_Sync_Logger();
        wp_send_json_success( $logger->get_recent_logs( 200 ) );
    }

    public function ajax_clear_log() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $logger = new SWS_Sync_Logger();
        $logger->clear_logs();
        wp_send_json_success();
    }

    public function ajax_get_products() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        global $wpdb;
        $table = $wpdb->prefix . 'sws_products';

        $status   = sanitize_text_field( $_POST['status'] ?? '' );
        $search   = sanitize_text_field( $_POST['search'] ?? '' );
        $category = sanitize_text_field( $_POST['category'] ?? '' );
        $page     = max( 1, intval( $_POST['page'] ?? 1 ) );
        $per      = 25;
        $offset   = ( $page - 1 ) * $per;

        $where = '1=1';
        $params = [];

        if ( $status && $status !== 'all' ) {
            $where .= ' AND sync_status = %s';
            $params[] = $status;
        }
        if ( $category ) {
            $cat_like = '%' . $wpdb->esc_like( $category ) . '%';
            $where .= ' AND square_categories LIKE %s';
            $params[] = $cat_like;
        }
        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= ' AND (square_name LIKE %s OR woo_product_name LIKE %s OR square_id LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $total = (int) $wpdb->get_var(
            $params ? $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where", ...$params ) : "SELECT COUNT(*) FROM $table WHERE $where"
        );

        $query = "SELECT * FROM $table WHERE $where ORDER BY square_categories ASC, square_name ASC LIMIT %d OFFSET %d";
        $params[] = $per;
        $params[] = $offset;

        $products = $wpdb->get_results( $wpdb->prepare( $query, ...$params ), ARRAY_A );

        $status_counts = $wpdb->get_results( "SELECT sync_status, COUNT(*) as cnt FROM $table GROUP BY sync_status", OBJECT_K );
        $counts = [];
        foreach ( $status_counts as $s => $row ) {
            $counts[ $s ] = (int) $row->cnt;
        }

        $cat_counts_raw = $wpdb->get_results( "SELECT square_categories, COUNT(*) as cnt FROM $table WHERE square_categories IS NOT NULL AND square_categories != '' GROUP BY square_categories ORDER BY square_categories ASC", ARRAY_A );
        $categories = [];
        foreach ( $cat_counts_raw as $row ) {
            $cats = array_map( 'trim', explode( ',', $row['square_categories'] ) );
            foreach ( $cats as $c ) {
                if ( $c === '' ) continue;
                $categories[ $c ] = ( $categories[ $c ] ?? 0 ) + (int) $row['cnt'];
            }
        }
        ksort( $categories );

        wp_send_json_success([
            'products'   => $products,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $per,
            'pages'      => ceil( $total / $per ),
            'counts'     => $counts,
            'categories' => $categories,
        ]);
    }

    public function ajax_get_sync_history() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        global $wpdb;
        $history = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}sws_sync_history ORDER BY sync_date DESC LIMIT 30",
            ARRAY_A
        );

        wp_send_json_success( $history );
    }

    public function ajax_ai_verify_product() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        try {
            $product_id = intval( $_POST['product_id'] ?? 0 );
            if ( ! $product_id ) wp_send_json_error( 'Invalid product ID.' );

            global $wpdb;
            $table = $wpdb->prefix . 'sws_products';
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $product_id ) );
            if ( ! $row ) wp_send_json_error( 'Product not found in tracking table. Run a sync first.' );
            if ( ! $row->woo_product_id ) wp_send_json_error( 'No WooCommerce product linked to this Square product.' );

            $wc_product = wc_get_product( $row->woo_product_id );
            if ( ! $wc_product ) wp_send_json_error( 'WooCommerce product #' . $row->woo_product_id . ' not found. It may have been deleted.' );

            $ai = new SWS_Ai_Matcher();

            $prompt = <<<PROMPT
You are a product data integrity auditor. Verify that the following Square product is correctly synced to the WooCommerce product.

SQUARE PRODUCT:
Name: {$row->square_name}
Categories: {$row->square_categories}
Variations: {$row->variations_json}

WOOCOMMERCE PRODUCT:
ID: {$wc_product->get_id()}
Name: {$wc_product->get_name()}
SKU: {$wc_product->get_sku()}
Price: {$wc_product->get_regular_price()}
Stock: {$wc_product->get_stock_quantity()}
Type: {$wc_product->get_type()}

Check:
1. Are names referring to the same product?
2. Are prices reasonable / matching?
3. Are stock levels synced?
4. Are SKUs consistent?
5. Any data mismatches or errors?

Respond ONLY with valid JSON:
{"integrity_score": <float 0.0-1.0>, "issues": [<string list of issues found, empty if none>], "summary": "<one sentence overall assessment>"}
PROMPT;

            $result = $ai->complete( $prompt, 500 );
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( 'AI verification failed: ' . $result->get_error_message() );
            }

            preg_match( '/\{.*\}/s', $result, $matches );
            $parsed = json_decode( $matches[0] ?? '', true );

            if ( ! $parsed ) {
                wp_send_json_error( 'AI returned unparseable response.' );
            }

            $score   = (float) ( $parsed['integrity_score'] ?? 0 );
            $issues  = $parsed['issues'] ?? [];
            $summary = $parsed['summary'] ?? '';

            $wpdb->update( $table, [
                'ai_verified'        => 1,
                'ai_integrity_score' => $score,
                'ai_integrity_notes' => wp_json_encode( [ 'issues' => $issues, 'summary' => $summary ] ),
            ], [ 'id' => $product_id ] );

            wp_send_json_success([
                'score'   => $score,
                'issues'  => $issues,
                'summary' => $summary,
            ]);
        } catch ( \Throwable $e ) {
            wp_send_json_error( 'AI verification error: ' . $e->getMessage() . ' (in ' . basename( $e->getFile() ) . ':' . $e->getLine() . ')' );
        }
    }

    public function ajax_check_inventory() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        try {
            $product_id = intval( $_POST['product_id'] ?? 0 );
            if ( ! $product_id ) wp_send_json_error( 'Invalid product ID.' );

            global $wpdb;
            $table = $wpdb->prefix . 'sws_products';
            $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $product_id ) );
            if ( ! $row || empty( $row->square_id ) ) wp_send_json_error( 'Product not found or no Square ID.' );

            $square = new SWS_Square_Api();

            $cache_file = wp_upload_dir()['basedir'] . '/sws-logs/batch-catalog.json';
            $sq_data = null;
            if ( file_exists( $cache_file ) ) {
                $catalog = json_decode( file_get_contents( $cache_file ), true );
                if ( is_array( $catalog ) ) {
                    foreach ( $catalog as $item ) {
                        if ( ( $item['square_id'] ?? '' ) === $row->square_id ) {
                            $sq_data = $item;
                            break;
                        }
                    }
                }
            }

            $variation_ids = [];
            $variations_info = [];
            if ( $sq_data && ! empty( $sq_data['variations'] ) ) {
                foreach ( $sq_data['variations'] as $v ) {
                    $variation_ids[] = $v['square_variation_id'];
                    $variations_info[ $v['square_variation_id'] ] = [
                        'name'       => $v['name'],
                        'sku'        => $v['sku'],
                        'cached_qty' => $v['quantity'],
                    ];
                }
            }

            $live_counts = [];
            if ( ! empty( $variation_ids ) ) {
                $live_counts = $square->get_inventory_counts( $variation_ids );
            }

            $result = [];
            foreach ( $variations_info as $vid => $info ) {
                $info['live_square_qty'] = $live_counts[ $vid ] ?? 'NOT_RETURNED';
                $result[] = array_merge( [ 'square_variation_id' => $vid ], $info );
            }

            $wc_data = [];
            if ( $row->woo_product_id ) {
                $wc_product = wc_get_product( $row->woo_product_id );
                if ( $wc_product && $wc_product->is_type( 'variable' ) ) {
                    foreach ( $wc_product->get_children() as $child_id ) {
                        $child = wc_get_product( $child_id );
                        if ( $child ) {
                            $wc_data[] = [
                                'wc_variation_id' => $child_id,
                                'name'            => implode( ' / ', $child->get_variation_attributes() ),
                                'sku'             => $child->get_sku(),
                                'stock_qty'       => $child->get_stock_quantity(),
                                'manage_stock'    => $child->get_manage_stock(),
                                'sq_var_id_meta'  => $child->get_meta( '_square_variation_id' ),
                            ];
                        }
                    }
                } elseif ( $wc_product ) {
                    $wc_data[] = [
                        'wc_product_id' => $wc_product->get_id(),
                        'name'          => $wc_product->get_name(),
                        'sku'           => $wc_product->get_sku(),
                        'stock_qty'     => $wc_product->get_stock_quantity(),
                        'manage_stock'  => $wc_product->get_manage_stock(),
                    ];
                }
            }

            wp_send_json_success([
                'square_product'    => $row->square_name,
                'square_id'         => $row->square_id,
                'location_id'       => get_option( 'sws_square_location_id', '(not set)' ),
                'square_variations' => $result,
                'wc_variations'     => $wc_data,
            ]);
        } catch ( \Throwable $e ) {
            wp_send_json_error( 'Error: ' . $e->getMessage() );
        }
    }

    public function ajax_single_product_sync() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $square_id = sanitize_text_field( $_POST['square_id'] ?? '' );
        if ( empty( $square_id ) ) {
            wp_send_json_error([ 'message' => 'Square product ID is required.' ]);
            return;
        }

        $logger = new SWS_Sync_Logger();
        $logger->info( sprintf( '--- Single product sync request: %s ---', $square_id ) );

        try {
            $engine = new SWS_Sync_Engine();
            $result = $engine->sync_single_product( $square_id );

            if ( ! empty( $result['success'] ) ) {
                $logger->info( sprintf( '--- Single sync complete: "%s" ---', $result['product'] ?? $square_id ) );
                wp_send_json_success( $result );
            } else {
                $error_msg = $result['error'] ?? 'Single sync failed';
                $logger->error( 'Single sync failed: ' . $error_msg );
                wp_send_json_error([
                    'message' => $error_msg,
                    'details' => $this->get_error_details( $error_msg ),
                ]);
            }
        } catch ( \Throwable $e ) {
            $error_msg = $e->getMessage() . ' (in ' . basename( $e->getFile() ) . ':' . $e->getLine() . ')';
            $logger->error( 'Single sync exception: ' . $error_msg );
            wp_send_json_error([
                'message' => $error_msg,
                'details' => $this->get_error_details( $error_msg ),
            ]);
        }
    }

    /**
     * Search WooCommerce products by name or ID for the relink UI.
     */
    public function ajax_search_woo_products() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $term = sanitize_text_field( $_POST['term'] ?? '' );
        if ( strlen( $term ) < 2 ) {
            wp_send_json_success( [] );
            return;
        }

        $results = [];

        // Direct product ID lookup
        if ( is_numeric( $term ) ) {
            $product = wc_get_product( (int) $term );
            if ( $product ) {
                $results[] = [
                    'id'   => $product->get_id(),
                    'name' => $product->get_name(),
                    'sku'  => $product->get_sku(),
                    'type' => $product->get_type(),
                ];
            }
        }

        // Name search
        $query = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => 20,
            's'              => $term,
            'fields'         => 'ids',
        ]);
        foreach ( $query->posts as $post_id ) {
            // Skip if already added via numeric lookup
            $already = false;
            foreach ( $results as $r ) {
                if ( $r['id'] == $post_id ) { $already = true; break; }
            }
            if ( $already ) continue;

            $product = wc_get_product( $post_id );
            if ( $product ) {
                $results[] = [
                    'id'   => $product->get_id(),
                    'name' => $product->get_name(),
                    'sku'  => $product->get_sku(),
                    'type' => $product->get_type(),
                ];
            }
        }

        wp_send_json_success( array_slice( $results, 0, 20 ) );
    }

    /**
     * Relink a Square product tracking record to a different (or no) WooCommerce product.
     */
    public function ajax_relink_product() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $tracking_id = intval( $_POST['tracking_id'] ?? 0 );
        $new_woo_id  = intval( $_POST['woo_product_id'] ?? 0 );

        if ( ! $tracking_id ) {
            wp_send_json_error( 'Invalid tracking ID.' );
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'sws_products';
        $row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $tracking_id ) );
        if ( ! $row ) {
            wp_send_json_error( 'Tracking record not found.' );
            return;
        }

        // Remove Square meta from the previously linked WC product
        if ( ! empty( $row->woo_product_id ) && $row->woo_product_id != $new_woo_id ) {
            $old_product = wc_get_product( (int) $row->woo_product_id );
            if ( $old_product ) {
                $old_product->delete_meta_data( '_square_product_id' );
                $old_product->save();
            }
        }

        if ( $new_woo_id > 0 ) {
            $new_product = wc_get_product( $new_woo_id );
            if ( ! $new_product ) {
                wp_send_json_error( 'WooCommerce product #' . $new_woo_id . ' not found.' );
                return;
            }

            // Set Square meta on the newly linked WC product
            $new_product->update_meta_data( '_square_product_id', $row->square_id );
            $new_product->save();

            $wpdb->update( $table, [
                'woo_product_id'   => $new_woo_id,
                'woo_product_name' => $new_product->get_name(),
                'sync_status'      => 'pending',
                'match_method'     => 'manual',
                'match_confidence' => 1.0,
                'ai_reasoning'     => 'Manually relinked by admin',
            ], [ 'id' => $tracking_id ] );

            wp_send_json_success([
                'woo_product_id'   => $new_woo_id,
                'woo_product_name' => $new_product->get_name(),
                'message'          => 'Linked to "' . esc_html( $new_product->get_name() ) . '" (#' . $new_woo_id . ')',
            ]);
        } else {
            // Clear link entirely
            $wpdb->update( $table, [
                'woo_product_id'   => null,
                'woo_product_name' => '',
                'sync_status'      => 'unmatched',
                'match_method'     => '',
                'match_confidence' => 0,
                'ai_reasoning'     => 'Manually unlinked by admin',
            ], [ 'id' => $tracking_id ] );

            wp_send_json_success([
                'woo_product_id'   => 0,
                'woo_product_name' => '',
                'message'          => 'Link cleared — product marked as unmatched.',
            ]);
        }
    }

    // ─── LOYALTY CUSTOMERS AJAX ────────────────────────────────────

    public function ajax_import_loyalty_customers() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        // Prevent PHP notices from corrupting JSON output.
        ob_start();

        try {
            @set_time_limit( 300 ); // Allow up to 5 minutes for large imports

            $api       = new SWS_Square_Api();
            $customers = $api->get_loyalty_customers();

            // Discard any stray output from PHP notices/warnings
            ob_end_clean();

            if ( is_wp_error( $customers ) ) {
                wp_send_json_error( $customers->get_error_message() );
                return;
            }

            // Store all for the loyalty list
            update_option( 'sws_loyalty_customers', $customers, false );
            update_option( 'sws_loyalty_last_import', current_time( 'mysql' ) );

            $with_phone = count( array_filter( $customers, function( $c ) { return ! empty( $c['phone'] ); } ) );

            wp_send_json_success([
                'customers'  => $customers,
                'total'      => count( $customers ),
                'with_phone' => $with_phone,
                'message'    => sprintf( 'Imported %d loyalty customers (%d with phone numbers).', count( $customers ), $with_phone ),
            ]);
        } catch ( \Throwable $e ) {
            ob_end_clean();
            wp_send_json_error( 'Import error: ' . $e->getMessage() );
        }
    }

    public function ajax_get_loyalty_customers() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $customers = get_option( 'sws_loyalty_customers', [] );
        if ( ! is_array( $customers ) ) $customers = [];

        wp_send_json_success([ 'customers' => $customers ]);
    }

    // ─── OPT-OUT MANAGEMENT ─────────────────────────────────────────

    private function normalize_phone( $phone ) {
        $phone = preg_replace( '/[^+0-9]/', '', $phone );
        if ( ! preg_match( '/^\+/', $phone ) ) {
            $phone = '+1' . $phone;
        }
        return $phone;
    }

    public function ajax_add_opt_out() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $phones_raw = sanitize_textarea_field( $_POST['phones'] ?? '' );
        if ( empty( $phones_raw ) ) {
            wp_send_json_error( 'No phone number(s) provided.' );
            return;
        }

        // Split by newlines, commas, or spaces
        $phones = preg_split( '/[\n,\s]+/', $phones_raw, -1, PREG_SPLIT_NO_EMPTY );

        $opt_outs = get_option( 'sws_sms_opt_outs', [] );
        if ( ! is_array( $opt_outs ) ) $opt_outs = [];

        $added = 0;
        $date  = current_time( 'M j, Y g:i A' );
        foreach ( $phones as $p ) {
            $normalized = $this->normalize_phone( $p );
            if ( strlen( $normalized ) >= 10 && ! isset( $opt_outs[ $normalized ] ) ) {
                $opt_outs[ $normalized ] = $date;
                $added++;
            }
        }

        update_option( 'sws_sms_opt_outs', $opt_outs, false );

        wp_send_json_success([
            'added'   => $added,
            'total'   => count( $opt_outs ),
            'message' => $added > 0
                ? sprintf( '%d number(s) added to opt-out list.', $added )
                : 'Number(s) already on the opt-out list.',
        ]);
    }

    public function ajax_remove_opt_out() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $phone = sanitize_text_field( $_POST['phone'] ?? '' );
        if ( empty( $phone ) ) {
            wp_send_json_error( 'No phone number provided.' );
            return;
        }

        $opt_outs = get_option( 'sws_sms_opt_outs', [] );
        if ( ! is_array( $opt_outs ) ) $opt_outs = [];

        $normalized = $this->normalize_phone( $phone );
        unset( $opt_outs[ $normalized ] );
        unset( $opt_outs[ $phone ] ); // Also try the raw value

        update_option( 'sws_sms_opt_outs', $opt_outs, false );

        wp_send_json_success([
            'total'   => count( $opt_outs ),
            'message' => 'Number removed from opt-out list.',
        ]);
    }

    // ─── SMS CAMPAIGN AJAX ──────────────────────────────────────────

    public function ajax_send_test_sms() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        $to      = sanitize_text_field( $_POST['to'] ?? '' );
        $message = sanitize_textarea_field( $_POST['message'] ?? '' );

        if ( empty( $to ) || empty( $message ) ) {
            wp_send_json_error( 'Phone number and message are required.' );
            return;
        }

        $result = $this->send_twilio_sms( $to, $message );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
            return;
        }

        wp_send_json_success([ 'message' => 'Test SMS sent successfully to ' . $to ]);
    }

    public function ajax_send_sms_campaign() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        @set_time_limit( 600 ); // Allow up to 10 minutes for large campaigns

        $campaign_name    = sanitize_text_field( $_POST['campaign_name'] ?? 'Untitled Campaign' );
        $message_template = sanitize_textarea_field( $_POST['message'] ?? '' );
        $recipient_mode   = sanitize_text_field( $_POST['recipient_mode'] ?? 'all' );
        $selected_ids     = isset( $_POST['selected_ids'] ) ? array_map( 'sanitize_text_field', (array) $_POST['selected_ids'] ) : [];
        $min_points       = intval( $_POST['min_points'] ?? 0 );
        $append_stop      = ( $_POST['append_stop'] ?? '1' ) === '1';

        if ( empty( $message_template ) ) {
            wp_send_json_error( 'Message is required.' );
            return;
        }

        // Auto-append opt-out text if enabled
        if ( $append_stop && stripos( $message_template, 'STOP' ) === false ) {
            $message_template .= "\nReply STOP to opt out.";
        }

        $customers = get_option( 'sws_loyalty_customers', [] );
        if ( ! is_array( $customers ) ) $customers = [];

        // Load opt-out list
        $opt_outs = get_option( 'sws_sms_opt_outs', [] );
        if ( ! is_array( $opt_outs ) ) $opt_outs = [];

        // Filter recipients
        $recipients = [];
        $skipped_optout = 0;
        foreach ( $customers as $c ) {
            if ( empty( $c['phone'] ) ) continue;
            if ( $recipient_mode === 'selected' && ! in_array( $c['customer_id'], $selected_ids ) ) continue;
            if ( $recipient_mode === 'min_points' && ( intval( $c['balance'] ?? 0 ) < $min_points ) ) continue;

            // Skip opted-out numbers
            $normalized_phone = $this->normalize_phone( $c['phone'] );
            if ( isset( $opt_outs[ $normalized_phone ] ) || isset( $opt_outs[ $c['phone'] ] ) ) {
                $skipped_optout++;
                continue;
            }

            $recipients[] = $c;
        }

        if ( empty( $recipients ) ) {
            wp_send_json_error( 'No recipients with phone numbers found.' );
            return;
        }

        $sent      = 0;
        $delivered  = 0;
        $failed     = 0;
        $errors     = [];

        foreach ( $recipients as $r ) {
            // Replace placeholders
            $msg = str_replace(
                [ '{first_name}', '{last_name}', '{full_name}', '{points}' ],
                [ $r['given_name'] ?? '', $r['family_name'] ?? '', $r['name'] ?? '', $r['balance'] ?? 0 ],
                $message_template
            );

            $result = $this->send_twilio_sms( $r['phone'], $msg );
            $sent++;

            if ( is_wp_error( $result ) ) {
                $failed++;
                $errors[] = $r['phone'] . ': ' . $result->get_error_message();
            } else {
                $delivered++;
            }

            // Delay between messages to avoid carrier filtering (30007).
            // Carriers flag rapid-fire messages as spam. 1 second per
            // message is the safe minimum for unregistered 10DLC numbers.
            usleep( 1000000 ); // 1 second between each message
        }

        // Save campaign history
        $campaigns = get_option( 'sws_sms_campaigns', [] );
        if ( ! is_array( $campaigns ) ) $campaigns = [];
        $campaigns[] = [
            'name'      => $campaign_name,
            'message'   => $message_template,
            'sent'      => $sent,
            'delivered'  => $delivered,
            'failed'    => $failed,
            'date'      => current_time( 'mysql' ),
        ];
        // Keep last 50 campaigns
        if ( count( $campaigns ) > 50 ) {
            $campaigns = array_slice( $campaigns, -50 );
        }
        update_option( 'sws_sms_campaigns', $campaigns, false );

        wp_send_json_success([
            'sent'      => $sent,
            'delivered'  => $delivered,
            'failed'    => $failed,
            'errors'    => array_slice( $errors, 0, 10 ),
            'skipped_optout' => $skipped_optout,
            'message'   => sprintf(
                'Campaign "%s" complete: %d sent, %d delivered, %d failed.%s',
                $campaign_name, $sent, $delivered, $failed,
                $skipped_optout > 0 ? sprintf( ' (%d opted-out numbers skipped)', $skipped_optout ) : ''
            ),
        ]);
    }

    /**
     * Send a single SMS via Twilio REST API.
     */
    private function send_twilio_sms( $to, $body ) {
        $account_sid           = get_option( 'sws_twilio_account_sid', '' );
        $auth_token            = get_option( 'sws_twilio_auth_token', '' );
        $from_number           = get_option( 'sws_twilio_from_number', '' );
        $messaging_service_sid = get_option( 'sws_twilio_messaging_service_sid', '' );

        if ( empty( $account_sid ) || empty( $auth_token ) ) {
            return new WP_Error( 'twilio_config', 'Twilio is not fully configured. Set Account SID and Auth Token in Settings.' );
        }

        // Must have either a Messaging Service SID or a From Number
        if ( empty( $messaging_service_sid ) && empty( $from_number ) ) {
            return new WP_Error( 'twilio_config', 'Twilio requires either a Messaging Service SID or a From Phone Number in Settings.' );
        }

        // Decrypt the auth token
        $auth_token = sws_decrypt_key( $auth_token );

        // Normalize phone number
        $to = preg_replace( '/[^+0-9]/', '', $to );
        if ( ! preg_match( '/^\+/', $to ) ) {
            $to = '+1' . $to; // Default to US
        }

        $url = 'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $account_sid ) . '/Messages.json';

        // Build the message body — prefer Messaging Service SID over From Number
        $msg_body = [
            'To'   => $to,
            'Body' => $body,
        ];

        if ( ! empty( $messaging_service_sid ) ) {
            $msg_body['MessagingServiceSid'] = $messaging_service_sid;
        } else {
            $msg_body['From'] = $from_number;
        }

        $response = wp_remote_post( $url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode( $account_sid . ':' . $auth_token ),
            ],
            'body' => $msg_body,
        ]);

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $msg = $data['message'] ?? ( $data['error_message'] ?? 'Twilio API error (HTTP ' . $code . ')' );
            return new WP_Error( 'twilio_error', $msg );
        }

        return $data;
    }

    public function run_scheduled_sync() {
        $logger = new SWS_Sync_Logger();

        $sync_days = get_option( 'sws_sync_days', 'mon,tue,wed,thu,fri,sat,sun' );
        if ( $sync_days ) {
            $active_days = array_map( 'trim', explode( ',', strtolower( $sync_days ) ) );
            $today = strtolower( current_time( 'D' ) );
            if ( ! in_array( $today, $active_days ) ) {
                $logger->info( 'Scheduled sync skipped: today (' . $today . ') is not an active sync day.' );
                return;
            }
        }

        if ( get_option( 'sws_batch_offset' ) !== false && get_option( 'sws_batch_total' ) !== false ) {
            // Guard against a stale lock left by an interrupted sync (e.g. WP-Cron never
            // finished all batch iterations because the site had no traffic).  If the batch
            // was started more than 4 hours ago, treat it as stale and clear it so this
            // scheduled run can proceed normally.
            $batch_start = (float) get_option( 'sws_batch_start_time', 0 );
            $age_seconds = microtime( true ) - $batch_start;
            if ( $batch_start > 0 && $age_seconds > ( 4 * HOUR_IN_SECONDS ) ) {
                $logger->info( sprintf(
                    'Scheduled sync: clearing stale batch lock (started %.1f hours ago).',
                    $age_seconds / HOUR_IN_SECONDS
                ) );
                $this->cleanup_batch_state();
                delete_option( 'sws_cron_sync_active' );
            } else {
                $logger->info( 'Scheduled sync skipped: a batch sync is already in progress.' );
                return;
            }
        }

        $sched_cats = get_option( 'sws_sync_categories', [] );
        if ( ! is_array( $sched_cats ) ) {
            $sched_cats = array_filter( array_map( 'trim', explode( ',', (string) $sched_cats ) ) );
        }

        if ( ! empty( $sched_cats ) ) {
            $logger->info( 'Scheduled sync starting with category filter: ' . implode( ', ', $sched_cats ) );
            $cat_filter = $sched_cats;
        } else {
            $logger->info( 'Scheduled sync starting with ALL categories (no filter).' );
            $cat_filter = '';
        }

        $engine = new SWS_Sync_Engine();
        $result = $engine->batch_start( $cat_filter );

        if ( ! empty( $result['success'] ) && $result['total'] > 0 ) {
            update_option( 'sws_cron_sync_active', '1' );
            wp_schedule_single_event( time() + 10, 'sws_cron_batch_process' );
            $logger->info( 'Batch started: ' . $result['total'] . ' products queued for processing.' );
        } else {
            $logger->info( 'Scheduled sync: no products to process.' . ( ! empty( $result['error'] ) ? ' Error: ' . $result['error'] : '' ) );
        }
    }

    public function cron_batch_process() {
        if ( get_option( 'sws_cron_sync_active' ) !== '1' ) {
            return;
        }

        $logger = new SWS_Sync_Logger();
        $engine = new SWS_Sync_Engine();
        $result = $engine->batch_process( 5 );

        if ( ! empty( $result['done'] ) ) {
            $logger->info( 'Cron batch sync completed successfully.' );
            delete_option( 'sws_cron_sync_active' );
            return;
        }

        if ( ! empty( $result['error'] ) ) {
            $logger->error( 'Cron batch sync error: ' . $result['error'] );
            delete_option( 'sws_cron_sync_active' );
            $this->cleanup_batch_state();
            return;
        }

        if ( empty( $result['success'] ) ) {
            $logger->error( 'Cron batch sync returned unexpected result.' );
            delete_option( 'sws_cron_sync_active' );
            $this->cleanup_batch_state();
            return;
        }

        $logger->info( sprintf( 'Cron batch progress: %d / %d processed.', $result['offset'] ?? 0, $result['total'] ?? 0 ) );
        wp_schedule_single_event( time() + 10, 'sws_cron_batch_process' );
    }
    /**
     * AJAX: Bulk-delete "(Copy)" WooCommerce products that share a Square Product ID
     * with an original product. Removes the Square meta, trashes the copy, and updates
     * the sws_products tracking table.
     */
    public function ajax_cleanup_copy_products() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        global $wpdb;

        // Find all published/draft products whose title contains "(Copy)" AND have _square_product_id meta
        $copy_products = $wpdb->get_results(
            "SELECT p.ID, p.post_title, pm.meta_value AS square_id
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_square_product_id'
             WHERE p.post_type = 'product'
             AND p.post_status IN ('publish','draft','pending','private')
             AND p.post_title LIKE '%%(Copy)%%'
             AND pm.meta_value != ''",
            ARRAY_A
        );

        if ( empty( $copy_products ) ) {
            wp_send_json_success([
                'cleaned' => 0,
                'message' => 'No duplicate "(Copy)" products found.',
            ]);
            return;
        }

        $cleaned = 0;
        $details = [];
        $products_table = $wpdb->prefix . 'sws_products';
        $table_exists   = (bool) $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $products_table ) );

        foreach ( $copy_products as $copy ) {
            $copy_id   = (int) $copy['ID'];
            $square_id = $copy['square_id'];

            // Check that an original (non-copy) product exists for this Square ID
            $original = $wpdb->get_row( $wpdb->prepare(
                "SELECT p.ID, p.post_title
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_square_product_id'
                 WHERE p.post_type = 'product'
                 AND p.post_status IN ('publish','draft','pending','private')
                 AND pm.meta_value = %s
                 AND p.ID != %d
                 AND p.post_title NOT LIKE '%%(Copy)%%'
                 LIMIT 1",
                $square_id, $copy_id
            ), ARRAY_A );

            // Remove _square_product_id from the copy regardless
            delete_post_meta( $copy_id, '_square_product_id' );

            // Also remove any _square_variation_id from child variations
            $child_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
                $copy_id
            ) );
            foreach ( $child_ids as $child_id ) {
                delete_post_meta( (int) $child_id, '_square_variation_id' );
            }

            // Trash the copy product (WooCommerce also trashes child variations)
            wp_trash_post( $copy_id );

            // Update sws_products tracking records that pointed to the copy
            if ( $table_exists && $original ) {
                $wpdb->update(
                    $products_table,
                    [ 'woo_product_id' => (int) $original['ID'] ],
                    [ 'woo_product_id' => $copy_id ],
                    [ '%d' ],
                    [ '%d' ]
                );
            }

            $details[] = sprintf(
                'Trashed WC#%d "%s" (Square ID: %s)%s',
                $copy_id,
                $copy['post_title'],
                $square_id,
                $original ? sprintf( ' → original WC#%d "%s"', (int) $original['ID'], $original['post_title'] ) : ''
            );
            $cleaned++;
        }

        wp_send_json_success([
            'cleaned' => $cleaned,
            'details' => $details,
            'message' => sprintf( 'Cleaned up %d duplicate "(Copy)" product(s).', $cleaned ),
        ]);
    }

    /**
     * AJAX: Clean up products that have BOTH a generic "Option" attribute AND a real
     * attribute (e.g. "Flavors", "Flavor").  Merges Option values into the real
     * attribute, removes Option, and rewrites child variation meta keys.
     */
    public function ajax_cleanup_option_attrs() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        global $wpdb;

        $generic_slugs = [ 'option', 'options' ];
        $fixed   = 0;
        $details = [];

        // ── Phase 1: Products that STILL have "option" in _product_attributes ──
        $phase1_ids = $wpdb->get_col(
            "SELECT DISTINCT p.ID
             FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_product_attributes'
             WHERE p.post_type = 'product'
             AND p.post_status IN ('publish','draft','pending','private')
             AND pm.meta_value LIKE '%\"option\"%'"
        );

        foreach ( ( $phase1_ids ?: [] ) as $pid ) {
            $pid = (int) $pid;
            $raw_attrs = get_post_meta( $pid, '_product_attributes', true );
            if ( ! is_array( $raw_attrs ) ) continue;

            $has_generic  = false;
            $has_real     = false;
            $real_slug    = '';
            $generic_slug = '';
            foreach ( $raw_attrs as $slug => $attr_data ) {
                if ( in_array( $slug, $generic_slugs, true ) ) {
                    $has_generic  = true;
                    $generic_slug = $slug;
                } else {
                    $has_real  = true;
                    if ( $real_slug === '' ) $real_slug = $slug;
                }
            }

            if ( ! $has_generic || ! $has_real || $real_slug === '' || $generic_slug === '' ) continue;

            // Merge generic attribute values into the real attribute.
            $real_vals    = array_filter( array_map( 'trim', explode( ' | ', $raw_attrs[ $real_slug ]['value'] ?? '' ) ) );
            $generic_vals = array_filter( array_map( 'trim', explode( ' | ', $raw_attrs[ $generic_slug ]['value'] ?? '' ) ) );
            $merged       = array_values( array_unique( array_merge( $real_vals, $generic_vals ) ) );

            $raw_attrs[ $real_slug ]['value']        = implode( ' | ', $merged );
            $raw_attrs[ $real_slug ]['is_variation']  = 1;
            unset( $raw_attrs[ $generic_slug ] );
            update_post_meta( $pid, '_product_attributes', $raw_attrs );

            // Rewrite child variation meta keys.
            $child_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation'",
                $pid
            ) );
            $rewritten = 0;
            foreach ( $child_ids as $child_id ) {
                $child_id = (int) $child_id;
                $old_val = get_post_meta( $child_id, 'attribute_' . $generic_slug, true );
                if ( $old_val !== '' && $old_val !== false ) {
                    update_post_meta( $child_id, 'attribute_' . $real_slug, $old_val );
                    delete_post_meta( $child_id, 'attribute_' . $generic_slug );
                    $rewritten++;
                }
            }
            wp_cache_delete( $pid, 'post_meta' );
            wc_delete_product_transients( $pid );

            $title = get_the_title( $pid );
            $details[] = sprintf(
                'WC#%d "%s": merged "%s" into "%s" (%d values, %d variations rewritten)',
                $pid, $title, $generic_slug, $real_slug, count( $merged ), $rewritten
            );
            $fixed++;
        }

        // ── Phase 2: Products where "option" was already removed from the parent ──
        // but variations STILL have orphaned attribute_option meta.
        // Find variations that have attribute_option meta.
        $orphaned = $wpdb->get_results(
            "SELECT v.ID AS var_id, v.post_parent AS product_id, vm.meta_value AS option_val
             FROM {$wpdb->posts} v
             JOIN {$wpdb->postmeta} vm ON vm.post_id = v.ID AND vm.meta_key = 'attribute_option'
             WHERE v.post_type = 'product_variation'
             AND v.post_status IN ('publish','draft','pending','private','inherit')
             AND vm.meta_value != ''",
            ARRAY_A
        );

        // Group by parent product.
        $orphan_groups = [];
        foreach ( ( $orphaned ?: [] ) as $row ) {
            $orphan_groups[ (int) $row['product_id'] ][] = $row;
        }

        foreach ( $orphan_groups as $pid => $rows ) {
            // Skip if this product was already handled in Phase 1.
            if ( in_array( $pid, array_map( 'intval', $phase1_ids ?: [] ), true ) ) continue;

            $raw_attrs = get_post_meta( $pid, '_product_attributes', true );
            if ( ! is_array( $raw_attrs ) ) continue;

            // Find the real (non-generic) attribute slug on the parent.
            $real_slug = '';
            foreach ( $raw_attrs as $slug => $attr_data ) {
                if ( ! in_array( $slug, $generic_slugs, true ) ) {
                    $real_slug = $slug;
                    break;
                }
            }
            if ( $real_slug === '' ) continue;

            // Rewrite each orphaned variation's attribute_option → attribute_{real_slug}.
            $rewritten = 0;
            $new_values = [];
            foreach ( $rows as $row ) {
                $var_id = (int) $row['var_id'];
                $val    = $row['option_val'];
                update_post_meta( $var_id, 'attribute_' . $real_slug, $val );
                delete_post_meta( $var_id, 'attribute_option' );
                wp_cache_delete( $var_id, 'post_meta' );
                $new_values[] = $val;
                $rewritten++;
            }

            // Also add these values to the parent attribute.
            if ( isset( $raw_attrs[ $real_slug ] ) && ! empty( $new_values ) ) {
                $existing_vals = array_filter( array_map( 'trim', explode( ' | ', $raw_attrs[ $real_slug ]['value'] ?? '' ) ) );
                $merged        = array_values( array_unique( array_merge( $existing_vals, $new_values ) ) );
                if ( count( $merged ) !== count( $existing_vals ) ) {
                    $raw_attrs[ $real_slug ]['value']        = implode( ' | ', $merged );
                    $raw_attrs[ $real_slug ]['is_variation']  = 1;
                    update_post_meta( $pid, '_product_attributes', $raw_attrs );
                }
            }
            wp_cache_delete( $pid, 'post_meta' );
            wc_delete_product_transients( $pid );

            $title = get_the_title( $pid );
            $details[] = sprintf(
                'WC#%d "%s": rewrote %d orphaned variation(s) attribute_option → attribute_%s',
                $pid, $title, $rewritten, $real_slug
            );
            $fixed++;
        }

        if ( $fixed === 0 ) {
            wp_send_json_success([
                'fixed'   => 0,
                'message' => 'No products with redundant "Option" attributes found.',
            ]);
            return;
        }

        wp_send_json_success([
            'fixed'   => $fixed,
            'details' => $details,
            'message' => sprintf( 'Fixed %d product(s) with redundant "Option" attribute.', $fixed ),
        ]);
    }

    /**
     * AJAX: Delete all product variations that have "Any" (empty) attribute values.
     * These are broken variations where attribute_flavor / attribute_option is blank,
     * causing WooCommerce to display "Any Flavor..." or "Any Option..." in the dropdown.
     */
    public function ajax_delete_any_variations() {
        check_ajax_referer( 'sws_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'Unauthorized' );

        global $wpdb;

        // Find all product_variation posts whose parent is a variable product.
        $variations = $wpdb->get_results(
            "SELECT v.ID AS var_id, v.post_parent AS product_id, v.post_title
             FROM {$wpdb->posts} v
             WHERE v.post_type = 'product_variation'
             AND v.post_status IN ('publish','draft','pending','private','inherit')",
            ARRAY_A
        );

        if ( empty( $variations ) ) {
            wp_send_json_success([
                'deleted' => 0,
                'message' => 'No variations found.',
            ]);
            return;
        }

        $deleted = 0;
        $details = [];
        $affected_parents = [];

        foreach ( $variations as $row ) {
            $var_id     = (int) $row['var_id'];
            $product_id = (int) $row['product_id'];

            // Get this variation's attribute_* meta.
            $var_meta = get_post_meta( $var_id );
            $has_any_attr   = false;
            $all_attrs_empty = true;

            foreach ( $var_meta as $meta_key => $meta_val ) {
                if ( strpos( $meta_key, 'attribute_' ) === 0 ) {
                    $has_any_attr = true;
                    $value = is_array( $meta_val ) ? $meta_val[0] : $meta_val;
                    if ( $value !== '' && $value !== null && $value !== false ) {
                        $all_attrs_empty = false;
                        break;
                    }
                }
            }

            // Delete if: has attribute meta but ALL values are empty (= "Any"),
            // or has NO attribute meta at all (orphaned variation).
            if ( ! $has_any_attr || $all_attrs_empty ) {
                $parent_title = get_the_title( $product_id );
                wp_delete_post( $var_id, true ); // Force delete, bypass trash.
                $deleted++;
                $affected_parents[ $product_id ] = $parent_title;

                $details[] = sprintf(
                    'Deleted variation #%d from "%s" (WC#%d)',
                    $var_id, $parent_title, $product_id
                );
            }
        }

        // Refresh transients for affected parent products.
        foreach ( $affected_parents as $pid => $title ) {
            wp_cache_delete( $pid, 'post_meta' );
            wc_delete_product_transients( $pid );
            $product = wc_get_product( $pid );
            if ( $product && $product->is_type( 'variable' ) ) {
                $product->get_data_store()->sync_price( $product );
            }
        }

        wp_send_json_success([
            'deleted' => $deleted,
            'parents' => count( $affected_parents ),
            'details' => $details,
            'message' => sprintf(
                'Deleted %d "Any" variation(s) across %d product(s).',
                $deleted, count( $affected_parents )
            ),
        ]);
    }
}

Square_Woo_Sync::instance();

function sws_register_dashboard_widget() {
    wp_add_dashboard_widget(
        'sws_dashboard_widget',
        '<span class="dashicons dashicons-update" style="margin-right:6px;color:#006AFF;"></span> Square WooCommerce Sync',
        'sws_render_dashboard_widget'
    );
}

function sws_render_dashboard_widget() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        echo '<p style="color:#dc2626;font-weight:600;">WooCommerce is required for Square WooCommerce Sync Pro.</p>';
        return;
    }

    global $wpdb;

    $is_pro = sws_is_pro();
    $license_label = $is_pro ? 'Pro Active' : 'Free';
    $license_color = $is_pro ? '#16a34a' : '#6b7280';

    $square_connected = ! empty( get_option( 'sws_square_access_token', '' ) );
    $auto_sync = get_option( 'sws_auto_sync', false );
    $sync_interval = get_option( 'sws_sync_interval', 'daily' );

    $products_table = $wpdb->prefix . 'sws_products';
    $history_table = $wpdb->prefix . 'sws_sync_history';

    $total_tracked = 0;
    $synced = 0;
    $pending = 0;
    $errors = 0;
    $last_sync = null;

    try {
        $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $products_table ) );
        if ( $table_exists ) {
            $total_tracked = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$products_table}" );
            $synced = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$products_table} WHERE sync_status='synced'" );
            $pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$products_table} WHERE sync_status='pending'" );
            $errors = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$products_table} WHERE sync_status='error'" );
        }

        $history_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $history_table ) );
        if ( $history_exists ) {
            $last_sync = $wpdb->get_row( "SELECT sync_date, matched, updated, created, errors FROM {$history_table} ORDER BY sync_date DESC LIMIT 1" );
        }
    } catch ( \Exception $e ) {
        // Tables may not exist yet
    }

    $accent = '#006AFF';

    ?>
    <style>
        #sws_dashboard_widget .inside { padding: 0 !important; }
    </style>
    <div style="padding:12px 16px 16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
            <span style="font-size:13px;font-weight:600;color:<?php echo $license_color; ?>;background:<?php echo $is_pro ? '#dcfce7' : '#f3f4f6'; ?>;padding:3px 10px;border-radius:4px;">
                <?php echo esc_html( $license_label ); ?>
            </span>
            <span style="font-size:12px;color:<?php echo $square_connected ? '#16a34a' : '#dc2626'; ?>;">
                Square: <?php echo $square_connected ? 'Connected' : 'Not Connected'; ?>
            </span>
        </div>

        <?php if ( $last_sync ) : ?>
        <div style="background:#eff6ff;border-left:3px solid <?php echo $accent; ?>;padding:10px 12px;border-radius:0 4px 4px 0;margin-bottom:14px;">
            <div style="font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:4px;">Last Sync</div>
            <div style="font-size:13px;font-weight:600;color:#1e293b;">
                <?php echo esc_html( date( 'M j, Y g:i A', strtotime( $last_sync->sync_date ) ) ); ?>
            </div>
            <div style="font-size:12px;color:#475569;margin-top:4px;">
                Matched: <?php echo (int) $last_sync->matched; ?> &middot;
                Updated: <?php echo (int) $last_sync->updated; ?> &middot;
                Created: <?php echo (int) $last_sync->created; ?>
                <?php if ( (int) $last_sync->errors > 0 ) : ?>
                    &middot; <span style="color:#dc2626;">Errors: <?php echo (int) $last_sync->errors; ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php else : ?>
        <div style="background:#f8fafc;padding:10px 12px;border-radius:4px;margin-bottom:14px;font-size:12px;color:#64748b;">
            No sync history yet. Run your first sync from the dashboard.
        </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px 16px;margin-bottom:14px;">
            <div style="font-size:12px;color:#64748b;">Total Tracked</div>
            <div style="font-size:12px;font-weight:600;color:#1e293b;text-align:right;"><?php echo number_format( $total_tracked ); ?></div>

            <div style="font-size:12px;color:#64748b;">Synced</div>
            <div style="font-size:12px;font-weight:600;color:#16a34a;text-align:right;"><?php echo number_format( $synced ); ?></div>

            <div style="font-size:12px;color:#64748b;">Pending</div>
            <div style="font-size:12px;font-weight:600;color:#d97706;text-align:right;"><?php echo number_format( $pending ); ?></div>

            <div style="font-size:12px;color:#64748b;">Errors</div>
            <div style="font-size:12px;font-weight:600;color:<?php echo $errors > 0 ? '#dc2626' : '#1e293b'; ?>;text-align:right;"><?php echo number_format( $errors ); ?></div>

            <div style="font-size:12px;color:#64748b;">Auto Sync</div>
            <div style="font-size:12px;font-weight:600;color:<?php echo $auto_sync ? '#16a34a' : '#6b7280'; ?>;text-align:right;">
                <?php echo $auto_sync ? esc_html( ucfirst( $sync_interval ) ) : 'Disabled'; ?>
            </div>
        </div>

        <a href="<?php echo esc_url( admin_url( 'admin.php?page=square-woo-sync' ) ); ?>"
           style="display:block;text-align:center;background:<?php echo $accent; ?>;color:#fff;padding:8px 16px;border-radius:4px;text-decoration:none;font-size:13px;font-weight:600;">
            Open Sync Dashboard &rarr;
        </a>
    </div>
    <?php
}
