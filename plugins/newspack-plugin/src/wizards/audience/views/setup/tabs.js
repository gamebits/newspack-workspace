/**
 * Audience setup wizard tab list.
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';

/**
 * Build the Audience setup tab list.
 *
 * Order per NPPD-1538 product wording: Configuration, Checkout & Payment,
 * Access Control (memberships only), Emails. The membership-gating tab keeps
 * its `/content-gating` route — only the visible label is "Access Control".
 * Kept as a pure, dependency-light function so the order + labels are
 * unit-testable without the withWizard/Router render harness.
 *
 * @param {Object}  options                Tab options.
 * @param {boolean} options.enabled        Whether Audience management is enabled (Configuration vs Setup label).
 * @param {boolean} options.hasMemberships Whether memberships are active (gates the Access Control tab).
 * @param {boolean} options.showEmails     Whether the Emails tab is shown (Newspack reader-revenue platform only).
 * @return {Array<{label: string, path: string}>} The ordered, filtered tab list.
 */
export const getSetupTabs = ( { enabled, hasMemberships, showEmails } ) =>
	[
		{
			label: enabled ? __( 'Configuration', 'newspack-plugin' ) : __( 'Setup', 'newspack-plugin' ),
			path: '/',
		},
		{
			label: __( 'Checkout & Payment', 'newspack-plugin' ),
			path: '/payment',
		},
		enabled &&
			hasMemberships && {
				label: __( 'Access Control', 'newspack-plugin' ),
				path: '/content-gating',
			},
		// Emails only appears when Newspack is the reader-revenue platform —
		// Newspack-managed transactional emails don't fire under RevEngine
		// (off-site checkout) or "Other". See class-audience-wizard.php.
		showEmails && {
			label: __( 'Emails', 'newspack-plugin' ),
			path: '/emails',
		},
	].filter( Boolean );
