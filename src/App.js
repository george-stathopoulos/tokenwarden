import { __, sprintf } from '@wordpress/i18n';
import {
	createInterpolateElement,
	useEffect,
	useState,
} from '@wordpress/element';
import { send, useRoute, useTheme } from './lib/hooks';
import { Button, Switch, ToastProvider } from './components/ui';
import Icon from './components/Icon';
import Overview from './pages/Overview';
import Sources from './pages/Sources';
import Requests from './pages/Requests';
import Privacy from './pages/Privacy';
import Caching from './pages/Caching';
import Settings from './pages/Settings';
import Help from './pages/Help';
import Welcome, { startDemo } from './pages/Welcome';

const ROUTES = [
	{
		id: 'overview',
		label: __( 'Overview', 'tokenwarden' ),
		icon: 'overview',
		Page: Overview,
	},
	{
		id: 'sources',
		label: __( 'Sources', 'tokenwarden' ),
		icon: 'sources',
		Page: Sources,
	},
	{
		id: 'requests',
		label: __( 'Requests', 'tokenwarden' ),
		icon: 'requests',
		Page: Requests,
	},
	{
		id: 'privacy',
		label: __( 'Privacy', 'tokenwarden' ),
		icon: 'shield',
		Page: Privacy,
	},
	{
		id: 'caching',
		label: __( 'Caching', 'tokenwarden' ),
		icon: 'bolt',
		Page: Caching,
	},
	{
		id: 'settings',
		label: __( 'Settings', 'tokenwarden' ),
		icon: 'settings',
		Page: Settings,
	},
];

/** Pages reachable by link but not shown in the menu. */
const HIDDEN = [
	{ id: 'welcome', label: __( 'Setup', 'tokenwarden' ), Page: Welcome },
	{ id: 'help', label: __( 'Help', 'tokenwarden' ), Page: Help },
];

const THEMES = [
	{
		id: 'system',
		icon: 'monitor',
		label: __( 'Theme: match system', 'tokenwarden' ),
	},
	{ id: 'light', icon: 'sun', label: __( 'Theme: light', 'tokenwarden' ) },
	{ id: 'dark', icon: 'moon', label: __( 'Theme: dark', 'tokenwarden' ) },
];

