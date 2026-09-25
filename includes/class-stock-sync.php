<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Quick stock sync: every 5 minutes, ask Square only for inventory counts that changed since the last
 * run and copy them onto the linked WooCommerce listings. Register sales reach the website within
 * minutes instead of waiting for the full sync.
 *
 * Open online orders (pending payment, processing, on hold) are paid and rung up in the store, so
 * Square doesn't know about them yet. Their quantities are held back: website stock = Square count −
 * quantity in open orders for that item. Completing the order releases the hold at the moment the
 * sale is rung up in Square. The full sync uses the same rule (see reserved_qty()).
 */
class SWS_Stock_Sync {

    const HOOK     = 'sws_stock_quick_sync';
    const SCHEDULE = 'sws_five_minutes';
    const LAST     = 'sws_stock_sync_last';
    const LOCK     = 'sws_stock_sync_lock';

    /** Open orders older than this don't hold stock (an abandoned pickup shouldn't hide stock forever). */
    const HOLD_DAYS = 14;

    public static function init() {
        add_filter( 'cron_schedules', [ __CLASS__, 'schedules' ] );
        add_action( self::HOOK, [ __CLASS__, 'run' ] );
        add_action( 'init', [ __CLASS__, 'ensure_schedule' ] );
    }

    public static function schedules( $s ) {
        $s[ self::SCHEDULE ] = [ 'interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (Square stock)' ];
        return $s;
    }

    public static function enabled() {
        return get_option( 'sws_quick_stock_sync', '0' ) === '1';
    }

    public static function reserve_open_orders() {
        return get_option( 'sws_reserve_open_orders', '1' ) === '1';
    }

    /** Keep the cron event in step with the setting. */
    public static function ensure_schedule() {
        $next = wp_next_scheduled( self::HOOK );
        if ( self::enabled() && ! $next ) {
            wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
        } elseif ( ! self::enabled() && $next ) {
            wp_clear_scheduled_hook( self::HOOK );
        }
    }

    /**
     * Quantity of this product/variation in open online orders placed in the last HOLD_DAYS days.
     *
     * @param int $post_id Simple product or variation ID.
     */
    public static function reserved_qty( $post_id ) {
        if ( ! self::reserve_open_orders() ) {
            return 0;
        }
        global $wpdb;
        $lookup = $wpdb->prefix . 'wc_order_product_lookup';
        $stats  = $wpdb->prefix . 'wc_order_stats';
        $since  = gmdate( 'Y-m-d H:i:s', time() - self::HOLD_DAYS * DAY_IN_SECONDS );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE( SUM( l.product_qty ), 0 ) FROM $lookup l
             JOIN $stats s ON s.order_id = l.order_id
             WHERE ( l.variation_id = %d OR ( l.variation_id = 0 AND l.product_id = %d ) )
               AND s.status IN ( 'wc-pending', 'wc-processing', 'wc-on-hold' )
               AND s.date_created_gmt >= %s",
            $post_id, $post_id, $since
        ) );
    }

    /** Stock the website should show for a Square count. */
    public static function site_qty( $post_id, $square_qty ) {
        return max( 0, (int) $square_qty - self::reserved_qty( $post_id ) );
    }

    /**
     * One quick-sync pass.
     *
     * @return array { checked, updated, skipped, error? }
     */
    public static function run() {
        $out = [ 'checked' => 0, 'updated' => 0, 'skipped' => 0 ];
        if ( get_transient( self::LOCK ) ) {
            return $out + [ 'error' => 'already running' ];
        }
        set_transient( self::LOCK, 1, 4 * MINUTE_IN_SECONDS );
        $logger  = new SWS_Sync_Logger();
        $started = time();
        try {
            $api   = new SWS_Square_Api();
            $since = (int) get_option( self::LAST, 0 );
            $since = $since ? $since - 120 : $started - 15 * MINUTE_IN_SECONDS; // 2-minute overlap.
            $cursor = null;
            $pages  = 0;
            do {
                $res = $api->get_changed_counts( gmdate( 'c', $since ), $cursor );
                if ( is_wp_error( $res ) ) {
                    $logger->warning( 'Quick stock sync: Square error — ' . $res->get_error_message() );
                    return $out + [ 'error' => $res->get_error_message() ];
                }
                foreach ( (array) ( $res['counts'] ?? [] ) as $c ) {
                    if ( ( $c['state'] ?? '' ) !== 'IN_STOCK' ) {
                        continue;
                    }
                    $out['checked']++;
                    $r = self::apply_count( (string) $c['catalog_object_id'], (int) floatval( $c['quantity'] ?? 0 ), $logger );
                    $out[ $r ]++;
                }
                $cursor = $res['cursor'] ?? null;
                $pages++;
            } while ( $cursor && $pages < 50 );
            update_option( self::LAST, $started, false );
            if ( $out['updated'] ) {
                $logger->info( sprintf( 'Quick stock sync: %d changed in Square, %d website listings updated.', $out['checked'], $out['updated'] ) );
            }
            return $out;
        } finally {
            delete_transient( self::LOCK );
        }
    }

    /** @return string 'updated' | 'skipped' */
    private static function apply_count( $square_variation_id, $square_qty, $logger ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->postmeta} m
             JOIN {$wpdb->posts} p ON p.ID = m.post_id
             LEFT JOIN {$wpdb->posts} pp ON pp.ID = p.post_parent
             WHERE m.meta_key = '_square_variation_id' AND m.meta_value = %s
               AND ( ( p.post_type = 'product_variation' AND p.post_status = 'publish' AND pp.post_status IN ( 'publish', 'private' ) )
                  OR ( p.post_type = 'product' AND p.post_status IN ( 'publish', 'private' ) ) )",
            $square_variation_id
        ) );
        $units = [];
        foreach ( $ids as $id ) {
            $p = wc_get_product( $id );
            if ( $p && ! $p->is_type( 'variable' ) ) {
                $units[] = $p;
            }
        }
        if ( count( $units ) !== 1 ) {
            if ( count( $units ) > 1 ) {
                $logger->warning( sprintf( 'Quick stock sync: Square variation %s is linked to %d listings — left for the full sync.', $square_variation_id, count( $units ) ) );
            }
            return 'skipped';
        }
        $p      = $units[0];
        $target = self::site_qty( $p->get_id(), $square_qty );
        if ( $p->get_manage_stock() && (int) $p->get_stock_quantity() === $target ) {
            return 'skipped';
        }
        if ( ! $p->get_manage_stock() ) {
            $p->set_manage_stock( true );
            $p->save();
        }
        wc_update_product_stock( $p, $target, 'set' );
        return 'updated';
    }
}
