import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { send, useApi } from '../lib/hooks';
import {
	Button,
	ErrorNotice,
	Loading,
	MoneyInput,
	CountInput,
	Pill,
	Setting,
	Switch,
	useToast,
} from '../components/ui';
import Icon from '../components/Icon';

/**
 * Turn demo mode on, then reload so every page shows sample data.
 *
 * @return {Promise} Request.
 */
export function startDemo() {
	return send( 'demo', 'POST', { enabled: true } ).then( () => {
		window.location.hash = '#/overview';
		window.location.reload();
	} );
}

/**
 * Class for a step in the progress bar.
 *
 * @param {number} i    Step index.
 * @param {number} step Current step.
 * @return {string} Class name.
 */
function stepClass( i, step ) {
	if ( i === step ) {
		return 'is-current';
	}
	return i < step ? 'is-done' : '';
}

export default function Welcome( { go } ) {
	const { data, error, reload } = useApi( 'setup' );
	const [ step, setStep ] = useState( 0 );
	const [ form, setForm ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();

	useEffect( () => {
		if ( data && ! form ) {
			setForm( {
				global_budget: data.budget,
				rate_limit: data.rate_limit,
				alerts: {
					enabled: data.alerts.enabled,
					email: data.alerts.email,
				},
				redaction: { enabled: data.redaction },
				// Pre-ticked: the guide shows exactly what is downloaded before anything is saved.
				prices_auto: true,
			} );
		}
	}, [ data, form ] );

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data || ! form ) {
		return <Loading />;
	}

	const steps = [
		{ id: 'welcome', label: __( 'Welcome', 'tokenwarden' ) },
		{ id: 'provider', label: __( 'AI provider', 'tokenwarden' ) },
		...( data.approval.active
			? [ { id: 'approval', label: __( 'Approvals', 'tokenwarden' ) } ]
			: [] ),
		{ id: 'protect', label: __( 'Safeguards', 'tokenwarden' ) },
		{ id: 'done', label: __( 'Ready', 'tokenwarden' ) },
	];
	const current = steps[ Math.min( step, steps.length - 1 ) ];
	const last = step >= steps.length - 1;

	const saveForm = () =>
		send( 'setup', 'POST', {
			...form,
			global_budget: Number( form.global_budget ) || 0,
		} );

	const next = () => {
		if ( current.id === 'protect' ) {
			setBusy( true );
			saveForm()
				.then( () => setStep( step + 1 ) )
				.catch( ( e ) => toast( e.message, 'alert' ) )
				.finally( () => setBusy( false ) );
			return;
		}
		setStep( step + 1 );
	};

	const finish = ( demo ) => {
		setBusy( true );
		send( 'setup', 'POST', { complete: true } )
			.then( () => {
				window.tokenwardenBoot.onboarded = true;
				if ( demo ) {
					return startDemo();
				}
				go( 'overview' );
			} )
			.catch( ( e ) => {
				toast( e.message, 'alert' );
				setBusy( false );
			} );
	};

	return (
		<div className="tokenwarden-wizard">
			<ol
				className="tokenwarden-wizard__steps"
				aria-label={ __( 'Setup steps', 'tokenwarden' ) }
			>
				{ steps.map( ( s, i ) => (
					<li
						key={ s.id }
						className={ stepClass( i, step ) }
						aria-current={ i === step ? 'step' : undefined }
					>
						<span className="tokenwarden-wizard__dot">
							{ i < step ? (
								<Icon name="check" size={ 13 } />
							) : (
								i + 1
							) }
						</span>
						<span className="tokenwarden-wizard__label">
							{ s.label }
						</span>
					</li>
				) ) }
			</ol>

			<section className="tokenwarden-card tokenwarden-wizard__card">
				{ current.id === 'welcome' && <StepWelcome /> }
				{ current.id === 'provider' && (
					<StepProvider data={ data } reload={ reload } />
				) }
				{ current.id === 'approval' && (
					<StepApproval data={ data } reload={ reload } />
				) }
				{ current.id === 'protect' && (
					<StepProtect form={ form } setForm={ setForm } />
				) }
				{ current.id === 'done' && (
					<StepDone busy={ busy } finish={ finish } />
				) }

				{ ! last && (
					<footer className="tokenwarden-wizard__foot">
						<Button
							variant="ghost"
							onClick={ () => finish( false ) }
							disabled={ busy }
						>
							{ __( 'Skip setup', 'tokenwarden' ) }
						</Button>
						<span className="tokenwarden-spacer" />
						{ step > 0 && (
							<Button
								onClick={ () => setStep( step - 1 ) }
								disabled={ busy }
							>
								{ __( 'Back', 'tokenwarden' ) }
							</Button>
						) }
						<Button
							variant="primary"
							onClick={ next }
							disabled={ busy }
						>
							{ step === 0
								? __( 'Get started', 'tokenwarden' )
								: __( 'Continue', 'tokenwarden' ) }
							<Icon name="arrowRight" size={ 14 } />
						</Button>
					</footer>
				) }
			</section>
		</div>
	);
}

