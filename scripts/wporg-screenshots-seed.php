<?php
/**
 * Demo-store seeder for the wp.org screenshot captures.
 *
 * Seeds the local wp-env site with a small fictional catalog ("Northbrew" coffee
 * gear) so the listing screenshots show real plugin output over realistic data:
 * a variable product exercising every mirror section (identifiers, classification,
 * specifications, price incl. a dated sale, availability, variants, reviews,
 * images, descriptions) plus two simple products so term mirrors have a list.
 * Demo GTINs are checksum-valid EAN-13s in a made-up 8720123456xxx block; all
 * names and reviews are fictional demo content for the local site only.
 *
 * Idempotent: reruns replace the demo products (matched by SKU).
 *
 * Run:
 *   npx wp-env run cli wp rewrite structure '/%postname%/' --hard
 *   npx wp-env run cli wp eval-file wp-content/plugins/markdown-mirror-for-woocommerce/scripts/wporg-screenshots-seed.php
 *
 * @package AgentMint\MarkdownMirrorWC
 */

if ( ! class_exists( 'WooCommerce' ) ) {
	echo "WooCommerce is not active; aborting.\n";
	exit( 1 );
}

require_once ABSPATH . 'wp-admin/includes/image.php';

// Store display settings the mirrors reflect: always show stock quantities,
// reviews + star ratings on.
update_option( 'woocommerce_stock_format', '' );
update_option( 'woocommerce_weight_unit', 'kg' );
update_option( 'woocommerce_dimension_unit', 'cm' );
update_option( 'woocommerce_enable_reviews', 'yes' );
update_option( 'woocommerce_enable_review_rating', 'yes' );
update_option( 'woocommerce_show_marketplace_suggestions', 'no' );

// --- Replace any previous demo products (idempotency) ---
foreach ( array( 'NB-DRP-CER', 'NB-FLT-02', 'NB-KTL-1L' ) as $sku ) {
	$existing = wc_get_product_id_by_sku( $sku );
	if ( $existing ) {
		$product = wc_get_product( $existing );
		if ( $product ) {
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child ) {
					$child->delete( true );
				}
			}
			$product->delete( true );
		}
	}
}

/**
 * Get-or-create a term, returns term id.
 *
 * @param string $name     Term name.
 * @param string $taxonomy Taxonomy.
 * @param int    $parent   Parent term id.
 * @return int
 */
function pmm_demo_term( $name, $taxonomy, $parent = 0 ) {
	$existing = get_terms(
		array(
			'taxonomy'   => $taxonomy,
			'name'       => $name,
			'parent'     => $parent,
			'hide_empty' => false,
			'number'     => 1,
		)
	);
	if ( ! is_wp_error( $existing ) && ! empty( $existing ) ) {
		return (int) $existing[0]->term_id;
	}
	$created = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent ) );
	return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
}

/**
 * Get-or-create a flat GD-drawn placeholder attachment, returns attachment id.
 *
 * @param string $filename File name.
 * @param string $alt      Alt text.
 * @param array  $rgb      Background color.
 * @return int
 */
function pmm_demo_image( $filename, $alt, $rgb ) {
	$found = get_posts(
		array(
			'post_type'   => 'attachment',
			'meta_key'    => '_pmm_demo_image',
			'meta_value'  => $filename,
			'numberposts' => 1,
			'fields'      => 'ids',
		)
	);
	if ( $found ) {
		return (int) $found[0];
	}

	$img = imagecreatetruecolor( 800, 800 );
	$bg  = imagecolorallocate( $img, $rgb[0], $rgb[1], $rgb[2] );
	$fg  = imagecolorallocate( $img, 255, 255, 255 );
	imagefilledrectangle( $img, 0, 0, 800, 800, $bg );
	// Simple dripper-ish silhouette so the placeholder is not a bare square.
	imagefilledpolygon( $img, array( 220, 280, 580, 280, 480, 560, 320, 560 ), 4, $fg );
	imagefilledrectangle( $img, 300, 570, 500, 600, $fg );
	ob_start();
	imagepng( $img );
	$bytes = ob_get_clean();
	imagedestroy( $img );

	$upload = wp_upload_bits( $filename, null, $bytes );
	if ( ! empty( $upload['error'] ) ) {
		return 0;
	}
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/png',
			'post_title'     => preg_replace( '/\.[^.]+$/', '', $filename ),
			'post_status'    => 'inherit',
		),
		$upload['file']
	);
	wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
	update_post_meta( $attachment_id, '_pmm_demo_image', $filename );
	return (int) $attachment_id;
}

