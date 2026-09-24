<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Responsible for finding WooCommerce products that correspond to Square products.
 * Uses multi-stage matching: SKU → title similarity → category + AI verification.
 */
class SWS_Product_Matcher {

    private $ai;
    private $logger;
    private $ai_confidence_threshold;
    private $last_match_info = [];
    private $square_id_cache = null;
    private $matched_wc_ids = [];
    /** WC product (parent) ID → Square item ID that owns it, from _square_product_id meta. */
    private $wc_owner_cache = [];

    public function __construct() {
        $this->ai                      = new SWS_Ai_Matcher();
        $this->logger                  = new SWS_Sync_Logger();
        $this->ai_confidence_threshold = (float) get_option( 'sws_ai_confidence_threshold', 0.75 );
        $this->load_already_matched();
    }

    private function load_already_matched() {
    }

    public function reset_matched_ids() {
        $this->matched_wc_ids = [];
    }

    public function get_last_match_info() {
        return $this->last_match_info;
    }

    /**
     * Find the best WooCommerce product for a given Square product.
     * Returns WC_Product|null
     */
    public function find_woo_product( array $square_product ) {
        $this->last_match_info = [];

        // Stage 0: Match by stored Square Product ID (most reliable for re-syncs)
        $meta_match = $this->match_by_square_id( $square_product['square_id'] );
        if ( $meta_match ) {
            $this->matched_wc_ids[ $meta_match->get_id() ] = $square_product['name'];
            $this->last_match_info = [ 'method' => 'square_id', 'confidence' => 1.0, 'reasoning' => 'Matched by stored Square Product ID' ];
            $this->logger->info( sprintf(
                '[Matcher] Square ID match for "%s" → WC Product #%d',
                $square_product['name'], $meta_match->get_id()
            ));
            return $meta_match;
        }

        // Stage 1: SKU-based match with name sanity check
        $sku_match = $this->match_by_sku( $square_product );
        if ( $sku_match ) {
            $name_sim = $this->name_similarity( $square_product['name'], $sku_match->get_name() );
            $claimed_by = $this->claimed_by_other( $sku_match->get_id(), $square_product );
            if ( $claimed_by ) {
                $this->logger->warning( sprintf(
                    '[Matcher] SKU match rejected for "%s" → WC #%d "%s": already linked to Square item %s',
                    $square_product['name'], $sku_match->get_id(), $sku_match->get_name(), $claimed_by
                ));
            } elseif ( $this->names_compatible( $square_product['name'], $sku_match->get_name() ) ) {
                if ( ! isset( $this->matched_wc_ids[ $sku_match->get_id() ] ) ) {
                    $this->matched_wc_ids[ $sku_match->get_id() ] = $square_product['name'];
                    $this->last_match_info = [ 'method' => 'sku', 'confidence' => 1.0, 'reasoning' => 'Exact SKU match' ];
                    $this->logger->info( sprintf(
                        '[Matcher] SKU match for "%s" → WC Product #%d "%s" (name similarity: %.0f%%)',
                        $square_product['name'], $sku_match->get_id(), $sku_match->get_name(), $name_sim
                    ));
                    return $sku_match;
                } else {
                    $this->logger->warning( sprintf(
                        '[Matcher] SKU match for "%s" → WC #%d rejected: already matched to "%s"',
                        $square_product['name'], $sku_match->get_id(), $this->matched_wc_ids[ $sku_match->get_id() ]
                    ));
                }
            } else {
                $this->logger->warning( sprintf(
                    '[Matcher] SKU match rejected for "%s" → WC #%d "%s": product names do not describe the same item (%.0f%% similar)',
                    $square_product['name'], $sku_match->get_id(), $sku_match->get_name(), $name_sim
                ));
            }
        }

        // Stage 2: Title-based search
        $candidates = $this->get_candidates_by_title( $square_product['name'] );

        // Stage 3: Also search by category if we have few candidates
        if ( count( $candidates ) < 3 && ! empty( $square_product['categories'] ) ) {
            $cat_candidates = $this->get_candidates_by_category( $square_product['categories'] );
            foreach ( $cat_candidates as $cc ) {
                $exists = false;
                foreach ( $candidates as $c ) {
                    if ( $c->get_id() === $cc->get_id() ) { $exists = true; break; }
                }
                if ( ! $exists ) $candidates[] = $cc;
            }
        }

        if ( empty( $candidates ) ) {
            $this->last_match_info = [ 'method' => 'none', 'confidence' => 0, 'reasoning' => 'No match found' ];
            return null;
        }

        // Filter out WC products already matched to other Square products
        $candidates = array_filter( $candidates, function( $c ) use ( $square_product ) {
            return ! isset( $this->matched_wc_ids[ $c->get_id() ] )
                && ! $this->claimed_by_other( $c->get_id(), $square_product );
        });
        $candidates = array_values( $candidates );

        if ( empty( $candidates ) ) {
            $this->last_match_info = [ 'method' => 'none', 'confidence' => 0, 'reasoning' => 'All candidates already matched to other Square products' ];
            return null;
        }

        // Stage 4: Fast title similarity match (no AI needed)
        $sq_name_lower = strtolower( trim( $square_product['name'] ) );
        $best_sim_pct  = 0;
        $best_sim_prod = null;

        foreach ( $candidates as $c ) {
            $wc_name_lower = strtolower( trim( $c->get_name() ) );

            if ( $sq_name_lower === $wc_name_lower ) {
                $this->matched_wc_ids[ $c->get_id() ] = $square_product['name'];
                $this->last_match_info = [
                    'method'     => 'title_exact',
                    'confidence' => 1.0,
                    'reasoning'  => 'Exact title match',
                ];
                $this->logger->info( sprintf(
                    '[Matcher] Exact title match for "%s" → WC Product #%d',
                    $square_product['name'], $c->get_id()
                ));
                return $c;
            }

            similar_text( $sq_name_lower, $wc_name_lower, $pct );
            if ( $pct > $best_sim_pct ) {
                $best_sim_pct  = $pct;
                $best_sim_prod = $c;
            }
        }

        if ( $best_sim_prod && $best_sim_pct >= 80 && $this->names_compatible( $square_product['name'], $best_sim_prod->get_name() ) ) {
            $this->matched_wc_ids[ $best_sim_prod->get_id() ] = $square_product['name'];
            $confidence = round( $best_sim_pct / 100, 2 );
            $this->last_match_info = [
                'method'     => 'title_similarity',
                'confidence' => $confidence,
                'reasoning'  => sprintf( 'Title similarity %.0f%%', $best_sim_pct ),
            ];
            $this->logger->info( sprintf(
                '[Matcher] Title similarity match (%.0f%%) for "%s" → WC Product #%d "%s"',
                $best_sim_pct, $square_product['name'], $best_sim_prod->get_id(), $best_sim_prod->get_name()
            ));
            return $best_sim_prod;
        }

        // Stage 5: AI-powered matching (only when title similarity is insufficient)
        $ai_result = $this->ai->find_best_match( $square_product, $candidates );

        $this->logger->info( sprintf(
            '[Matcher] AI match for "%s": WC#%s (confidence: %.2f) — %s',
            $square_product['name'],
            $ai_result['match_id'] ?? 'none',
            $ai_result['confidence'],
            $ai_result['reasoning']
        ));

        if ( $ai_result['match_id'] && $ai_result['confidence'] >= $this->ai_confidence_threshold ) {
            $wc_product = wc_get_product( $ai_result['match_id'] );
            if ( $wc_product ) {
                $ai_name_sim = $this->name_similarity( $square_product['name'], $wc_product->get_name() );
                if ( $ai_name_sim < 35 || ! $this->names_compatible( $square_product['name'], $wc_product->get_name() ) ) {
                    $this->logger->warning( sprintf(
                        '[Matcher] AI match rejected for "%s" → WC #%d "%s": name similarity too low (%.0f%%)',
                        $square_product['name'], $wc_product->get_id(), $wc_product->get_name(), $ai_name_sim
                    ));
                } elseif ( isset( $this->matched_wc_ids[ $wc_product->get_id() ] )
                    || $this->claimed_by_other( $wc_product->get_id(), $square_product ) ) {
                    $this->logger->warning( sprintf(
                        '[Matcher] AI match rejected for "%s" → WC #%d: already matched to "%s"',
                        $square_product['name'], $wc_product->get_id(),
                        $this->matched_wc_ids[ $wc_product->get_id() ] ?? $this->claimed_by_other( $wc_product->get_id(), $square_product )
                    ));
                } else {
                    $this->matched_wc_ids[ $wc_product->get_id() ] = $square_product['name'];
                    $this->last_match_info = [
                        'method'     => 'ai_matched',
                        'confidence' => $ai_result['confidence'],
                        'reasoning'  => $ai_result['reasoning'],
                    ];
                    return $wc_product;
                }
            }
        }

        $this->last_match_info = [
            'method'     => 'none',
            'confidence' => 0,
            'reasoning'  => $ai_result['match_id']
                ? sprintf( 'AI suggested WC#%d but confidence %.2f below threshold %.2f', $ai_result['match_id'], $ai_result['confidence'], $this->ai_confidence_threshold )
                : 'No candidates matched — product should be created as new',
        ];
        $this->logger->info( sprintf(
            '[Matcher] No match for "%s": %s',
            $square_product['name'], $this->last_match_info['reasoning']
        ));
        return null;
    }