function StepWelcome() {
	const points = [
		{
			icon: 'coins',
			title: __( 'See what AI costs', 'tokenwarden' ),
			text: __(
				'AI calls made through WordPress, traced to the plugin that made it, with an estimated cost.',
				'tokenwarden'
			),
		},
		{
			icon: 'gauge',
			title: __( 'Stop runaway spending', 'tokenwarden' ),
			text: __(
				'Monthly budgets for the whole site and for each plugin, enforced before anything is sent.',
				'tokenwarden'
			),
		},
		{
			icon: 'shield',
			title: __( 'Know what personal data leaves', 'tokenwarden' ),
			text: __(
				'See which plugins send emails, phone numbers or card numbers to AI providers, and replace them for the plugins that don’t need them. Pattern-based, so it does not catch every name or address.',
				'tokenwarden'
			),
		},
	];
	return (
		<div className="tokenwarden-wizard__body">
			<h1 className="tokenwarden-wizard__title">
				{ __( 'Welcome to Tokenwarden', 'tokenwarden' ) }
			</h1>
			<p className="tokenwarden-wizard__lede">
				{ __(
					'Tokenwarden sits between your plugins and AI providers, with no setup in those plugins. It sees plugins that use the AI Client built into WordPress, and plugins that call Anthropic, OpenAI, Google and similar providers directly with their own API key. It can’t see plugins that send AI requests to their own service (as many SEO and page builder plugins do), or that bypass WordPress’s HTTP functions. This short guide takes about two minutes.',
					'tokenwarden'
				) }
			</p>
			<div className="tokenwarden-wizard__points">
				{ points.map( ( p ) => (
					<div key={ p.title } className="tokenwarden-wizard__point">
						<span
							className="tokenwarden-savings__icon"
							style={ { width: 36, height: 36 } }
						>
							<Icon name={ p.icon } size={ 18 } />
						</span>
						<div>
							<div style={ { fontWeight: 620 } }>{ p.title }</div>
							<div
								className="tokenwarden-muted"
								style={ { fontSize: 13 } }
							>
								{ p.text }
							</div>
						</div>
					</div>
				) ) }
			</div>
		</div>
	);
}

