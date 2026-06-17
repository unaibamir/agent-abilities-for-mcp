<?php
/**
 * Plain-language disclosure line for every ability — what it can read or write,
 * and where its reach stops. Shown as helper text under each row on the Abilities tab.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Map of ability name => one-line, honest description of its reach.
 *
 * Each line states what the ability touches and, where it matters, what it leaves alone.
 * Keys match aafm_get_abilities_registry() one-to-one; the render layer falls back to the
 * registry description if a key is ever missing.
 *
 * @return array<string,string>
 */
function aafm_ability_disclosures(): array {
	return array(
		// Reads.
		'aafm/get-posts'            => __( 'Lists posts by type, status, or search. Returns the title, status, excerpt, link, dates, and author id, never the full body or private fields.', 'agent-abilities-for-mcp' ),
		'aafm/get-post'             => __( 'Reads one post by id: title, status, excerpt, link, dates, and author id. Not the full body, not protected fields.', 'agent-abilities-for-mcp' ),
		'aafm/get-pages'            => __( 'Lists pages by status or search, with the same fields as posts.', 'agent-abilities-for-mcp' ),
		'aafm/get-page'             => __( 'Reads one page by id, with the same curated fields as a post.', 'agent-abilities-for-mcp' ),
		'aafm/get-post-meta'        => __( 'Reads one allowlisted scalar meta value from a post the agent can already edit. Protected and underscore keys are off limits.', 'agent-abilities-for-mcp' ),
		'aafm/get-comments'         => __( 'Lists approved comments on a post. Author email and IP are never returned.', 'agent-abilities-for-mcp' ),
		'aafm/get-pending-comments' => __( 'Lists the comment moderation queue. Requires the moderate_comments capability.', 'agent-abilities-for-mcp' ),
		'aafm/get-media'            => __( 'Lists media library items: URL, alt text, mime type, and dimensions.', 'agent-abilities-for-mcp' ),
		'aafm/get-media-item'       => __( 'Reads one media item by id: caption, description, date, byte size, parent post, and every image size URL. Never the server file path.', 'agent-abilities-for-mcp' ),
		'aafm/count-media'          => __( 'Counts media library items, total and by mime type. An optional mime filter narrows the breakdown.', 'agent-abilities-for-mcp' ),
		'aafm/get-users'            => __( 'Lists users with their id, display name, roles, and post count. Never email or login.', 'agent-abilities-for-mcp' ),
		'aafm/get-terms'            => __( 'Lists terms and their post counts in a public taxonomy.', 'agent-abilities-for-mcp' ),
		'aafm/get-taxonomies'       => __( 'Lists the public taxonomies registered on the site.', 'agent-abilities-for-mcp' ),
		'aafm/get-post-types'       => __( 'Lists the public post types registered on the site.', 'agent-abilities-for-mcp' ),
		'aafm/get-site-info'        => __( 'Reads the site name, tagline, URL, and language.', 'agent-abilities-for-mcp' ),
		'aafm/list-revisions'       => __( "Lists a post's revisions by id, author, and date. No body content.", 'agent-abilities-for-mcp' ),
		'aafm/get-revision'         => __( "Reads one revision's id, author, and date. No body content.", 'agent-abilities-for-mcp' ),
		'aafm/search-content'       => __( 'Searches the content types you have exposed in a single query, returning the same curated fields.', 'agent-abilities-for-mcp' ),

		// Writes.
		'aafm/create-draft'         => __( 'Creates a new draft post. The agent drafts, a human publishes. It never goes live on its own.', 'agent-abilities-for-mcp' ),
		'aafm/create-post'          => __( 'Creates and publishes a post. Requires the publish capability, and respects force-draft if you turned it on.', 'agent-abilities-for-mcp' ),
		'aafm/update-post'          => __( "Updates an existing post's fields by id. Publishing is gated separately.", 'agent-abilities-for-mcp' ),
		'aafm/create-page'          => __( 'Creates and publishes a page. Requires the publish_pages capability.', 'agent-abilities-for-mcp' ),
		'aafm/update-page'          => __( 'Updates an existing page by id. Publishing is gated separately.', 'agent-abilities-for-mcp' ),
		'aafm/create-cpt-item'      => __( 'Creates an item of a custom content type you have allowlisted. It stays a draft unless the agent holds that type\'s publish capability, and force-draft still applies.', 'agent-abilities-for-mcp' ),
		'aafm/update-cpt-item'      => __( 'Updates an item of an allowlisted custom content type by id. Publishing needs that type\'s own publish capability.', 'agent-abilities-for-mcp' ),
		'aafm/update-post-meta'     => __( 'Writes one allowlisted scalar meta value to a post the agent can edit. Only allowlisted keys.', 'agent-abilities-for-mcp' ),
		'aafm/set-featured-image'   => __( "Sets a post's featured image to an existing attachment id. It does not upload anything.", 'agent-abilities-for-mcp' ),
		'aafm/upload-media'         => __( 'Uploads an image from base64 data (jpg, png, gif, webp; SVG is rejected) and adds it to the media library.', 'agent-abilities-for-mcp' ),
		'aafm/update-media'         => __( "Updates an attachment's title, alt text, caption, or description. Requires edit access to that attachment.", 'agent-abilities-for-mcp' ),
		'aafm/moderate-comment'     => __( 'Approves, unapproves, spams, or trashes a comment. Requires the moderate_comments capability.', 'agent-abilities-for-mcp' ),
		'aafm/create-term'          => __( 'Creates a term in a public taxonomy. Requires the manage_categories capability.', 'agent-abilities-for-mcp' ),
		'aafm/update-term'          => __( 'Updates a term. Reparenting is guarded against hierarchy loops.', 'agent-abilities-for-mcp' ),
		'aafm/restore-revision'     => __( 'Restores a post to one of its revisions. The current state is saved as a fresh revision first, so the change is reversible.', 'agent-abilities-for-mcp' ),

		// Destructive (still recoverable).
		'aafm/trash-post'           => __( 'Moves a post the agent can edit to the Trash, where you can restore it. Never a permanent delete.', 'agent-abilities-for-mcp' ),
		'aafm/trash-page'           => __( 'Moves a page to the Trash, where you can restore it. Never a permanent delete.', 'agent-abilities-for-mcp' ),
		'aafm/delete-post-meta'     => __( 'Removes an allowlisted meta key and all its values from a post the agent can edit. Only allowlisted keys.', 'agent-abilities-for-mcp' ),

		// Destructive (permanent).
		'aafm/delete-revision'      => __( "Permanently removes one revision from a post's history. The live post is unchanged, but the deleted revision cannot be recovered. Requires edit access to the parent post.", 'agent-abilities-for-mcp' ),
		'aafm/delete-media'         => __( 'Permanently deletes an attachment: the file and its library entry are removed and cannot be recovered. Requires delete access to that attachment.', 'agent-abilities-for-mcp' ),
	);
}
