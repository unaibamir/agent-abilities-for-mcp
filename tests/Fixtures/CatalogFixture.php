<?php
/**
 * Single source of truth for the read+write catalog slug lists.
 *
 * The exact set of read and write ability slugs is the contract the whole catalog
 * is locked against. It used to live as byte-identical arrays in both CatalogTest
 * and ReadsCatalogTest, so any drift had to be edited in two places and could
 * silently diverge. This fixture holds the one copy each test consumes.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace Oversio\Tests\Fixtures;

/**
 * Canonical READS / WRITES / DESTRUCTIVE_WRITES slug lists for the catalog locks.
 */
final class CatalogFixture {

	/**
	 * The exact, complete set of read abilities.
	 *
	 * @var string[]
	 */
	public const READS = array(
		'oversio/get-posts',
		'oversio/count-posts',
		'oversio/get-post',
		'oversio/get-post-meta',
		'oversio/get-all-post-meta',
		'oversio/get-pages',
		'oversio/get-page',
		'oversio/get-terms',
		'oversio/get-term',
		'oversio/get-term-meta',
		'oversio/get-taxonomies',
		'oversio/get-post-types',
		'oversio/get-site-info',
		'oversio/get-comments',
		'oversio/get-pending-comments',
		'oversio/get-comment',
		'oversio/get-media',
		'oversio/get-media-item',
		'oversio/count-media',
		'oversio/get-users',
		'oversio/get-user',
		'oversio/get-user-meta',
		'oversio/list-revisions',
		'oversio/get-revision',
		'oversio/search-content',
		'oversio/get-site-settings',
		'oversio/list-plugins',
		'oversio/get-activity-log',
		'oversio/list-blocks',
		'oversio/get-block',
		'oversio/list-menus',
		'oversio/get-menu',
		'oversio/list-menu-items',
		'oversio/get-active-theme',
		'oversio/list-themes',
		'oversio/list-templates',
		'oversio/get-template',
		'oversio/get-global-styles',
		'oversio/yoast-get-post',
		'oversio/yoast-get-head',
		'oversio/rankmath-get-post',
		'oversio/rankmath-get-schema',
		'oversio/rankmath-get-head',
		'oversio/aioseo-get-post',
		'oversio/aioseo-get-head',
		'oversio/acf-list-field-groups',
		'oversio/acf-get-post-fields',
		'oversio/acf-get-term-fields',
		'oversio/acf-get-user-fields',
		'oversio/wc-list-products',
		'oversio/wc-get-product',
		'oversio/wc-list-product-variations',
		'oversio/wc-get-product-variation',
		'oversio/wc-list-product-attributes',
		'oversio/wc-list-orders',
		'oversio/wc-get-order',
		'oversio/wc-list-order-notes',
		'oversio/wc-list-order-refunds',
		'oversio/wc-get-order-refund',
		'oversio/wc-list-customers',
		'oversio/wc-get-customer',
		'oversio/wc-list-coupons',
		'oversio/wc-get-coupon',
		'oversio/wc-list-shipping-zones',
		'oversio/wc-get-shipping-zone',
		'oversio/wc-list-shipping-methods',
		'oversio/wc-get-shipping-method',
		'oversio/wc-list-tax-rates',
		'oversio/wc-get-tax-rate',
		'oversio/wc-list-tax-classes',
		'oversio/wc-get-sales-report',
		'oversio/wc-get-top-sellers-report',
		'oversio/wc-count-orders',
		'oversio/wc-count-products',
		'oversio/wc-list-payment-gateways',
		'oversio/wc-get-payment-gateway',
	);