function StepProvider( { data, reload } ) {
	const connected = data.providers.filter( ( p ) => p.configured );
	return (
		<div className="tokenwarden-wizard__body">
			<h1 className="tokenwarden-wizard__title">
				{ __( 'Connect an AI provider', 'tokenwarden' ) }
			</h1>
			<p className="tokenwarden-wizard__lede">
				{ __(
					'Plugins use the AI provider you connect to WordPress, such as Anthropic, OpenAI or Google. Tokenwarden does not need its own API key.',
					'tokenwarden'
				) }
			</p>

			{ data.providers.length > 0 && (
				<ul className="tokenwarden-checklist">
					{ data.providers.map( ( p ) => (
						<li key={ p.id }>
							<span>{ p.name }</span>
							{ p.configured ? (
								<Pill tone="good" icon="check">
									{ __( 'API key added', 'tokenwarden' ) }
								</Pill>
							) : (
								<Pill tone="warning" icon="alert">
									{ __( 'No API key yet', 'tokenwarden' ) }
								</Pill>
							) }
						</li>
					) ) }
				</ul>
			) }

			{ connected.length > 0 ? (
				<p className="tokenwarden-wizard__ok">
					<Icon name="check" size={ 16 } />
					{ __(
						'You’re connected. Continue to the next step.',
						'tokenwarden'
					) }
				</p>
			) : (
				<ol className="tokenwarden-howto">
					{ data.providers.length === 0 && (
						<li>
							<strong>
								{ __(
									'Install a provider plugin.',
									'tokenwarden'
								) }
							</strong>{ ' ' }
							{ __(
								'For example “AI Provider for Anthropic”, “AI Provider for OpenAI” or “AI Provider for Google”.',
								'tokenwarden'
							) }
							<div>
								<a
									className="tokenwarden-btn is-sm"
									href={ data.plugins }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ __(
										'Find provider plugins',
										'tokenwarden'
									) }
									<Icon name="external" size={ 13 } />
								</a>
							</div>
						</li>
					) }
					<li>
						<strong>
							{ __( 'Add your API key.', 'tokenwarden' ) }
						</strong>{ ' ' }
						{ __(
							'Go to Settings → Connectors and paste the key from your provider’s account.',
							'tokenwarden'
						) }
						<div>
							<a
								className="tokenwarden-btn is-sm"
								href={ data.connectors }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Open Connectors', 'tokenwarden' ) }
								<Icon name="external" size={ 13 } />
							</a>
						</div>
					</li>
					<li>
						<strong>
							{ __( 'Come back here', 'tokenwarden' ) }
						</strong>{ ' ' }
						{ __( 'and check again.', 'tokenwarden' ) }
						<div>
							<Button size="sm" icon="refresh" onClick={ reload }>
								{ __( 'Check again', 'tokenwarden' ) }
							</Button>
						</div>
					</li>
				</ol>
			) }
			<p
				className="tokenwarden-muted"
				style={ { fontSize: 12.5, marginTop: 16 } }
			>
				{ __(
					'No provider yet? You can still continue and explore Tokenwarden with demo data at the end.',
					'tokenwarden'
				) }
			</p>
		</div>
	);
}

