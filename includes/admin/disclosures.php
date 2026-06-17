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
		'aafm/get-all-post-meta'    => __( 'Reads every allowlisted scalar meta value from a post the agent can already edit, as a key/value map. Protected, underscore, and non-scalar values are left out.', 'agent-abilities-for-mcp' ),
		'aafm/get-comments'         => __( 'Lists approved comments on a post. Author email and IP are never returned.', 'agent-abilities-for-mcp' ),
		'aafm/get-pending-comments' => __( 'Lists the comment moderation queue. Requires the moderate_comments capability.', 'agent-abilities-for-mcp' ),
		'aafm/get-comment'          => __( 'Reads one comment by id: author name, content, status, and date. Author email and IP are never returned.', 'agent-abilities-for-mcp' ),
		'aafm/get-media'            => __( 'Lists media library items: URL, alt text, mime type, and dimensions.', 'agent-abilities-for-mcp' ),
		'aafm/get-media-item'       => __( 'Reads one media item by id: caption, description, date, byte size, parent post, and every image size URL. Never the server file path.', 'agent-abilities-for-mcp' ),
		'aafm/count-media'          => __( 'Counts media library items, total and by mime type. An optional mime filter narrows the breakdown.', 'agent-abilities-for-mcp' ),
		'aafm/count-posts'          => __( 'Counts posts of an allowlisted type, total and by status (publish, draft, pending, and so on). No post content is returned.', 'agent-abilities-for-mcp' ),
		'aafm/get-users'            => __( 'Lists users with their id, display name, email, roles, and post count. Gated by the list-users capability. Never login or password.', 'agent-abilities-for-mcp' ),
		'aafm/get-user'             => __( 'Reads one user by id: display name, email, roles, post count, registration date, and bio. Gated by the list-users capability. Never login or password.', 'agent-abilities-for-mcp' ),
		'aafm/get-user-meta'        => __( 'Reads one allowlisted user meta value from a user the agent can edit. Session tokens, passwords, capabilities, and 2FA keys are never readable.', 'agent-abilities-for-mcp' ),
		'aafm/get-terms'            => __( 'Lists terms and their post counts in a public taxonomy.', 'agent-abilities-for-mcp' ),
		'aafm/get-term'             => __( 'Reads a single term (name, slug, description, post count) from a public taxonomy.', 'agent-abilities-for-mcp' ),
		'aafm/get-term-meta'        => __( 'Reads one allowlisted scalar meta value from a term. Protected and underscore keys are off limits, and nothing is exposed unless an allowlist is configured.', 'agent-abilities-for-mcp' ),
		'aafm/get-taxonomies'       => __( 'Lists the public taxonomies registered on the site.', 'agent-abilities-for-mcp' ),
		'aafm/get-post-types'       => __( 'Lists the public post types registered on the site.', 'agent-abilities-for-mcp' ),
		'aafm/get-site-info'        => __( 'Reads the site name, tagline, URL, and language.', 'agent-abilities-for-mcp' ),
		'aafm/get-site-settings'    => __( 'Reads a small allowlist of site settings (name, tagline, timezone, date and time formats, posts per page). Requires the manage-options capability. Never the site URL or admin email.', 'agent-abilities-for-mcp' ),
		'aafm/list-revisions'       => __( "Lists a post's revisions by id, author, and date. No body content.", 'agent-abilities-for-mcp' ),
		'aafm/get-revision'         => __( "Reads one revision's id, author, date, and body content (rendered by default, raw on request), plus an optional diff against the current post. Gated by edit access to the parent post.", 'agent-abilities-for-mcp' ),
		'aafm/search-content'       => __( 'Searches the content types you have exposed in a single query, returning the same curated fields.', 'agent-abilities-for-mcp' ),
		'aafm/list-plugins'         => __( 'Lists installed plugins with their name, version, and active state. Read-only — it can never activate, deactivate, or change a plugin. Requires the activate-plugins capability.', 'agent-abilities-for-mcp' ),
		'aafm/get-activity-log'     => __( "Reads this plugin's own audit log (ability, status, acting user, argument keys, timestamp), most recent first. Never argument values or network addresses. Requires the manage-options capability.", 'agent-abilities-for-mcp' ),
		'aafm/list-blocks'          => __( 'Lists reusable blocks (synced patterns) by id, title, slug, status, and last-modified time. No block markup in the list. Requires the edit-posts capability.', 'agent-abilities-for-mcp' ),
		'aafm/get-block'            => __( 'Reads one reusable block by id, including its raw block markup. Requires edit access to that block.', 'agent-abilities-for-mcp' ),
		'aafm/list-menus'           => __( 'Lists navigation menus by id, name, slug, and item count. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'aafm/get-menu'             => __( 'Reads one navigation menu by id: name, slug, and item count. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'aafm/list-menu-items'      => __( "Lists a navigation menu's items: title, URL, type, linked object, parent, and order. Requires the edit-theme-options capability.", 'agent-abilities-for-mcp' ),
		'aafm/get-active-theme'     => __( 'Reads the active theme: name, version, stylesheet, parent, and whether it is a block theme. Never a filesystem path. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'aafm/list-themes'          => __( 'Lists installed themes by name, version, stylesheet, and active state. Never a filesystem path. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'aafm/list-templates'       => __( 'Lists block templates (or template parts) by id, slug, title, type, and source. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'aafm/get-template'         => __( 'Reads one block template by id, including its markup. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'aafm/get-global-styles'    => __( "Reads the active theme's resolved global styles and settings (theme.json). Requires the edit-theme-options capability.", 'agent-abilities-for-mcp' ),
		'aafm/seo-get-post'         => __( "Reads a post's SEO fields (title, description, focus keyword, canonical URL, robots, and social tags) from whichever SEO plugin is active. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'aafm/seo-get-schema'       => __( "Reads a post's structured-data schema (JSON-LD) from Rank Math. Other SEO plugins return nothing. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'aafm/seo-get-head'         => __( 'Reads the rendered SEO head markup for a post when the active plugin offers one, and an empty string when it does not. Requires the edit-posts capability and edit access to that post.', 'agent-abilities-for-mcp' ),

		// Writes.
		'aafm/create-draft'         => __( 'Creates a new draft post. The agent drafts, a human publishes. It never goes live on its own.', 'agent-abilities-for-mcp' ),
		'aafm/create-post'          => __( 'Creates and publishes a post. Requires the publish capability, and respects force-draft if you turned it on.', 'agent-abilities-for-mcp' ),
		'aafm/update-post'          => __( "Updates an existing post's fields by id. Publishing is gated separately.", 'agent-abilities-for-mcp' ),
		'aafm/replace-in-post'      => __( 'Finds and replaces literal text in a post\'s body and sanitizes the result. It edits only the content, never the status, and the change is reversible from the revision history.', 'agent-abilities-for-mcp' ),
		'aafm/create-page'          => __( 'Creates and publishes a page. Requires the publish_pages capability.', 'agent-abilities-for-mcp' ),
		'aafm/update-page'          => __( 'Updates an existing page by id. Publishing is gated separately.', 'agent-abilities-for-mcp' ),
		'aafm/create-cpt-item'      => __( 'Creates an item of a custom content type you have allowlisted. It stays a draft unless the agent holds that type\'s publish capability, and force-draft still applies.', 'agent-abilities-for-mcp' ),
		'aafm/update-cpt-item'      => __( 'Updates an item of an allowlisted custom content type by id. Publishing needs that type\'s own publish capability.', 'agent-abilities-for-mcp' ),
		'aafm/update-post-meta'     => __( 'Writes one allowlisted scalar meta value to a post the agent can edit. Only allowlisted keys.', 'agent-abilities-for-mcp' ),
		'aafm/update-user-meta'     => __( 'Writes one allowlisted scalar user meta value to a user the agent can edit. Auth, capability, and 2FA keys are blocked outright.', 'agent-abilities-for-mcp' ),
		'aafm/set-featured-image'   => __( "Sets a post's featured image to an existing attachment id. It does not upload anything.", 'agent-abilities-for-mcp' ),
		'aafm/upload-media'         => __( 'Uploads an image from base64 data (jpg, png, gif, webp; SVG is rejected) and adds it to the media library.', 'agent-abilities-for-mcp' ),
		'aafm/update-media'         => __( "Updates an attachment's title, alt text, caption, or description. Requires edit access to that attachment.", 'agent-abilities-for-mcp' ),
		'aafm/moderate-comment'     => __( 'Approves, unapproves, spams, or trashes a comment. Requires the moderate_comments capability.', 'agent-abilities-for-mcp' ),
		'aafm/create-comment'       => __( 'Adds a comment to a post as the agent user. It is held for moderation, never auto-published, and the author is always the agent, not free-form input. Requires the moderate_comments capability.', 'agent-abilities-for-mcp' ),
		'aafm/update-comment'       => __( "Edits a comment's text only. It cannot change the post, author, email, or IP. Requires edit access to that comment.", 'agent-abilities-for-mcp' ),
		'aafm/create-term'          => __( 'Creates a term in a public taxonomy. Requires the manage_categories capability.', 'agent-abilities-for-mcp' ),
		'aafm/update-term'          => __( 'Updates a term. Reparenting is guarded against hierarchy loops.', 'agent-abilities-for-mcp' ),
		'aafm/add-post-terms'       => __( 'Adds terms to a post without removing its existing terms. Requires edit access to the post and the taxonomy\'s assign capability; only existing terms in that taxonomy can be added.', 'agent-abilities-for-mcp' ),
		'aafm/update-term-meta'     => __( 'Writes one allowlisted scalar meta value to a term you can edit. Only allowlisted keys; protected keys are blocked.', 'agent-abilities-for-mcp' ),
		'aafm/restore-revision'     => __( 'Restores a post to one of its revisions. The current state is saved as a fresh revision first, so the change is reversible.', 'agent-abilities-for-mcp' ),
		'aafm/update-user'          => __( 'Edits a user\'s display name, name, or email. Changing a role needs the promote-users capability and never demotes the last administrator. Requires edit access to that user.', 'agent-abilities-for-mcp' ),
		'aafm/create-block'         => __( 'Creates a reusable block. Its markup is sanitized, and the author is always the agent. Requires the edit-posts capability.', 'agent-abilities-for-mcp' ),
		'aafm/update-block'         => __( "Updates a reusable block's title or markup by id. The markup is sanitized. Requires edit access to that block.", 'agent-abilities-for-mcp' ),
		'aafm/create-menu'          => __( 'Creates a navigation menu. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'aafm/update-menu'          => __( 'Renames a navigation menu by id. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'aafm/create-menu-item'     => __( 'Adds an item (link) to a navigation menu. The URL is sanitized. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'aafm/update-menu-item'     => __( "Updates a menu item's title or URL by id. Requires the edit-theme-options capability.", 'agent-abilities-for-mcp' ),
		'aafm/update-template'      => __( 'Updates a database block template by id. Its markup is sanitized, and theme-file templates cannot be edited. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'aafm/seo-update-post'      => __( "Writes a post's SEO fields to the active plugin's meta keys. URL fields are sanitized as URLs. Requires edit access to that post.", 'agent-abilities-for-mcp' ),
		'aafm/seo-update-schema'    => __( "Writes a post's structured-data schema (JSON-LD) to Rank Math. The whole object is sanitized, top to bottom. Requires edit access to that post.", 'agent-abilities-for-mcp' ),

		// Destructive (still recoverable).
		'aafm/trash-post'           => __( 'Moves a post the agent can edit to the Trash, where you can restore it. Never a permanent delete.', 'agent-abilities-for-mcp' ),
		'aafm/trash-page'           => __( 'Moves a page to the Trash, where you can restore it. Never a permanent delete.', 'agent-abilities-for-mcp' ),
		'aafm/delete-post-meta'     => __( 'Removes an allowlisted meta key and all its values from a post the agent can edit. Only allowlisted keys.', 'agent-abilities-for-mcp' ),
		'aafm/delete-block'         => __( 'Moves a reusable block to the Trash, where you can restore it. Never a permanent delete. Requires delete access to that block.', 'agent-abilities-for-mcp' ),

		// Destructive (permanent).
		'aafm/delete-revision'      => __( "Permanently removes one revision from a post's history. The live post is unchanged, but the deleted revision cannot be recovered. Requires edit access to the parent post.", 'agent-abilities-for-mcp' ),
		'aafm/delete-media'         => __( 'Permanently deletes an attachment: the file and its library entry are removed and cannot be recovered. Requires delete access to that attachment.', 'agent-abilities-for-mcp' ),
		'aafm/delete-term-meta'     => __( 'Removes an allowlisted meta key and all its values from a term you can edit. This cannot be undone. Only allowlisted keys.', 'agent-abilities-for-mcp' ),
		'aafm/delete-comment'       => __( 'Permanently deletes a comment. This bypasses the Trash and cannot be undone — use moderate-comment to trash a comment recoverably instead. Requires edit access to that comment.', 'agent-abilities-for-mcp' ),
		'aafm/create-user'          => __( 'Creates a new user with the site default role only (never an admin or a caller-chosen role). Requires the create-users capability. Off by default.', 'agent-abilities-for-mcp' ),
		'aafm/delete-user'          => __( 'Permanently deletes a user and reassigns their content to another user. Never deletes you or the last administrator. Requires the delete-users capability. Off by default.', 'agent-abilities-for-mcp' ),
		'aafm/delete-user-meta'     => __( 'Removes an allowlisted user meta key from a user the agent can edit. This cannot be undone. Auth and capability keys can never be touched.', 'agent-abilities-for-mcp' ),
		'aafm/delete-post'          => __( 'Permanently deletes a post, bypassing the Trash. This cannot be undone — use trash-post to remove a post recoverably instead. Requires delete access to that post. Off by default.', 'agent-abilities-for-mcp' ),
		'aafm/delete-page'          => __( 'Permanently deletes a page, bypassing the Trash. This cannot be undone — use trash-page to remove a page recoverably instead. Requires delete access to that page. Off by default.', 'agent-abilities-for-mcp' ),
		'aafm/update-site-settings' => __( 'Writes a small allowlist of site settings only (name, tagline, timezone, formats, posts per page). It can never change the site URL, admin email, default role, or open registration. Requires manage-options. Off by default.', 'agent-abilities-for-mcp' ),
		'aafm/delete-menu'          => __( 'Permanently deletes a navigation menu and all of its items. This cannot be undone — menus have no Trash. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
		'aafm/delete-menu-item'     => __( 'Permanently removes one item from a navigation menu. This cannot be undone. Requires the edit-theme-options capability.', 'agent-abilities-for-mcp' ),
	);
}