// --- Terms ---
$cat_gear = pmm_demo_term( 'Coffee Gear', 'product_cat' );
$cat_drip = pmm_demo_term( 'Drippers', 'product_cat', $cat_gear );
$tag_ceramic = pmm_demo_term( 'ceramic', 'product_tag' );
$tag_pour    = pmm_demo_term( 'pour-over', 'product_tag' );
$tag_paper   = pmm_demo_term( 'paper', 'product_tag' );
$has_brands  = taxonomy_exists( 'product_brand' );
$brand_id    = $has_brands ? pmm_demo_term( 'Northbrew', 'product_brand' ) : 0;

// --- Images ---
$img_main    = pmm_demo_image( 'ceramic-dripper.png', 'Ceramic pour-over dripper, front view', array( 22, 112, 228 ) );
$img_gallery = pmm_demo_image( 'ceramic-dripper-filter.png', 'Ceramic dripper with a paper filter seated', array( 14, 42, 110 ) );
$img_filters = pmm_demo_image( 'paper-filters.png', 'Pack of 100 size 02 paper filters', array( 47, 107, 222 ) );
$img_kettle  = pmm_demo_image( 'gooseneck-kettle.png', 'Gooseneck pour-over kettle, 1 liter', array( 11, 42, 110 ) );

// --- Product 1: variable dripper (the readme example: /product/ceramic-dripper.md) ---
$size = new WC_Product_Attribute();
$size->set_name( 'Size' );
$size->set_options( array( '01', '02' ) );
$size->set_position( 0 );
$size->set_visible( true );
$size->set_variation( true );

$material = new WC_Product_Attribute();
$material->set_name( 'Material' );
$material->set_options( array( 'Ceramic' ) );
$material->set_position( 1 );
$material->set_visible( true );
$material->set_variation( false );

$dripper = new WC_Product_Variable();
$dripper->set_name( 'Ceramic Pour-Over Dripper' );
$dripper->set_slug( 'ceramic-dripper' );
$dripper->set_status( 'publish' );
$dripper->set_sku( 'NB-DRP-CER' );
if ( method_exists( $dripper, 'set_global_unique_id' ) ) {
	$dripper->set_global_unique_id( '8720123456783' );
}
$dripper->set_category_ids( array( $cat_drip ) );
$dripper->set_tag_ids( array( $tag_ceramic, $tag_pour ) );
$dripper->set_attributes( array( $size, $material ) );
$dripper->set_weight( '0.42' );
$dripper->set_length( '12' );
$dripper->set_width( '12' );
$dripper->set_height( '9' );
$dripper->set_short_description( 'A two-cup ceramic dripper for even, controlled pour-over brewing.' );
$dripper->set_description(
	"<p>The Northbrew ceramic dripper uses a single large drain hole and spiral ribs, so flow rate is set by your grind and pour instead of the brewer. The glazed ceramic body holds heat through the brew for a steady extraction.</p>\n\n" .
	'<p>Size 01 brews one to two cups; size 02 brews two to four. Both take standard conical paper filters and are dishwasher safe.</p>'
);
$dripper->set_reviews_allowed( true );
$dripper->set_image_id( $img_main );
$dripper->set_gallery_image_ids( array( $img_gallery ) );
$dripper_id = $dripper->save();

if ( $has_brands ) {
	wp_set_object_terms( $dripper_id, array( $brand_id ), 'product_brand' );
}

$v1 = new WC_Product_Variation();
$v1->set_parent_id( $dripper_id );
$v1->set_attributes( array( 'size' => '01' ) );
$v1->set_sku( 'NB-DRP-CER-01' );
if ( method_exists( $v1, 'set_global_unique_id' ) ) {
	$v1->set_global_unique_id( '8720123456790' );
}
$v1->set_regular_price( '21.00' );
$v1->set_manage_stock( true );
$v1->set_stock_quantity( 14 );
$v1->save();

