<?php
/**
 * Class Newspack Block Renderers Test
 *
 * @package Newspack_Newsletters
 */

use Newspack\Newsletters\Email_Renderers\Block_Renderer_Registry;
use Newspack\Newsletters\Email_Renderers\Blocks\Posts_Inserter;
use Newspack\Newsletters\Email_Renderers\Blocks\Share;
use Newspack\Newsletters\Email_Renderers\Editor_Bootstrap;
use Newspack\Newsletters\Email_Renderers\Renderer_Controller;

/**
 * Tests the Newspack dynamic-block overrides for the WC email engine:
 * posts-inserter (renders inserted children through do_blocks) and share
 * (emits the saved anchor only when the newsletter is public).
 */
class Test_Newspack_Block_Renderers extends WP_UnitTestCase {
	/**
	 * Run override discovery so the self-registering renderers are mapped.
	 */
	public function set_up() {
		parent::set_up();
		Block_Renderer_Registry::init();
	}

	/**
	 * The posts-inserter helper renders nested blocks, leaking no delimiters.
	 *
	 * A child whose innerHTML carries a nested core/columns block must come back
	 * fully rendered (the wp-block-columns markup) with no raw `<!-- wp:`
	 * block-comment delimiters surviving into the output.
	 */
	public function test_posts_inserter_renders_nested_blocks_without_delimiters() {
		$children = [
			[
				'blockName' => 'core/columns',
				'innerHTML' => '<!-- wp:columns --><div class="wp-block-columns">'
					. '<!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
					. '</div><!-- /wp:columns -->',
			],
		];

		$result = Posts_Inserter::render_inserted_blocks( $children );

		$this->assertStringContainsString( 'wp-block-columns', $result, 'Expected the nested columns block to be rendered.' );
		$this->assertStringContainsString( 'Hello', $result, 'Expected the inner paragraph content to survive.' );
		$this->assertStringNotContainsString( '<!-- wp:', $result, 'Expected no raw block-comment delimiters to leak into the output.' );
	}

	/**
	 * The posts-inserter helper returns an empty string for no children.
	 */
	public function test_posts_inserter_empty_array_renders_nothing() {
		$this->assertSame( '', Posts_Inserter::render_inserted_blocks( [] ), 'Expected an empty children array to render nothing.' );
	}

	/**
	 * The posts-inserter helper concatenates children in document order.
	 *
	 * Two paragraph children must render in the same order they appear in the
	 * innerBlocksToInsert array.
	 */
	public function test_posts_inserter_preserves_child_order() {
		$children = [
			[ 'innerHTML' => '<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->' ],
			[ 'innerHTML' => '<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->' ],
		];

		$result = Posts_Inserter::render_inserted_blocks( $children );

		$this->assertLessThan(
			strpos( $result, 'Second' ),
			strpos( $result, 'First' ),
			'Expected children to render in document order.'
		);
		$this->assertStringNotContainsString( '<!-- wp:', $result, 'Expected no raw block-comment delimiters to leak.' );
	}

	/**
	 * A real posts-inserter render emits the child columns and no raw comments.
	 *
	 * Renders a newsletter CPT containing a posts-inserter block whose
	 * innerBlocksToInsert holds a columns child through the full WC pipeline and
	 * asserts the column markup appears with no leaked block-comment delimiters.
	 */
	public function test_posts_inserter_integration_renders_columns() {
		Editor_Bootstrap::init();

		$inner = '<!-- wp:columns --><div class="wp-block-columns">'
			. '<!-- wp:column {"width":"50%"} --><div class="wp-block-column"><!-- wp:paragraph --><p>Inserted col</p><!-- /wp:paragraph --></div><!-- /wp:column -->'
			. '</div><!-- /wp:columns -->';

		$attrs = [
			'innerBlocksToInsert' => [
				[
					'blockName' => 'core/columns',
					'attrs'     => [],
					'innerHTML' => $inner,
				],
			],
		];

		$content = '<!-- wp:newspack-newsletters/posts-inserter ' . wp_json_encode( $attrs ) . ' /-->';

		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Posts inserter newsletter',
				'post_content' => $content,
			]
		);

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$this->assertStringContainsString( 'wp-block-column', $html, 'Expected the inserted column markup to appear in the email.' );
		$this->assertStringContainsString( 'Inserted col', $html, 'Expected the inserted paragraph content to appear.' );
		$this->assertStringNotContainsString( '<!-- wp:', $html, 'Expected no raw block-comment delimiters in the rendered email.' );
	}

	/**
	 * The share builder wraps the content in a single anchor with the href.
	 */
	public function test_share_builder_wraps_anchor() {
		$result = Share::build_share_html( 'mailto:?body=x', 'Share this' );

		$this->assertStringContainsString( '<a href="mailto:?body=x">', $result, 'Expected the anchor to carry the share href.' );
		$this->assertStringContainsString( 'Share this', $result, 'Expected the link text to be preserved.' );
		$this->assertStringContainsString( 'newspack-newsletters-share-block', $result, 'Expected the share-block paragraph class.' );
		$this->assertSame( 1, substr_count( $result, '<a ' ), 'Expected exactly one anchor in the share markup.' );
	}

	/**
	 * The share builder renders nothing when there is no href to link to.
	 */
	public function test_share_builder_empty_href_renders_nothing() {
		$this->assertSame( '', Share::build_share_html( '', 'Share this' ), 'Expected an empty href to render nothing.' );
	}

	/**
	 * A public newsletter renders the share anchor.
	 *
	 * With `is_public` meta truthy the override must emit the saved share anchor
	 * (the mailto link) into the rendered email.
	 */
	public function test_share_integration_public_renders_anchor() {
		Editor_Bootstrap::init();

		$content = '<!-- wp:newspack-newsletters/share {"href":"mailto:?body=read-this","content":"Share this"} -->'
			. '<p class="newspack-newsletters-share-block"><a href="mailto:?body=read-this">Share this</a></p>'
			. '<!-- /wp:newspack-newsletters/share -->';

		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Public share newsletter',
				'post_content' => $content,
			]
		);
		update_post_meta( $post_id, 'is_public', 1 );

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$this->assertStringContainsString( 'mailto:?body=read-this', $html, 'Expected the share anchor href in a public newsletter email.' );
		$this->assertStringContainsString( 'Share this', $html, 'Expected the share link text in a public newsletter email.' );
	}

	/**
	 * A non-public newsletter renders no share anchor.
	 *
	 * Without `is_public` meta the share link points nowhere, so the override must
	 * emit nothing for the share block.
	 */
	public function test_share_integration_non_public_renders_no_anchor() {
		Editor_Bootstrap::init();

		$content = '<!-- wp:newspack-newsletters/share {"href":"mailto:?body=read-this","content":"Share this"} -->'
			. '<p class="newspack-newsletters-share-block"><a href="mailto:?body=read-this">Share this</a></p>'
			. '<!-- /wp:newspack-newsletters/share -->';

		$post_id = self::factory()->post->create(
			[
				'post_type'    => \Newspack_Newsletters::NEWSPACK_NEWSLETTERS_CPT,
				'post_status'  => 'draft',
				'post_title'   => 'Non-public share newsletter',
				'post_content' => $content,
			]
		);

		$html = Renderer_Controller::render_wc( get_post( $post_id ) );

		$this->assertStringNotContainsString( 'mailto:?body=read-this', $html, 'Expected no share anchor href in a non-public newsletter email.' );
	}
}