    private function name_similarity( $name_a, $name_b ) {
        $a = strtolower( trim( $name_a ) );
        $b = strtolower( trim( $name_b ) );
        if ( $a === $b ) return 100.0;
        similar_text( $a, $b, $pct );
        return $pct;
    }

    /**
     * Square item ID that already owns this WC product, when it is not $square_id.
     * Survives across batch requests (unlike $matched_wc_ids), so two Square items can
     * no longer take turns overwriting one WooCommerce product.
     */
    private function claimed_by_other( $wc_id, array $square_product ) {
        if ( $this->square_id_cache === null ) {
            $this->load_square_id_cache();
        }
        $owner = $this->wc_owner_cache[ (int) $wc_id ] ?? '';
        if ( $owner === '' || $owner === $square_product['square_id'] ) {
            return '';
        }
        // Square sometimes holds two items for one product (e.g. two "Pod Juice Clear"
        // entries). When this product's variations are already linked to this Square
        // item's variations, it serves both items — not a conflict. Without this the
        // second item was rejected every night and a duplicate draft was created.
        $sq_var_ids = array_filter( array_column( $square_product['variations'] ?? [], 'square_variation_id' ) );
        if ( $sq_var_ids ) {
            global $wpdb;
            $in     = implode( ',', array_fill( 0, count( $sq_var_ids ), '%s' ) );
            $linked = $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_square_variation_id' AND pm.meta_value IN ($in)
                   AND p.post_status = 'publish' AND ( p.ID = %d OR p.post_parent = %d )",
                array_merge( array_values( $sq_var_ids ), [ (int) $wc_id, (int) $wc_id ] )
            ) );
            if ( (int) $linked > 0 ) {
                return '';
            }
        }
        return $owner;
    }

    /**
     * Word-level check that two product names describe the same item.
     *
     * similar_text() alone passed "Custard Monster Salts Pumpkin Spice" →
     * "Custard Monster Salt – Butterscotch" (67%) because brand words dominate.
     * Here the names are compatible when one name's significant words are all found
     * in the other (word order / "BY BRAND 30ML" suffixes don't matter), or when they
     * share most of their words (Jaccard >= 0.6). Differing flavours/models on both
     * sides fail the check.
     */
    public static function names_compatible( $name_a, $name_b ) {
        $a = self::name_tokens( $name_a );
        $b = self::name_tokens( $name_b );
        if ( empty( $a ) || empty( $b ) ) {
            return strtolower( trim( $name_a ) ) === strtolower( trim( $name_b ) );
        }
        $a_joined = implode( '', $a );
        $b_joined = implode( '', $b );

        $a_hit = self::count_found( $a, $b, $b_joined );
        $b_hit = self::count_found( $b, $a, $a_joined );
        if ( $a_hit === 0 || $b_hit === 0 ) return false;
        if ( $a_hit === count( $a ) || $b_hit === count( $b ) ) return true;

        $shared = min( $a_hit, $b_hit );
        $union  = count( $a ) + count( $b ) - $shared;
        return ( $shared / max( 1, $union ) ) >= 0.6;
    }

    /** How many of $tokens appear in the other name (as a word, or glued: "elf bar" ↔ "elfbar"). */
    private static function count_found( array $tokens, array $other, $other_joined ) {
        $hit = 0;
        foreach ( $tokens as $t ) {
            $found = strlen( $t ) >= 3 && ! ctype_digit( $t ) && strpos( $other_joined, $t ) !== false;
            if ( ! $found ) {
                foreach ( $other as $o ) {
                    if ( self::tokens_equal( $t, $o ) ) { $found = true; break; }
                }
            }
            if ( $found ) $hit++;
        }
        return $hit;
    }

    private static function name_tokens( $name ) {
        static $stop = [
            'a', 'the', 'by', 'and', 'with', 'for', 'of', 'salt', 'salts', 'nic', 'nicotine',
            'eliquid', 'liquid', 'ejuice', 'juice', 'vape', 'disposable', 'disposables',
            'pod', 'pods', 'kit', 'kits', 'tank', 'coil', 'coils', 'pack', 'replacement',
            'device', 'ml', 'mg', 'pc', 'pcs', 'piece', 'single', 'new', 'ohm', 'ohms',
        ];
        static $alias = [ 'nkd' => 'naked', 'mvl' => 'monster vape labs', 'pachamama' => 'pacha' ];

        $name = strtolower( html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) );
        $name = preg_replace( '/\(?\b\d+\s*-?\s*(pack|packs|pk|pcs?|piece|count|ct)\b\)?|\b(pack|packs)\s+of\s+\d+\b|\b\d+x\b/', ' ', $name ); // "5 Pack", "(2-pack)", "2x"
        $name = preg_replace( '/\b\d*\.\d+\s*(ohms?|Ω)?|\b\d+\s*ohms?\b/u', ' ', $name ); // 0.15 Ohm, .5, 1 ohm
        $name = preg_replace_callback( '/\b(ii|iii|iv|v|vi)\b/', function ( $m ) {
            return (string) [ 'ii' => 2, 'iii' => 3, 'iv' => 4, 'v' => 5, 'vi' => 6 ][ $m[1] ];
        }, $name );                                                        // Valyrian II → 2
        $name = preg_replace( '/(\d+)k\b/', '${1}000', $name );          // 10k → 10000
        $name = preg_replace( '/(\d+(?:ml|mg|mah|w))\b/', ' ', $name );   // 30ml, 50mg, 310mah, 80w
        $name = preg_replace( '/[^a-z0-9]+/', ' ', $name );
        $name = preg_replace( '/(?<=[a-z])(?=\d)|(?<=\d)(?=[a-z])/', ' ', $name ); // os5000 → os 5000
        $tokens = [];
        foreach ( explode( ' ', $name ) as $t ) {
            if ( $t === '' || in_array( $t, $stop, true ) ) continue;
            foreach ( explode( ' ', $alias[ $t ] ?? $t ) as $w ) {
                if ( $w === '' ) continue; // single letters stay: "Pulse X" is not "Pulse 2"
                $tokens[ $w ] = true;
            }
        }
        return array_map( 'strval', array_keys( $tokens ) );
    }

    private static function tokens_equal( $x, $y ) {
        if ( $x === $y ) return true;
        if ( ctype_digit( $x ) || ctype_digit( $y ) ) return false;
        // Singular/plural and short forms: "bar"/"bars", "berry"/"berries".
        $short = strlen( $x ) <= strlen( $y ) ? $x : $y;
        $long  = $short === $x ? $y : $x;
        if ( strlen( $short ) >= 3 && strpos( $long, $short ) === 0 && strlen( $long ) - strlen( $short ) <= 3 ) return true;
        // One-letter typos in longer words: "moster"/"monster".
        if ( strlen( $short ) >= 5 && levenshtein( $x, $y ) <= 1 ) return true;
        return false;
    }

    /**
     * Match by stored _square_product_id meta (for previously synced products).
     * Uses a pre-loaded cache for performance — one DB query instead of one per product.
     */
    private function match_by_square_id( $square_id ) {
        if ( empty( $square_id ) ) return null;

        if ( $this->square_id_cache === null ) {
            $this->load_square_id_cache();
        }

        $post_id = $this->square_id_cache[ $square_id ] ?? null;
        if ( ! $post_id ) return null;

        $product = wc_get_product( $post_id );
        if ( ! $product ) return null;

        if ( $product->is_type( 'variation' ) ) {
            return wc_get_product( $product->get_parent_id() );
        }
        return $product;
    }

    /**
     * Pre-load all _square_product_id → post_id mappings in one query.
     */
    private function load_square_id_cache() {
        global $wpdb;
        $this->square_id_cache = [];
        $this->wc_owner_cache  = [];

        $results = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value, p.post_title, p.post_type, p.post_parent
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_square_product_id' AND pm.meta_value != ''
             AND p.post_status IN ('publish','draft','pending','private')
             AND p.post_type IN ('product','product_variation')",
            ARRAY_A
        );

        // Group all post_ids per square_id to detect duplicates
        $grouped = [];
        foreach ( $results as $row ) {
            $sq_id = $row['meta_value'];
            $grouped[ $sq_id ][] = $row;
        }

        foreach ( $grouped as $sq_id => $rows ) {
            if ( count( $rows ) === 1 ) {
                $this->square_id_cache[ $sq_id ] = (int) $rows[0]['post_id'];
                continue;
            }

            // Multiple products share the same Square ID — prefer the non-"(Copy)" one
            $originals = [];
            $copies    = [];
            foreach ( $rows as $row ) {
                if ( stripos( $row['post_title'], '(Copy)' ) !== false ) {
                    $copies[] = $row;
                } else {
                    $originals[] = $row;
                }
            }

            if ( ! empty( $originals ) ) {
                // Use the first original product
                $winner = $originals[0];
                $this->square_id_cache[ $sq_id ] = (int) $winner['post_id'];

                // Remove _square_product_id from copies to prevent future conflicts
                foreach ( $copies as $copy ) {
                    delete_post_meta( (int) $copy['post_id'], '_square_product_id' );
                    $this->logger->warning( sprintf(
                        '[Matcher] Duplicate Square ID "%s": removed from copy WC#%d "%s" (keeping original WC#%d "%s")',
                        $sq_id, (int) $copy['post_id'], $copy['post_title'],
                        (int) $winner['post_id'], $winner['post_title']
                    ) );
                }
            } else {
                // All are copies — just use the first one
                $this->square_id_cache[ $sq_id ] = (int) $rows[0]['post_id'];
                $this->logger->warning( sprintf(
                    '[Matcher] Duplicate Square ID "%s": all %d products appear to be copies, using WC#%d',
                    $sq_id, count( $rows ), (int) $rows[0]['post_id']
                ) );
            }
        }

        $rows_by_post = array_column( $results, null, 'post_id' );
        foreach ( $this->square_id_cache as $sq_id => $post_id ) {
            $row = $rows_by_post[ $post_id ] ?? null;
            if ( ! $row ) continue;
            $owner_id = $row['post_type'] === 'product_variation' ? (int) $row['post_parent'] : $post_id;
            if ( ! isset( $this->wc_owner_cache[ $owner_id ] ) ) {
                $this->wc_owner_cache[ $owner_id ] = $sq_id;
            }
        }

        $this->logger->info( sprintf( '[Matcher] Loaded %d Square ID mappings from cache.', count( $this->square_id_cache ) ) );
    }

    /**
     * Match by SKU across all variations.
     */
    private function match_by_sku( array $square_product ) {
        foreach ( $square_product['variations'] as $variation ) {
            if ( empty( $variation['sku'] ) ) continue;

            $product_id = wc_get_product_id_by_sku( $variation['sku'] );
            if ( $product_id ) {
                $product = wc_get_product( $product_id );
                // If it's a variation, return parent
                if ( $product && $product->is_type( 'variation' ) ) {
                    return wc_get_product( $product->get_parent_id() );
                }
                return $product;
            }
        }
        return null;
    }

    /**
     * Search WooCommerce products by fuzzy title match.
     * Uses a multi-stage approach: exact title → LIKE title → WP search → individual keywords.
     */
    private function get_candidates_by_title( $title ) {
        $found = [];
        $seen  = [];

        $add_products = function( $product_ids ) use ( &$found, &$seen ) {
            foreach ( $product_ids as $pid ) {
                if ( ! isset( $seen[ $pid ] ) ) {
                    $product = wc_get_product( $pid );
                    if ( $product ) {
                        $found[]      = $product;
                        $seen[ $pid ] = true;
                    }
                }
            }
        };

        // Stage A: Exact post_title match (most reliable — bypasses WordPress search entirely)
        global $wpdb;
        $exact_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product'
             AND post_status IN ('publish','draft','pending','private')
             AND post_title = %s
             LIMIT 5",
            $title
        ) );
        $add_products( $exact_ids );

        // Stage B: LIKE match on post_title (catches small differences like trailing spaces, extra chars)
        $like_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product'
             AND post_status IN ('publish','draft','pending','private')
             AND post_title LIKE %s
             LIMIT 20",
            '%' . $wpdb->esc_like( $title ) . '%'
        ) );
        $add_products( $like_ids );

        // Stage C: Also try LIKE with simplified title (no noise words)
        $simplified = $this->simplify_title( $title );
        if ( $simplified !== strtolower( $title ) ) {
            // Try the first 2-3 significant words as LIKE
            $sig_words = array_slice( explode( ' ', $simplified ), 0, 3 );
            if ( count( $sig_words ) >= 2 ) {
                $like_pattern = '%' . $wpdb->esc_like( implode( ' ', $sig_words ) ) . '%';
                $simple_ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type = 'product'
                     AND post_status IN ('publish','draft','pending','private')
                     AND LOWER(post_title) LIKE %s
                     LIMIT 20",
                    strtolower( $like_pattern )
                ) );
                $add_products( $simple_ids );
            }
        }

        // Stage D: WordPress full-text search (catches partial matches WP is good at)
        $searches = [
            $title,
            $simplified,
        ];

        $words = preg_split( '/\s+/', trim( $title ) );
        if ( count( $words ) > 2 ) {
            $searches[] = implode( ' ', array_slice( $words, 0, 3 ) );
        }
        if ( count( $words ) > 1 ) {
            $searches[] = implode( ' ', array_slice( $words, 0, 2 ) );
        }

        $brand = $words[0] ?? '';
        $model = isset( $words[1] ) ? $words[1] : '';
        if ( $brand && $model ) {
            $searches[] = $brand . ' ' . $model;
        }

        // Add individual significant words (3+ chars) for broader matching
        foreach ( $words as $w ) {
            if ( strlen( $w ) >= 4 ) {
                $searches[] = $w;
            }
        }

        $searches = array_unique( array_filter( $searches ) );

        foreach ( $searches as $search_term ) {
            if ( count( $found ) >= 30 ) break; // Enough candidates

            $args = [
                'post_type'      => 'product',
                'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
                'posts_per_page' => 20,
                's'              => $search_term,
                'fields'         => 'ids',
            ];

            $query = new WP_Query( $args );
            $add_products( $query->posts );
        }

        return $found;
    }

    /**
     * Get candidates by category name, checking category mappings first.
     */
    private function get_candidates_by_category( array $category_names ) {
        $found    = [];
        $seen     = [];
        $mappings = get_option( 'sws_category_mapping', [] );
        if ( ! is_array( $mappings ) ) $mappings = [];

        foreach ( $category_names as $cat_name ) {
            $term_id = null;

            if ( isset( $mappings[ $cat_name ] ) ) {
                $mapped_term = get_term( (int) $mappings[ $cat_name ], 'product_cat' );
                if ( $mapped_term && ! is_wp_error( $mapped_term ) ) {
                    $term_id = $mapped_term->term_id;
                    $this->logger->info( sprintf( '[Matcher] Using mapped category "%s" → WC term #%d (%s)', $cat_name, $term_id, $mapped_term->name ) );
                }
            }

            if ( ! $term_id ) {
                $term = get_term_by( 'name', $cat_name, 'product_cat' );
                if ( ! $term ) {
                    $term = get_term_by( 'slug', sanitize_title( $cat_name ), 'product_cat' );
                }
                if ( $term ) $term_id = $term->term_id;
            }

            if ( ! $term_id ) continue;

            $args = [
                'post_type'      => 'product',
                'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
                'posts_per_page' => 20,
                'tax_query'      => [
                    [
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => $term_id,
                    ],
                ],
                'fields' => 'ids',
            ];

            $query = new WP_Query( $args );
            foreach ( $query->posts as $pid ) {
                if ( ! isset( $seen[ $pid ] ) ) {
                    $product = wc_get_product( $pid );
                    if ( $product ) {
                        $found[]      = $product;
                        $seen[ $pid ] = true;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Simplify a product title for better matching.
     * Removes common noise words, sizes, brand prefixes.
     */
    private function simplify_title( $title ) {
        $title = strtolower( $title );
        $noise = [ 'the', 'a', 'an', 'and', 'or', 'with', 'for', 'in', 'oz', 'lb', 'pack', 'set', 'kit' ];
        $words = explode( ' ', $title );
        $words = array_filter( $words, function( $w ) use ( $noise ) {
            return ! in_array( $w, $noise ) && strlen( $w ) > 1;
        });
        return implode( ' ', $words );
    }

    /**
     * Find matching WooCommerce variation for a Square variation within a product.
     *
     * @param  array $square_variation  Normalized Square variation data.
     * @param  WC_Product $wc_product   The parent WC variable product.
     * @param  int[] $claimed_wc_ids    WC variation IDs already matched in this sync run.
     *                                  Prevents the same WC variation from being returned for
     *                                  multiple Square variations.
     * @return WC_Product_Variation|null
     */
    public function find_woo_variation( array $square_variation, $wc_product, array $claimed_wc_ids = [] ) {
        if ( ! $wc_product->is_type( 'variable' ) ) {
            return null;
        }

        $children     = $wc_product->get_children();
        // Only enabled variations. Disabled ("private") ones are retired duplicates —
        // matching them by exact name re-linked them and left the live option stale.
        $wc_variations = array_values( array_filter( array_map( 'wc_get_product', $children ), function( $wv ) {
            return $wv && $wv->get_status( 'edit' ) === 'publish';
        } ) );

        // Exclude WC variations already matched to a different Square variation in THIS
        // sync run.  Without this, the same WC variation (e.g. "blue razz") could be
        // returned for two different Square variations ("blue razz" AND "blue raz ice"),
        // preventing the unmatched one from ever being created as a new WC variation.
        if ( ! empty( $claimed_wc_ids ) ) {
            $wc_variations = array_values( array_filter( $wc_variations, function( $wv ) use ( $claimed_wc_ids ) {
                return ! in_array( $wv->get_id(), $claimed_wc_ids, true );
            } ) );
        }

        // Pre-compute normalised Square name used in sanity checks for Stages 0 and 1.
        // Strip separator characters (/ \ - | ,) so "Miami Mint / Winter Mint" becomes
        // "miami mint  winter mint" and can be compared to WC attribute values cleanly.
        $sq_name_norm_check = trim( preg_replace(
            '/\s+/', ' ',
            preg_replace( '/[\\/\\\\\\-\\|,]+/', ' ', strtolower( $square_variation['name'] ) )
        ) );

        // Stage 0: Match by stored Square Variation ID — WITH a name-sanity check.
        // A corrupted previous sync may have written the wrong Square variation ID onto a
        // WC variation (e.g., a combo variant's ID stored on the "Miami Mint" variation).
        // If the stored ID matches but the variation name is very different from the WC
        // attribute values (< 65 % similarity), skip this match so the correct pairing
        // can be found by Stage 0.5 or Stage 1 below instead of creating duplicates.
        $stored_fallback = null;
        if ( ! empty( $square_variation['square_variation_id'] ) ) {
            foreach ( $wc_variations as $wv ) {
                $stored_id = $wv->get_meta( '_square_variation_id' );
                if ( $stored_id !== $square_variation['square_variation_id'] ) {
                    continue;
                }
                $wc_attr_str = trim( implode( ' ', array_map( 'strtolower', $wv->get_variation_attributes() ) ) );
                similar_text( $sq_name_norm_check, $wc_attr_str, $s0_pct );
                // Square's placeholder variation ("Regular") carries no option to compare,
                // so a stored ID link is the only reliable signal — trust it.
                $is_placeholder = strtolower( trim( $square_variation['name'] ) ) === 'regular';
                if ( $is_placeholder || $s0_pct >= 65 || $this->option_values_equal( $square_variation['option_values'], $wv->get_variation_attributes() ) ) {
                    return $wv; // Stored ID matches AND names are reasonably similar — trust it.
                }
                // Stored ID matches but the labels differ ("Red Carbon Fiber" vs "Red",
                // "6MG Ice" vs "Iced 6mg"). Try the stricter stages first; if none finds a
                // better variation, the stored link wins (see below) instead of a new
                // duplicate variation being created next to it.
                $stored_fallback = $stored_fallback ?: $wv;
            }
        }

        // Stage 0.5: Exact attribute-value match (case-insensitive).
        // This recovers pairings even when _square_variation_id was corrupted by a prior
        // bad sync. For "miami mint" it will find the WC variation whose attribute_flavor
        // is already "miami mint", regardless of what Square ID is stored on it.
        // A combo variant ("miami mint / winter mint") will NOT match a single-value WC
        // variation because the full option_values array must match exactly.
        $sq_opt_lower = array_map( function( $v ) { return strtolower( trim( $v ) ); },
                                   $square_variation['option_values'] );
        sort( $sq_opt_lower );
        foreach ( $wc_variations as $wv ) {
            $wc_attr_lower = array_map( 'strtolower', array_values( $wv->get_variation_attributes() ) );
            sort( $wc_attr_lower );
            if ( ! empty( $sq_opt_lower ) && $sq_opt_lower === $wc_attr_lower ) {
                return $wv;
            }
        }

        // Stage 0.6: Same option values once strength/size formatting is normalised —
        // Square "3" / "6" vs the original WC "3mg" / "6mg", "0" vs "omg". Without this
        // the sync created a second "3" variation next to "3mg" on every such product.
        // Prefer a variation already linked to this Square variation, then any unclaimed one.
        $sq_vid_norm = $square_variation['square_variation_id'] ?? '';
        $norm_hits   = array_values( array_filter( $wc_variations, function( $wv ) use ( $square_variation ) {
            return $this->option_values_equal( $square_variation['option_values'], $wv->get_variation_attributes() );
        } ) );
        foreach ( $norm_hits as $wv ) {
            if ( $sq_vid_norm !== '' && $wv->get_meta( '_square_variation_id' ) === $sq_vid_norm ) {
                return $wv;
            }
        }
        foreach ( $norm_hits as $wv ) {
            if ( $wv->get_meta( '_square_variation_id' ) === '' ) {
                return $wv;
            }
        }

        // Stage 1: Match by SKU — with the same name-sanity check used in Stage 0.
        // A previous buggy sync may have overwritten a WC variation's SKU with a combo
        // variant's SKU, so we verify the names are reasonably similar before trusting
        // a SKU hit.
        if ( ! empty( $square_variation['sku'] ) ) {
            foreach ( $wc_variations as $wv ) {
                if ( $wv->get_sku() !== $square_variation['sku'] ) {
                    continue;
                }
                $wc_attr_str = trim( implode( ' ', array_map( 'strtolower', $wv->get_variation_attributes() ) ) );
                similar_text( $sq_name_norm_check, $wc_attr_str, $s1_pct );
                if ( $s1_pct >= 65 || $this->option_values_equal( $square_variation['option_values'], $wv->get_variation_attributes() ) ) {
                    return $wv;
                }
                // SKU matches but name doesn't — likely a corrupt SKU from a prior sync.
                // Fall through to other stages.
            }
        }

        if ( $stored_fallback ) {
            return $stored_fallback;
        }

        // Stages 2-4: Only consider variations not already claimed by a DIFFERENT Square
        // variation. A variation that already has '_square_variation_id' pointing to
        // a different Square variation must NOT be fuzzy/AI-matched to a new one — doing
        // so is what caused combo-named variations ("Miami Mint / Winter Mint") to falsely
        // match the existing "Miami Mint" WC variation instead of being created as new.
        $sq_vid    = $square_variation['square_variation_id'] ?? '';
        $unclaimed = array_values( array_filter( $wc_variations, function( $wv ) use ( $sq_vid ) {
            $stored = $wv->get_meta( '_square_variation_id' );
            return empty( $stored ) || $stored === $sq_vid;
        } ) );

        // Stage 2: Normalized string similarity on variation name vs attributes.
        // Strips "/" separators so "Red / Large" matches WC attrs "red large".
        $sq_name_raw  = strtolower( $square_variation['name'] );
        $sq_name_norm = trim( preg_replace( '/\s+/', ' ', preg_replace( '/[\\/\\-\\|,]+/', ' ', $sq_name_raw ) ) );
        $sq_opt_vals  = array_map( 'strtolower', array_map( 'trim', $square_variation['option_values'] ) );

        foreach ( $unclaimed as $wv ) {
            $wc_attr_vals = array_values( array_map( 'strtolower', $wv->get_variation_attributes() ) );
            $attrs_norm   = trim( implode( ' ', $wc_attr_vals ) );

            // Direct normalized name similarity
            similar_text( $sq_name_norm, $attrs_norm, $pct );
            if ( $pct >= 75 ) {
                return $wv;
            }

            // Per-option-value match: each Square option must match at least one WC attribute value
            if ( ! empty( $sq_opt_vals ) && ! empty( $wc_attr_vals ) ) {
                $matched_opts = 0;
                foreach ( $sq_opt_vals as $opt ) {
                    foreach ( $wc_attr_vals as $attr_val ) {
                        similar_text( $opt, $attr_val, $opt_pct );
                        if ( $opt_pct >= 85 || $opt === $attr_val ) {
                            $matched_opts++;
                            break;
                        }
                    }
                }
                if ( $matched_opts > 0 && $matched_opts >= count( $sq_opt_vals ) ) {
                    return $wv;
                }
            }
        }

        // Stage 3: AI matching (against unclaimed variations only)
        $square_variation['option_values_str'] = implode( ' / ', $square_variation['option_values'] );
        $ai_result = $this->ai->match_variation( $square_variation, $unclaimed );

        if ( $ai_result['match_id'] && $ai_result['confidence'] >= 0.7 ) {
            foreach ( $unclaimed as $wv ) {
                if ( $wv->get_id() === (int) $ai_result['match_id'] ) {
                    return $wv;
                }
            }
        }

        // Stage 4: Single unclaimed variation fallback
        if ( count( $unclaimed ) === 1 ) {
            return $unclaimed[0];
        }

        return null;
    }

    /**
     * Square option values vs WC variation attributes, compared after normalising
     * nicotine/size formatting: "3" = "3mg" = "3 MG", "0" = "0mg" = "omg",
     * "3 ice" = "3mg-ice". Order-insensitive; the number of values must match.
     */
    private function option_values_equal( array $sq_values, array $wc_attrs ) {
        $a = array_map( [ $this, 'normalize_option_value' ], array_values( $sq_values ) );
        $b = array_map( [ $this, 'normalize_option_value' ], array_values( $wc_attrs ) );
        $a = array_values( array_filter( $a, 'strlen' ) );
        $b = array_values( array_filter( $b, 'strlen' ) );
        if ( empty( $a ) || count( $a ) !== count( $b ) ) {
            return false;
        }
        sort( $a );
        sort( $b );
        return $a === $b;
    }

    private function normalize_option_value( $value ) {
        $v = strtolower( trim( (string) $value ) );
        $v = preg_replace( '/^new\s+(?=\d)/', '', $v );             // "New 3mg" (reformulation label) → 3mg
        $v = preg_replace( '/^omg\b/', '0mg', $v );                 // "omg" typo for 0mg
        $v = preg_replace( '/(?<![0-9])\.(\d)/', '0.$1', $v );        // ".5 ohm" → "0.5 ohm"
        $v = preg_replace( '/(\d)-(\d)/', '$1.$2', $v );             // term slug "0-5" → 0.5
        $v = preg_replace( '/(\d+(?:\.\d+)?)\s*mg\b/', '$1', $v ); // 3mg / 6 Mg → number
        $v = preg_replace( '/\b(iced|freeze|frozen)\b/', 'ice', $v );  // "Iced 6mg" / "Freeze 6" = "6mg ice"
        $tokens = preg_split( '/[^a-z0-9.]+/', $v, -1, PREG_SPLIT_NO_EMPTY );
        $tokens = array_values( array_diff( $tokens, [ 'ohm', 'ohms', 'mg', 'new' ] ) );
        sort( $tokens );                                             // word order doesn't matter
        return implode( ' ', $tokens );
    }
}
