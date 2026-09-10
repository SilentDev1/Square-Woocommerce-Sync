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
            if ( $name_sim >= 40 ) {
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
                    '[Matcher] SKU match rejected for "%s" → WC #%d "%s": name similarity too low (%.0f%%)',
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
        $candidates = array_filter( $candidates, function( $c ) {
            return ! isset( $this->matched_wc_ids[ $c->get_id() ] );
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

        if ( $best_sim_prod && $best_sim_pct >= 80 ) {
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
                if ( $ai_name_sim < 35 ) {
                    $this->logger->warning( sprintf(
                        '[Matcher] AI match rejected for "%s" → WC #%d "%s": name similarity too low (%.0f%%)',
                        $square_product['name'], $wc_product->get_id(), $wc_product->get_name(), $ai_name_sim
                    ));
                } elseif ( isset( $this->matched_wc_ids[ $wc_product->get_id() ] ) ) {
                    $this->logger->warning( sprintf(
                        '[Matcher] AI match rejected for "%s" → WC #%d: already matched to "%s"',
                        $square_product['name'], $wc_product->get_id(), $this->matched_wc_ids[ $wc_product->get_id() ]
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

        $results = $wpdb->get_results(
            "SELECT pm.post_id, pm.meta_value, p.post_title
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
        $wc_variations = array_filter( array_map( 'wc_get_product', $children ) );

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
        if ( ! empty( $square_variation['square_variation_id'] ) ) {
            foreach ( $wc_variations as $wv ) {
                $stored_id = $wv->get_meta( '_square_variation_id' );
                if ( $stored_id !== $square_variation['square_variation_id'] ) {
                    continue;
                }
                $wc_attr_str = trim( implode( ' ', array_map( 'strtolower', $wv->get_variation_attributes() ) ) );
                similar_text( $sq_name_norm_check, $wc_attr_str, $s0_pct );
                if ( $s0_pct >= 65 ) {
                    return $wv; // Stored ID matches AND names are reasonably similar — trust it.
                }
                // Stored ID matches but names are too different: the ID was probably written
                // by a buggy sync onto the wrong WC variation. Fall through to other stages.
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
                if ( $s1_pct >= 65 ) {
                    return $wv;
                }
                // SKU matches but name doesn't — likely a corrupt SKU from a prior sync.
                // Fall through to other stages.
            }
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
            return wc_get_product( $ai_result['match_id'] );
        }

        // Stage 4: Single unclaimed variation fallback
        if ( count( $unclaimed ) === 1 ) {
            return $unclaimed[0];
        }

        return null;
    }
}
