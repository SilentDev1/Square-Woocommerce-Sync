<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Handles AI-powered product matching and description generation.
 * Uses the configured AI provider (Anthropic Claude or OpenAI) for intelligent cross-checking.
 */
class SWS_Ai_Matcher {

    private $api_key;
    private $provider;
    private $model;

    public function __construct() {
        $stored_key     = get_option( 'sws_ai_api_key', '' );
        $this->api_key  = ! empty( $stored_key ) ? sws_decrypt_key( $stored_key ) : '';
        $this->provider = get_option( 'sws_ai_provider', 'anthropic' );
        $this->model    = get_option( 'sws_ai_model', 'claude-3-haiku-20240307' );
    }

    public function test_connection() {
        if ( empty( $this->api_key ) ) {
            return [ 'success' => false, 'message' => 'AI API key not configured.' ];
        }

        $response = $this->complete( 'Say "OK" and nothing else.', 10 );
        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }
        return [ 'success' => true, 'message' => 'AI connected: ' . trim( $response ) ];
    }

    /**
     * Core completion request.
     */
    public function complete( $prompt, $max_tokens = 500 ) {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_key', 'AI API key not configured.' );
        }

        if ( $this->provider === 'openai' ) {
            return $this->openai_complete( $prompt, $max_tokens );
        }
        return $this->anthropic_complete( $prompt, $max_tokens );
    }

    private function anthropic_complete( $prompt, $max_tokens ) {
        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 60,
            'headers' => [
                'x-api-key'         => $this->api_key,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ],
            'body' => json_encode([
                'model'      => $this->model ?: 'claude-3-haiku-20240307',
                'max_tokens' => $max_tokens,
                'messages'   => [
                    [ 'role' => 'user', 'content' => $prompt ],
                ],
            ]),
        ]);

        if ( is_wp_error( $response ) ) return $response;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $data['error'] ) ) {
            return new WP_Error( 'ai_error', $data['error']['message'] ?? 'Unknown AI error' );
        }
        return $data['content'][0]['text'] ?? '';
    }

    private function openai_complete( $prompt, $max_tokens ) {
        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'timeout' => 60,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model'      => $this->model ?: 'gpt-4o-mini',
                'max_tokens' => $max_tokens,
                'messages'   => [
                    [ 'role' => 'user', 'content' => $prompt ],
                ],
            ]),
        ]);

        if ( is_wp_error( $response ) ) return $response;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $data['error'] ) ) {
            return new WP_Error( 'ai_error', $data['error']['message'] ?? 'Unknown AI error' );
        }
        return $data['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Ask AI to match a Square product to a list of WooCommerce candidates.
     * Returns: [ 'match_id' => int|null, 'confidence' => float, 'reasoning' => string ]
     */
    public function find_best_match( array $square_product, array $woo_candidates ) {
        if ( empty( $woo_candidates ) ) {
            return [ 'match_id' => null, 'confidence' => 0, 'reasoning' => 'No candidates.' ];
        }

        $candidates_text = '';
        foreach ( $woo_candidates as $idx => $wc ) {
            $cats = implode( ', ', wp_get_post_terms( $wc->get_id(), 'product_cat', ['fields' => 'names'] ) );
            $variations_text = '';
            if ( $wc->is_type( 'variable' ) ) {
                $children = $wc->get_children();
                foreach ( array_slice( $children, 0, 10 ) as $child_id ) {
                    $child = wc_get_product( $child_id );
                    if ( $child ) {
                        $attrs = implode( '/', $child->get_variation_attributes() );
                        $child_sku = $child->get_sku();
                        $child_price = $child->get_regular_price();
                        $variations_text .= "\n    - Variation: {$attrs} | SKU: {$child_sku} | Price: \${$child_price}";
                    }
                }
            }
            $wc_price = $wc->get_regular_price() ?: ( $wc->get_price() ?: 'N/A' );
            $candidates_text .= sprintf(
                "\n[%d] WooCommerce ID: %d | Name: %s | SKU: %s | Price: $%s | Type: %s | Categories: %s%s",
                $idx + 1,
                $wc->get_id(),
                $wc->get_name(),
                $wc->get_sku() ?: '(none)',
                $wc_price,
                $wc->get_type(),
                $cats ?: 'None',
                $variations_text
            );
        }

        $square_variations_text = '';
        foreach ( $square_product['variations'] as $v ) {
            $square_variations_text .= "\n  - {$v['name']} | SKU: {$v['sku']} | Price: \${$v['price']}";
        }

        $square_cats = implode( ', ', $square_product['categories'] );

        $prompt = <<<PROMPT
You are a product matching expert for an inventory sync between Square POS and WooCommerce.

SQUARE PRODUCT:
Name: {$square_product['name']}
Categories: {$square_cats}
Variations:{$square_variations_text}

WOOCOMMERCE CANDIDATES:{$candidates_text}

YOUR JOB: Determine which WooCommerce candidate (if any) is the SAME product as the Square product.

MATCHING RULES — follow these carefully:
1. Two products are the SAME if they share the same core identity (brand + model/product line).
2. IGNORE these common naming differences between Square and WooCommerce:
   - Extra specs/suffixes: "Product X" = "Product X 30k" or "Product X 5000"
   - Size/capacity numbers appended or missing
   - Minor word additions: "Brand Model" = "Brand Model Pro" or "Brand Model Kit"
   - Abbreviations, capitalization, punctuation, spacing differences
   - Category words added: "Widget" = "Premium Widget" or "Widget Set"
3. Focus on the BRAND + MODEL words. If the core identifying words match, it IS the same product.
4. SKU matches are a strong signal — if a Square variation's SKU matches a WooCommerce product's SKU, it is almost certainly the same product.
5. Category similarity is a supporting signal — products in the same category are more likely to match.
6. A Square product with multiple variations is likely a variable product in WooCommerce.
7. PREFER matching over not matching. If there is a plausible candidate, MATCH it. Only return null if NO candidate is reasonably similar.
8. Confidence guide: >0.85 = very sure, 0.65-0.85 = likely match. Below 0.5 = no match.
9. If NONE of the candidates match, return match_woo_id: null with confidence 0.

Respond ONLY with valid JSON in this exact format:
{"match_woo_id": <integer or null>, "confidence": <float 0.0-1.0>, "reasoning": "<one sentence>"}
PROMPT;

        $result = $this->complete( $prompt, 300 );

        if ( is_wp_error( $result ) ) {
            return [ 'match_id' => null, 'confidence' => 0, 'reasoning' => 'AI error: ' . $result->get_error_message() ];
        }

        // Extract JSON from response
        preg_match( '/\{.*\}/s', $result, $matches );
        if ( empty( $matches[0] ) ) {
            return [ 'match_id' => null, 'confidence' => 0, 'reasoning' => 'AI returned unparseable response.' ];
        }

        $parsed = json_decode( $matches[0], true );
        if ( ! $parsed ) {
            return [ 'match_id' => null, 'confidence' => 0, 'reasoning' => 'JSON parse failed.' ];
        }

        return [
            'match_id'   => isset( $parsed['match_woo_id'] ) ? (int) $parsed['match_woo_id'] : null,
            'confidence' => (float) ( $parsed['confidence'] ?? 0 ),
            'reasoning'  => $parsed['reasoning'] ?? '',
        ];
    }

    /**
     * Match a Square variation to a WooCommerce variation.
     */
    public function match_variation( array $square_variation, array $woo_variations ) {
        if ( empty( $woo_variations ) ) {
            return [ 'match_id' => null, 'confidence' => 0 ];
        }

        $candidates_text = '';
        foreach ( $woo_variations as $wv ) {
            $attrs = implode( '/', $wv->get_variation_attributes() );
            $candidates_text .= sprintf(
                "\n  - WC Variation ID: %d | Attributes: %s | SKU: %s",
                $wv->get_id(), $attrs, $wv->get_sku()
            );
        }

        $prompt = <<<PROMPT
Match this Square variation to the best WooCommerce variation:

SQUARE VARIATION:
Name: {$square_variation['name']}
SKU: {$square_variation['sku']}
Option Values: {$square_variation['option_values_str']}

WOOCOMMERCE VARIATIONS:{$candidates_text}

Respond ONLY with valid JSON: {"match_woo_id": <integer or null>, "confidence": <float 0.0-1.0>}
PROMPT;

        $result = $this->complete( $prompt, 150 );
        if ( is_wp_error( $result ) ) {
            return [ 'match_id' => null, 'confidence' => 0 ];
        }

        preg_match( '/\{.*\}/s', $result, $matches );
        $parsed = json_decode( $matches[0] ?? '', true );

        return [
            'match_id'   => isset( $parsed['match_woo_id'] ) ? (int) $parsed['match_woo_id'] : null,
            'confidence' => (float) ( $parsed['confidence'] ?? 0 ),
        ];
    }

    /**
     * Generate a product description by searching the web context about the product.
     * Uses AI knowledge to create a description since direct web search isn't available from WP.
     */
    public function generate_product_description( array $square_product ) {
        $categories = implode( ', ', $square_product['categories'] );
        $variations = [];
        foreach ( $square_product['variations'] as $v ) {
            $variations[] = $v['name'] . ( $v['price'] ? " (\${$v['price']})" : '' );
        }
        $variations_str = implode( ', ', $variations );

        $prompt = <<<PROMPT
You are an e-commerce product copywriter. Write product copy for the following product. Use your knowledge to provide accurate, helpful information about this product.

Product Name: {$square_product['name']}
Categories: {$categories}
Available Variations/Options: {$variations_str}
Existing Description: {$square_product['description']}

You must return ONLY valid JSON in this exact format (no other text):
{
  "short_description": "<p>A concise 1-2 sentence summary highlighting the key selling point. Keep it punchy and under 160 characters.</p>",
  "description": "<p>Paragraph 1...</p><p>Paragraph 2...</p><p>Paragraph 3...</p>"
}

Rules for the description (2-3 paragraphs with <p> tags):
1. Describes what the product is and its key features/benefits
2. Mentions common uses or who it's for
3. Is engaging and optimized for e-commerce

Rules for the short_description (1-2 sentences with <p> tags):
1. Brief, punchy summary of the product
2. Highlights the main selling point or benefit
3. Suitable for product listing cards and search results
PROMPT;

        $result = $this->complete( $prompt, 800 );
        if ( is_wp_error( $result ) ) {
            $fallback = '<p>' . esc_html( $square_product['name'] ) . '</p>';
            return [ 'description' => $fallback, 'short_description' => $fallback ];
        }

        preg_match( '/\{.*\}/s', $result, $matches );
        $parsed = json_decode( $matches[0] ?? '', true );

        if ( $parsed && isset( $parsed['description'] ) ) {
            return [
                'description'       => wp_kses_post( trim( $parsed['description'] ) ),
                'short_description' => wp_kses_post( trim( $parsed['short_description'] ?? '' ) ),
            ];
        }

        return [
            'description'       => wp_kses_post( trim( $result ) ),
            'short_description' => '',
        ];
    }

    /**
     * Use AI to determine the correct WooCommerce attribute name for each
     * dimension of option values extracted from Square variations.
     *
     * Example input:  ['Option' => ['Miami Mint', 'Blue Razz', 'Watermelon Ice']]
     * Example output: ['Flavor' => ['Miami Mint', 'Blue Razz', 'Watermelon Ice']]
     *
     * @param  array  $option_labels  Generic-named dimensions from extract_attribute_options().
     * @param  string $product_name   The Square product name for context.
     * @return array                  Same shape with AI-detected attribute names.
     */
    public function detect_attribute_labels( array $option_labels, $product_name = '' ) {
        if ( empty( $this->api_key ) || empty( $option_labels ) ) {
            return $option_labels;
        }

        $dimensions_text = '';
        foreach ( $option_labels as $generic_name => $values ) {
            $sample = array_slice( $values, 0, 15 );
            $dimensions_text .= sprintf(
                "\nDimension \"%s\": %s",
                $generic_name,
                implode( ', ', $sample )
            );
            if ( count( $values ) > 15 ) {
                $dimensions_text .= sprintf( ' ... (%d total)', count( $values ) );
            }
        }

        $prompt = <<<PROMPT
You are a WooCommerce product attribute expert. Given a product and its variation option values, determine the correct attribute label for each dimension.

Product: {$product_name}
{$dimensions_text}

Common attribute names: Flavor, Nicotine Strength, Size, Color, Puff Count, Wattage, Resistance, Capacity, Material, Scent, Strength, Type, Pack Size, Concentration

Rules:
1. Choose the MOST SPECIFIC attribute name that describes ALL values in a dimension.
2. If the values are flavor names (fruit, candy, menthol, drink names), use "Flavor".
3. If the values contain mg/ml measurements, use "Nicotine Strength".
4. If the values are puff counts (numbers like 5000, 10000, 15000), use "Puff Count".
5. If the values are physical sizes (S/M/L, ml, oz), use "Size".
6. If the values are colors, use "Color".
7. Use title case, singular form (e.g., "Flavor" not "Flavors" or "flavor").
8. If you cannot determine a good name, keep the original generic name.

Respond ONLY with valid JSON mapping each generic name to the detected name:
{"Option": "Flavor", "Size": "Size"}
PROMPT;

        $result = $this->complete( $prompt, 200 );
        if ( is_wp_error( $result ) ) {
            return $option_labels;
        }

        preg_match( '/\{.*\}/s', $result, $matches );
        $parsed = json_decode( $matches[0] ?? '', true );

        if ( ! is_array( $parsed ) ) {
            return $option_labels;
        }

        $remapped = [];
        foreach ( $option_labels as $generic_name => $values ) {
            $detected = $parsed[ $generic_name ] ?? $generic_name;
            $detected = sanitize_text_field( $detected );
            if ( empty( $detected ) ) {
                $detected = $generic_name;
            }
            $remapped[ $detected ] = $values;
        }

        return $remapped;
    }

    /**
     * Use AI to extract attribute values from SKUs when variation names
     * are generic (e.g., "Regular") but SKUs contain encoded information.
     *
     * @param  array  $variations    Square variations array.
     * @param  string $product_name  Product name for context.
     * @return array                 Same variations with enriched option_values.
     */
    public function extract_attributes_from_skus( array $variations, $product_name = '' ) {
        if ( empty( $this->api_key ) || empty( $variations ) ) {
            return $variations;
        }

        // Only invoke AI if variations have generic/empty names but have SKUs.
        $needs_extraction = false;
        foreach ( $variations as $v ) {
            $name = strtolower( trim( $v['name'] ?? '' ) );
            if ( ( $name === 'regular' || $name === 'default' || $name === '' ) && ! empty( $v['sku'] ) ) {
                $needs_extraction = true;
                break;
            }
        }
        if ( ! $needs_extraction ) {
            return $variations;
        }

        $sku_list = '';
        foreach ( $variations as $i => $v ) {
            $sku_list .= sprintf( "\n[%d] Name: \"%s\" | SKU: \"%s\"", $i, $v['name'], $v['sku'] );
        }

        $prompt = <<<PROMPT
You are analyzing product variation SKUs to extract attribute values (e.g., flavor, size, color).

Product: {$product_name}
Variations:{$sku_list}

Rules:
1. Examine the SKU patterns to identify encoded attributes (flavors, sizes, etc.).
2. For each variation, determine the most likely attribute value from the SKU.
3. Common SKU patterns: brand abbreviations + flavor codes (e.g., "GB-MM" = "Miami Mint", "GB-BR" = "Blue Razz").
4. Use your product knowledge: if the product is a vape/e-juice, SKU codes likely represent flavors.
5. If the variation already has a descriptive name (not "Regular"/"Default"), keep it.
6. If you CANNOT determine the value, use the variation name as-is.

Respond ONLY with valid JSON — an array of extracted values in order:
[{"index": 0, "attribute_value": "Miami Mint"}, {"index": 1, "attribute_value": "Blue Razz"}]
PROMPT;

        $result = $this->complete( $prompt, 1000 );
        if ( is_wp_error( $result ) ) {
            return $variations;
        }

        preg_match( '/\[.*\]/s', $result, $matches );
        $parsed = json_decode( $matches[0] ?? '', true );

        if ( ! is_array( $parsed ) ) {
            return $variations;
        }

        foreach ( $parsed as $entry ) {
            $idx   = (int) ( $entry['index'] ?? -1 );
            $value = sanitize_text_field( $entry['attribute_value'] ?? '' );
            if ( $idx >= 0 && $idx < count( $variations ) && $value !== '' ) {
                $old_name = strtolower( trim( $variations[ $idx ]['name'] ) );
                if ( $old_name === 'regular' || $old_name === 'default' || $old_name === '' ) {
                    $variations[ $idx ]['name']          = $value;
                    $variations[ $idx ]['option_values']  = array_map( 'trim', explode( '/', $value ) );
                }
            }
        }

        return $variations;
    }

    /**
     * Verify a proposed product match before making changes.
     * Extra safety check before modifying WooCommerce products.
     */
    public function verify_match( array $square_product, $woo_product ) {
        $woo_name = $woo_product->get_name();
        $woo_sku  = $woo_product->get_sku();
        $woo_cats = implode( ', ', wp_get_post_terms( $woo_product->get_id(), 'product_cat', ['fields' => 'names'] ) );
        $sq_cats  = implode( ', ', $square_product['categories'] );

        $prompt = <<<PROMPT
Verify if these are the SAME product listed in two different systems (Square POS and WooCommerce).

Square: "{$square_product['name']}" | Categories: {$sq_cats}
WooCommerce: "{$woo_name}" | SKU: {$woo_sku} | Categories: {$woo_cats}

RULES:
- Names may differ: one system might include model numbers, specs, sizes, or extra words that the other omits.
- Focus on whether the CORE product identity (brand + model) is the same.
- Ignore: size suffixes, capacity numbers, minor word additions, abbreviations, punctuation.
- If the key identifying words (brand, model name) match, answer true.
- Same category is a supporting signal.
- When in doubt, prefer true over false — it's better to link products than to create duplicates.

Respond only with: {"same": true/false, "confidence": 0.0-1.0}
PROMPT;

        $result = $this->complete( $prompt, 100 );
        if ( is_wp_error( $result ) ) {
            return [ 'same' => false, 'confidence' => 0 ];
        }

        preg_match( '/\{.*\}/s', $result, $matches );
        $parsed = json_decode( $matches[0] ?? '', true );

        return [
            'same'       => (bool) ( $parsed['same'] ?? false ),
            'confidence' => (float) ( $parsed['confidence'] ?? 0 ),
        ];
    }
}