function StepApproval( { data, reload } ) {
	const pending = data.approval.pending.filter( ( p ) => ! p.self );
	const self = data.approval.pending.some( ( p ) => p.self );
	return (
		<div className="tokenwarden-wizard__body">
			<h1 className="tokenwarden-wizard__title">
				{ __( 'Approve plugins that use AI', 'tokenwarden' ) }
			</h1>
			<p className="tokenwarden-wizard__lede">
				{ __(
					'Your site uses the AI plugin’s Connector Approval feature: each plugin must be approved before it can use your AI provider. Until then its AI calls are blocked, and Tokenwarden logs them as “Blocked”.',
					'tokenwarden'
				) }
			</p>

			{ pending.length > 0 ? (
				<>
					<h2 className="tokenwarden-section-title">
						{ sprintf(
							/* translators: %d: number of plugins. */
							_n(
								'%d plugin is waiting for approval',
								'%d plugins are waiting for approval',
								pending.length,
								'tokenwarden'
							),
							pending.length
						) }
					</h2>
					<ul className="tokenwarden-checklist">
						{ pending.map( ( p ) => (
							<li key={ `${ p.basename }-${ p.connector }` }>
								<span>
									{ p.name }
									<span
										className="tokenwarden-muted"
										style={ { fontSize: 12.5 } }
									>
										{ ` · ${ p.connector }` }
									</span>
								</span>
								<Pill tone="warning" icon="clock">
									{ __( 'Waiting', 'tokenwarden' ) }
								</Pill>
							</li>
						) ) }
					</ul>
				</>
			) : (
				<p className="tokenwarden-wizard__ok">
					<Icon name="check" size={ 16 } />
					{ __(
						'No plugins are waiting for approval right now.',
						'tokenwarden'
					) }
				</p>
			) }

			<ol className="tokenwarden-howto">
				<li>
					<strong>
						{ __(
							'Open Tools → Connector Approval.',
							'tokenwarden'
						) }
					</strong>
					<div>
						<a
							className="tokenwarden-btn is-sm"
							href={ data.approval.url }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'Open Connector Approval', 'tokenwarden' ) }
							<Icon name="external" size={ 13 } />
						</a>
					</div>
				</li>
				<li>
					<strong>
						{ __(
							'Approve the plugins you trust to use AI.',
							'tokenwarden'
						) }
					</strong>{ ' ' }
					{ __(
						'A plugin appears there after it first tries to use AI.',
						'tokenwarden'
					) }
				</li>
				<li>
					<strong>
						{ __( 'Come back and check again.', 'tokenwarden' ) }
					</strong>
					<div>
						<Button size="sm" icon="refresh" onClick={ reload }>
							{ __( 'Check again', 'tokenwarden' ) }
						</Button>
					</div>
				</li>
			</ol>

			<p
				className="tokenwarden-callout-inline"
				style={ { marginTop: 16 } }
			>
				<Icon
					name="info"
					size={ 16 }
					style={ { flexShrink: 0, marginTop: 2 } }
				/>
				<span>
					{ __(
						'Tokenwarden itself never uses AI, so it never needs approval.',
						'tokenwarden'
					) }
					{ self &&
						' ' +
							__(
								'If “Tokenwarden” is listed on the approval screen (earlier versions checked providers in a way that triggered this), you can deny or ignore it.',
								'tokenwarden'
							) }
				</span>
			</p>
		</div>
	);
}

function StepProtect( { form, setForm } ) {
	const set = ( patch ) => setForm( ( f ) => ( { ...f, ...patch } ) );
	return (
		<div className="tokenwarden-wizard__body">
			<h1 className="tokenwarden-wizard__title">
				{ __( 'Set up safeguards', 'tokenwarden' ) }
			</h1>
			<p className="tokenwarden-wizard__lede">
				{ __(
					'Safe defaults you can change any time under Settings and Privacy.',
					'tokenwarden'
				) }
			</p>
			<div className="tokenwarden-card" style={ { boxShadow: 'none' } }>
				<Setting
					title={ __(
						'Monthly AI budget for the whole site',
						'tokenwarden'
					) }
					desc={ __(
						'AI calls stop when this is reached, until the 1st of next month. Leave empty for no limit.',
						'tokenwarden'
					) }
				>
					<div style={ { width: 140 } }>
						<MoneyInput
							value={ form.global_budget }
							onChange={ ( v ) => set( { global_budget: v } ) }
							label={ __(
								'Monthly budget in US dollars',
								'tokenwarden'
							) }
						/>
					</div>
				</Setting>
				<Setting
					title={ __(
						'Hourly call limit per plugin',
						'tokenwarden'
					) }
					desc={ __(
						'Stops a plugin stuck in a loop: once it makes this many AI calls in an hour, its further calls are blocked and you get an email. Set it well above normal use. Leave empty for no limit.',
						'tokenwarden'
					) }
				>
					<div style={ { width: 170 } }>
						<CountInput
							value={ form.rate_limit }
							onChange={ ( v ) => set( { rate_limit: v } ) }
							suffix={ __( 'per hour', 'tokenwarden' ) }
							placeholder={ __( 'No limit', 'tokenwarden' ) }
							label={ __(
								'Hourly call limit per plugin',
								'tokenwarden'
							) }
						/>
					</div>
				</Setting>
				<Setting
					title={ __( 'Email me about budgets', 'tokenwarden' ) }
					desc={ __(
						'One email at 80% of a budget, one when it is reached, and one if a plugin hits the hourly limit.',
						'tokenwarden'
					) }
				>
					<Switch
						checked={ form.alerts.enabled }
						onChange={ ( v ) =>
							set( { alerts: { ...form.alerts, enabled: v } } )
						}
						label={ __( 'Email alerts', 'tokenwarden' ) }
					/>
				</Setting>
				{ form.alerts.enabled && (
					<Setting title={ __( 'Send alerts to', 'tokenwarden' ) }>
						<input
							type="email"
							className="tokenwarden-input"
							style={ { width: 260 } }
							value={ form.alerts.email }
							aria-label={ __(
								'Alert email address',
								'tokenwarden'
							) }
							onChange={ ( e ) =>
								set( {
									alerts: {
										...form.alerts,
										email: e.target.value,
									},
								} )
							}
						/>
					</Setting>
				) }
				<Setting
					title={ __(
						'Keep model prices up to date',
						'tokenwarden'
					) }
					desc={ __(
						'Downloads current prices once a day from OpenRouter’s public model list, so cost estimates stay accurate. Nothing about your site is sent. Without this, the prices built into the plugin are used.',
						'tokenwarden'
					) }
				>
					<Switch
						checked={ form.prices_auto }
						onChange={ ( v ) => set( { prices_auto: v } ) }
						label={ __(
							'Keep model prices up to date',
							'tokenwarden'
						) }
					/>
				</Setting>
				<Setting
					title={ __(
						'Check AI requests for personal data',
						'tokenwarden'
					) }
					desc={ __(
						'Shows which plugins send emails, phone numbers and similar to AI providers. Nothing is changed; later you can turn on redaction for the plugins that don’t need the real values.',
						'tokenwarden'
					) }
				>
					<Switch
						checked={ form.redaction.enabled }
						onChange={ ( v ) =>
							set( { redaction: { enabled: v } } )
						}
						label={ __(
							'Check AI requests for personal data',
							'tokenwarden'
						) }
					/>
				</Setting>
			</div>
		</div>
	);
}

