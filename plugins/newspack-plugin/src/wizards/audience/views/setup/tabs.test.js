/**
 * NPPD-1538 — Audience setup tab order + labels.
 *
 * Locks the tab list contract the product wording defines so a future
 * reorder/rename is a deliberate, test-visible change rather than a silent
 * regression. Tests the pure `getSetupTabs` builder directly (no render
 * harness needed).
 *
 * The Emails tab is always present; the email LIST is scoped by platform
 * server-side (see emails-section.php), not by hiding the tab.
 */

/**
 * Internal dependencies
 */
import { getSetupTabs } from './tabs';

describe( 'getSetupTabs', () => {
	it( 'orders tabs Configuration → Checkout & Payment → Access Control → Emails when enabled with memberships', () => {
		const tabs = getSetupTabs( { enabled: true, hasMemberships: true } );

		expect( tabs.map( tab => tab.label ) ).toEqual( [ 'Configuration', 'Checkout & Payment', 'Access Control', 'Emails' ] );
		// Access Control keeps the legacy /content-gating route.
		expect( tabs.map( tab => tab.path ) ).toEqual( [ '/', '/payment', '/content-gating', '/emails' ] );
	} );

	it( 'omits the Access Control tab when memberships are not enabled', () => {
		const tabs = getSetupTabs( { enabled: true, hasMemberships: false } );

		expect( tabs.map( tab => tab.label ) ).toEqual( [ 'Configuration', 'Checkout & Payment', 'Emails' ] );
		expect( tabs.some( tab => tab.path === '/content-gating' ) ).toBe( false );
	} );

	it( 'labels the first tab "Setup" (and hides Access Control) before Audience is enabled', () => {
		const tabs = getSetupTabs( { enabled: false, hasMemberships: true } );

		// Access Control is gated on `enabled` too, so it stays hidden during
		// initial setup even when memberships exist. Emails stays visible.
		expect( tabs.map( tab => tab.label ) ).toEqual( [ 'Setup', 'Checkout & Payment', 'Emails' ] );
	} );
} );
