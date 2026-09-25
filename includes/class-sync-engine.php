<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SWS_Sync_Engine {

    private $square;
    private $matcher;
    private $ai;
    private $logger;

    private $sync_stock;
    private $sync_price;
    private $create_new;
    private $ai_generate_desc;
    private $dry_run;
    private $min_confidence;
    private $ai_verify_all;

    /**
     * Square is the source of truth (option sws_square_truth): product names, option labels and
     * SKUs follow Square; options Square doesn't have are retired; every Square item is matched
     * (the category filter only limits which NEW products get created); live listings that
     * aren't in Square are set out of stock.
     */
    private $square_truth = false;

    /** Variation IDs created in this request (never retired by the same run). */
    private $created_var_ids = [];

    private $stats = [
        'matched'       => 0,
        'updated'       => 0,
        'sku_added'     => 0,
        'created'       => 0,
        'skipped'       => 0,
        'errors'        => 0,
        'total_square'  => 0,
        'ai_checks'     => 0,
        'ai_issues'     => 0,
        'renamed'       => 0,
        'relabeled'     => 0,
        'retired'       => 0,
        'not_in_square' => 0,
        'sku_taken'     => 0,
    ];

    /**
     * Deferred variation attribute writes — applied AFTER the final parent product save
     * so no WC hook triggered during save() can wipe them.
     * [ var_id => [ 'attribute_slug' => 'value', ... ], ... ]
     */
    private $pending_variation_attrs = [];

    public function __construct() {
        $this->square           = new SWS_Square_Api();
        $this->matcher          = new SWS_Product_Matcher();
        $this->ai               = new SWS_Ai_Matcher();
        $this->logger           = new SWS_Sync_Logger();
        $this->sync_stock         = get_option( 'sws_sync_stock', '1' ) === '1';
        $this->sync_price         = get_option( 'sws_sync_price', '0' ) === '1';
        $this->create_new         = get_option( 'sws_create_new', '1' ) === '1';
        $this->skip_out_of_stock  = get_option( 'sws_skip_out_of_stock', '0' ) === '1';
        $this->ai_generate_desc   = get_option( 'sws_ai_generate_desc', '1' ) === '1';
        $this->dry_run            = get_option( 'sws_dry_run', '0' ) === '1';
        $this->min_confidence     = (float) get_option( 'sws_ai_confidence_threshold', 0.75 );
        $this->ai_verify_all      = get_option( 'sws_ai_verify_all', '1' ) === '1';
        $this->square_truth       = get_option( 'sws_square_truth', '0' ) === '1';
    }

    public function run_full_sync() {
        $start = microtime( true );
        $this->logger->info( '=== Square WooCommerce Sync Started' . ( $this->dry_run ? ' [DRY RUN]' : '' ) . ( $this->square_truth ? ' [SQUARE IS SOURCE OF TRUTH]' : '' ) . ' ===' );
        // Full runs process every Square item; the configured categories only decide which NEW products get created.
        update_option( 'sws_batch_create_filter', array_values( array_filter( (array) get_option( 'sws_sync_categories', [] ) ) ) );

        $square_products = $this->square->get_normalized_products();
        if ( is_wp_error( $square_products ) ) {
            $this->logger->error( 'Failed to fetch Square products: ' . $square_products->get_error_message() );
            return array_merge( $this->stats, [ 'success' => false, 'error' => $square_products->get_error_message() ] );
        }

        $this->stats['total_square'] = count( $square_products );
        $this->logger->info( sprintf( 'Processing %d Square products...', $this->stats['total_square'] ) );

        $processed = 0;
        foreach ( $square_products as $sq_product ) {
            $processed++;
            try {
                $this->process_product( $sq_product );
            } catch ( \Throwable $e ) {
                $this->stats['errors']++;
                $this->logger->error( sprintf( 'Exception processing "%s": %s in %s:%d', $sq_product['name'], $e->getMessage(), basename( $e->getFile() ), $e->getLine() ) );
                try {
                    $this->update_product_record( $sq_product, null, 'error', '', 0, $e->getMessage(), [] );
                } catch ( \Throwable $e2 ) {
                    $this->logger->error( 'Failed to update product record: ' . $e2->getMessage() );
                }
            }
            if ( $processed % 5 === 0 || $processed <= 3 || $processed === $this->stats['total_square'] ) {
                update_option( 'sws_sync_progress', [
                    'current'  => $processed,
                    'total'    => $this->stats['total_square'],
                    'product'  => $sq_product['name'],
                    'matched'  => $this->stats['matched'],
                    'created'  => $this->stats['created'],
                    'errors'   => $this->stats['errors'],
                ]);
            }
        }

        $this->truth_retire_unlisted( $square_products );

        return $this->finalize_sync( $start );
    }

    /**
     * BATCH MODE: Step 1 — Fetch Square catalog and store for batch processing.
     */
    public function batch_start( $category_filter = '' ) {
        $filters = [];
        if ( is_array( $category_filter ) ) {
            $filters = array_map( 'trim', array_filter( $category_filter ) );
        } elseif ( $category_filter !== '' ) {
            $filters = [ trim( $category_filter ) ];
        }

        $label = ! empty( $filters ) ? ' [Categories: ' . implode( ', ', $filters ) . ']' : '';
        $this->logger->info( '=== Square WooCommerce Batch Sync Started' . $label . ( $this->dry_run ? ' [DRY RUN]' : '' ) . ' ===' );

        $square_products = $this->square->get_normalized_products();
        if ( is_wp_error( $square_products ) ) {
            $this->logger->error( 'Failed to fetch Square products: ' . $square_products->get_error_message() );
            return [ 'success' => false, 'error' => $square_products->get_error_message() ];
        }

        update_option( 'sws_batch_create_filter', $filters );
        if ( ! empty( $filters ) && $this->square_truth ) {
            $this->logger->info( sprintf( 'Square-truth mode: all %d Square items are matched and updated; the category filter (%s) only limits which new products are created.', count( $square_products ), implode( ', ', $filters ) ) );
        } elseif ( ! empty( $filters ) ) {
            $before = count( $square_products );
            $has_uncategorized = in_array( 'Uncategorized', $filters, true );
            $named_filters = array_filter( $filters, function( $f ) { return $f !== 'Uncategorized'; } );

            // Items already linked to a WooCommerce product always sync, whatever their
            // Square category — the filter only decides which NEW products get created.
            // Otherwise a live listing whose Square item sits in an unselected category
            // ("dispo pods", "Tanks", …) silently kept stale stock and price forever.
            global $wpdb;
            $linked_ids = array_flip( $wpdb->get_col(
                "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_square_product_id' AND pm.meta_value <> ''
                   AND p.post_type = 'product' AND p.post_status IN ('publish','private')"
            ) );

            $square_products = array_values( array_filter( $square_products, function( $p ) use ( $named_filters, $has_uncategorized, $linked_ids ) {
                if ( isset( $linked_ids[ $p['square_id'] ] ) ) return true;
                $cats = $p['categories'] ?? [];
                if ( $has_uncategorized && empty( $cats ) ) return true;
                if ( ! empty( $named_filters ) ) {
                    foreach ( $named_filters as $f ) {
                        if ( in_array( $f, $cats, true ) ) return true;
                    }
                }
                return false;
            }));
            $this->logger->info( sprintf( 'Category filter: %d of %d products match (incl. items already linked to a live product).', count( $square_products ), $before ) );
        }

        $total = count( $square_products );
        $this->logger->info( sprintf( 'Saving %d products for batch processing...', $total ) );

        $upload_dir = wp_upload_dir();
        $cache_dir  = $upload_dir['basedir'] . '/sws-logs';
        if ( ! file_exists( $cache_dir ) ) {
            wp_mkdir_p( $cache_dir );
        }
        $cache_file = $cache_dir . '/batch-catalog.json';
        file_put_contents( $cache_file, wp_json_encode( $square_products ) );

        update_option( 'sws_batch_total', $total );
        update_option( 'sws_batch_offset', 0 );
        update_option( 'sws_batch_stats', $this->stats );
        update_option( 'sws_batch_start_time', microtime( true ) );

        return [
            'success' => true,
            'total'   => $total,
            'offset'  => 0,
        ];
    }

    /**
     * BATCH MODE: Step 2 — Process a batch of products.
     */
    public function batch_process( $batch_size = 25 ) {
        $upload_dir = wp_upload_dir();
        $cache_file = $upload_dir['basedir'] . '/sws-logs/batch-catalog.json';

        if ( ! file_exists( $cache_file ) ) {
            return [ 'success' => false, 'error' => 'No batch catalog found. Start a new sync.' ];
        }

        $square_products = json_decode( file_get_contents( $cache_file ), true );
        if ( ! is_array( $square_products ) ) {
            return [ 'success' => false, 'error' => 'Corrupt batch catalog. Start a new sync.' ];
        }

        $total  = (int) get_option( 'sws_batch_total', count( $square_products ) );
        $offset = (int) get_option( 'sws_batch_offset', 0 );
        $stored_stats = get_option( 'sws_batch_stats', null );
        if ( is_array( $stored_stats ) && isset( $stored_stats['matched'] ) ) {
            $this->stats = array_merge( $this->stats, $stored_stats );
        }

        $this->stats['total_square'] = $total;

        $batch = array_slice( $square_products, $offset, $batch_size );
        $batch_count = count( $batch );

        if ( $batch_count === 0 ) {
            return $this->batch_finish();
        }

        $this->logger->info( sprintf( '--- Batch: processing products %d–%d of %d ---', $offset + 1, $offset + $batch_count, $total ) );

        foreach ( $batch as $sq_product ) {
            $offset++;
            try {
                $this->process_product( $sq_product );
            } catch ( \Throwable $e ) {
                $this->stats['errors']++;
                $this->logger->error( sprintf( 'Exception processing "%s": %s in %s:%d', $sq_product['name'], $e->getMessage(), basename( $e->getFile() ), $e->getLine() ) );
                try {
                    $this->update_product_record( $sq_product, null, 'error', '', 0, $e->getMessage(), [] );
                } catch ( \Throwable $e2 ) {
                    $this->logger->error( 'Failed to update product record: ' . $e2->getMessage() );
                }
            }
        }

        $last_product_name = $batch[ $batch_count - 1 ]['name'] ?? '';

        update_option( 'sws_batch_offset', $offset );
        update_option( 'sws_batch_stats', $this->stats );

        update_option( 'sws_sync_progress', [
            'current' => $offset,
            'total'   => $total,
            'product' => $last_product_name,
            'matched' => $this->stats['matched'],
            'created' => $this->stats['created'],
            'errors'  => $this->stats['errors'],
        ]);

        $done = ( $offset >= $total );

        if ( $done ) {
            return $this->batch_finish();
        }

        return [
            'success'      => true,
            'done'         => false,
            'offset'       => $offset,
            'total'        => $total,
            'last_product' => $last_product_name,
            'stats'        => $this->stats,
        ];
    }

    /**
     * BATCH MODE: Step 3 — Finalize batch sync.
     */
    private function batch_finish() {
        $start_time = (float) get_option( 'sws_batch_start_time', microtime( true ) );

        $upload_dir = wp_upload_dir();
        $cache_file = $upload_dir['basedir'] . '/sws-logs/batch-catalog.json';
        if ( file_exists( $cache_file ) ) {
            $catalog = json_decode( (string) file_get_contents( $cache_file ), true );
            if ( is_array( $catalog ) ) {
                $this->truth_retire_unlisted( $catalog );
            }
            @unlink( $cache_file );
        }
        delete_option( 'sws_batch_create_filter' );

        $result = $this->finalize_sync( $start_time );

        delete_option( 'sws_batch_total' );
        delete_option( 'sws_batch_offset' );
        delete_option( 'sws_batch_stats' );
        delete_option( 'sws_batch_start_time' );

        return $result;
    }

    /**
     * Shared sync finalization (used by both full and batch modes).
     */
    private function finalize_sync( $start_time ) {
        $elapsed = round( microtime( true ) - $start_time, 2 );
        $this->logger->info( sprintf(
            '=== Sync Complete in %ss | Matched: %d | Updated: %d | SKUs Added: %d | Created: %d | Skipped: %d | Errors: %d | AI Checks: %d | AI Issues: %d ===',
            $elapsed,
            $this->stats['matched'],
            $this->stats['updated'],
            $this->stats['sku_added'],
            $this->stats['created'],
            $this->stats['skipped'],
            $this->stats['errors'],
            $this->stats['ai_checks'],
            $this->stats['ai_issues']
        ));

        delete_option( 'sws_sync_progress' );
        update_option( 'sws_last_sync', current_time( 'mysql' ) );
        update_option( 'sws_last_sync_stats', $this->stats );

        try {
            global $wpdb;
            $wpdb->insert( $wpdb->prefix . 'sws_sync_history', [
                'total_square'    => $this->stats['total_square'],
                'matched'         => $this->stats['matched'],
                'updated'         => $this->stats['updated'],
                'created'         => $this->stats['created'],
                'skipped'         => $this->stats['skipped'],
                'errors'          => $this->stats['errors'],
                'sku_added'       => $this->stats['sku_added'],
                'ai_checks'       => $this->stats['ai_checks'],
                'ai_issues'       => $this->stats['ai_issues'],
                'elapsed_seconds' => $elapsed,
                'is_dry_run'      => $this->dry_run ? 1 : 0,
            ]);
        } catch ( \Throwable $e ) {
            $this->logger->error( 'Failed to save sync history: ' . $e->getMessage() );
        }

        return array_merge( $this->stats, [ 'success' => true, 'done' => true, 'elapsed' => $elapsed ] );
    }

    private function process_product( array $sq_product ) {
        $this->logger->info( sprintf( 'Processing Square product: "%s" (ID: %s)', $sq_product['name'], $sq_product['square_id'] ) );

        $wc_product = $this->matcher->find_woo_product( $sq_product );
        $match_method = '';
        $match_confidence = 0;
        $ai_reasoning = '';
        $changes = [];

        if ( $wc_product ) {
            $this->stats['matched']++;
            $match_info = $this->matcher->get_last_match_info();
            $match_method = $match_info['method'] ?? 'unknown';
            $match_confidence = $match_info['confidence'] ?? 1.0;
            $ai_reasoning = $match_info['reasoning'] ?? '';

            $this->logger->info( sprintf( '  ✓ Matched to WC Product #%d: "%s" (method: %s, confidence: %.2f)',
                $wc_product->get_id(), $wc_product->get_name(), $match_method, $match_confidence ) );

            $changes = $this->update_existing_product( $sq_product, $wc_product );

            $status = empty( $changes ) ? 'synced' : 'updated';

            $skip_ai_verify = in_array( $match_method, [ 'sku', 'square_id', 'title_exact', 'title_similarity' ], true );
            if ( $this->ai_verify_all && ! $this->dry_run && ! $skip_ai_verify ) {
                $integrity = $this->run_ai_integrity_check( $sq_product, $wc_product, $changes );
                $this->update_product_record( $sq_product, $wc_product, $status, $match_method, $match_confidence, $ai_reasoning, $changes, $integrity );
            } else {
                if ( $skip_ai_verify ) {
                    $this->logger->info( sprintf( '  ⏭ Skipping AI integrity check for "%s" (%s = high certainty)', $sq_product['name'], $match_method ) );
                }
                $this->update_product_record( $sq_product, $wc_product, $status, $match_method, $match_confidence, $ai_reasoning, $changes );
            }
        } else {
            $this->logger->info( sprintf( '  ✗ No match found for "%s"', $sq_product['name'] ) );
            if ( $this->create_new && ! $this->category_allows_creation( $sq_product ) ) {
                $this->logger->info( sprintf( '  ⏭ Not creating "%s" — its Square category is not selected for new products', $sq_product['name'] ) );
                $this->stats['skipped']++;
                $this->update_product_record( $sq_product, null, 'unmatched', '', 0, 'Category not selected for new products', [] );
            } elseif ( $this->create_new ) {
                if ( $this->skip_out_of_stock && $this->is_square_product_out_of_stock( $sq_product ) ) {
                    $this->logger->info( sprintf( '  ⏭ Skipping "%s" — out of stock on Square', $sq_product['name'] ) );
                    $this->stats['skipped']++;
                    $this->update_product_record( $sq_product, null, 'skipped', '', 0, 'Out of stock — skipped', [] );
                } else {
                    $new_id = $this->create_new_product( $sq_product );
                    $new_wc = $new_id ? wc_get_product( $new_id ) : null;
                    $this->update_product_record( $sq_product, $new_wc, 'created', 'new', 0, '', [ 'action' => 'created' ] );
                }
            } else {
                $this->stats['skipped']++;
                $this->update_product_record( $sq_product, null, 'unmatched', '', 0, '', [] );
            }
        }
    }

    private function update_product_record( array $sq_product, $wc_product, $status, $match_method, $confidence, $reasoning, $changes, $integrity = null ) {
        global $wpdb;
        $table = $wpdb->prefix . 'sws_products';

        $data = [
            'square_name'       => $sq_product['name'],
            'square_categories' => implode( ', ', $sq_product['categories'] ),
            'square_image_url'  => $sq_product['image_url'] ?? '',
            'woo_product_id'    => $wc_product ? $wc_product->get_id() : null,
            'woo_product_name'  => $wc_product ? $wc_product->get_name() : '',
            'sync_status'       => $status,
            'match_method'      => $match_method,
            'match_confidence'  => $confidence,
            'ai_reasoning'      => $reasoning,
            'variations_json'   => wp_json_encode( $sq_product['variations'] ),
            'changes_json'      => wp_json_encode( $changes ),
            'last_synced_at'    => current_time( 'mysql' ),
        ];

        if ( $integrity ) {
            $data['ai_verified']        = 1;
            $data['ai_integrity_score'] = $integrity['score'];
            $data['ai_integrity_notes'] = wp_json_encode( $integrity );
        }

        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE square_id = %s", $sq_product['square_id'] ) );

        if ( $existing ) {
            $wpdb->update( $table, $data, [ 'id' => $existing ] );
        } else {
            $data['square_id'] = $sq_product['square_id'];
            $wpdb->insert( $table, $data );
        }
    }

    private function run_ai_integrity_check( array $sq_product, $wc_product, array $changes ) {
        $this->stats['ai_checks']++;

        $sq_vars_text = '';
        foreach ( $sq_product['variations'] as $v ) {
            $sq_vars_text .= sprintf( "\n  - %s | SKU: %s | Price: $%s | Stock: %d", $v['name'], $v['sku'], $v['price'], $v['quantity'] );
        }

        $wc_stock = $wc_product->get_stock_quantity();
        $wc_price = $wc_product->get_regular_price();
        $wc_sku   = $wc_product->get_sku();
        $changes_text = empty( $changes ) ? 'No changes made' : wp_json_encode( $changes );

        $prompt = <<<PROMPT
You are a data integrity auditor for an inventory sync system. Verify this sync operation is correct.

SQUARE SOURCE:
Name: {$sq_product['name']}
Categories: {$sq_product['categories'][0]}
Variations:{$sq_vars_text}

WOOCOMMERCE TARGET:
Name: {$wc_product->get_name()}
SKU: {$wc_sku}
Price: {$wc_price}
Stock: {$wc_stock}
Type: {$wc_product->get_type()}

CHANGES APPLIED: {$changes_text}

Check for:
1. Name mismatch — are these truly the same product?
2. Price discrepancies — is the price transfer correct?
3. Stock accuracy — did stock sync correctly?
4. SKU consistency — do SKUs match or were they correctly assigned?
5. Data corruption — any sign of wrong product linked or garbled data?

Respond ONLY with valid JSON:
{"score": <float 0.0-1.0>, "issues": [<list of issue strings, empty if clean>], "summary": "<one sentence>"}
PROMPT;

        $result = $this->ai->complete( $prompt, 400 );
        if ( is_wp_error( $result ) ) {
            $this->logger->warning( sprintf( '  ⚠ AI integrity check failed for "%s": %s', $sq_product['name'], $result->get_error_message() ) );
            return [ 'score' => 0, 'issues' => [ 'AI check failed' ], 'summary' => 'Could not verify.' ];
        }

        preg_match( '/\{.*\}/s', $result, $matches );
        $parsed = json_decode( $matches[0] ?? '', true );

        if ( ! $parsed ) {
            return [ 'score' => 0, 'issues' => [ 'Parse failed' ], 'summary' => 'AI returned unparseable response.' ];
        }

        $score  = (float) ( $parsed['score'] ?? 0 );
        $issues = $parsed['issues'] ?? [];

        if ( ! empty( $issues ) ) {
            $this->stats['ai_issues']++;
            $this->logger->warning( sprintf( '  ⚠ AI found issues with "%s" (score: %.2f): %s',
                $sq_product['name'], $score, implode( '; ', $issues ) ) );
        } else {
            $this->logger->info( sprintf( '  ✓ AI integrity check passed for "%s" (score: %.2f)', $sq_product['name'], $score ) );
        }

        return [
            'score'   => $score,
            'issues'  => $issues,
            'summary' => $parsed['summary'] ?? '',
        ];
    }

    private function update_existing_product( array $sq_product, $wc_product ) {
        $changes = [];

        $wc_product->update_meta_data( '_square_product_id', $sq_product['square_id'] );

        if ( $this->square_truth ) {
            $sq_name = trim( (string) $sq_product['name'] );
            if ( $sq_name !== '' && $sq_name !== trim( html_entity_decode( $wc_product->get_name( 'edit' ), ENT_QUOTES, 'UTF-8' ) ) ) {
                $this->logger->info( sprintf( '  ✎ Name: "%s" → "%s"', $wc_product->get_name( 'edit' ), $sq_name ) );
                $changes[] = [ 'field' => 'name', 'from' => $wc_product->get_name( 'edit' ), 'to' => $sq_name ];
                $this->stats['renamed']++;
                if ( ! $this->dry_run ) {
                    $wc_product->set_name( $sq_name ); // The URL slug is kept.
                }
            }
        }

        $sq_is_variable = count( $sq_product['variations'] ) > 1 ||
                          ( count( $sq_product['variations'] ) === 1 && $sq_product['variations'][0]['name'] !== 'Regular' );

        // For simple products, collect pending stock/price writes to apply AFTER save().
        $pending_simple = [];

        if ( $wc_product->is_type( 'simple' ) && $sq_is_variable ) {
            $this->logger->info( sprintf(
                '  ↑ Converting Simple Product #%d to Variable (Square has %d variations)',
                $wc_product->get_id(), count( $sq_product['variations'] )
            ));
            if ( ! $this->dry_run ) {
                $wc_product = $this->convert_simple_to_variable( $wc_product, $sq_product );
                $changes[] = [ 'field' => 'type', 'from' => 'simple', 'to' => 'variable' ];
            }
        } elseif ( $wc_product->is_type( 'simple' ) ) {
            $result = $this->update_simple_product( $sq_product, $wc_product );
            $changes = $result['changes'];
            $pending_simple = $result['pending'];
        }

        if ( $wc_product->is_type( 'variable' ) ) {
            // A variable product is never itself a Square variation. A link left over from when it
            // was simple points at one of its options' Square variations, so tools that push stock
            // (StockDeck) would write the parent's number onto that option. Drop it.
            if ( $wc_product->get_meta( '_square_variation_id' ) !== '' && ! $this->dry_run ) {
                $this->logger->info( sprintf( '    ✂ Removed leftover variation link from variable product #%d', $wc_product->get_id() ) );
                delete_post_meta( $wc_product->get_id(), '_square_variation_id' );
                $wc_product->delete_meta_data( '_square_variation_id' );
            }
            $var_changes = $this->update_variable_product( $sq_product, $wc_product );
            $changes = array_merge( $changes, $var_changes );
        }

        if ( ! empty( $changes ) && ! $this->dry_run ) {
            $wc_product->save();

            // Apply stock/price writes AFTER save() so that no hook fired during save()
            // can silently revert them.  Bust the WordPress meta cache afterwards so the
            // next in-process get_post_meta() call reflects the freshly written values.
            if ( ! empty( $pending_simple ) ) {
                $pid = $wc_product->get_id();
                foreach ( $pending_simple as $meta_key => $meta_value ) {
                    update_post_meta( $pid, $meta_key, $meta_value );
                }
                wp_cache_delete( $pid, 'post_meta' );
                wc_delete_product_transients( $pid );
            }

            $this->stats['updated']++;
            $this->logger->info( sprintf( '  → Updated WC Product #%d (%d changes)', $wc_product->get_id(), count( $changes ) ) );
        }

        // DEFERRED VARIATION ATTRIBUTES — Final pass AFTER the parent product save.
        // The parent save() above can trigger WC hooks that wipe variation attribute
        // meta AND the parent's attribute option list. This re-applies everything
        // directly via update_post_meta, bypassing WC's object model entirely.
        if ( ! empty( $this->pending_variation_attrs ) ) {
            $this->apply_deferred_variation_attrs( $wc_product->get_id() );
        }

        return $changes;
    }

    private function update_simple_product( array $sq_product, $wc_product ) {
        $changes  = [];
        $pending  = [];   // stock/price meta to write AFTER the parent save()
        $sq_var   = $sq_product['variations'][0] ?? null;
        if ( ! $sq_var ) return [ 'changes' => $changes, 'pending' => $pending ];

        if ( ! empty( $sq_var['sku'] ) && $wc_product->get_sku() !== $sq_var['sku'] && ( empty( $wc_product->get_sku() ) || $this->square_truth ) ) {
            if ( $this->dry_run ) {
                $changes[] = [ 'field' => 'sku', 'from' => $wc_product->get_sku() ?: '', 'to' => $sq_var['sku'] ];
                $this->logger->info( sprintf( '    + SKU: %s → %s', $wc_product->get_sku() ?: '(none)', $sq_var['sku'] ) );
            } else {
                if ( $this->sku_is_available( $sq_var['sku'], $wc_product->get_id() ) || $this->take_over_sku( $sq_var['sku'], $sq_var['square_variation_id'], $wc_product->get_id() ) ) {
                    $wc_product->set_sku( $sq_var['sku'] );
                    $this->stats['sku_added']++;
                    $changes[] = [ 'field' => 'sku', 'from' => '', 'to' => $sq_var['sku'] ];
                    $this->logger->info( sprintf( '    + SKU: %s', $sq_var['sku'] ) );
                } else {
                    $this->logger->warning( sprintf( '    ⚠ SKU "%s" already used by another product — skipped', $sq_var['sku'] ) );
                }
            }
        }

        $wc_product->update_meta_data( '_square_variation_id', $sq_var['square_variation_id'] );

        if ( $this->sync_stock ) {
            // Website stock = Square count minus open online orders not yet rung up in Square.
            $new_qty = SWS_Stock_Sync::site_qty( $wc_product->get_id(), (int) $sq_var['quantity'] );
            $old_qty = $wc_product->get_stock_quantity();

            if ( $old_qty != $new_qty ) {
                if ( ! $this->dry_run ) {
                    // Queue direct meta writes — applied after save() in update_existing_product().
                    $pending['_manage_stock'] = 'yes';
                    $pending['_stock']        = $new_qty;
                    $pending['_stock_status'] = $new_qty > 0 ? 'instock' : 'outofstock';
                }
                $changes[] = [ 'field' => 'stock', 'from' => $old_qty, 'to' => $new_qty ];
                $this->logger->info( sprintf( '    → Stock: %d → %d', $old_qty, $new_qty ) );
            }
        }

        if ( $this->sync_price && $sq_var['price'] !== null ) {
            $new_price = number_format( $sq_var['price'], 2, '.', '' );
            $old_price = $wc_product->get_regular_price();

            if ( $old_price != $new_price ) {
                if ( ! $this->dry_run ) {
                    $pending['_regular_price'] = $new_price;
                    $pending['_price']         = $new_price;
                }
                $changes[] = [ 'field' => 'price', 'from' => $old_price, 'to' => $new_price ];
                $this->logger->info( sprintf( '    → Price: $%s → $%s', $old_price, $new_price ) );
            }
        }

        return [ 'changes' => $changes, 'pending' => $pending ];
    }

    private function update_variable_product( array $sq_product, $wc_product ) {
        $changes = [];
        $missing_vars = [];

        // Diagnostic debug info — captured and returned to single-sync AJAX callers.
        $debug = [
            'wc_product_id'   => $wc_product->get_id(),
            'wc_product_type' => $wc_product->get_type(),
            'wc_children'     => count( $wc_product->get_children() ),
            'sq_variations'   => count( $sq_product['variations'] ),
            'matched'         => [],
            'missing'         => [],
            'created'         => [],
            'dry_run'         => $this->dry_run,
        ];

        $this->logger->info( sprintf(
            '    [DEBUG] WC Product #%d (type=%s, children=%d), Square variations=%d, dry_run=%s',
            $wc_product->get_id(), $wc_product->get_type(),
            count( $wc_product->get_children() ), count( $sq_product['variations'] ),
            $this->dry_run ? 'YES' : 'no'
        ));

        // Track WC variation IDs claimed during THIS sync run so find_woo_variation()
        // never returns the same WC variation for two different Square variations.
        // Without this, "blue raz ice" could fuzzy-match the WC variation already used
        // by "blue razz", preventing the new flavour from ever being created.
        $claimed_wc_ids = [];

        foreach ( $sq_product['variations'] as $sq_var ) {
            $wc_var = $this->matcher->find_woo_variation( $sq_var, $wc_product, $claimed_wc_ids );

            if ( ! $wc_var ) {
                $this->logger->info( sprintf(
                    '    ⊕ No WC variation found for Square variation "%s" (SKU: %s) — will create',
                    $sq_var['name'], $sq_var['sku']
                ));
                $missing_vars[] = $sq_var;
                $debug['missing'][] = $sq_var['name'];
                continue;
            }

            // Claim this WC variation so it cannot be returned for a different Square variation.
            $claimed_wc_ids[] = $wc_var->get_id();
            $debug['matched'][] = $sq_var['name'] . ' → #' . $wc_var->get_id();

            $this->logger->info( sprintf(
                '    ✓ Matched variation "%s" → WC Variation #%d',
                $sq_var['name'], $wc_var->get_id()
            ));

            $var_id      = $wc_var->get_id();
            $var_changes = [];
            $meta_dirty  = false;

            $stored_sq_id = $wc_var->get_meta( '_square_variation_id' );
            if ( $stored_sq_id !== $sq_var['square_variation_id'] ) {
                $wc_var->update_meta_data( '_square_variation_id', $sq_var['square_variation_id'] );
                $meta_dirty = true;
            }

            // One Square variation ↔ one WooCommerce option. Any OTHER option of this product still
            // carrying this Square variation ID is a stale duplicate (a retired option, or one an
            // earlier sync mis-paired): it would never be updated again, yet anything reading the link
            // (StockDeck's stock push, reports) would treat it as the same Square item. Unlink it.
            if ( ! $this->dry_run ) {
                foreach ( $wc_product->get_children() as $other_id ) {
                    if ( (int) $other_id === $var_id ) {
                        continue;
                    }
                    if ( get_post_meta( $other_id, '_square_variation_id', true ) === $sq_var['square_variation_id'] ) {
                        delete_post_meta( $other_id, '_square_variation_id' );
                        wc_delete_product_transients( $other_id );
                        $this->logger->warning( sprintf(
                            '      ✂ Unlinked WC Variation #%d: Square variation "%s" belongs to #%d',
                            $other_id, $sq_var['name'], $var_id
                        ) );
                    }
                }
            }

            // Always sync the SKU from Square — this also auto-corrects duplicates left by
            // the v1.6.7 sibling bug (where multiple variations got the same SKU).
            $wc_sku = $wc_var->get_sku();
            $sq_sku = $sq_var['sku'];
            if ( ! empty( $sq_sku ) && $wc_sku !== $sq_sku && ! $this->dry_run ) {
                if ( $this->sku_is_available( $sq_sku, $var_id ) || $this->take_over_sku( $sq_sku, $sq_var['square_variation_id'], $var_id ) ) {
                    $wc_var->set_sku( $sq_sku );
                    $this->stats['sku_added']++;
                    $var_changes[] = [ 'field' => 'sku', 'variation' => $sq_var['name'], 'from' => $wc_sku ?: '(none)', 'to' => $sq_sku ];
                    $this->logger->info( sprintf( '      + SKU: %s → %s', $wc_sku ?: '(none)', $sq_sku ) );
                } else {
                    $this->logger->warning( sprintf( '      ⚠ SKU "%s" already used by another product — skipped', $sq_sku ) );
                }
            }

            if ( $this->square_truth ) {
                $relabel = $this->relabel_variation( $wc_product, $wc_var, (string) $sq_var['name'] );
                if ( $relabel ) {
                    $var_changes[] = $relabel;
                    $meta_dirty    = true;
                }
            }

            // Determine pending stock/price writes (applied AFTER save() below).
            $pending_var = [];

            if ( $this->sync_stock ) {
                // Website stock = Square count minus open online orders not yet rung up in Square.
                $new_qty = SWS_Stock_Sync::site_qty( $var_id, (int) ( $sq_var['quantity'] ?? 0 ) );
                // Use 'edit' context to read the variation's own stock (not inherited from parent).
                $own_manage = $wc_var->get_manage_stock( 'edit' );
                $old_qty    = (int) $wc_var->get_stock_quantity( 'edit' );

                if ( $old_qty !== $new_qty || ! $own_manage ) {
                    if ( ! $this->dry_run ) {
                        $pending_var['_manage_stock'] = 'yes';
                        $pending_var['_stock']        = $new_qty;
                        $pending_var['_stock_status'] = $new_qty > 0 ? 'instock' : 'outofstock';
                        $meta_dirty = true;
                    }
                    $var_changes[] = [ 'field' => 'stock', 'variation' => $sq_var['name'], 'from' => $old_qty, 'to' => $new_qty ];
                    $this->logger->info( sprintf( '      → Stock: %d → %d', $old_qty, $new_qty ) );
                }
            }

            if ( $this->sync_price && $sq_var['price'] !== null ) {
                $new_price = number_format( $sq_var['price'], 2, '.', '' );
                $old_price = $wc_var->get_regular_price( 'edit' );

                if ( $old_price != $new_price ) {
                    if ( ! $this->dry_run ) {
                        $pending_var['_regular_price'] = $new_price;
                        $pending_var['_price']         = $new_price;
                        $meta_dirty = true;
                    }
                    $var_changes[] = [ 'field' => 'price', 'variation' => $sq_var['name'], 'from' => $old_price, 'to' => $new_price ];
                    $this->logger->info( sprintf( '      → Price: $%s → $%s', $old_price, $new_price ) );
                }
            }

            if ( ( ! empty( $var_changes ) || $meta_dirty ) && ! $this->dry_run ) {
                // Step 1: Save meta/SKU through WC pipeline (fires proper hooks).
                $wc_var->save();

                // Step 2: Write stock/price AFTER save() so any hook fired during save()
                // cannot revert these values.  Bust the WordPress post_meta object cache
                // so the next in-process read reflects the freshly written values.
                if ( ! empty( $pending_var ) ) {
                    foreach ( $pending_var as $meta_key => $meta_value ) {
                        update_post_meta( $var_id, $meta_key, $meta_value );
                    }
                    wp_cache_delete( $var_id, 'post_meta' );
                }

                wc_delete_product_transients( $var_id );
                $changes = array_merge( $changes, $var_changes );
            }
        }

        if ( ! empty( $missing_vars ) && ! $this->dry_run ) {
            $created = $this->create_missing_variations( $wc_product, $sq_product, $missing_vars );
            $changes = array_merge( $changes, $created );
        }

        if ( $this->square_truth ) {
            $changes = array_merge( $changes, $this->retire_unmatched_variations( $sq_product, $wc_product, $claimed_wc_ids ) );
        }

        return $changes;
    }

    /**
     * Square-truth: enabled options of this product that no Square variation of this item matched
     * are retired — disabled (private), set out of stock and unlinked — so the listing offers
     * exactly what Square has. Order history stays on the disabled option. Options linked to a
     * variation of a DIFFERENT Square item are left alone (a listing that mixes two Square items
     * is reported, not guessed at).
     */
    private function retire_unmatched_variations( array $sq_product, $wc_product, array $claimed_wc_ids ) {
        $changes  = [];
        $item_var = array_flip( array_column( $sq_product['variations'], 'square_variation_id' ) );
        $product  = wc_get_product( $wc_product->get_id() ); // Includes variations created just now.
        $live     = 0;
        $retire   = [];
        foreach ( $product->get_children() as $cid ) {
            $v = wc_get_product( $cid );
            if ( ! $v || $v->get_status( 'edit' ) !== 'publish' ) {
                continue;
            }
            if ( in_array( (int) $cid, $claimed_wc_ids, true ) ) {
                $live++;
                continue;
            }
            $stored = (string) $v->get_meta( '_square_variation_id' );
            if ( $stored !== '' && ! isset( $item_var[ $stored ] ) ) {
                $this->logger->warning( sprintf( '    ⚑ Option #%d "%s" belongs to another Square item (%s) — left as is (listing mixes Square items)', $cid, implode( ', ', $v->get_attributes() ), $stored ) );
                $live++;
                continue;
            }
            // Created in this run for this Square item → matched by construction.
            if ( in_array( (int) $cid, $this->created_var_ids, true ) ) {
                $live++;
                continue;
            }
            $retire[] = $v;
        }
        foreach ( $retire as $v ) {
            $label = implode( ', ', $v->get_attributes() );
            $this->stats['retired']++;
            $changes[] = [ 'field' => 'retired', 'variation' => $label, 'from' => 'enabled', 'to' => 'disabled' ];
            $this->logger->warning( sprintf( '    ✂ Retire option #%d "%s" (stock %s) — not in Square item "%s"', $v->get_id(), $label, (string) $v->get_stock_quantity(), $sq_product['name'] ) );
            if ( $this->dry_run ) {
                continue;
            }
            if ( $live === 0 && empty( $sq_product['variations'] ) ) {
                break; // Never empty a listing when Square has nothing to replace it with.
            }
            $id = $v->get_id();
            $v->set_status( 'private' );
            $v->save();
            update_post_meta( $id, '_manage_stock', 'yes' );
            update_post_meta( $id, '_stock', 0 );
            update_post_meta( $id, '_stock_status', 'outofstock' );
            delete_post_meta( $id, '_square_variation_id' );
            update_post_meta( $id, '_sws_retired', gmdate( 'c' ) . ' not in Square item ' . $sq_product['square_id'] );
            wp_cache_delete( $id, 'post_meta' );
            wc_delete_product_transients( $id );
        }
        return $changes;
    }

    /**
     * Square-truth: make a variation's option label read exactly like Square's variation name.
     * Only for products with a single variation attribute. Taxonomy attributes get (or reuse) a term.
     *
     * @return array|null change record
     */
    private function relabel_variation( $wc_product, $wc_var, string $sq_label ) {
        $sq_label = trim( $sq_label );
        if ( $sq_label === '' || strtolower( $sq_label ) === 'regular' ) {
            return null;
        }
        $var_attrs = array_values( array_filter( $wc_product->get_attributes(), function( $a ) { return $a->get_variation(); } ) );
        if ( count( $var_attrs ) !== 1 ) {
            return null;
        }
        $attr = $var_attrs[0];
        $name = $attr->get_name();
        $key  = 'attribute_' . sanitize_title( $name );
        $cur  = (string) get_post_meta( $wc_var->get_id(), $key, true );
        if ( $attr->is_taxonomy() ) {
            $term  = $cur !== '' ? get_term_by( 'slug', $cur, $name ) : false;
            $label = $term ? $term->name : $cur;
            if ( strtolower( trim( html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ) ) ) === strtolower( $sq_label ) ) {
                return null;
            }
            $this->stats['relabeled']++;
            $this->logger->info( sprintf( '      ✎ Option: "%s" → "%s"', $label, $sq_label ) );
            if ( ! $this->dry_run ) {
                $new = get_term_by( 'name', $sq_label, $name );
                if ( ! $new ) {
                    $res = wp_insert_term( $sq_label, $name );
                    if ( is_wp_error( $res ) ) {
                        $this->logger->warning( '      ⚠ Could not create term: ' . $res->get_error_message() );
                        return null;
                    }
                    $new = get_term( $res['term_id'], $name );
                }
                wp_set_object_terms( $wc_product->get_id(), (int) $new->term_id, $name, true );
                $opts = array_map( 'intval', (array) $attr->get_options() );
                if ( ! in_array( (int) $new->term_id, $opts, true ) ) {
                    $opts[] = (int) $new->term_id;
                    $attr->set_options( $opts );
                    $this->save_attribute( $wc_product, $attr );
                }
                update_post_meta( $wc_var->get_id(), $key, $new->slug );
                $this->pending_variation_attrs[ $wc_var->get_id() ][ $key ] = $new->slug;
            }
            return [ 'field' => 'option', 'variation' => $sq_label, 'from' => $label, 'to' => $sq_label ];
        }
        if ( $cur === $sq_label ) {
            return null;
        }
        $this->stats['relabeled']++;
        $this->logger->info( sprintf( '      ✎ Option: "%s" → "%s"', $cur, $sq_label ) );
        if ( ! $this->dry_run ) {
            $opts = (array) $attr->get_options();
            if ( ! in_array( $sq_label, $opts, true ) ) {
                $opts[] = $sq_label;
                $attr->set_options( $opts );
                $this->save_attribute( $wc_product, $attr );
            }
            update_post_meta( $wc_var->get_id(), $key, $sq_label );
            $this->pending_variation_attrs[ $wc_var->get_id() ][ $key ] = $sq_label;
        }
        return [ 'field' => 'option', 'variation' => $sq_label, 'from' => $cur, 'to' => $sq_label ];
    }

    private function save_attribute( $wc_product, $attr ) {
        $all = $wc_product->get_attributes();
        foreach ( $all as $k => $a ) {
            if ( $a->get_name() === $attr->get_name() ) {
                $all[ $k ] = $attr;
            }
        }
        $wc_product->set_attributes( $all );
    }

    /**
     * Square-truth: Square's SKU belongs to its variation. If another post holds it and isn't a live
     * listing linked to that same Square variation (a retired option, an unlinked duplicate, or one
     * whose own Square SKU differs), clear it there so it can be set here.
     */
    private function take_over_sku( string $sku, string $sq_var_id, int $owner_id ) : bool {
        if ( ! $this->square_truth || $sku === '' ) {
            return false;
        }
        $holder = (int) wc_get_product_id_by_sku( $sku );
        if ( ! $holder || $holder === $owner_id ) {
            return true;
        }
        if ( (string) get_post_meta( $holder, '_square_variation_id', true ) === $sq_var_id && get_post_status( $holder ) === 'publish' ) {
            return false; // Genuinely linked twice — leave for the exclusivity pass.
        }
        $h = wc_get_product( $holder );
        if ( ! $h ) {
            return false;
        }
        $h->set_sku( '' );
        $h->save();
        $this->stats['sku_taken']++;
        $this->logger->warning( sprintf( '      ⇄ SKU "%s" moved from #%d to #%d (Square assigns it to this item)', $sku, $holder, $owner_id ) );
        return true;
    }

    /** Creation gate: the stored category filter (if any) decides which NEW products are created. */
    private function category_allows_creation( array $sq_product ) : bool {
        $filters = (array) get_option( 'sws_batch_create_filter', [] );
        if ( empty( $filters ) ) {
            return true;
        }
        $cats = (array) ( $sq_product['categories'] ?? [] );
        if ( in_array( 'Uncategorized', $filters, true ) && empty( $cats ) ) {
            return true;
        }
        return (bool) array_intersect( $filters, $cats );
    }

    /**
     * Square-truth, end of a FULL run: live listings with no Square link at all, whose SKUs are not
     * in Square and whose name doesn't describe any Square item, are set out of stock (kept published,
     * marked _sws_not_in_square). Listings that look like a Square item but didn't match are only reported.
     */
    private function truth_retire_unlisted( array $square_products ) {
        if ( ! $this->square_truth || $this->stats['errors'] > 0 ) {
            return;
        }
        global $wpdb;
        $skus  = [];
        $names = [];
        foreach ( $square_products as $sp ) {
            $names[] = $sp['name'];
            foreach ( $sp['variations'] as $sv ) {
                if ( ! empty( $sv['sku'] ) ) {
                    $skus[ trim( $sv['sku'] ) ] = true;
                }
            }
        }
        $ids = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             WHERE p.post_type = 'product' AND p.post_status IN ('publish','private')
               AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key IN ('_square_product_id','_square_variation_id') AND m.meta_value <> '' )
               AND NOT EXISTS ( SELECT 1 FROM {$wpdb->posts} c JOIN {$wpdb->postmeta} cm ON cm.post_id = c.ID
                                WHERE c.post_parent = p.ID AND c.post_type = 'product_variation' AND c.post_status = 'publish'
                                  AND cm.meta_key = '_square_variation_id' AND cm.meta_value <> '' )"
        );
        foreach ( $ids as $pid ) {
            $p = wc_get_product( $pid );
            if ( ! $p ) {
                continue;
            }
            $units = $p->is_type( 'variable' ) ? array_filter( array_map( 'wc_get_product', $p->get_children() ) ) : [ $p ];
            $sku_hit = false;
            $in_stock = false;
            foreach ( $units as $u ) {
                if ( $u->get_sku() !== '' && isset( $skus[ $u->get_sku() ] ) ) {
                    $sku_hit = true;
                }
                if ( $u->get_stock_status() === 'instock' ) {
                    $in_stock = true;
                }
            }
            $looks_like = false;
            foreach ( $names as $n ) {
                if ( SWS_Product_Matcher::names_compatible( $n, $p->get_name() ) ) {
                    $looks_like = $n;
                    break;
                }
            }
            if ( $sku_hit || $looks_like ) {
                $this->logger->warning( sprintf( '  ⚑ #%d "%s" is not linked but looks like Square "%s" — review', $pid, $p->get_name(), $looks_like ?: 'SKU match' ) );
                continue;
            }
            if ( ! $in_stock ) {
                continue;
            }
            $this->stats['not_in_square']++;
            $this->logger->warning( sprintf( '  ✂ #%d "%s" is not in Square — set out of stock', $pid, $p->get_name() ) );
            if ( $this->dry_run ) {
                continue;
            }
            foreach ( $units as $u ) {
                update_post_meta( $u->get_id(), '_manage_stock', 'yes' );
                update_post_meta( $u->get_id(), '_stock', 0 );
                update_post_meta( $u->get_id(), '_stock_status', 'outofstock' );
                wp_cache_delete( $u->get_id(), 'post_meta' );
            }
            update_post_meta( $pid, '_sws_not_in_square', gmdate( 'c' ) );
            if ( $p->is_type( 'variable' ) ) {
                WC_Product_Variable::sync_stock_status( $pid );
            } else {
                update_post_meta( $pid, '_stock_status', 'outofstock' );
            }
            wc_delete_product_transients( $pid );
        }
    }

    private function create_missing_variations( $wc_product, array $sq_product, array $missing_vars ) {
        $changes    = [];
        $product_id = $wc_product->get_id();

        // CLEANUP: If a prior sync created a redundant "Option" attribute alongside
        // an existing attribute like "Flavors", merge them now before proceeding.
        if ( $this->cleanup_redundant_option_attr( $wc_product ) ) {
            // Re-load the product to get the cleaned-up attributes.
            $wc_product = wc_get_product( $product_id );
        }

        // AI: extract attribute values from SKUs when variation names are generic.
        $sq_product['variations'] = $this->ai->extract_attributes_from_skus(
            $sq_product['variations'],
            $sq_product['name']
        );

        // Build generic option labels (option_values dimensions → option value lists).
        $option_labels = $this->extract_attribute_options( $sq_product['variations'] );

        // CRITICAL: Map generic dimension names to the product's ACTUAL attribute names.
        // extract_attribute_options() uses placeholder names like "Option", "Size", etc.
        // If the product already has attributes (e.g., "Flavor"), we must use those real
        // names so the new variations get the correct attribute key (attribute_flavor, not
        // attribute_option) and appear under the right attribute in the admin.
        $existing_attrs = $wc_product->get_attributes();

        // Determine the real attribute names by checking what existing variations actually
        // use. This is more reliable than is_variation flag which may not be set on older
        // products.  e.g. if existing variations have 'attribute_flavor' meta, we know the
        // real attribute is "Flavor" regardless of whether the flag is checked.
        $real_var_attrs = $this->detect_variation_attrs_from_children( $wc_product, $existing_attrs );

        $option_labels = $this->remap_option_labels_to_product_attrs( $option_labels, $real_var_attrs );

        $attrs_changed = false;

        foreach ( $option_labels as $attr_name => $options ) {
            $slug  = sanitize_title( $attr_name );
            $found = false;

            foreach ( $existing_attrs as $key => $attr ) {
                if ( $attr->get_name() === $attr_name || sanitize_title( $attr->get_name() ) === $slug ) {
                    if ( ! $attr->is_taxonomy() ) {
                        // Non-taxonomy attribute: add any new option values as plain text.
                        $existing_options = $attr->get_options();
                        $new_options      = array_unique( array_merge( $existing_options, $options ) );
                        if ( count( $new_options ) !== count( $existing_options ) ) {
                            $attr->set_options( $new_options );
                            $attr->set_variation( true );
                            $attrs_changed = true;
                            $this->logger->info( sprintf(
                                '    + Updated attribute "%s" with %d new option(s)',
                                $attr_name, count( $new_options ) - count( $existing_options )
                            ) );
                        }
                    } else {
                        // Taxonomy attribute: ensure each option value exists as a term.
                        $added = 0;
                        foreach ( $options as $option_value ) {
                            if ( ! term_exists( $option_value, $attr_name ) ) {
                                wp_insert_term( $option_value, $attr_name );
                                $added++;
                            }
                        }
                        if ( $added ) {
                            $this->logger->info( sprintf(
                                '    + Added %d new term(s) to taxonomy "%s"', $added, $attr_name
                            ) );
                        }
                    }
                    $found = true;
                    break;
                }
            }

            if ( ! $found ) {
                // Check if the product already has ANY variation attribute we can use
                // instead of creating a new generic one.  Only create a brand-new
                // attribute if the product has NO existing attributes at all.
                $generic_names = [ 'option', 'options', 'size', 'color', 'style', 'type' ];
                $is_generic    = in_array( sanitize_title( $attr_name ), $generic_names, true );

                if ( $is_generic && ! empty( $existing_attrs ) ) {
                    // Find the first existing non-taxonomy variation attribute to absorb values.
                    $target_attr = null;
                    foreach ( $existing_attrs as $attr ) {
                        if ( ! $attr->is_taxonomy() ) {
                            $target_attr = $attr;
                            break;
                        }
                    }
                    if ( $target_attr ) {
                        $existing_options = $target_attr->get_options();
                        $new_options      = array_unique( array_merge( $existing_options, $options ) );
                        $target_attr->set_options( $new_options );
                        $target_attr->set_variation( true );
                        $attrs_changed = true;

                        // Rewrite $option_labels so build_variation_attributes() uses the right key.
                        $real_name = $target_attr->get_name();
                        if ( $real_name !== $attr_name ) {
                            unset( $option_labels[ $attr_name ] );
                            $option_labels[ $real_name ] = $options;
                        }

                        $this->logger->info( sprintf(
                            '    + Merged %d option(s) into existing attribute "%s" (skipped creating "%s")',
                            count( $options ), $real_name, $attr_name
                        ) );
                    } else {
                        // Only taxonomy attrs exist — create new attribute as fallback.
                        $attribute = new WC_Product_Attribute();
                        $attribute->set_name( $attr_name );
                        $attribute->set_options( $options );
                        $attribute->set_visible( true );
                        $attribute->set_variation( true );
                        $existing_attrs[] = $attribute;
                        $attrs_changed    = true;
                        $this->logger->info( sprintf(
                            '    + Created attribute "%s" with %d option(s): %s',
                            $attr_name, count( $options ), implode( ', ', $options )
                        ) );
                    }
                } else {
                    // Product has no existing attributes, or the attribute name is
                    // non-generic (specific) — create it.
                    $attribute = new WC_Product_Attribute();
                    $attribute->set_name( $attr_name );
                    $attribute->set_options( $options );
                    $attribute->set_visible( true );
                    $attribute->set_variation( true );
                    $existing_attrs[] = $attribute;
                    $attrs_changed    = true;
                    $this->logger->info( sprintf(
                        '    + Created attribute "%s" with %d option(s): %s',
                        $attr_name, count( $options ), implode( ', ', $options )
                    ) );
                }
            }
        }

        if ( $attrs_changed ) {
            $wc_product->set_attributes( $existing_attrs );
            $wc_product->save();
        }

        foreach ( $missing_vars as $sq_var ) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id( $product_id );

            if ( ! empty( $sq_var['sku'] ) ) {
                // Pass 0 as owner_id: the variation is brand-new (no post ID yet),
                // so any existing holder of this SKU is a different product/variation.
                if ( $this->sku_is_available( $sq_var['sku'], 0 ) ) {
                    $variation->set_sku( $sq_var['sku'] );
                } else {
                    $this->logger->warning( sprintf( '      ⚠ SKU "%s" already in use — new variation created without SKU', $sq_var['sku'] ) );
                }
            }

            if ( $sq_var['price'] !== null ) {
                $variation->set_regular_price( number_format( $sq_var['price'], 2, '.', '' ) );
                $variation->set_price( number_format( $sq_var['price'], 2, '.', '' ) );
            }

            $qty = (int) ( $sq_var['quantity'] ?? 0 );
            $variation->set_manage_stock( true );
            $variation->set_stock_quantity( $qty );
            $variation->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
            $variation->set_status( 'publish' );

            $var_attrs = $this->build_variation_attributes( $sq_var, $option_labels, $existing_attrs );
            $variation->set_attributes( $var_attrs );

            $this->logger->info( sprintf(
                '      [ATTR-DEBUG] Variation "%s" attrs: %s',
                $sq_var['name'], wp_json_encode( $var_attrs )
            ) );

            $variation->update_meta_data( '_square_variation_id', $sq_var['square_variation_id'] );
            $var_id = $variation->save();

            if ( $var_id ) {
                $this->created_var_ids[] = (int) $var_id;
                // Write ALL values AFTER save() to prevent hook interference during save()
                // from reverting them (same pattern used for stock/price on existing variations).

                // Variation attributes: write directly so WC internal hooks that run during
                // save() cannot clear them — this is what caused "Any Flavor" when set_attributes()
                // was called before save() and a WC hook wiped the values during the save.
                foreach ( $var_attrs as $attr_meta_key => $attr_meta_val ) {
                    update_post_meta( $var_id, $attr_meta_key, $attr_meta_val );
                }

                // Also queue for deferred write — applied AFTER the final parent product save
                // in update_existing_product(), so even if the parent save triggers WC hooks
                // that wipe variation attributes, they get re-applied at the very end.
                if ( ! empty( $var_attrs ) ) {
                    $this->pending_variation_attrs[ $var_id ] = $var_attrs;
                }

                // Stock / price.
                update_post_meta( $var_id, '_manage_stock', 'yes' );
                update_post_meta( $var_id, '_stock', $qty );
                update_post_meta( $var_id, '_stock_status', $qty > 0 ? 'instock' : 'outofstock' );
                if ( $sq_var['price'] !== null ) {
                    $price_str = number_format( $sq_var['price'], 2, '.', '' );
                    update_post_meta( $var_id, '_regular_price', $price_str );
                    update_post_meta( $var_id, '_price', $price_str );
                }
                wp_cache_delete( $var_id, 'post_meta' );
                wc_delete_product_transients( $var_id );

                $changes[] = [
                    'field'     => 'new_variation',
                    'variation' => $sq_var['name'],
                    'from'      => '',
                    'to'        => sprintf( '#%d', $var_id ),
                ];

                $this->logger->info( sprintf(
                    '      + Created Variation #%d "%s": SKU=%s, Price=$%s, Stock=%d',
                    $var_id, $sq_var['name'], $sq_var['sku'] ?: '(none)',
                    $sq_var['price'] !== null ? number_format( $sq_var['price'], 2, '.', '' ) : '(none)',
                    $qty
                ) );
            } else {
                $this->logger->error( sprintf(
                    '      ✗ Failed to create variation "%s" for Product #%d',
                    $sq_var['name'], $product_id
                ) );
            }
        }

        // Refresh the parent variable product's price cache from children.
        $variable = wc_get_product( $product_id );
        if ( $variable && $variable->is_type( 'variable' ) ) {
            $variable->get_data_store()->sync_price( $variable );
            wc_delete_product_transients( $product_id );
        }

        $this->logger->info( sprintf(
            '    ✓ Created %d missing variation(s) for Product #%d',
            count( $missing_vars ), $product_id
        ) );

        return $changes;
    }

    /**
     * Clean up a product that has BOTH a generic "Option" attribute AND a real
     * attribute (e.g. "Flavor", "Flavors").  Merges "Option" values into the real
     * attribute, removes "Option", and rewrites all child variation meta keys.
     *
     * This fixes products damaged by earlier sync runs that created "Option" instead
     * of using the existing attribute.
     */
    private function cleanup_redundant_option_attr( $wc_product ) {
        $existing_attrs = $wc_product->get_attributes();
        if ( count( $existing_attrs ) < 2 ) {
            return false; // Need at least 2 attributes for there to be a redundant one.
        }

        $generic_slugs = [ 'option', 'options' ];
        $option_attr   = null;
        $option_key    = null;
        $real_attr     = null;
        $real_key      = null;

        foreach ( $existing_attrs as $key => $attr ) {
            $slug = sanitize_title( $attr->get_name() );
            if ( in_array( $slug, $generic_slugs, true ) ) {
                $option_attr = $attr;
                $option_key  = $key;
            } else {
                // Pick the first non-generic attribute as the real one.
                if ( $real_attr === null ) {
                    $real_attr = $attr;
                    $real_key  = $key;
                }
            }
        }

        if ( ! $option_attr || ! $real_attr ) {
            return false; // No duplicate situation.
        }

        $product_id  = $wc_product->get_id();
        $real_slug   = sanitize_title( $real_attr->get_name() );
        $option_slug = sanitize_title( $option_attr->get_name() );

        // 1. Merge "Option" values into the real attribute.
        $real_options   = $real_attr->get_options();
        $option_options = $option_attr->get_options();
        $merged         = array_values( array_unique( array_merge( $real_options, $option_options ) ) );
        $real_attr->set_options( $merged );
        $real_attr->set_variation( true );

        // 2. Remove "Option" attribute from the product.
        $new_attrs = [];
        foreach ( $existing_attrs as $key => $attr ) {
            $slug = sanitize_title( $attr->get_name() );
            if ( ! in_array( $slug, $generic_slugs, true ) ) {
                $new_attrs[ $key ] = $attr;
            }
        }
        $wc_product->set_attributes( $new_attrs );
        $wc_product->save();

        // 3. Rewrite child variation meta: attribute_option → attribute_{real_slug}.
        $children      = $wc_product->get_children();
        $rewritten     = 0;
        foreach ( $children as $child_id ) {
            $old_val = get_post_meta( $child_id, 'attribute_' . $option_slug, true );
            if ( $old_val !== '' && $old_val !== false ) {
                update_post_meta( $child_id, 'attribute_' . $real_slug, $old_val );
                delete_post_meta( $child_id, 'attribute_' . $option_slug );
                wp_cache_delete( $child_id, 'post_meta' );
                $rewritten++;
            }
        }

        // 4. Fix _product_attributes raw meta directly to be safe.
        $raw = get_post_meta( $product_id, '_product_attributes', true );
        if ( is_array( $raw ) && isset( $raw[ $option_slug ] ) ) {
            if ( isset( $raw[ $real_slug ] ) ) {
                $existing_vals = array_filter( array_map( 'trim', explode( ' | ', $raw[ $real_slug ]['value'] ?? '' ) ) );
                $option_vals   = array_filter( array_map( 'trim', explode( ' | ', $raw[ $option_slug ]['value'] ?? '' ) ) );
                $raw[ $real_slug ]['value']        = implode( ' | ', array_unique( array_merge( $existing_vals, $option_vals ) ) );
                $raw[ $real_slug ]['is_variation']  = 1;
            }
            unset( $raw[ $option_slug ] );
            update_post_meta( $product_id, '_product_attributes', $raw );
            wp_cache_delete( $product_id, 'post_meta' );
        }

        wc_delete_product_transients( $product_id );

        $this->logger->info( sprintf(
            '  [CLEANUP] Merged "%s" attribute into "%s" (%d values), rewrote %d variation meta keys, removed "%s"',
            $option_attr->get_name(), $real_attr->get_name(), count( $merged ), $rewritten, $option_attr->get_name()
        ) );

        return true;
    }

    /**
     * Detect the REAL variation attributes by inspecting what existing child
     * variations actually use (attribute_* post meta), not just the is_variation flag.
     *
     * Older products may have an attribute called "Flavor" without the is_variation
     * flag checked, yet their variations still use attribute_flavor.  This method
     * reads the actual meta keys from children to determine the truth.
     *
     * Returns an ordered array of WC_Product_Attribute that variations use.
     * Falls back to is_variation-flagged attrs, then ALL attrs.
     */
    private function detect_variation_attrs_from_children( $wc_product, $existing_attrs ) {
        // Generic attribute slugs that should be deprioritized if a real one exists.
        $generic_slugs = [ 'option', 'options', 'size', 'color', 'style', 'type' ];

        // Strategy 1: Read attribute_* meta keys from existing child variations.
        $children = $wc_product->get_children();
        if ( ! empty( $children ) ) {
            // Sample up to 5 children to find which attribute_* keys they use.
            $sample_ids = array_slice( $children, 0, 5 );
            $attr_slugs_used = [];
            foreach ( $sample_ids as $child_id ) {
                $child_meta = get_post_meta( $child_id );
                foreach ( $child_meta as $meta_key => $meta_val ) {
                    if ( strpos( $meta_key, 'attribute_' ) === 0 && $meta_key !== 'attribute_' ) {
                        $slug = str_replace( 'attribute_', '', $meta_key );
                        $attr_slugs_used[ $slug ] = true;
                    }
                }
            }

            if ( ! empty( $attr_slugs_used ) ) {
                // If we have both a generic slug (option) and a real one (flavor),
                // drop the generic — it was incorrectly created by a prior sync.
                $has_real = false;
                foreach ( $attr_slugs_used as $slug => $_true ) {
                    if ( ! in_array( $slug, $generic_slugs, true ) ) {
                        $has_real = true;
                        break;
                    }
                }
                if ( $has_real ) {
                    foreach ( $generic_slugs as $gs ) {
                        unset( $attr_slugs_used[ $gs ] );
                    }
                }

                // Match these slugs to the product's existing attributes.
                $matched = [];
                foreach ( $attr_slugs_used as $slug => $_true ) {
                    foreach ( $existing_attrs as $attr ) {
                        if ( sanitize_title( $attr->get_name() ) === $slug ) {
                            // Ensure it's marked for variations (fix older products).
                            $attr->set_variation( true );
                            $matched[] = $attr;
                            break;
                        }
                    }
                }
                if ( ! empty( $matched ) ) {
                    $this->logger->info( sprintf(
                        '    [ATTR] Detected variation attributes from children: %s',
                        implode( ', ', array_map( function( $a ) { return $a->get_name(); }, $matched ) )
                    ) );
                    return $matched;
                }
            }
        }

        // Strategy 2: Fall back to attributes flagged with is_variation.
        // Prefer non-generic names if available.
        $variation_attrs = array_values( array_filter( $existing_attrs, function( $a ) {
            return $a->get_variation();
        } ) );
        if ( ! empty( $variation_attrs ) ) {
            // If there's a mix of generic and real, only return real ones.
            $real_only = array_values( array_filter( $variation_attrs, function( $a ) use ( $generic_slugs ) {
                return ! in_array( sanitize_title( $a->get_name() ), $generic_slugs, true );
            } ) );
            return ! empty( $real_only ) ? $real_only : $variation_attrs;
        }

        // Strategy 3: Fall back to ANY attributes on the product.
        // This handles products where "Flavor" exists but is_variation was never checked.
        $all_attrs = array_values( $existing_attrs );
        if ( ! empty( $all_attrs ) ) {
            // Prefer non-generic attributes.
            $real_only = array_values( array_filter( $all_attrs, function( $a ) use ( $generic_slugs ) {
                return ! in_array( sanitize_title( $a->get_name() ), $generic_slugs, true );
            } ) );
            $result = ! empty( $real_only ) ? $real_only : $all_attrs;

            $this->logger->info( sprintf(
                '    [ATTR] No variation-flagged attributes found; falling back to: %s',
                implode( ', ', array_map( function( $a ) { return $a->get_name(); }, $result ) )
            ) );
            // Mark them for variations since we're about to create variations under them.
            foreach ( $result as $attr ) {
                $attr->set_variation( true );
            }
            return $result;
        }

        return $all_attrs;
    }

    /**
     * Remap the generic dimension names from extract_attribute_options() to the
     * actual attribute names that already exist on the product.
     *
     * extract_attribute_options() uses placeholder names ("Option", "Size", etc.)
     * because Square variations don't carry attribute names — only values.  When the
     * product already has variation-enabled attributes we use those instead, matching
     * by position (first Square dimension → first product variation attribute, etc.).
     *
     * @param  array $option_labels  ['Option' => ['Miami Mint', ...], ...]
     * @param  array $product_attrs  Ordered array of WC_Product_Attribute (variation=true).
     * @return array                 Same shape but with real attribute names.
     */
    private function remap_option_labels_to_product_attrs( array $option_labels, array $product_attrs ) {
        if ( empty( $product_attrs ) ) {
            return $option_labels; // No existing variation attributes — keep generic names.
        }

        $remapped   = [];
        $dimensions = array_values( $option_labels );
        foreach ( $dimensions as $i => $options ) {
            if ( isset( $product_attrs[ $i ] ) ) {
                $real_name = $product_attrs[ $i ]->get_name();
            } else {
                // More Square dimensions than product attributes — use generic fallback.
                $generic_names = [ 'Option', 'Size', 'Color', 'Style', 'Type' ];
                $real_name     = $generic_names[ $i ] ?? 'Option ' . ( $i + 1 );
            }
            $remapped[ $real_name ] = $options;
        }

        return $remapped;
    }

    private function convert_simple_to_variable( $wc_product, array $sq_product ) {
        $product_id = $wc_product->get_id();

        $old_sku   = $wc_product->get_sku();
        $old_name  = $wc_product->get_name();
        $old_desc  = $wc_product->get_description();
        $old_short = $wc_product->get_short_description();
        $old_cats  = $wc_product->get_category_ids();
        $old_img   = $wc_product->get_image_id();
        $old_status = $wc_product->get_status();

        if ( $old_sku ) {
            $wc_product->set_sku( '' );
            $wc_product->save();
        }

        global $wpdb;
        $wpdb->update( $wpdb->posts, [ 'post_type' => 'product' ], [ 'ID' => $product_id ] );

        clean_post_cache( $product_id );
        wc_delete_product_transients( $product_id );
        wp_set_object_terms( $product_id, 'variable', 'product_type' );

        $variable = new WC_Product_Variable( $product_id );
        $variable->set_name( $old_name );
        $variable->set_description( $old_desc );
        $variable->set_short_description( $old_short );
        $variable->set_category_ids( $old_cats );
        $variable->set_status( $old_status );
        if ( $old_img ) {
            $variable->set_image_id( $old_img );
        }
        $variable->update_meta_data( '_square_product_id', $sq_product['square_id'] );

        // AI: extract attribute values from SKUs when variation names are generic.
        $sq_product['variations'] = $this->ai->extract_attributes_from_skus(
            $sq_product['variations'],
            $sq_product['name']
        );

        $option_labels = $this->extract_attribute_options( $sq_product['variations'] );

        // AI: detect proper attribute names (e.g., "Flavor" instead of generic "Option").
        $option_labels = $this->ai->detect_attribute_labels( $option_labels, $sq_product['name'] );

        $attributes = [];
        foreach ( $option_labels as $attr_name => $options ) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_name( $attr_name );
            $attribute->set_options( $options );
            $attribute->set_visible( true );
            $attribute->set_variation( true );
            $attributes[] = $attribute;
        }
        $variable->set_attributes( $attributes );
        $variable->save();

        $this->logger->info( sprintf(
            '    ✓ Converted Product #%d from Simple to Variable with %d attribute(s): %s',
            $product_id, count( $attributes ),
            implode( ', ', array_keys( $option_labels ) )
        ));

        return $variable;
    }

    private function is_square_product_out_of_stock( array $sq_product ) {
        if ( empty( $sq_product['variations'] ) ) {
            return true;
        }
        $total_qty = 0;
        foreach ( $sq_product['variations'] as $var ) {
            $total_qty += (int) ( $var['quantity'] ?? 0 );
        }
        return $total_qty <= 0;
    }

    private function create_new_product( array $sq_product ) {
        $this->logger->info( sprintf( '  + Creating new WC product: "%s"', $sq_product['name'] ) );

        if ( $this->dry_run ) {
            $this->logger->info( '    [DRY RUN] Would create product.' );
            $this->stats['created']++;
            return null;
        }

        $is_variable = count( $sq_product['variations'] ) > 1 ||
                       ( count( $sq_product['variations'] ) === 1 && $sq_product['variations'][0]['name'] !== 'Regular' );

        $descriptions = [ 'description' => '', 'short_description' => '' ];
        if ( $this->ai_generate_desc ) {
            $this->logger->info( '    Generating AI description...' );
            $ai_result = $this->ai->generate_product_description( $sq_product );
            if ( is_array( $ai_result ) ) {
                $descriptions = $ai_result;
            } else {
                $descriptions['description'] = $ai_result;
            }
        }

        $product_id = null;
        if ( $is_variable ) {
            $product_id = $this->create_variable_product( $sq_product, $descriptions );
        } else {
            $product_id = $this->create_simple_product( $sq_product, $descriptions );
        }

        $this->stats['created']++;
        return $product_id;
    }

    private function create_simple_product( array $sq_product, $descriptions ) {
        $product = new WC_Product_Simple();
        $sq_var  = $sq_product['variations'][0];

        $desc       = is_array( $descriptions ) ? ( $descriptions['description'] ?? '' ) : $descriptions;
        $short_desc = is_array( $descriptions ) ? ( $descriptions['short_description'] ?? '' ) : '';

        $product->set_name( $sq_product['name'] );
        $product->set_description( $desc ?: $sq_product['description'] );
        $product->set_short_description( $short_desc );
        $product->set_status( 'draft' );

        if ( ! empty( $sq_var['sku'] ) ) {
            if ( $this->sku_is_available( $sq_var['sku'] ) ) {
                $product->set_sku( $sq_var['sku'] );
            } else {
                $this->logger->warning( sprintf( '    ⚠ SKU "%s" already in use — new simple product created without SKU', $sq_var['sku'] ) );
            }
        }

        if ( $sq_var['price'] !== null ) {
            $product->set_regular_price( number_format( $sq_var['price'], 2, '.', '' ) );
        }

        if ( $this->sync_stock ) {
            $product->set_manage_stock( true );
            $product->set_stock_quantity( $sq_var['quantity'] );
            $product->set_stock_status( $sq_var['quantity'] > 0 ? 'instock' : 'outofstock' );
        }

        $cat_ids = $this->get_or_create_categories( $sq_product['categories'] );
        if ( $cat_ids ) {
            $product->set_category_ids( $cat_ids );
        }

        $product->update_meta_data( '_square_product_id', $sq_product['square_id'] );
        $product->update_meta_data( '_square_variation_id', $sq_var['square_variation_id'] );

        $product_id = $product->save();

        if ( ! empty( $sq_product['image_url'] ) ) {
            $this->attach_image( $product_id, $sq_product['image_url'], $sq_product['name'] );
        }

        $this->logger->info( sprintf( '    ✓ Created Simple Product #%d: "%s"', $product_id, $sq_product['name'] ) );
        return $product_id;
    }

    private function create_variable_product( array $sq_product, $descriptions ) {
        $product = new WC_Product_Variable();

        $desc       = is_array( $descriptions ) ? ( $descriptions['description'] ?? '' ) : $descriptions;
        $short_desc = is_array( $descriptions ) ? ( $descriptions['short_description'] ?? '' ) : '';

        $product->set_name( $sq_product['name'] );
        $product->set_description( $desc ?: $sq_product['description'] );
        $product->set_short_description( $short_desc );
        $product->set_status( 'draft' );

        $cat_ids = $this->get_or_create_categories( $sq_product['categories'] );
        if ( $cat_ids ) {
            $product->set_category_ids( $cat_ids );
        }

        $product->update_meta_data( '_square_product_id', $sq_product['square_id'] );

        $product_id = $product->save();

        // AI: extract attribute values from SKUs when variation names are generic.
        $sq_product['variations'] = $this->ai->extract_attributes_from_skus(
            $sq_product['variations'],
            $sq_product['name']
        );

        $option_labels = $this->extract_attribute_options( $sq_product['variations'] );

        // AI: detect proper attribute names (e.g., "Flavor" instead of generic "Option").
        $option_labels = $this->ai->detect_attribute_labels( $option_labels, $sq_product['name'] );
        $this->logger->info( sprintf(
            '    AI detected attribute labels: %s',
            implode( ', ', array_map( function( $k, $v ) {
                return sprintf( '"%s" (%d values)', $k, count( $v ) );
            }, array_keys( $option_labels ), $option_labels ) )
        ) );

        $attributes = [];
        foreach ( $option_labels as $attr_name => $options ) {
            $attribute = new WC_Product_Attribute();
            $attribute->set_name( $attr_name );
            $attribute->set_options( $options );
            $attribute->set_visible( true );
            $attribute->set_variation( true );
            $attributes[] = $attribute;
        }
        $product->set_attributes( $attributes );
        $product->save();

        foreach ( $sq_product['variations'] as $sq_var ) {
            $variation = new WC_Product_Variation();
            $variation->set_parent_id( $product_id );

            if ( ! empty( $sq_var['sku'] ) ) {
                if ( $this->sku_is_available( $sq_var['sku'], $product_id ) ) {
                    $variation->set_sku( $sq_var['sku'] );
                } else {
                    $this->logger->warning( sprintf( '      ⚠ SKU "%s" already in use — variation created without SKU', $sq_var['sku'] ) );
                }
            }

            if ( $sq_var['price'] !== null ) {
                $variation->set_regular_price( number_format( $sq_var['price'], 2, '.', '' ) );
                $variation->set_price( number_format( $sq_var['price'], 2, '.', '' ) );
            }

            $qty = (int) ( $sq_var['quantity'] ?? 0 );
            $variation->set_manage_stock( true );
            $variation->set_stock_quantity( $qty );
            $variation->set_stock_status( $qty > 0 ? 'instock' : 'outofstock' );
            $variation->set_status( 'publish' );

            $var_attrs = $this->build_variation_attributes( $sq_var, $option_labels );
            $variation->set_attributes( $var_attrs );

            $this->logger->info( sprintf(
                '      [ATTR-DEBUG] Variation "%s" attrs: %s',
                $sq_var['name'], wp_json_encode( $var_attrs )
            ) );

            $variation->update_meta_data( '_square_variation_id', $sq_var['square_variation_id'] );
            $var_id = $variation->save();

            if ( $var_id ) {
                // Write variation attributes AFTER save() to prevent WC hooks
                // from wiping them during save() — same fix as create_missing_variations().
                foreach ( $var_attrs as $attr_meta_key => $attr_meta_val ) {
                    update_post_meta( $var_id, $attr_meta_key, $attr_meta_val );
                }

                // Queue for deferred final pass after all saves complete.
                if ( ! empty( $var_attrs ) ) {
                    $this->pending_variation_attrs[ $var_id ] = $var_attrs;
                }

                // Apply stock/price after save() to prevent hook reversion.
                update_post_meta( $var_id, '_manage_stock', 'yes' );
                update_post_meta( $var_id, '_stock', $qty );
                update_post_meta( $var_id, '_stock_status', $qty > 0 ? 'instock' : 'outofstock' );
                if ( $sq_var['price'] !== null ) {
                    $price_str = number_format( $sq_var['price'], 2, '.', '' );
                    update_post_meta( $var_id, '_regular_price', $price_str );
                    update_post_meta( $var_id, '_price', $price_str );
                }
                wp_cache_delete( $var_id, 'post_meta' );
                wc_delete_product_transients( $var_id );
            }

            $this->logger->info( sprintf(
                '      + Variation #%d "%s" [%s]: SKU=%s, Price=$%s, Stock=%d',
                $var_id, $sq_var['name'],
                implode( ', ', array_filter( $var_attrs ) ) ?: '(no attrs)',
                $sq_var['sku'] ?: '(none)',
                $sq_var['price'] !== null ? number_format( $sq_var['price'], 2, '.', '' ) : '(none)',
                $qty
            ) );
        }

        // Sync parent price range from children — may trigger hooks that save the parent.
        $product->get_data_store()->sync_price( $product );
        wc_delete_product_transients( $product_id );

        // Final pass: re-apply variation attributes + parent attribute options
        // after all saves to ensure no WC hook wiped them.
        if ( ! empty( $this->pending_variation_attrs ) ) {
            $this->apply_deferred_variation_attrs( $product_id );
        }

        if ( ! empty( $sq_product['image_url'] ) ) {
            $this->attach_image( $product_id, $sq_product['image_url'], $sq_product['name'] );
        }

        $this->logger->info( sprintf(
            '    ✓ Created Variable Product #%d: "%s" with %d variations',
            $product_id, $sq_product['name'], count( $sq_product['variations'] )
        ));

        return $product_id;
    }

    /**
     * Apply deferred variation attributes AFTER the final parent product save.
     *
     * Two things can go wrong during the normal save flow:
     * 1. Variation attribute meta (e.g. attribute_option = "grape") gets wiped by WC hooks.
     * 2. The parent product's _product_attributes option list gets overwritten by a second
     *    $wc_product->save(), losing values that create_missing_variations() added.
     *
     * This method fixes both by writing directly to the DB via update_post_meta,
     * completely bypassing WC's object model.
     */
    private function apply_deferred_variation_attrs( int $product_id ) {
        // Step 0: Build a key remap.  If pending attrs have 'attribute_option' but the
        // parent product's _product_attributes only has 'flavors', remap so we write to
        // the correct key.  This prevents the "Any Flavor" problem where WC looks for
        // attribute_flavors but we wrote attribute_option.
        $raw_attrs = get_post_meta( $product_id, '_product_attributes', true );
        $key_remap = []; // [ 'attribute_option' => 'attribute_flavors' ]
        $generic_slugs = [ 'option', 'options' ];

        if ( is_array( $raw_attrs ) ) {
            $parent_slugs = array_keys( $raw_attrs ); // e.g. ['flavors']

            // Collect all pending attribute meta keys.
            $pending_slugs = [];
            foreach ( $this->pending_variation_attrs as $var_id => $var_attrs ) {
                foreach ( $var_attrs as $attr_meta_key => $_ ) {
                    $slug = str_replace( 'attribute_', '', $attr_meta_key );
                    $pending_slugs[ $slug ] = $attr_meta_key;
                }
            }

            // For each pending slug that is NOT in the parent's _product_attributes,
            // find the correct parent slug to map to.
            foreach ( $pending_slugs as $slug => $meta_key ) {
                if ( ! isset( $raw_attrs[ $slug ] ) ) {
                    // Pending key doesn't match any parent attribute — remap it.
                    // If the pending key is generic (option/options) and the parent has
                    // a non-generic key, map to the first non-generic.
                    if ( in_array( $slug, $generic_slugs, true ) ) {
                        foreach ( $parent_slugs as $ps ) {
                            if ( ! in_array( $ps, $generic_slugs, true ) && empty( $raw_attrs[ $ps ]['is_taxonomy'] ) ) {
                                $key_remap[ $meta_key ] = 'attribute_' . $ps;
                                $this->logger->info( sprintf(
                                    '  [ATTR-FIX] Remapping "%s" → "attribute_%s" to match parent attribute',
                                    $meta_key, $ps
                                ) );
                                break;
                            }
                        }
                    } else {
                        // Non-generic slug not found — map to first parent attribute.
                        foreach ( $parent_slugs as $ps ) {
                            if ( empty( $raw_attrs[ $ps ]['is_taxonomy'] ) ) {
                                $key_remap[ $meta_key ] = 'attribute_' . $ps;
                                break;
                            }
                        }
                    }
                }
            }
        }

        // Apply the remap to pending_variation_attrs.
        if ( ! empty( $key_remap ) ) {
            foreach ( $this->pending_variation_attrs as $var_id => &$var_attrs ) {
                $remapped = [];
                foreach ( $var_attrs as $attr_meta_key => $attr_meta_val ) {
                    $new_key = $key_remap[ $attr_meta_key ] ?? $attr_meta_key;
                    $remapped[ $new_key ] = $attr_meta_val;
                }
                $var_attrs = $remapped;
            }
            unset( $var_attrs );
        }

        // Step 1: Collect ALL unique variation attribute values (after remap).
        $all_attr_values = []; // [ 'attribute_flavors' => [ 'grape', 'mint', ... ] ]
        foreach ( $this->pending_variation_attrs as $var_id => $var_attrs ) {
            foreach ( $var_attrs as $attr_meta_key => $attr_meta_val ) {
                if ( $attr_meta_val !== '' ) {
                    $all_attr_values[ $attr_meta_key ][] = $attr_meta_val;
                }
            }
        }

        // Step 2: Update the parent product's _product_attributes to include ALL values.
        if ( is_array( $raw_attrs ) ) {
            $parent_updated = false;
            foreach ( $all_attr_values as $attr_meta_key => $values ) {
                // 'attribute_flavors' → 'flavors'
                $attr_slug = str_replace( 'attribute_', '', $attr_meta_key );
                if ( isset( $raw_attrs[ $attr_slug ] ) && empty( $raw_attrs[ $attr_slug ]['is_taxonomy'] ) ) {
                    $existing_vals = array_filter( array_map( 'trim', explode( ' | ', $raw_attrs[ $attr_slug ]['value'] ?? '' ) ) );
                    $merged_vals   = array_values( array_unique( array_merge( $existing_vals, $values ) ) );
                    if ( count( $merged_vals ) !== count( $existing_vals ) ) {
                        $raw_attrs[ $attr_slug ]['value']        = implode( ' | ', $merged_vals );
                        $raw_attrs[ $attr_slug ]['is_variation'] = 1;
                        $parent_updated = true;
                        $this->logger->info( sprintf(
                            '  [ATTR-FIX] Parent attribute "%s": %d → %d values',
                            $attr_slug, count( $existing_vals ), count( $merged_vals )
                        ) );
                    }
                }
            }
            if ( $parent_updated ) {
                update_post_meta( $product_id, '_product_attributes', $raw_attrs );
                wp_cache_delete( $product_id, 'post_meta' );
                wc_delete_product_transients( $product_id );
                $this->logger->info( '  [ATTR-FIX] Wrote _product_attributes directly to DB' );
            }
        }

        // Step 3: Re-apply each variation's attribute meta.
        // Also clean up old generic meta keys if a remap occurred.
        $this->logger->info( sprintf(
            '  [ATTR-FIX] Writing attributes to %d variations',
            count( $this->pending_variation_attrs )
        ) );
        foreach ( $this->pending_variation_attrs as $var_id => $var_attrs ) {
            foreach ( $var_attrs as $attr_meta_key => $attr_meta_val ) {
                update_post_meta( $var_id, $attr_meta_key, $attr_meta_val );
            }
            // Remove old generic keys that were remapped.
            foreach ( $key_remap as $old_key => $new_key ) {
                if ( $old_key !== $new_key ) {
                    delete_post_meta( $var_id, $old_key );
                }
            }
            wp_cache_delete( $var_id, 'post_meta' );
        }
        $this->pending_variation_attrs = [];
    }

    private function extract_attribute_options( array $variations ) {
        $max_parts = 1;
        foreach ( $variations as $v ) {
            $parts = count( $v['option_values'] );
            if ( $parts > $max_parts ) $max_parts = $parts;
        }

        $default_names = [ 'Option', 'Size', 'Color', 'Style', 'Type' ];
        $result = [];

        for ( $i = 0; $i < $max_parts; $i++ ) {
            $attr_name = $default_names[ $i ] ?? "Option " . ( $i + 1 );
            $options   = [];
            foreach ( $variations as $v ) {
                $val = $v['option_values'][ $i ] ?? '';
                if ( $val && ! in_array( $val, $options ) ) {
                    $options[] = $val;
                }
            }
            if ( ! empty( $options ) ) {
                $result[ $attr_name ] = $options;
            }
        }

        return $result;
    }

    private function build_variation_attributes( array $sq_var, array $option_labels, array $existing_product_attrs = [] ) {
        $attr_names = array_keys( $option_labels );

        // Build a lookup: attribute name → WC_Product_Attribute (to know if it's taxonomy).
        $attr_lookup = [];
        foreach ( $existing_product_attrs as $pa ) {
            $attr_lookup[ $pa->get_name() ] = $pa;
        }

        $attrs = [];

        foreach ( $attr_names as $i => $attr_name ) {
            $slug  = sanitize_title( $attr_name );
            $value = $sq_var['option_values'][ $i ] ?? '';

            // For taxonomy attributes, store the term SLUG (not the display name).
            if ( isset( $attr_lookup[ $attr_name ] ) && $attr_lookup[ $attr_name ]->is_taxonomy() ) {
                $term = get_term_by( 'name', $value, $attr_name );
                if ( $term ) {
                    $value = $term->slug;
                } else {
                    $value = sanitize_title( $value );
                }
            }

            $attrs[ 'attribute_' . $slug ] = $value;
        }

        return $attrs;
    }

    /**
     * Check whether a SKU is safe to assign to the given product/variation ID.
     * Returns false if the SKU is already claimed by a DIFFERENT product or variation.
     * Pass $owner_id = 0 for brand-new (unsaved) products.
     */
    private function sku_is_available( string $sku, int $owner_id = 0 ) : bool {
        if ( $sku === '' ) return false;
        $existing_id = (int) wc_get_product_id_by_sku( $sku );
        if ( $existing_id === 0 ) return true;          // SKU not in use
        if ( $existing_id === $owner_id ) return true;  // Already owned by this exact post
        // NOTE: Do NOT allow sibling variations to share a SKU — WooCommerce requires
        // every variation to have a globally unique SKU.
        return false;
    }

    private function get_or_create_categories( array $names ) {
        $ids      = [];
        $mappings = get_option( 'sws_category_mapping', [] );
        if ( ! is_array( $mappings ) ) $mappings = [];

        // Build a lowercase index for case-insensitive mapping lookup
        $mappings_lower = [];
        foreach ( $mappings as $k => $v ) {
            $mappings_lower[ strtolower( trim( $k ) ) ] = $v;
        }

        foreach ( $names as $name ) {
            if ( empty( $name ) ) continue;

            // Try exact match first, then case-insensitive fallback
            $mapped_id = null;
            if ( isset( $mappings[ $name ] ) ) {
                $mapped_id = $mappings[ $name ];
            } elseif ( isset( $mappings_lower[ strtolower( trim( $name ) ) ] ) ) {
                $mapped_id = $mappings_lower[ strtolower( trim( $name ) ) ];
            }

            if ( $mapped_id !== null ) {
                $mapped_term = get_term( (int) $mapped_id, 'product_cat' );
                if ( $mapped_term && ! is_wp_error( $mapped_term ) ) {
                    $ids[] = $mapped_term->term_id;
                    $this->logger->info( sprintf( '[Sync] Mapped category "%s" → WC "%s" (#%d)', $name, $mapped_term->name, $mapped_term->term_id ) );
                    continue;
                }
            }

            // Search by exact name, then by slug (handles capitalization differences)
            $term = get_term_by( 'name', $name, 'product_cat' );
            if ( ! $term ) {
                $term = get_term_by( 'slug', sanitize_title( $name ), 'product_cat' );
            }
            if ( ! $term ) {
                $new = wp_insert_term( $name, 'product_cat' );
                if ( ! is_wp_error( $new ) ) {
                    $ids[] = $new['term_id'];
                    $this->logger->info( sprintf( '[Sync] Created new WC category "%s" (#%d)', $name, $new['term_id'] ) );
                }
            } else {
                $ids[] = $term->term_id;
            }
        }
        return $ids;
    }

    /**
     * Sync a single Square product by its Square catalog item ID.
     * Fetches fresh data from Square and updates/creates the matching WooCommerce product.
     */
    public function sync_single_product( $square_id ) {
        $square_id = sanitize_text_field( $square_id );
        $this->logger->info( sprintf( '=== Single Product Sync: %s%s ===', $square_id, $this->dry_run ? ' [DRY RUN]' : '' ) );

        $sq_product = $this->square->get_normalized_single_product( $square_id );
        if ( is_wp_error( $sq_product ) ) {
            return [ 'success' => false, 'error' => $sq_product->get_error_message() ];
        }

        // Capture diagnostic info for the response.
        $sq_var_count = count( $sq_product['variations'] );
        $sq_var_names = array_map( function( $v ) { return $v['name']; }, $sq_product['variations'] );
        $this->logger->info( sprintf( '  Square variations (%d): %s', $sq_var_count, implode( ', ', $sq_var_names ) ) );

        $this->stats['total_square'] = 1;

        try {
            $this->process_product( $sq_product );
        } catch ( \Throwable $e ) {
            $this->stats['errors']++;
            $this->logger->error( sprintf( 'Exception syncing "%s": %s', $sq_product['name'], $e->getMessage() ) );
            return [ 'success' => false, 'error' => $e->getMessage() ];
        }

        $this->logger->info( sprintf( '=== Single Product Sync Complete: "%s" ===', $sq_product['name'] ) );
        return array_merge( $this->stats, [
            'success'         => true,
            'product'         => $sq_product['name'],
            'sq_var_count'    => $sq_var_count,
            'sq_var_names'    => $sq_var_names,
            'dry_run'         => $this->dry_run,
            'sync_stock'      => $this->sync_stock,
            'sync_price'      => $this->sync_price,
            'debug_var_sync'  => $this->_debug_var_sync ?? [],
        ]);
    }

    private function attach_image( $product_id, $url, $title ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) return;

        $file_array = [
            'name'     => sanitize_file_name( $title ) . '.jpg',
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $product_id, $title );
        if ( ! is_wp_error( $attachment_id ) ) {
            set_post_thumbnail( $product_id, $attachment_id );
        } else {
            @unlink( $tmp );
        }
    }
}
