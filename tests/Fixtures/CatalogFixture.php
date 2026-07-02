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

namespace AAFM\Tests\Fixtures;

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
		'aafm/get-posts',
		'aafm/count-posts',
		'aafm/get-post',
		'aafm/get-post-meta',
		'aafm/get-all-post-meta',
		'aafm/get-pages',
		'aafm/get-page',
		'aafm/get-terms',
		'aafm/get-term',
		'aafm/get-term-meta',
		'aafm/get-taxonomies',
		'aafm/get-post-types',
		'aafm/get-site-info',
		'aafm/get-comments',
		'aafm/get-pending-comments',
		'aafm/get-comment',
		'aafm/get-media',
		'aafm/get-media-item',
		'aafm/count-media',
		'aafm/get-users',
		'aafm/get-user',
		'aafm/get-user-meta',
		'aafm/list-revisions',
		'aafm/get-revision',
		'aafm/search-content',
		'aafm/get-site-settings',
		'aafm/list-plugins',
		'aafm/get-activity-log',
		'aafm/list-blocks',
		'aafm/get-block',
		'aafm/list-menus',
		'aafm/get-menu',
		'aafm/list-menu-items',
		'aafm/get-active-theme',
		'aafm/list-themes',
		'aafm/list-templates',
		'aafm/get-template',
		'aafm/get-global-styles',
		'aafm/yoast-get-post',
		'aafm/yoast-get-head',
		'aafm/rankmath-get-post',
		'aafm/rankmath-get-schema',
		'aafm/rankmath-get-head',
		'aafm/aioseo-get-post',
		'aafm/aioseo-get-head',
		'aafm/acf-list-field-groups',
		'aafm/acf-get-post-fields',
		'aafm/acf-get-term-fields',
		'aafm/acf-get-user-fields',
		'aafm/wc-list-products',
		'aafm/wc-get-product',
		'aafm/wc-list-product-variations',
		'aafm/wc-get-product-variation',
		'aafm/wc-list-product-attributes',
		'aafm/wc-list-orders',
		'aafm/wc-get-order',
		'aafm/wc-list-order-notes',
		'aafm/wc-list-order-refunds',
		'aafm/wc-get-order-refund',
		'aafm/wc-list-customers',
		'aafm/wc-get-customer',
		'aafm/wc-list-coupons',
		'aafm/wc-get-coupon',
		'aafm/wc-list-shipping-zones',
		'aafm/wc-get-shipping-zone',
		'aafm/wc-list-shipping-methods',
		'aafm/wc-get-shipping-method',
		'aafm/wc-list-tax-rates',
		'aafm/wc-get-tax-rate',
		'aafm/wc-list-tax-classes',
		'aafm/wc-get-sales-report',
		'aafm/wc-get-top-sellers-report',
		'aafm/wc-count-orders',
		'aafm/wc-count-products',
		'aafm/wc-list-payment-gateways',
		'aafm/wc-get-payment-gateway',
	);

	/**
	 * The exact, complete set of write abilities.
	 *
	 * @var string[]
	 */
	public const WRITES = array(
		'aafm/create-draft',
		'aafm/create-post',
		'aafm/update-post',
		'aafm/replace-in-post',
		'aafm/trash-post',
		'aafm/create-page',
		'aafm/update-page',
		'aafm/trash-page',
		'aafm/create-term',
		'aafm/update-term',
		'aafm/add-post-terms',
		'aafm/update-term-meta',
		'aafm/delete-term-meta',
		'aafm/moderate-comment',
		'aafm/create-comment',
		'aafm/update-comment',
		'aafm/delete-comment',
		'aafm/set-featured-image',
		'aafm/upload-media',
		'aafm/update-media',
		'aafm/delete-media',
		'aafm/update-post-meta',
		'aafm/delete-post-meta',
		'aafm/restore-revision',
		'aafm/delete-revision',
		'aafm/create-cpt-item',
		'aafm/update-cpt-item',
		'aafm/create-user',
		'aafm/update-user',
		'aafm/delete-user',
		'aafm/update-user-meta',
		'aafm/delete-user-meta',
		'aafm/update-site-settings',
		'aafm/delete-post',
		'aafm/delete-page',
		'aafm/create-block',
		'aafm/update-block',
		'aafm/delete-block',
		'aafm/create-menu',
		'aafm/update-menu',
		'aafm/delete-menu',
		'aafm/create-menu-item',
		'aafm/update-menu-item',
		'aafm/delete-menu-item',
		'aafm/update-template',
		'aafm/yoast-update-post',
		'aafm/rankmath-update-post',
		'aafm/rankmath-update-schema',
		'aafm/aioseo-update-post',
		'aafm/acf-update-post-fields',
		'aafm/acf-update-term-fields',
		'aafm/acf-update-user-fields',
		'aafm/wc-create-product',
		'aafm/wc-update-product',
		'aafm/wc-delete-product',
		'aafm/wc-create-product-variation',
		'aafm/wc-update-product-variation',
		'aafm/wc-delete-product-variation',
		'aafm/wc-create-product-attribute',
		'aafm/wc-update-product-attribute',
		'aafm/wc-create-order',
		'aafm/wc-update-order',
		'aafm/wc-update-order-status',
		'aafm/wc-create-order-note',
		'aafm/wc-create-order-refund',
		'aafm/wc-create-customer',
		'aafm/wc-update-customer',
		'aafm/wc-create-coupon',
		'aafm/wc-update-coupon',
		'aafm/wc-create-shipping-zone',
		'aafm/wc-update-shipping-zone',
		'aafm/wc-create-shipping-method',
		'aafm/wc-update-shipping-method',
		'aafm/wc-create-tax-rate',
		'aafm/wc-update-tax-rate',
		'aafm/wc-create-tax-class',
		'aafm/wc-update-payment-gateway',
	);

	/**
	 * The writes whose action is destruction - recoverable (trash / spam) or permanent
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
	 * variation is permanent on the same basis - it removes a single product variation through the WC
	 * data store with no recoverable Trash.
	 *
	 * @var string[]
	 */
	public const DESTRUCTIVE_WRITES = array(
		'aafm/trash-post',
		'aafm/trash-page',
		'aafm/moderate-comment',
		'aafm/delete-comment',
		'aafm/delete-post-meta',
		'aafm/delete-revision',
		'aafm/delete-media',
		'aafm/delete-term-meta',
		'aafm/create-user',
		'aafm/delete-user',
		'aafm/delete-user-meta',
		'aafm/update-site-settings',
		'aafm/delete-post',
		'aafm/delete-page',
		'aafm/delete-block',
		'aafm/delete-menu',
		'aafm/delete-menu-item',
		'aafm/wc-delete-product',
		'aafm/wc-delete-product-variation',
	);
}
