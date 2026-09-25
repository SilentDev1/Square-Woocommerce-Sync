<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles all Square API communication.
 * Uses Square Catalog API to retrieve all catalog items, variations, and inventory counts.
 */
class SWS_Square_Api {

    private $access_token;
    private $location_id;
    private $environment;
    private $base_url;

    public function __construct() {
        $this->access_token = get_option( 'sws_square_access_token', '' );
        $this->location_id  = get_option( 'sws_square_location_id', '' );
        $this->environment  = get_option( 'sws_square_environment', 'sandbox' );
        $this->base_url     = $this->environment === 'production'
            ? 'https://connect.squareup.com/v2'
            : 'https://connect.squareupsandbox.com/v2';
    }

    /**
     * Make authenticated request to Square API.
     */
    private function request( $endpoint, $method = 'GET', $body = null ) {
        if ( empty( $this->access_token ) ) {
            return new WP_Error( 'no_token', 'Square access token is not configured.' );
        }

        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type'  => 'application/json',
                'Square-Version' => '2025-01-23',
            ],
        ];

        if ( $body ) {
            $args['body'] = json_encode( $body );
        }

        $response = wp_remote_request( $this->base_url . $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $msg = isset( $data['errors'][0]['detail'] ) ? $data['errors'][0]['detail'] : 'Unknown Square API error.';
            return new WP_Error( 'square_error', $msg, [ 'status' => $code, 'data' => $data ] );
        }

        return $data;
    }

    /**
     * Inventory counts (IN_STOCK at this location) that changed after $updated_after — for the
     * 5-minute quick stock sync.
     *
     * @param string      $updated_after RFC 3339 time.
     * @param string|null $cursor        Page cursor.
     * @return array|WP_Error { counts, cursor? }
     */
    public function get_changed_counts( $updated_after, $cursor = null ) {
        $body = [
            'states'        => [ 'IN_STOCK' ],
            'updated_after' => $updated_after,
            'limit'         => 1000,
        ];
        if ( $this->location_id ) {
            $body['location_ids'] = [ $this->location_id ];
        }
        if ( $cursor ) {
            $body['cursor'] = $cursor;
        }
        return $this->request( '/inventory/counts/batch-retrieve', 'POST', $body );
    }

    /**
     * Test API connection.
     */
    public function test_connection() {
        $result = $this->request( '/locations' );
        if ( is_wp_error( $result ) ) {
            return [ 'success' => false, 'message' => $result->get_error_message() ];
        }
        $count = count( $result['locations'] ?? [] );
        return [ 'success' => true, 'message' => "Connected. Found {$count} location(s)." ];
    }

    /**
     * Get all locations.
     */
    public function get_locations() {
        return $this->request( '/locations' );
    }

    /**
     * Retrieve ALL catalog items (products) using pagination.
     * Uses SearchCatalogItems with ALL types to capture everything.
     */
    public function get_all_catalog_items() {
        $items   = [];
        $cursor  = null;

        do {
            $body = [
                'object_types' => [ 'ITEM' ],
                'include_deleted_objects' => false,
                'include_related_objects' => true,
            ];

            if ( $cursor ) {
                $body['cursor'] = $cursor;
            }

            $response = $this->request( '/catalog/list?' . http_build_query([
                'types'  => 'ITEM',
                'cursor' => $cursor,
            ]), 'GET' );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            if ( ! empty( $response['objects'] ) ) {
                $items = array_merge( $items, $response['objects'] );
            }

            $cursor = $response['cursor'] ?? null;

        } while ( $cursor );

        return $items;
    }

    /**
     * Get catalog items with full detail including related objects (variations, categories, images).
     */
    public function get_catalog_items_full() {
        $items   = [];
        $cursor  = null;
        $related = [];

        do {
            $params = [
                'types'                    => 'ITEM',
                'include_related_objects'  => 'true',
            ];
            if ( $cursor ) {
                $params['cursor'] = $cursor;
            }

            $response = $this->request( '/catalog/list?' . http_build_query( $params ) );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            if ( ! empty( $response['objects'] ) ) {
                $items = array_merge( $items, $response['objects'] );
            }

            if ( ! empty( $response['related_objects'] ) ) {
                foreach ( $response['related_objects'] as $obj ) {
                    $related[ $obj['id'] ] = $obj;
                }
            }

            $cursor = $response['cursor'] ?? null;

        } while ( $cursor );

        return [
            'items'   => $items,
            'related' => $related,
        ];
    }

    /**
     * Get inventory counts for all catalog item variations at a specific location.
     */
    public function get_inventory_counts( array $catalog_object_ids ) {
        $logger = new SWS_Sync_Logger();
        if ( empty( $catalog_object_ids ) ) return [];

        if ( empty( $this->location_id ) ) {
            $logger->warning( '[Inventory] No location_id configured — inventory counts may be empty or aggregated.' );
        }

        $counts = $this->fetch_inventory_batch( $catalog_object_ids, $logger );

        if ( empty( $counts ) ) {
            $logger->info( '[Inventory] Batch endpoint returned no data — trying per-item fallback for first 5 IDs...' );
            $sample = array_slice( $catalog_object_ids, 0, 5 );
            foreach ( $sample as $vid ) {
                $endpoint = '/inventory/' . $vid;
                if ( $this->location_id ) {
                    $endpoint .= '?location_ids=' . $this->location_id;
                }
                $resp = $this->request( $endpoint );
                if ( is_wp_error( $resp ) ) {
                    $logger->error( sprintf( '[Inventory] Per-item fallback error for %s: %s', $vid, $resp->get_error_message() ) );
                } else {
                    $logger->info( sprintf( '[Inventory] Per-item response for %s: %s', $vid, wp_json_encode( $resp ) ) );
                    if ( ! empty( $resp['counts'] ) ) {
                        foreach ( $resp['counts'] as $count ) {
                            $state = $count['state'] ?? '';
                            if ( $state === 'IN_STOCK' ) {
                                $counts[ $count['catalog_object_id'] ] = (int) floatval( $count['quantity'] ?? 0 );
                            }
                        }
                    }
                }
            }

            if ( ! empty( $counts ) ) {
                $logger->info( '[Inventory] Per-item fallback found data — fetching all via per-item method...' );
                foreach ( array_slice( $catalog_object_ids, 5 ) as $vid ) {
                    $endpoint = '/inventory/' . $vid;
                    if ( $this->location_id ) {
                        $endpoint .= '?location_ids=' . $this->location_id;
                    }
                    $resp = $this->request( $endpoint );
                    if ( ! is_wp_error( $resp ) && ! empty( $resp['counts'] ) ) {
                        foreach ( $resp['counts'] as $count ) {
                            if ( ( $count['state'] ?? '' ) === 'IN_STOCK' ) {
                                $counts[ $count['catalog_object_id'] ] = (int) floatval( $count['quantity'] ?? 0 );
                            }
                        }
                    }
                }
            }
        }

        $non_zero = count( array_filter( $counts, function( $q ) { return $q > 0; } ) );
        $logger->info( sprintf(
            '[Inventory] Final summary: %d variation counts (%d non-zero) from %d requested IDs.',
            count( $counts ), $non_zero, count( $catalog_object_ids )
        ));

        return $counts;
    }

    private function fetch_inventory_batch( array $catalog_object_ids, $logger ) {
        $counts = [];
        $chunks = array_chunk( $catalog_object_ids, 100 );
        $total_api_records = 0;

        foreach ( $chunks as $ci => $chunk ) {
            $body = [
                'catalog_object_ids' => $chunk,
            ];

            if ( $this->location_id ) {
                $body['location_ids'] = [ $this->location_id ];
            }

            $cursor = null;
            do {
                if ( $cursor ) {
                    $body['cursor'] = $cursor;
                }

                $response = $this->request( '/inventory/batch-retrieve-counts', 'POST', $body );

                if ( is_wp_error( $response ) ) {
                    $logger->warning( sprintf( '[Inventory] New endpoint failed on chunk %d: %s — trying deprecated endpoint...', $ci, $response->get_error_message() ) );
                    $response = $this->request( '/inventory/counts/batch-retrieve', 'POST', $body );
                    if ( is_wp_error( $response ) ) {
                        $logger->error( sprintf( '[Inventory] Both endpoints failed on chunk %d: %s', $ci, $response->get_error_message() ) );
                        break;
                    }
                }

                if ( ! empty( $response['counts'] ) ) {
                    $total_api_records += count( $response['counts'] );
                    foreach ( $response['counts'] as $count ) {
                        $vid   = $count['catalog_object_id'];
                        $state = $count['state'] ?? 'UNKNOWN';
                        $qty   = (int) floatval( $count['quantity'] ?? 0 );

                        if ( ! isset( $counts[ $vid ] ) || $state === 'IN_STOCK' ) {
                            $counts[ $vid ] = $qty;
                        }
                    }
                } else {
                    $logger->info( sprintf( '[Inventory] Chunk %d (%d IDs): Square returned no counts. Response keys: %s',
                        $ci, count( $chunk ), implode( ', ', array_keys( $response ?? [] ) ) ) );
                }
                $cursor = $response['cursor'] ?? null;
            } while ( $cursor );
        }

        $logger->info( sprintf( '[Inventory] Batch fetch: %d API records → %d counts.', $total_api_records, count( $counts ) ) );
        return $counts;
    }

    // ─── LOYALTY PROGRAM & CUSTOMER METHODS ─────────────────────────

    /**
     * Get the loyalty program for this seller.
     */
    public function get_loyalty_program() {
        // Try the /main endpoint first (available since Square API 2022-05-12).
        $response = $this->request( '/loyalty/programs/main' );
        if ( ! is_wp_error( $response ) && ! empty( $response['program'] ) ) {
            return $response['program'];
        }

        // Fallback: list all programs and take the first one.
        $list = $this->request( '/loyalty/programs' );
        if ( is_wp_error( $list ) ) {
            // If both endpoints fail, return a clear error.
            $msg = $list->get_error_message();
            if ( stripos( $msg, 'not found' ) !== false || stripos( $msg, '404' ) !== false ) {
                return new WP_Error( 'no_program', 'No loyalty program found. Make sure Square Loyalty is enabled for your account.' );
            }
            return new WP_Error( 'loyalty_api_error', 'Square Loyalty API error: ' . $msg );
        }

        $programs = $list['programs'] ?? [];
        if ( empty( $programs ) ) {
            return new WP_Error( 'no_program', 'No loyalty program found. Create a loyalty program in your Square Dashboard first.' );
        }

        return $programs[0];
    }

    /**
     * Search loyalty accounts (all enrolled customers).
     * Returns array of loyalty account objects with customer_id and phone.
     */
    public function get_loyalty_accounts( $limit = 200 ) {
        $accounts = [];
        $cursor   = null;

        do {
            $body = [
                'limit' => min( $limit, 200 ),
                'query' => new \stdClass(), // Empty query = return all accounts
            ];
            if ( $cursor ) {
                $body['cursor'] = $cursor;
            }

            $response = $this->request( '/loyalty/accounts/search', 'POST', $body );
            if ( is_wp_error( $response ) ) {
                // If search fails, the merchant might not have loyalty enabled
                $msg = $response->get_error_message();
                if ( empty( $accounts ) ) {
                    return $response;
                }
                break; // Return what we have so far
            }

            if ( ! empty( $response['loyalty_accounts'] ) ) {
                $accounts = array_merge( $accounts, $response['loyalty_accounts'] );
            }

            $cursor = $response['cursor'] ?? null;
        } while ( $cursor );

        return $accounts;
    }

    /**
     * Retrieve customer details by ID.
     */
    public function get_customer( $customer_id ) {
        $response = $this->request( '/customers/' . rawurlencode( $customer_id ) );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        return $response['customer'] ?? null;
    }

    /**
     * Retrieve customers by IDs — uses individual fetches for reliability.
     */
    public function get_customers_bulk( array $customer_ids ) {
        $customers = [];

        // Try the bulk endpoint first (available since Square API 2024-06-04).
        $chunks = array_chunk( $customer_ids, 100 );
        $bulk_works = true;

        foreach ( $chunks as $chunk ) {
            if ( ! $bulk_works ) {
                // Bulk endpoint didn't work — fall back to individual fetches
                foreach ( $chunk as $cid ) {
                    $c = $this->get_customer( $cid );
                    if ( ! is_wp_error( $c ) && $c ) {
                        $customers[ $cid ] = $c;
                    }
                }
                continue;
            }

            $body = [ 'customer_ids' => array_values( $chunk ) ];
            $response = $this->request( '/customers/bulk-retrieve', 'POST', $body );

            if ( is_wp_error( $response ) ) {
                $bulk_works = false;
                // Fall back to individual fetches for this chunk
                foreach ( $chunk as $cid ) {
                    $c = $this->get_customer( $cid );
                    if ( ! is_wp_error( $c ) && $c ) {
                        $customers[ $cid ] = $c;
                    }
                }
                continue;
            }

            if ( ! empty( $response['responses'] ) ) {
                foreach ( $response['responses'] as $cid => $resp ) {
                    if ( ! empty( $resp['customer'] ) ) {
                        $customers[ $cid ] = $resp['customer'];
                    }
                }
            }
        }

        return $customers;
    }

    /**
     * Get all loyalty customers with their name, phone, and loyalty balance.
     * Returns array of normalized customer records.
     */
    public function get_loyalty_customers() {
        $program = $this->get_loyalty_program();
        if ( is_wp_error( $program ) ) {
            return $program;
        }

        $accounts = $this->get_loyalty_accounts();
        if ( is_wp_error( $accounts ) ) {
            return $accounts;
        }
        if ( empty( $accounts ) ) {
            return [];
        }

        // Collect unique customer IDs
        $customer_ids = [];
        $account_map  = []; // customer_id => loyalty account data
        foreach ( $accounts as $acct ) {
            $cid = $acct['customer_id'] ?? '';
            if ( $cid ) {
                $customer_ids[]      = $cid;
                $account_map[ $cid ] = $acct;
            }
        }
        $customer_ids = array_values( array_unique( $customer_ids ) );

        if ( empty( $customer_ids ) ) {
            return [];
        }

        // Fetch customer details (name, phone, email)
        $customers = $this->get_customers_bulk( $customer_ids );

        // Build normalized list
        $results = [];
        foreach ( $customer_ids as $cid ) {
            $cust = $customers[ $cid ] ?? null;
            $acct = $account_map[ $cid ] ?? null;
            if ( ! $cust ) continue;

            $phone  = $cust['phone_number'] ?? '';
            $given  = $cust['given_name'] ?? '';
            $family = $cust['family_name'] ?? '';
            $name   = trim( $given . ' ' . $family );
            $email  = $cust['email_address'] ?? '';

            $results[] = [
                'customer_id'        => $cid,
                'loyalty_account_id' => $acct['id'] ?? '',
                'name'               => $name,
                'given_name'         => $given,
                'family_name'        => $family,
                'phone'              => $phone,
                'email'              => $email,
                'balance'            => $acct['balance'] ?? 0,
                'lifetime_points'    => $acct['lifetime_points'] ?? 0,
                'enrolled_at'        => $acct['enrolled_at'] ?? $acct['created_at'] ?? '',
            ];
        }

        return $results;
    }

    /**
     * Fetch all CATEGORY objects from the Square catalog.
     * Returns associative array of category_id => category_name.
     */
    public function get_all_categories() {
        $categories = [];
        $cursor     = null;

        do {
            $params = [ 'types' => 'CATEGORY' ];
            if ( $cursor ) {
                $params['cursor'] = $cursor;
            }

            $response = $this->request( '/catalog/list?' . http_build_query( $params ) );

            if ( is_wp_error( $response ) ) {
                break;
            }

            foreach ( $response['objects'] ?? [] as $obj ) {
                $cat_name = trim( $obj['category_data']['name'] ?? '' );
                $cat_name = preg_replace( '/\s+/', ' ', str_replace( "\xc2\xa0", ' ', $cat_name ) );
                if ( $cat_name !== '' ) {
                    $categories[ $obj['id'] ] = $cat_name;
                }
            }

            $cursor = $response['cursor'] ?? null;
        } while ( $cursor );

        return $categories;
    }

    /**
     * Fetch and normalize a single Square catalog item by its ID.
     * Returns a normalized product array (same shape as get_normalized_products items)
     * or a WP_Error on failure.
     */
    public function get_normalized_single_product( $item_id ) {
        $logger = new SWS_Sync_Logger();
        $logger->info( sprintf( '[SingleSync] Fetching Square item: %s', $item_id ) );

        $response = $this->request( '/catalog/object/' . rawurlencode( $item_id ) . '?include_related_objects=true' );
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $item = $response['object'] ?? null;
        if ( ! $item || ( $item['type'] ?? '' ) !== 'ITEM' ) {
            return new WP_Error( 'not_found', 'Square catalog item not found or is not an ITEM type.' );
        }

        // Build related-objects lookup
        $related = [];
        foreach ( $response['related_objects'] ?? [] as $obj ) {
            $related[ $obj['id'] ] = $obj;
        }

        // Build category lookup from related objects
        $cat_lookup = [];
        foreach ( $related as $obj ) {
            if ( ( $obj['type'] ?? '' ) === 'CATEGORY' ) {
                $cat_name = trim( $obj['category_data']['name'] ?? '' );
                $cat_name = preg_replace( '/\s+/', ' ', str_replace( "\xc2\xa0", ' ', $cat_name ) );
                if ( $cat_name !== '' ) {
                    $cat_lookup[ $obj['id'] ] = $cat_name;
                }
            }
        }
        if ( empty( $cat_lookup ) ) {
            $cat_lookup = $this->get_all_categories();
        }

        $item_data = $item['item_data'] ?? [];
        $name      = $item_data['name'] ?? '';
        if ( empty( $name ) ) {
            return new WP_Error( 'no_name', 'Square catalog item has no name.' );
        }

        // Collect variation IDs for inventory lookup
        $variation_ids = [];
        foreach ( $item_data['variations'] ?? [] as $variation ) {
            $variation_ids[] = $variation['id'];
        }
        $inventory = $this->get_inventory_counts( $variation_ids );

        // Resolve categories
        $categories = [];
        foreach ( $item_data['categories'] ?? [] as $cat_ref ) {
            $cat_id = $cat_ref['id'] ?? '';
            if ( $cat_id && isset( $cat_lookup[ $cat_id ] ) ) {
                $categories[] = $cat_lookup[ $cat_id ];
            } elseif ( $cat_id && isset( $related[ $cat_id ] ) ) {
                $cat_name = $related[ $cat_id ]['category_data']['name'] ?? '';
                if ( $cat_name !== '' ) $categories[] = $cat_name;
            }
        }
        if ( empty( $categories ) && ! empty( $item_data['category_id'] ) ) {
            $legacy_id = $item_data['category_id'];
            if ( isset( $cat_lookup[ $legacy_id ] ) ) {
                $categories[] = $cat_lookup[ $legacy_id ];
            } elseif ( isset( $related[ $legacy_id ] ) ) {
                $cat_name = $related[ $legacy_id ]['category_data']['name'] ?? '';
                if ( $cat_name !== '' ) $categories[] = $cat_name;
            }
        }
        if ( empty( $categories ) && ! empty( $item_data['reporting_category'] ) ) {
            $rep_id = $item_data['reporting_category']['id'] ?? '';
            if ( $rep_id && isset( $cat_lookup[ $rep_id ] ) ) {
                $categories[] = $cat_lookup[ $rep_id ];
            }
        }

        // Resolve image
        $image_url = '';
        foreach ( $item_data['image_ids'] ?? [] as $img_id ) {
            if ( isset( $related[ $img_id ] ) ) {
                $image_url = $related[ $img_id ]['image_data']['url'] ?? '';
                break;
            }
        }

        // Parse variations
        $variations = [];
        foreach ( $item_data['variations'] ?? [] as $var ) {
            $var_data    = $var['item_variation_data'] ?? [];
            $price_money = $var_data['price_money'] ?? null;
            $price       = $price_money ? ( $price_money['amount'] / 100 ) : null;
            $var_name    = $var_data['name'] ?? 'Default';
            $sku         = $var_data['sku'] ?? '';
            $qty         = $inventory[ $var['id'] ] ?? 0;

            $variations[] = [
                'square_variation_id' => $var['id'],
                'name'                => $var_name,
                'sku'                 => $sku,
                'price'               => $price,
                'quantity'            => $qty,
                'option_values'       => array_map( 'trim', explode( '/', $var_name ) ),
            ];
        }

        $categories = array_values( array_unique( array_filter( array_map( function( $c ) {
            $c = trim( $c );
            $c = preg_replace( '/\s+/', ' ', $c );
            $c = str_replace( "\xc2\xa0", ' ', $c );
            return trim( $c );
        }, $categories ) ) ) );

        $logger->info( sprintf( '[SingleSync] Found: "%s", %d variation(s), categories: %s',
            $name, count( $variations ), implode( ', ', $categories ) ?: '(none)' ) );

        return [
            'square_id'   => $item['id'],
            'name'        => $name,
            'description' => $item_data['description'] ?? '',
            'categories'  => $categories,
            'image_url'   => $image_url,
            'variations'  => $variations,
            'is_single'   => ( count( $variations ) === 1 && ( $variations[0]['name'] ?? '' ) === 'Regular' ),
        ];
    }

    /**
     * Build normalized product list from Square catalog.
     * Returns array of normalized Square product objects.
     */
    public function get_normalized_products() {
        $logger = new SWS_Sync_Logger();
        $logger->info( 'Fetching Square catalog...' );

        $catalog = $this->get_catalog_items_full();
        if ( is_wp_error( $catalog ) ) {
            return $catalog;
        }

        $items   = $catalog['items'];
        $related = $catalog['related'];

        $logger->info( sprintf( 'Fetched %d catalog items from Square.', count( $items ) ) );

        $cat_lookup = [];
        foreach ( $related as $obj ) {
            if ( ( $obj['type'] ?? '' ) === 'CATEGORY' ) {
                $cat_name = trim( $obj['category_data']['name'] ?? '' );
                $cat_name = preg_replace( '/\s+/', ' ', str_replace( "\xc2\xa0", ' ', $cat_name ) );
                if ( $cat_name !== '' ) {
                    $cat_lookup[ $obj['id'] ] = $cat_name;
                }
            }
        }

        if ( empty( $cat_lookup ) ) {
            $logger->info( 'No categories found in related objects, fetching categories separately...' );
            $cat_lookup = $this->get_all_categories();
            $logger->info( sprintf( 'Fetched %d categories from Square catalog.', count( $cat_lookup ) ) );
        }

        // Collect all variation IDs for inventory lookup
        $variation_ids = [];
        foreach ( $items as $item ) {
            foreach ( $item['item_data']['variations'] ?? [] as $variation ) {
                $variation_ids[] = $variation['id'];
            }
        }

        $inventory = $this->get_inventory_counts( $variation_ids );
        $logger->info( sprintf( 'Fetched inventory for %d variations.', count( $inventory ) ) );

        $products = [];

        foreach ( $items as $item ) {
            $item_data = $item['item_data'] ?? [];
            $name      = $item_data['name'] ?? '';

            if ( empty( $name ) ) continue;

            // Resolve categories from multiple sources
            $categories = [];

            // Source 1: item_data.categories (newer array format)
            foreach ( $item_data['categories'] ?? [] as $cat_ref ) {
                $cat_id = $cat_ref['id'] ?? '';
                if ( $cat_id && isset( $cat_lookup[ $cat_id ] ) ) {
                    $categories[] = $cat_lookup[ $cat_id ];
                } elseif ( $cat_id && isset( $related[ $cat_id ] ) ) {
                    $cat_name = $related[ $cat_id ]['category_data']['name'] ?? '';
                    if ( $cat_name !== '' ) {
                        $categories[] = $cat_name;
                    }
                }
            }

            // Source 2: item_data.category_id (legacy single category)
            if ( empty( $categories ) && ! empty( $item_data['category_id'] ) ) {
                $legacy_id = $item_data['category_id'];
                if ( isset( $cat_lookup[ $legacy_id ] ) ) {
                    $categories[] = $cat_lookup[ $legacy_id ];
                } elseif ( isset( $related[ $legacy_id ] ) ) {
                    $cat_name = $related[ $legacy_id ]['category_data']['name'] ?? '';
                    if ( $cat_name !== '' ) {
                        $categories[] = $cat_name;
                    }
                }
            }

            // Source 3: item_data.reporting_category (reporting category fallback)
            if ( empty( $categories ) && ! empty( $item_data['reporting_category'] ) ) {
                $rep_id = $item_data['reporting_category']['id'] ?? '';
                if ( $rep_id && isset( $cat_lookup[ $rep_id ] ) ) {
                    $categories[] = $cat_lookup[ $rep_id ];
                } elseif ( $rep_id && isset( $related[ $rep_id ] ) ) {
                    $cat_name = $related[ $rep_id ]['category_data']['name'] ?? '';
                    if ( $cat_name !== '' ) {
                        $categories[] = $cat_name;
                    }
                }
            }

            // Resolve image
            $image_url = '';
            foreach ( $item_data['image_ids'] ?? [] as $img_id ) {
                if ( isset( $related[ $img_id ] ) ) {
                    $image_url = $related[ $img_id ]['image_data']['url'] ?? '';
                    break;
                }
            }

            // Parse variations
            $variations = [];
            foreach ( $item_data['variations'] ?? [] as $var ) {
                $var_data = $var['item_variation_data'] ?? [];
                $price_money = $var_data['price_money'] ?? null;
                $price = $price_money ? ( $price_money['amount'] / 100 ) : null;

                $variation_name = $var_data['name'] ?? 'Default';
                $sku            = $var_data['sku'] ?? '';
                $qty            = $inventory[ $var['id'] ] ?? 0;

                // Parse options from variation name (e.g. "Red / Large")
                $option_values = array_map( 'trim', explode( '/', $variation_name ) );

                $variations[] = [
                    'square_variation_id' => $var['id'],
                    'name'                => $variation_name,
                    'sku'                 => $sku,
                    'price'               => $price,
                    'quantity'            => $qty,
                    'option_values'       => $option_values,
                ];
            }

            $categories = array_values( array_unique( array_filter( array_map( function( $c ) {
                $c = trim( $c );
                $c = preg_replace( '/\s+/', ' ', $c );
                $c = str_replace( "\xc2\xa0", ' ', $c );
                return trim( $c );
            }, $categories ) ) ) );

            $products[] = [
                'square_id'        => $item['id'],
                'name'             => $name,
                'description'      => $item_data['description'] ?? '',
                'categories'       => $categories,
                'image_url'        => $image_url,
                'variations'       => $variations,
                'is_single'        => ( count( $variations ) === 1 && ( $variations[0]['name'] ?? '' ) === 'Regular' ),
            ];
        }

        return $products;
    }
}
