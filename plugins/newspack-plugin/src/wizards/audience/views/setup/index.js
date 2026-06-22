/* global newspackAudience */

/**
 * Configuration
 */

/**
 * WordPress dependencies.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState, forwardRef } from '@wordpress/element';

/**
 * Internal dependencies.
 */
import Setup from './setup';
import Campaign from './campaign';
import Complete from './complete';
import { withWizard } from '../../../../../packages/components/src';
import Router from '../../../../../packages/components/src/proxied-imports/router';
import ContentGating from './content-gating';
import Payment from './payment';
import Emails from './emails';
import { getSetupTabs } from './tabs';

const { HashRouter, Redirect, Route, Switch } = Router;

function AudienceWizard( { confirmAction, pluginRequirements, wizardApiFetch }, ref ) {
	const [ inFlight, setInFlight ] = useState( false );
	const [ config, setConfig ] = useState( {} );
	const [ prerequisites, setPrerequisites ] = useState( null );
	const [ error, setError ] = useState( false );
	const [ espSyncErrors, setEspSyncErrors ] = useState( [] );

	const fetchConfig = () => {
		setError( false );
		setInFlight( true );
		return wizardApiFetch( {
			path: '/newspack/v1/wizard/newspack-audience/audience-management',
		} )
			.then( ( { config: fetchedConfig, prerequisites_status, can_esp_sync } ) => {
				setPrerequisites( prerequisites_status );
				setConfig( fetchedConfig );
				setEspSyncErrors( can_esp_sync.errors );
			} )
			.catch( setError )
			.finally( () => setInFlight( false ) );
	};
	const updateConfig = ( key, val ) => {
		setConfig( { ...config, [ key ]: val } );
	};
	const saveConfig = data => {
		setError( false );
		setInFlight( true );
		wizardApiFetch( {
			path: '/newspack/v1/wizard/newspack-audience/audience-management',
			method: 'post',
			quiet: true,
			data,
		} )
			.then( ( { config: fetchedConfig, prerequisites_status, can_esp_sync } ) => {
				setPrerequisites( prerequisites_status );
				setConfig( fetchedConfig );
				setEspSyncErrors( can_esp_sync.errors );
			} )
			.catch( setError )
			.finally( () => setInFlight( false ) );
	};
	const skipPrerequisite = ( data, callback = null ) => {
		confirmAction( {
			message: __( 'Are you sure you want to skip this step? You can always come back later.', 'newspack-plugin' ),
			confirmText: __( 'Skip', 'newspack-plugin' ),
			callback: () => {
				setError( false );
				setInFlight( true );
				wizardApiFetch( {
					path: '/newspack/v1/wizard/newspack-audience/audience-management/skip',
					method: 'post',
					quiet: true,
					data,
				} )
					.then( ( { config: fetchedConfig, prerequisites_status, can_esp_sync } ) => {
						setPrerequisites( prerequisites_status );
						setConfig( fetchedConfig );
						setEspSyncErrors( can_esp_sync.errors );
						if ( callback ) {
							callback();
						}
					} )
					.catch( setError )
					.finally( () => setInFlight( false ) );
			},
		} );
	};

	useEffect( () => {
		window.scrollTo( 0, 0 );
		fetchConfig();
	}, [] );

	// Emails is Newspack-platform-only: Newspack-managed transactional
	// emails only fire when Newspack is the reader-revenue platform. The
	// server computes this from Donations::is_platform_wc().
	const showEmails = Boolean( newspackAudience.emails?.isNewspackPlatform );

	const tabs = getSetupTabs( {
		enabled: config.enabled,
		hasMemberships: newspackAudience.has_memberships,
		showEmails,
	} );

	const getSharedProps = ( configKey, type = 'checkbox' ) => {
		const props = {
			onChange: val => updateConfig( configKey, val ),
		};
		if ( configKey !== 'enabled' ) {
			props.disabled = inFlight;
		}
		switch ( type ) {
			case 'checkbox':
				props.checked = Boolean( config[ configKey ] );
				break;
			case 'text':
				props.value = config[ configKey ] || '';
				break;
		}

		return props;
	};

	const props = {
		headerText: __( 'Audience Management', 'newspack-plugin' ),
		tabbedNavigation: tabs,
		wizardApiFetch,
		inFlight,
		error,
		fetchConfig,
		updateConfig,
		saveConfig,
		skipPrerequisite,
		setInFlight,
		setError,
		getSharedProps,
		espSyncErrors,
		prerequisites,
		config,
	};

	return (
		<div ref={ ref }>
			<HashRouter hashType="slash">
				<Switch>
					{ pluginRequirements }
					<Route path="/" exact render={ () => <Setup { ...props } /> } />
					<Route path="/content-gating" render={ () => <ContentGating { ...props } /> } />
					<Route path="/payment" render={ () => <Payment { ...props } /> } />
					<Route
						path="/emails"
						render={ () =>
							showEmails ? <Emails { ...props } className="newspack-wizard__content--full-width" /> : <Redirect to="/" />
						}
					/>
					<Route path="/campaign" render={ () => <Campaign { ...props } /> } />
					<Route path="/complete" render={ () => <Complete { ...props } /> } />
					<Redirect to="/" />
				</Switch>
			</HashRouter>
		</div>
	);
}

export default withWizard( forwardRef( AudienceWizard ) );
