<?php
/**
 * Newspack WC email-editor renderer for the share block.
 *
 * Emits the saved share anchor when the newsletter is public, mirroring the
 * legacy MJML renderer's intent. The block's own server render callback returns
 * an empty string unconditionally, so under the WC engine a public newsletter's
 * valid share link renders empty.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Abstract_Block_Renderer;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a newspack-newsletters/share block under the WC engine.
 *
 * The share link only makes sense when the newsletter has a public permalink,
 * so the override renders the saved anchor only when the newsletter's
 * `is_public` meta is truthy, and nothing otherwise. The anchor is rebuilt from
 * the saved `href` and `content` attributes (v1 reuses the saved values,
 * matching the legacy MJML path).
 */
class Share extends Abstract_Block_Renderer {
	/**
	 * Render the share block content.
	 *
	 * Resolves the newsletter post, gates on its `is_public` meta, and emits the
	 * saved share anchor. Returns an empty string when the post cannot be resolved
	 * or the newsletter is not public.
	 *
	 * @param string            $block_content     Block content (ignored; rebuilt from attrs).
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
	 */
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		$post = Renderer_Controller::get_rendering_post();
		if ( ! $post instanceof \WP_Post ) {
			$post = $GLOBALS['post'] ?? null;
		}
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		// Only public newsletters have a permalink the share link can point to.
		if ( ! get_post_meta( $post->ID, 'is_public', true ) ) {
			return '';
		}

		$attrs   = $parsed_block['attrs'] ?? [];
		$href    = (string) ( $attrs['href'] ?? '' );
		$content = (string) ( $attrs['content'] ?? '' );

		return self::build_share_html( $href, $content );
	}

	/**
	 * Build the share anchor markup.
	 *
	 * Pure string builder kept separate from the post resolution so it stays
	 * unit-testable without booting the WC engine. Mirrors the block's saved
	 * markup: a paragraph wrapping a single anchor. Returns an empty string when
	 * there is no link to point at.
	 *
	 * @param string $href    The share link URL.
	 * @param string $content The link text.
	 * @return string The share anchor HTML, or an empty string when href is empty.
	 */
	public static function build_share_html( string $href, string $content ): string {
		if ( '' === $href ) {
			return '';
		}
		return sprintf(
			'<p class="newspack-newsletters-share-block"><a href="%1$s">%2$s</a></p>',
			esc_url( $href, [ 'http', 'https', 'mailto' ] ),
			wp_kses_post( $content )
		);
	}
}

// Self-register this override so the registry discovers it via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'newspack-newsletters/share', Share::class );
