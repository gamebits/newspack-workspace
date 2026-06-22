/**
 * NPPD-1538 — Audience setup tab order + labels.
 *
 * Locks the tab list contract the product wording defines so a future
 * reorder/rename is a deliberate, test-visible change rather than a silent
 * regression. Tests the pure `getSetupTabs` builder directly (no render
 * harness needed).
 */

/**
 * Internal dependencies
 */
import { getSetupTabs } from './tabs';

describe( 'getSetupTabs', () => {
	it( 'orders tabs Configuration → Checkout & Payment → Access Control → Emails when enabled with memberships', () => {
		const tabs = getSetupTabs( { enabled: true, hasMemberships: true, showEmails: true } );

		expect( tabs.map( tab => tab.label ) ).toEqual( [ 'Configuration', 'Checkout & Payment', 'Access Control', 'Emails' ] );
		// Access Control keeps the legacy /content-gating route.
		expect( tabs.map( tab => tab.path ) ).toEqual( [ '/', '/payment', '/content-gating', '/emails' ] );
	} );

	it( 'omits the Access Control tab when memberships are not enabled', () => {
		const tabs = getSetupTabs( { enabled: true, hasMemberships: false, showEmails: true } );

		expect( tabs.map( tab => tab.label ) ).toEqual( [ 'Configuration', 'Checkout & Payment', 'Emails' ] );
		expect( tabs.some( tab => tab.path === '/content-gating' ) ).toBe( false );
	} );

	it( 'labels the first tab "Setup" (and hides Access Control) before Audience is enabled', () => {
		const tabs = getSetupTabs( { enabled: false, hasMemberships: true, showEmails: true } );

		// Access Control is gated on `enabled` too, so it stays hidden during
		// initial setup even when memberships exist.
		expect( tabs.map( tab => tab.label ) ).toEqual( [ 'Setup', 'Checkout & Payment', 'Emails' ] );
	} );

	it( 'shows the Emails tab only when Newspack is the reader-revenue platform', () => {
		// NPPD-1538: Newspack-managed transactional emails only fire on the
		// Newspack (wc) platform — RevEngine redirects checkout off-site and
		// "Other" sends nothing through Newspack — so Emails is hidden for
		// any non-Newspack platform.
		const newspack = getSetupTabs( { enabled: true, hasMemberships: true, showEmails: true } );
		expect( newspack.some( tab => tab.path === '/emails' ) ).toBe( true );

		const nonNewspack = getSetupTabs( { enabled: true, hasMemberships: true, showEmails: false } );
		expect( nonNewspack.some( tab => tab.path === '/emails' ) ).toBe( false );
		expect( nonNewspack.map( tab => tab.label ) ).toEqual( [ 'Configuration', 'Checkout & Payment', 'Access Control' ] );
	} );
} );
