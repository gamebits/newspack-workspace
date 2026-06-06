<?php
/**
 * Newspack Settings Admin Page
 *
 * @package Newspack
 */

namespace Newspack\Wizards\Newspack;

use Newspack\OAuth;
use Newspack\Wizard;
use Newspack\Everlit_Configuration_Manager;
use Newspack\Nextdoor;
use Newspack\Complianz;
use function Newspack\google_site_kit_available;

defined( 'ABSPATH' ) || exit;

/**
 * Common functionality for admin wizards. Override this class.
 */
class Newspack_Settings extends Wizard {

	/**
	 * The slug of this wizard.
	 *
	 * @var string
	 */
	protected $slug = 'newspack-settings';

	/**
	 * The capability required to access this.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Constructor — extends Wizard's setup with an admin_init hook for
	 * the legacy-Emails-URL redirect (NPPD-1538). The Emails screen
	 * moved out of Settings into Audience > Configuration; this hook
	 * forwards explicit `?emails=1` redirect hints to the new home.
	 *
	 * @param array $args Wizard arguments — forwarded to the parent.
	 */
	public function __construct( $args = [] ) {
		parent::__construct( $args );
		add_action( 'admin_init', [ __CLASS__, 'maybe_redirect_legacy_emails_url' ] );
	}