export default function App() {
	const [ route, go ] = useRoute( 'overview' );
	const [ theme, setTheme ] = useTheme();
	const routes = ROUTES;
	const current =
		[ ...routes, ...HIDDEN ].find( ( r ) => r.id === route ) || routes[ 0 ];
	const boot = window.tokenwardenBoot || {};
	const [ busy, setBusy ] = useState( '' );

	// First visit: open the setup guide until it is completed or skipped.
	useEffect( () => {
		if ( ! boot.onboarded && ! window.location.hash ) {
			go( 'welcome' );
		}
	}, [] ); // eslint-disable-line react-hooks/exhaustive-deps

	const setDemo = ( on ) => {
		setBusy(
			on
				? __( 'Preparing demo data…', 'tokenwarden' )
				: __( 'Switching back to your data…', 'tokenwarden' )
		);
		const done = on
			? startDemo()
			: send( 'demo', 'POST', { enabled: false } ).then( () =>
					window.location.reload()
			  );
		done.catch( () => setBusy( '' ) );
	};

	const deleteDemo = () => {
		setBusy( __( 'Deleting demo data…', 'tokenwarden' ) );
		send( 'demo', 'DELETE' )
			.then( () => window.location.reload() )
			.catch( () => setBusy( '' ) );
	};
	const themeIndex = Math.max(
		0,
		THEMES.findIndex( ( t ) => t.id === theme )
	);
	const nextTheme = THEMES[ ( themeIndex + 1 ) % THEMES.length ];
	const { Page } = current;

	return (
		<div
			className="tokenwarden-root"
			data-theme={ theme === 'system' ? undefined : theme }
		>
			<ToastProvider>
				<header className="tokenwarden-header">
					<div className="tokenwarden-header__inner">
						<div className="tokenwarden-brand">
							<span className="tokenwarden-brand__mark">
								<svg
									width="20"
									height="20"
									viewBox="0 0 20 20"
									aria-hidden="true"
								>
									<path
										fill="currentColor"
										d="M10 2a7 7 0 0 0-7 7v8a1 1 0 0 0 1 1h3v-6a3 3 0 0 1 6 0v6h3a1 1 0 0 0 1-1V9a7 7 0 0 0-7-7Zm0 3.2a.9.9 0 1 1 0 1.8.9.9 0 0 1 0-1.8Z"
									/>
								</svg>
							</span>
							<span>
								<div className="tokenwarden-brand__name">
									Tokenwarden
								</div>
								<div className="tokenwarden-brand__sub">
									{ __(
										'Cost, privacy & policy for site AI',
										'tokenwarden'
									) }
								</div>
							</span>
						</div>
						<nav
							className="tokenwarden-nav"
							aria-label={ __(
								'Tokenwarden sections',
								'tokenwarden'
							) }
						>
							{ routes.map( ( r ) => (
								<a
									key={ r.id }
									href={ `#/${ r.id }` }
									className={ `tokenwarden-nav__item ${
										r.id === current.id ? 'is-active' : ''
									}` }
									aria-current={
										r.id === current.id ? 'page' : undefined
									}
								>
									<Icon name={ r.icon } size={ 15 } />
									{ r.label }
								</a>
							) ) }
						</nav>
						<div className="tokenwarden-header__end">
							<a
								href="#/help"
								className={ `tokenwarden-btn is-ghost is-sm ${
									current.id === 'help' ? 'is-active' : ''
								}` }
								aria-current={
									current.id === 'help' ? 'page' : undefined
								}
							>
								<Icon name="info" size={ 15 } />
								{ __( 'Help', 'tokenwarden' ) }
							</a>
							<span
								className="tokenwarden-demo-toggle"
								title={ __(
									'Show sample numbers from fictional plugins. Your real data and settings are not affected.',
									'tokenwarden'
								) }
							>
								<Switch
									checked={ !! boot.demo }
									onChange={ setDemo }
									label={ __( 'Demo data', 'tokenwarden' ) }
								/>
								<span aria-hidden="true">
									{ __( 'Demo data', 'tokenwarden' ) }
								</span>
							</span>
							<button
								type="button"
								className="tokenwarden-btn is-ghost is-icon"
								onClick={ () => setTheme( nextTheme.id ) }
								title={ THEMES[ themeIndex ].label }
								aria-label={ `${
									THEMES[ themeIndex ].label
								}. ${ __(
									'Click to change.',
									'tokenwarden'
								) }` }
							>
								<Icon
									name={ THEMES[ themeIndex ].icon }
									size={ 17 }
								/>
							</button>
						</div>
					</div>
				</header>
				<main className="tokenwarden-shell" key={ current.id }>
					{ boot.demo && current.id !== 'welcome' && (
						<div className="tokenwarden-demo-bar" role="status">
							<Icon name="spark" size={ 18 } />
							<div className="tokenwarden-demo-bar__text">
								<strong>
									{ __(
										'You’re viewing demo data.',
										'tokenwarden'
									) }
								</strong>{ ' ' }
								{ __(
									'The numbers come from fictional plugins, and changes you make here are saved to the demo only. Your real AI calls, budgets and settings are not affected.',
									'tokenwarden'
								) }
							</div>
							<Button
								size="sm"
								variant="primary"
								onClick={ () => setDemo( false ) }
							>
								{ __( 'Back to my data', 'tokenwarden' ) }
							</Button>
							<Button
								size="sm"
								variant="ghost"
								onClick={ deleteDemo }
							>
								{ __( 'Delete demo data', 'tokenwarden' ) }
							</Button>
						</div>
					) }
					<Page go={ go } />
				</main>
				<footer className="tokenwarden-footer">
					<span>
						{ createInterpolateElement(
							__(
								'Made with <heart /> by <a>Tokenwarden</a>',
								'tokenwarden'
							),
							{
								heart: (
									<span
										className="tokenwarden-footer__heart"
										role="img"
										aria-label={ __(
											'love',
											'tokenwarden'
										) }
									>
										♥
									</span>
								),
								a: (
									// eslint-disable-next-line jsx-a11y/anchor-has-content
									<a
										href={ boot.siteUrl }
										target="_blank"
										rel="noopener noreferrer"
									/>
								),
							}
						) }
					</span>
					<span className="tokenwarden-footer__links">
						<a
							href={ boot.docsUrl }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Help center', 'tokenwarden' ) }
						</a>
						<a
							href={ boot.supportUrl }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Support', 'tokenwarden' ) }
						</a>
						<span>
							{ sprintf(
								/* translators: %s: plugin version number. */
								__( 'Version %s', 'tokenwarden' ),
								boot.version
							) }
						</span>
					</span>
				</footer>
				{ busy && (
					<div className="tokenwarden-busy" role="status">
						<div className="tokenwarden-busy__box">
							<span className="tokenwarden-busy__spin" />
							{ busy }
						</div>
					</div>
				) }
			</ToastProvider>
		</div>
	);
}
