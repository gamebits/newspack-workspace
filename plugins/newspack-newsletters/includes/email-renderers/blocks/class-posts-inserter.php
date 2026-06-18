<?php
/**
 * Newspack WC email-editor renderer for the posts-inserter block.
 *
 * Renders each inserted child block through `do_blocks()` so nested blocks are
 * fully rendered and email-processed, instead of leaking raw block-comment
 * delimiters into the email body.
 *
 * @package Newspack
 */

namespace Newspack\Newsletters\Email_Renderers\Blocks;

use Automattic\WooCommerce\EmailEditor\Engine\Renderer\ContentRenderer\Rendering_Context;
use Automattic\WooCommerce\EmailEditor\Integrations\Core\Renderer\Blocks\Abstract_Block_Renderer;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a newspack-newsletters/posts-inserter block under the WC engine.
 *
 * The block stores its content in the `innerBlocksToInsert` attribute, an array
 * of child blocks shaped `{ blockName, attrs, innerHTML, innerBlocks }`. The
 * block's own server render callback concatenates each child's raw `innerHTML`,
 * so any child carrying nested blocks leaks literal `<!-- wp:... -->` delimiters
 * into the output. This override instead pushes each child's `innerHTML` through
 * `do_blocks()`. Because the package's `render_block` filter is still active mid
 * render, the rendered children come back email-processed for free.
 */
class Posts_Inserter extends Abstract_Block_Renderer {
	/**
	 * Render the posts-inserter content.
	 *
	 * Rebuilds the output from the `innerBlocksToInsert` attribute rather than the
	 * supplied `$block_content` (the block's own callback produces the leaky raw
	 * concatenation). The base `render()` adds the spacer and the package adds the
	 * root horizontal padding around the result.
	 *
	 * @param string            $block_content     Block content (ignored; rebuilt from attrs).
	 * @param array             $parsed_block      Parsed block.
	 * @param Rendering_Context $rendering_context Rendering context.
	 * @return string
	 */
	protected function render_content( string $block_content, array $parsed_block, Rendering_Context $rendering_context ): string {
		$children = $parsed_block['attrs']['innerBlocksToInsert'] ?? [];
		if ( ! is_array( $children ) ) {
			return '';
		}
		return self::render_inserted_blocks( $children );
	}

	/**
	 * Render the inserted child blocks to email-safe HTML.
	 *
	 * Concatenates each child's `innerHTML` after running it through
	 * `do_blocks()`, which renders any nested blocks (so no raw block-comment
	 * delimiters survive) and, while the package's `render_block` filter is
	 * active, returns them email-processed. Kept as a static so it stays
	 * unit-testable without booting the WC engine.
	 *
	 * @param array $children The `innerBlocksToInsert` array of child blocks.
	 * @return string The concatenated rendered HTML, in child order.
	 */
	public static function render_inserted_blocks( array $children ): string {
		$html = '';
		foreach ( $children as $child ) {
			$inner_html = is_array( $child ) ? ( $child['innerHTML'] ?? '' ) : '';
			$html      .= do_blocks( (string) $inner_html );
		}
		return $html;
	}
}

// Self-register this override so the registry discovers it via the blocks/ glob.
\Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry::add( 'newspack-newsletters/posts-inserter', Posts_Inserter::class );