function StepDone( { busy, finish } ) {
	return (
		<div
			className="tokenwarden-wizard__body"
			style={ { textAlign: 'center' } }
		>
			<span
				className="tokenwarden-savings__icon"
				style={ { width: 52, height: 52, margin: '0 auto 14px' } }
			>
				<Icon name="check" size={ 24 } />
			</span>
			<h1 className="tokenwarden-wizard__title">
				{ __( 'You’re all set', 'tokenwarden' ) }
			</h1>
			<p
				className="tokenwarden-wizard__lede"
				style={ { margin: '0 auto' } }
			>
				{ __(
					'Tokenwarden now records the AI calls plugins make through WordPress. They appear on the dashboard as soon as a plugin uses AI.',
					'tokenwarden'
				) }
			</p>
			<div className="tokenwarden-wizard__choices">
				<button
					type="button"
					className="tokenwarden-choice"
					onClick={ () => finish( false ) }
					disabled={ busy }
				>
					<Icon name="overview" size={ 22 } />
					<strong>
						{ __( 'Go to my dashboard', 'tokenwarden' ) }
					</strong>
					<span>
						{ __(
							'See your site’s real AI activity.',
							'tokenwarden'
						) }
					</span>
				</button>
				<button
					type="button"
					className="tokenwarden-choice"
					onClick={ () => finish( true ) }
					disabled={ busy }
				>
					<Icon name="spark" size={ 22 } />
					<strong>
						{ __( 'Explore with demo data', 'tokenwarden' ) }
					</strong>
					<span>
						{ __(
							'Try every feature with sample numbers. Your real data and settings aren’t touched; switch back any time.',
							'tokenwarden'
						) }
					</span>
				</button>
			</div>
			<p
				className="tokenwarden-muted"
				style={ { fontSize: 12.5, marginTop: 18 } }
			>
				{ __(
					'You can open this guide again from Help.',
					'tokenwarden'
				) }
			</p>
		</div>
	);
}
