/**
 * Newspack - Dashboard, Sections
 *
 * Component for outputting sections with grid and cards
 */
import { __ } from '@wordpress/i18n';
import { lazy } from '@wordpress/element';

// NPPD-1538: forward stale bookmarks of `?page=newspack-settings#/emails`
// to the new Audience > Configuration > Emails home. This is an
// intentional module-load side-effect — normally a code smell, but
// justified here because the side effect is "leave the page entirely
// before React mounts." Doing this inside a useEffect would render a
// Settings-page frame first, then unmount and navigate, producing a
// visible flash. The hash-check + replace must run at module top, before
// <Wizard> mounts and before the HashRouter parses the hash.
//
// `location.replace` (not `assign`) keeps the back button working: the
// old URL never enters browser history. The server-side handler in
// `class-newspack-settings.php` catches the inverse case (explicit
// `?emails=1` query param, no hash).
//
// The destination URL is derived from `newspack_urls.dashboard` (which the
// Wizard base class localizes via `newspack_data`) so the redirect respects
// subdirectory WordPress installs.
if ( window.location.hash === '#/emails' && window.newspack_urls?.dashboard ) {
	const audienceUrl = window.newspack_urls.dashboard.replace( 'newspack-dashboard', 'newspack-audience' );
	window.location.replace( `${ audienceUrl }#/emails` );
}

const settingsTabs = window.newspackSettings;

import Seo from './seo';
import Social from './social';
import Connections from './connections';
import Syndication from './syndication';
import AdvancedSettings from './advanced-settings';
import ThemeAndBrand from './theme-and-brand';
import Collections from './collections';
import Print from './print';
import Privacy from './privacy';

type SectionKeys = keyof typeof settingsTabs;

const sectionComponents: Partial< Record< SectionKeys | 'default', ( props: { isPartOfSetup?: boolean } ) => React.ReactNode > > = {
	connections: Connections,
	social: Social,
	syndication: Syndication,
	seo: Seo,
	'theme-and-brand': ThemeAndBrand,
	'advanced-settings': AdvancedSettings,
	collections: Collections,
	print: Print,
	privacy: Privacy,
	default: () => <h2>🚫 { __( 'Not found' ) }</h2>,
};

/**
 * Load additional brands section if `newspack-multibranded-site` plugin is active.
 */
if ( 'additional-brands' in settingsTabs ) {
	sectionComponents[ 'additional-brands' ] = lazy( () => import( /* webpackChunkName: "newspack-wizards" */ './additional-brands' ) );
}

/**
 * Load experimental tools section if tools are registered.
 */
if ( 'experimental-tools' in settingsTabs ) {
	sectionComponents[ 'experimental-tools' ] = lazy( () => import( /* webpackChunkName: "newspack-wizards" */ './experimental-tools' ) );
}

const settingsSectionKeys = Object.keys( settingsTabs ) as SectionKeys[];

export default settingsSectionKeys.reduce( ( acc: any[], sectionPath ) => {
	acc.push( {
		label: settingsTabs[ sectionPath ].label,
		exact: '/' === ( settingsTabs[ sectionPath ].path ?? '' ),
		path: settingsTabs[ sectionPath ].path ?? `/${ sectionPath }`,
		activeTabPaths: settingsTabs[ sectionPath ].activeTabPaths ?? undefined,
		render: sectionComponents[ sectionPath ] ?? sectionComponents.default,
	} );
	return acc;
}, [] );
