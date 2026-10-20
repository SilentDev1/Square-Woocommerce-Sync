<?php
/**
 * Product-name rules of the matcher (no WordPress needed). Run: php tests/matcher/run.php
 */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../../includes/class-product-matcher.php';

$fail = 0;
$pass = 0;
function check( $ok, $what ) {
	global $fail, $pass;
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $what . "\n";
	$ok ? $pass++ : $fail++;
}
$M = 'SWS_Product_Matcher';

// Kit vs replacement pods: never the same product (Evolve, 2026-10-20: both Square items synced into one).
check( ! $M::names_compatible( 'Crave Ultra Pods', 'Crave Ultra Pods Kit' ), 'pods ≠ pods kit' );
check( ! $M::names_compatible( 'Crave Ultra Pods Kit', 'Crave Ultra Pods' ), 'pods kit ≠ pods (either order)' );
check( ! $M::names_compatible( 'Vaporesso XROS Pod Cartridge', 'Vaporesso XROS 4 Kit' ), 'cartridge ≠ kit' );
check( $M::names_compatible( 'Crave Ultra Pods Kit', 'Crave Ultra Pods Kit' ), 'kit = kit' );
check( $M::names_compatible( 'Crave Ultra Pods', 'CRAVE ULTRA PODS' ), 'pods = pods (case)' );
check( $M::names_compatible( 'Vaporesso XROS Pods', 'Vaporesso XROS Replacement Pod Cartridge' ), 'pods = replacement pod cartridge' );
// Unchanged when only one side says what it is.
check( $M::names_compatible( 'Geek Bar Pulse', 'Geek Bar Pulse Kit' ), 'no form on one side: unchanged' );
check( $M::names_compatible( 'SMOK Nord 5', 'SMOK Nord 5 Pod Kit' ), 'model vs its kit: unchanged' );
// Loose mode (review-only decisions) ignores the form.
check( $M::names_compatible( 'Crave Ultra Pods', 'Crave Ultra Pods Kit', false ), 'loose mode keeps old behaviour' );
// Existing rules still hold.
check( ! $M::names_compatible( 'Custard Monster Salts Pumpkin Spice', 'Custard Monster Salt – Butterscotch' ), 'different flavours still differ' );
check( $M::names_compatible( 'Elf Bar BC5000', 'ELFBAR BC 5000' ), 'glued brand still matches' );

// Two Square items may share one product only when they are real duplicates (same name).
check( $M::same_item_name( 'Pod Juice Clear', ' pod juice  CLEAR ' ), 'duplicate items: same name' );
check( ! $M::same_item_name( 'Crave Ultra Pods', 'Crave Ultra Pods Kit' ), 'pods and kit are not duplicates' );
check( ! $M::same_item_name( '', '' ), 'empty names are never "the same"' );

check( 'kit' === $M::product_form( 'Crave Ultra Pods Kit' ), 'form: kit wins over pods' );
check( 'pods' === $M::product_form( 'Crave Ultra Pods' ), 'form: pods' );
check( '' === $M::product_form( 'Geek Bar Pulse 15000' ), 'form: unknown' );
check( '' === $M::product_form( 'Podium Cola' ), 'form: words, not substrings' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
