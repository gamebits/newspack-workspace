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
 * @return {Array<{label: string, path: string}>} The ordered, filtered tab list.
 */
export const getSetupTabs = ( { enabled, hasMemberships } ) =>
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
		// Emails is always available. The email LIST is scoped by platform
		// server-side (reader-revenue emails only on the Newspack platform;
		// auth/account emails everywhere) — see Emails_Section.
		{
			label: __( 'Emails', 'newspack-plugin' ),
			path: '/emails',
		},
	].filter( Boolean );