$v2 = new WC_Product_Variation();
$v2->set_parent_id( $dripper_id );
$v2->set_attributes( array( 'size' => '02' ) );
$v2->set_sku( 'NB-DRP-CER-02' );
if ( method_exists( $v2, 'set_global_unique_id' ) ) {
	$v2->set_global_unique_id( '8720123456806' );
}
$v2->set_regular_price( '24.00' );
$v2->set_sale_price( '19.00' );
$v2->set_date_on_sale_to( gmdate( 'Y-m-d 23:59:59', time() + 14 * DAY_IN_SECONDS ) );
$v2->set_manage_stock( true );
$v2->set_stock_quantity( 9 );
$v2->save();

WC_Product_Variable::sync( $dripper_id );

// Reviews (fictional demo reviewers).
$existing_reviews = get_comments( array( 'post_id' => $dripper_id, 'type' => 'review', 'count' => true ) );
if ( ! $existing_reviews ) {
	$r1 = wp_insert_comment(
		array(
			'comment_post_ID'      => $dripper_id,
			'comment_author'       => 'Maya R.',
			'comment_author_email' => 'maya@example.com',
			'comment_content'      => 'Even extraction and the 02 fits my carafe perfectly. Pours clean with no bypass.',
			'comment_approved'     => 1,
			'comment_type'         => 'review',
		)
	);
	update_comment_meta( $r1, 'rating', 5 );
	$r2 = wp_insert_comment(
		array(
			'comment_post_ID'      => $dripper_id,
			'comment_author'       => 'Tom B.',
			'comment_author_email' => 'tom@example.com',
			'comment_content'      => 'Good dripper, holds heat well. Wish a starter pack of filters came with it.',
			'comment_approved'     => 1,
			'comment_type'         => 'review',
		)
	);
	update_comment_meta( $r2, 'rating', 4 );
}
$dripper_obj = wc_get_product( $dripper_id );
WC_Comments::get_average_rating_for_product( $dripper_obj );
WC_Comments::get_review_count_for_product( $dripper_obj );

// --- Product 2: simple filters ---
$filters = new WC_Product_Simple();
$filters->set_name( 'Paper Filters for 02 Dripper, 100 Pack' );
$filters->set_slug( 'paper-filters-02' );
$filters->set_status( 'publish' );
$filters->set_sku( 'NB-FLT-02' );
if ( method_exists( $filters, 'set_global_unique_id' ) ) {
	$filters->set_global_unique_id( '8720123456813' );
}
$filters->set_regular_price( '6.50' );
$filters->set_manage_stock( true );
$filters->set_stock_quantity( 120 );
$filters->set_weight( '0.2' );
$filters->set_category_ids( array( $cat_drip ) );
$filters->set_tag_ids( array( $tag_paper, $tag_pour ) );
$filters->set_short_description( 'Unbleached conical paper filters sized for 02 drippers.' );
$filters->set_image_id( $img_filters );
$filters_id = $filters->save();
if ( $has_brands ) {
	wp_set_object_terms( $filters_id, array( $brand_id ), 'product_brand' );
}

// --- Product 3: simple kettle (parent category, so both category mirrors list products) ---
$kettle = new WC_Product_Simple();
$kettle->set_name( 'Gooseneck Pour-Over Kettle 1 L' );
$kettle->set_slug( 'gooseneck-kettle-1l' );
$kettle->set_status( 'publish' );
$kettle->set_sku( 'NB-KTL-1L' );
if ( method_exists( $kettle, 'set_global_unique_id' ) ) {
	$kettle->set_global_unique_id( '8720123456820' );
}
$kettle->set_regular_price( '49.00' );
$kettle->set_manage_stock( true );
$kettle->set_stock_quantity( 7 );
$kettle->set_weight( '0.9' );
$kettle->set_category_ids( array( $cat_gear ) );
$kettle->set_tag_ids( array( $tag_pour ) );
$kettle->set_short_description( 'A 1 liter gooseneck kettle for a slow, precise pour.' );
$kettle->set_image_id( $img_kettle );
$kettle_id = $kettle->save();
if ( $has_brands ) {
	wp_set_object_terms( $kettle_id, array( $brand_id ), 'product_brand' );
}

echo "Seeded demo catalog:\n";
echo "  dripper (variable): {$dripper_id} -> " . get_permalink( $dripper_id ) . "\n";
echo "  filters (simple):   {$filters_id} -> " . get_permalink( $filters_id ) . "\n";
echo "  kettle (simple):    {$kettle_id} -> " . get_permalink( $kettle_id ) . "\n";
echo $has_brands ? "  brand taxonomy: product_brand present, Northbrew assigned\n" : "  brand taxonomy: absent\n";