	/**
	 * Redirect legacy `?page=newspack-settings&emails=1` URLs to the new
	 * Audience > Configuration > Emails home (NPPD-1538).
	 *
	 * The `?emails=1` query string is the explicit opt-in marker: it
	 * tells the server "this request was heading for Emails, not for
	 * Settings root." Bare `?page=newspack-settings` (no marker) is
	 * left alone — the Settings page still exists and hosts other
	 * sections. The hash-only case (`?page=newspack-settings#/emails`)
	 * is handled client-side in `sections.tsx` because the server
	 * never sees the URL fragment.
	 *
	 * Note: no caller inside this codebase currently generates
	 * `?emails=1` URLs. The handler is here as a forward-compatible
	 * deep-link entry point for external integrations (admin emails,
	 * help-center docs, third-party plugin links) that want to point
	 * at the new home via query string without depending on JS
	 * execution. The JS-side redirect in `sections.tsx` covers the
	 * common case (hash-based bookmarks); this is the complementary
	 * server path.
	 *
	 * Uses `wp_safe_redirect()` (default 302) to match the pattern
	 * in `Newspack::admin_redirects()`. 302 over 301 so browsers
	 * don't cache the redirect — if a future feature re-introduces
	 * a Settings > Emails page, cached 301s would block it.
	 */
	public static function maybe_redirect_legacy_emails_url() {
		// Fastest checks first — this fires on every admin_init across all
		// of wp-admin, so cheap $_GET reads short-circuit before the
		// capability + AJAX + network-admin guards. Custom-role plugins
		// can make current_user_can() non-trivial (user_has_cap filter
		// chains, per-request role mapping) and there's no reason to pay
		// that on every Dashboard/Plugins/Posts pageload.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only check on admin URL params, no state change.
		$page   = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$emails = isset( $_GET['emails'] ) ? sanitize_text_field( wp_unslash( $_GET['emails'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'newspack-settings' !== $page || '1' !== $emails ) {
			return;
		}
		if ( wp_doing_ajax() || is_network_admin() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		// Preserve any other query args on the incoming request
		// (e.g. utm_source, highlight=...) so external deep-link
		// generators can carry context across the redirect. The
		// `page` and `emails` keys are dropped — page is replaced
		// with the new home, `emails` was just the hint marker.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only superglobal access, no state change.
		$extra_args = $_GET;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		unset( $extra_args['page'], $extra_args['emails'] );
		// `map_deep` (not `array_map`) so array-valued query params
		// (e.g. `?foo[]=a&foo[]=b`) are sanitized recursively instead of
		// being passed to `sanitize_text_field` as an array — which warns
		// and drops the param, losing deep-link context.
		$target = add_query_arg(
			array_merge( [ 'page' => 'newspack-audience' ], map_deep( wp_unslash( $extra_args ), 'sanitize_text_field' ) ),
			admin_url( 'admin.php' )
		) . '#/emails';
		wp_safe_redirect( $target );
		exit;
	}

	/**
	 * Get Settings local data
	 *
	 * @return []
	 */
	public function get_local_data() {
		$google_site_kit_url = google_site_kit_available() ? admin_url( 'admin.php?page=googlesitekit-settings#/connected-services/analytics-4' ) : admin_url( 'admin.php?page=googlesitekit-splash' );
		$newspack_settings = [
			'connections'       => [
				'label'    => __( 'Connections', 'newspack-plugin' ),
				'path'     => '/',
				'sections' => [
					'plugins'      => [
						'editLink' => [
							'everlit'         => 'admin.php?page=everlit_settings',
							'jetpack'         => 'admin.php?page=jetpack#/settings',
							'google-site-kit' => $google_site_kit_url,
						],
						'enabled'  => [
							'everlit' => Everlit_Configuration_Manager::is_enabled(),
						],
					],
					'apis'         => [
						'dependencies' => [
							'googleOAuth' => OAuth::is_proxy_configured( 'google' ),
						],
					],
					'jetpack_sso'  => [
						'dependencies' => [
							'jetpack_sso' => class_exists( 'Jetpack' ) && defined( 'NEWSPACK_MANAGER_FILE' ),
						],
					],
					'recaptcha'    => [],
					'analytics'    => [
						'editLink'                    => $google_site_kit_url,
						'measurement_id'              => get_option( 'ga4_measurement_id', '' ),
						'measurement_protocol_secret' => get_option( 'ga4_measurement_protocol_secret', '' ),
					],
					'customEvents' => $this->sections['custom-events']->get_data(),
				],
			],
			'social'            => [
				'label'    => __( 'Social', 'newspack-plugin' ),
				'nextdoor' => [
					'available_roles' => Nextdoor::get_available_roles(),
					'country_options' => Nextdoor::get_available_countries(),
					'redirect_uri'    => Nextdoor::get_redirect_uri(),
				],
			],
			'syndication'       => [
				'label' => __( 'Syndication', 'newspack-plugin' ),
			],
			'seo'               => [
				'label' => __( 'SEO', 'newspack-plugin' ),
			],
			'theme-and-brand'   => [
				'label' => __( 'Theme and Brand', 'newspack-plugin' ),
			],
			'advanced-settings' => [
				'label' => __( 'Advanced Settings', 'newspack-plugin' ),
			],
		];
		if ( Complianz::is_complianz_active() ) {
			$newspack_settings['privacy'] = [
				'label' => __( 'Privacy', 'newspack-plugin' ),
			];
		}
		if ( \Newspack\Optional_Modules\Collections::is_feature_enabled() ) {
			$newspack_settings['collections'] = [
				'label' => __( 'Collections', 'newspack-plugin' ),
			];
		}
		if ( \Newspack\Optional_Modules\InDesign_Exporter::is_feature_enabled() ) {
			$newspack_settings['print'] = [
				'label' => __( 'Print', 'newspack-plugin' ),
			];

		}
		if ( defined( 'NEWSPACK_MULTIBRANDED_SITE_PLUGIN_FILE' ) ) {
			$newspack_settings['additional-brands'] = [
				'label'          => __( 'Additional Brands', 'newspack-plugin' ),
				'activeTabPaths' => [
					'/additional-brands/*',
				],
				'sections'       => [
					'additionalBrands' => [
						'themeColors'   => \Newspack_Multibranded_Site\Customizations\Theme_Colors::get_registered_theme_colors(),
						'menuLocations' => get_registered_nav_menus(),
						'menus'         => array_map(
							function( $menu ) {
								return array(
									'value' => $menu->term_id,
									'label' => $menu->name,
								);
							},
							wp_get_nav_menus()
						),
					],
				],
			];
		}
		$experimental_tools = \Newspack\Experimental_Tools::get_tools();
		if ( ! empty( $experimental_tools ) ) {
			$newspack_settings['experimental-tools'] = [
				'label'          => __( 'Experimental tools', 'newspack-plugin' ),
				'activeTabPaths' => [ '/experimental-tools/*' ],
				'sections'       => [
					'tools' => $experimental_tools,
				],
			];
		}

		return $newspack_settings;
	}

	/**
	 * Get the name for this wizard.
	 *
	 * @return string The wizard name.
	 */
	public function get_name() {
		return esc_html__( 'Newspack', 'newspack' );
	}

	/**
	 * Add an admin page for the wizard to live on.
	 */
	public function add_page() {
		add_submenu_page(
			'newspack-dashboard',
			__( 'Newspack / Settings', 'newspack-plugin' ),
			__( 'Settings', 'newspack-plugin' ),
			$this->capability,
			$this->slug,
			[ $this, 'render_wizard' ]
		);
	}

	/**
	 * Load up JS/CSS.
	 */
	public function enqueue_scripts_and_styles() {
		parent::enqueue_scripts_and_styles();

		if ( filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) !== $this->slug ) {
			return;
		}

		/**
		 * JavaScript
		 */
		wp_localize_script(
			'newspack-wizards',
			'newspackSettings',
			$this->get_local_data()
		);
		wp_enqueue_script( 'newspack-wizards' );
	}
}