	/**
	 * The exact, complete set of write abilities.
	 *
	 * @var string[]
	 */
	public const WRITES = array(
		'oversio/create-draft',
		'oversio/create-post',
		'oversio/update-post',
		'oversio/replace-in-post',
		'oversio/trash-post',
		'oversio/create-page',
		'oversio/update-page',
		'oversio/trash-page',
		'oversio/create-term',
		'oversio/update-term',
		'oversio/add-post-terms',
		'oversio/update-term-meta',
		'oversio/delete-term-meta',
		'oversio/moderate-comment',
		'oversio/create-comment',
		'oversio/update-comment',
		'oversio/delete-comment',
		'oversio/set-featured-image',
		'oversio/upload-media',
		'oversio/update-media',
		'oversio/delete-media',
		'oversio/update-post-meta',
		'oversio/delete-post-meta',
		'oversio/restore-revision',
		'oversio/delete-revision',
		'oversio/create-cpt-item',
		'oversio/update-cpt-item',
		'oversio/create-user',
		'oversio/update-user',
		'oversio/delete-user',
		'oversio/update-user-meta',
		'oversio/delete-user-meta',
		'oversio/update-site-settings',
		'oversio/delete-post',
		'oversio/delete-page',
		'oversio/create-block',
		'oversio/update-block',
		'oversio/delete-block',
		'oversio/create-menu',
		'oversio/update-menu',
		'oversio/delete-menu',
		'oversio/create-menu-item',
		'oversio/update-menu-item',
		'oversio/delete-menu-item',
		'oversio/update-template',
		'oversio/yoast-update-post',
		'oversio/rankmath-update-post',
		'oversio/rankmath-update-schema',
		'oversio/aioseo-update-post',
		'oversio/acf-update-post-fields',
		'oversio/acf-update-term-fields',
		'oversio/acf-update-user-fields',
		'oversio/wc-create-product',
		'oversio/wc-update-product',
		'oversio/wc-delete-product',
		'oversio/wc-create-product-variation',
		'oversio/wc-update-product-variation',
		'oversio/wc-delete-product-variation',
		'oversio/wc-create-product-attribute',
		'oversio/wc-update-product-attribute',
		'oversio/wc-create-order',
		'oversio/wc-update-order',
		'oversio/wc-update-order-status',
		'oversio/wc-create-order-note',
		'oversio/wc-create-order-refund',
		'oversio/wc-create-customer',
		'oversio/wc-update-customer',
		'oversio/wc-create-coupon',
		'oversio/wc-update-coupon',
		'oversio/wc-create-shipping-zone',
		'oversio/wc-update-shipping-zone',
		'oversio/wc-create-shipping-method',
		'oversio/wc-update-shipping-method',
		'oversio/wc-create-tax-rate',
		'oversio/wc-update-tax-rate',
		'oversio/wc-create-tax-class',
		'oversio/wc-update-payment-gateway',
	);

	/**
	 * The writes whose action is destruction — recoverable (trash / spam) or permanent
	 * (force-delete of posts/pages/media/revisions/meta, comment purge, user-meta removal,
	 * and user removal/creation). These MUST be annotated destructive:true. Every other write
	 * is destructive:false.
	 *
	 * The user CRUD writes create-user and delete-user are destructive: both make a permanent,
	 * security-sensitive change to the user table (a new account, or a removal with content
	 * reassignment). update-user is a recoverable profile edit, so it is NOT destructive.
	 *
	 * update-site-settings is destructive: a settings change is permanent and site-wide, with
	 * no per-setting undo, so the agent is told it is a permanent change.
	 *
	 * delete-post and delete-page are permanent: they force-delete past the Trash through the
	 * single posts.php executor, so the agent is told the removal cannot be undone.
	 *
	 * delete-block is recoverable (it moves a reusable block to the Trash) but is still a
	 * removal the agent is told about, so it is annotated destructive:true.
	 *
	 * delete-menu and delete-menu-item are permanent: navigation menus and their items have no
	 * Trash, so removing a menu (and every item inside it) or a single item cannot be undone, and
	 * the agent is told so.
	 *
	 * wc-delete-product is permanent: it removes a WooCommerce product through the WC data store
	 * (bypassing the Trash), so the agent is told the removal cannot be undone. wc-delete-product-
	 * variation is permanent on the same basis — it removes a single product variation through the WC
	 * data store with no recoverable Trash.
	 *
	 * @var string[]
	 */
	public const DESTRUCTIVE_WRITES = array(
		'oversio/trash-post',
		'oversio/trash-page',
		'oversio/moderate-comment',
		'oversio/delete-comment',
		'oversio/delete-post-meta',
		'oversio/delete-revision',
		'oversio/delete-media',
		'oversio/delete-term-meta',
		'oversio/create-user',
		'oversio/delete-user',
		'oversio/delete-user-meta',
		'oversio/update-site-settings',
		'oversio/delete-post',
		'oversio/delete-page',
		'oversio/delete-block',
		'oversio/delete-menu',
		'oversio/delete-menu-item',
		'oversio/wc-delete-product',
		'oversio/wc-delete-product-variation',
	);
}
