import { __ } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { Card, PageHead, Button, Pill } from '../components/ui';
import Icon from '../components/Icon';
import { startDemo } from './Welcome';
import { send } from '../lib/hooks';

/**
 * Help topics. `id` is used by "Help" links on other pages: #/help?topic=<id>.
 */
const GUIDES = [
	{
		id: 'overview',
		route: 'overview',
		icon: 'overview',
		title: __( 'Overview', 'tokenwarden' ),
		text: __(
			'Your AI spend for the period, the month-end forecast against your budget, anything that needs attention, and charts of what runs when.',
			'tokenwarden'
		),
	},
	{
		id: 'sources',
		route: 'sources',
		icon: 'sources',
		title: __( 'Sources and budgets', 'tokenwarden' ),
		text: __(
			'Every plugin and theme that used AI. Open a source’s Policy to give it a monthly budget, pause it, or change how the gateway treats it.',
			'tokenwarden'
		),
	},
	{
		id: 'requests',
		route: 'requests',
		icon: 'requests',
		title: __( 'Requests', 'tokenwarden' ),
		text: __(
			'A log of the AI calls Tokenwarden can see: those made through the WordPress AI Client, and direct calls plugins make to AI providers with their own API key. Completed, blocked by a budget, pause or approval, or failed at the provider. Click a row for details.',
			'tokenwarden'
		),
	},
	{
		id: 'privacy',
		route: 'privacy',
		icon: 'shield',
		title: __( 'Privacy', 'tokenwarden' ),
		text: __(
			'See which plugins send personal data to AI providers, and turn on redaction for the ones that don’t need it.',
			'tokenwarden'
		),
	},
	{
		id: 'settings',
		route: 'settings',
		icon: 'settings',
		title: __( 'Settings', 'tokenwarden' ),
		text: __(
			'Site-wide budget, alert emails, what is logged and for how long, and the prices used to estimate cost.',
			'tokenwarden'
		),
	},
];

const FAQ = [
	{
		id: 'nothing',
		topic: 'troubleshooting',
		q: __( 'Why don’t I see any AI calls?', 'tokenwarden' ),
		a: __(
			'Tokenwarden sees calls made through WordPress’s built-in AI (the AI Client). Check that an AI provider is connected under Settings → Connectors, that the plugin you use works through WordPress AI, and that you picked a long enough period at the top of the Overview. Plugins that call a provider directly with their own API key can’t be seen.',
			'tokenwarden'
		),
	},
	{
		id: 'approval',
		topic: 'troubleshooting',
		q: __(
			'A plugin’s calls show “Not approved in AI → Connector Approval”.',
			'tokenwarden'
		),
		a: __(
			'Your site uses the AI plugin’s Connector Approval feature, which blocks plugins until an administrator approves them. Go to Tools → Connector Approval and approve the plugin. Tokenwarden itself never uses AI and never needs approval.',
			'tokenwarden'
		),
	},
	{
		id: 'disappeared',
		topic: 'troubleshooting',
		q: __( 'A plugin’s AI features disappeared.', 'tokenwarden' ),
		a: __(
			'It is probably blocked: paused, over its monthly budget, or over the site-wide budget. Check its status on Sources, or filter Requests by “Blocked” to see the reason.',
			'tokenwarden'
		),
	},
	{
		id: 'provider',
		topic: 'troubleshooting',
		q: __( 'A banner says my provider is not connected.', 'tokenwarden' ),
		a: __(
			'Install a provider plugin (for example “AI Provider for Anthropic”) and add its API key under Settings → Connectors. If it says the provider is installed but not connected, the key is missing.',
			'tokenwarden'
		),
	},
	{
		id: 'costs',
		topic: 'costs',
		q: __( 'Are the costs exact?', 'tokenwarden' ),
		a: __(
			'They are estimates: the tokens your provider reports × the price table under Settings. Discounts (caching, batch, negotiated rates), taxes and fees aren’t included. Your provider’s invoice is the source of truth.',
			'tokenwarden'
		),
	},
	{
		id: 'prices',
		topic: 'costs',
		q: __( 'How are model prices kept up to date?', 'tokenwarden' ),
		a: __(
			'Turn on “Update prices automatically” under Settings → Model prices. Tokenwarden then downloads current prices once a day from OpenRouter’s public model list (nothing about your site is sent). With it off, the prices built into the plugin are used and updated with each release. Prices you edit always take priority.',
			'tokenwarden'
		),
	},
	{
		id: 'noprice',
		topic: 'costs',
		q: __( 'A model shows “no price”.', 'tokenwarden' ),
		a: __(
			'The model isn’t in your price table. Turn on automatic price updates, or add its price under Settings → Model prices (there is a one-click button for each). Its calls are logged at $0 until it has a price.',
			'tokenwarden'
		),
	},
	{
		id: 'budgets',
		topic: 'budgets',
		q: __( 'What happens when a budget is reached?', 'tokenwarden' ),
		a: __(
			'The next AI call is stopped before anything is sent, so it costs nothing. The plugin gets the same error as when AI is unavailable, and many plugins hide their AI buttons. Budgets reset on the 1st of each month, or straight away if you raise them.',
			'tokenwarden'
		),
	},
	{
		id: 'forecast',
		topic: 'budgets',
		q: __( 'How is the forecast worked out?', 'tokenwarden' ),
		a: __(
			'Spend so far this month, plus the average daily spend of the last 7 days for each remaining day. “On pace to exceed” means a source is still under budget but its forecast is over.',
			'tokenwarden'
		),
	},
	{
		id: 'names',
		topic: 'privacy',
		q: __( 'Are names removed from prompts?', 'tokenwarden' ),
		a: __(
			'Not automatically. Add the names that matter (key customers, staff) as custom terms on the Privacy page.',
			'tokenwarden'
		),
	},
	{
		id: 'placeholders',
		topic: 'privacy',
		q: __(
			'An answer contains [EMAIL_1] instead of the real address.',
			'tokenwarden'
		),
		a: __(
			'The AI model changed the placeholder so much that it couldn’t be recognised and put back. If this keeps happening for a plugin, turn off redaction for it on the Privacy page.',
			'tokenwarden'
		),
	},
	{
		id: 'stored',
		topic: 'privacy',
		q: __(
			'Does Tokenwarden store my prompts or send data anywhere?',
			'tokenwarden'
		),
		a: __(
			'No. It stores facts about each call (plugin, model, tokens, cost, timing) in your own database. Prompt text is only stored if you turn on excerpts under Settings → Logging. Nothing is sent to Tokenwarden’s makers. The only outside request is the optional daily price download, which sends nothing about your site.',
			'tokenwarden'
		),
	},
	{
		id: 'demo',
		topic: 'demo',
		q: __( 'What is demo data?', 'tokenwarden' ),
		a: __(
			'Sample numbers from fictional plugins, so you can try every feature. Demo data lives in a separate sandbox: your real log, budgets and settings are never touched, and real AI calls keep being controlled by your real settings. Turn it off with “Back to my data”.',
			'tokenwarden'
		),
	},
	{
		id: 'alerts',
		topic: 'troubleshooting',
		q: __( 'I don’t receive alert emails.', 'tokenwarden' ),
		a: __(
			'Check that Email alerts is on under Settings and the address is right. Each alert is sent once per budget per month. If other WordPress emails also don’t arrive, install an SMTP plugin.',
			'tokenwarden'
		),
	},
];

const GLOSSARY = [
	[
		__( 'Source', 'tokenwarden' ),
		__( 'The plugin or theme that made an AI call.', 'tokenwarden' ),
	],
	[
		__( 'Token', 'tokenwarden' ),
		__(
			'The unit AI providers count and charge by, roughly ¾ of a word. Input tokens are what you send; output tokens are the answer.',
			'tokenwarden'
		),
	],
	[
		__( 'Budget', 'tokenwarden' ),
		__(
			'A monthly spending limit, for the whole site or one source. Calls are blocked once it is reached.',
			'tokenwarden'
		),
	],
	[
		__( 'Forecast', 'tokenwarden' ),
		__(
			'Where spending is likely to end up by the end of the month, based on the last 7 days.',
			'tokenwarden'
		),
	],
	[
		__( 'Personal data detection', 'tokenwarden' ),
		__(
			'Checking each AI request for emails, phone numbers and other personal data, and recording what kind was found (never the values). It changes nothing.',
			'tokenwarden'
		),
	],
	[
		__( 'Redaction', 'tokenwarden' ),
		__(
			'Replacing personal data with placeholders such as [EMAIL_1] before a request leaves your site, and putting the real values back in the answer. Off by default; you turn it on per plugin. It is pattern-based, so it reduces personal data rather than removing all of it.',
			'tokenwarden'
		),
	],
	[
		__( 'Blocked', 'tokenwarden' ),
		__(
			'A call stopped before reaching the provider: by a pause, a budget, or Connector Approval.',
			'tokenwarden'
		),
	],
	[
		__( 'Connector', 'tokenwarden' ),
		__(
			'WordPress’s connection to an AI provider, set up under Settings → Connectors.',
			'tokenwarden'
		),
	],
];

/**
 * Topic from the URL: #/help?topic=budgets.
 *
 * @return {string} Topic or ''.
 */
function topicFromHash() {
	const query = window.location.hash.split( '?' )[ 1 ] || '';
	return new URLSearchParams( query ).get( 'topic' ) || '';
}

export default function Help( { go } ) {
	const [ query, setQuery ] = useState( '' );
	const [ topic, setTopic ] = useState( topicFromHash );
	const boot = window.tokenwardenBoot || {};

	useEffect( () => {
		const onHash = () => setTopic( topicFromHash() );
		window.addEventListener( 'hashchange', onHash );
		return () => window.removeEventListener( 'hashchange', onHash );
	}, [] );

	useEffect( () => {
		if ( topic ) {
			const el = document.getElementById( `tokenwarden-help-${ topic }` );
			if ( el ) {
				el.scrollIntoView( { block: 'start', behavior: 'smooth' } );
			}
		}
	}, [ topic ] );

	const q = query.trim().toLowerCase();
	const faq = useMemo(
		() =>
			FAQ.filter(
				( f ) =>
					! q ||
					f.q.toLowerCase().includes( q ) ||
					f.a.toLowerCase().includes( q )
			),
		[ q ]
	);
	const glossary = GLOSSARY.filter(
		( [ t, d ] ) =>
			! q ||
			t.toLowerCase().includes( q ) ||
			d.toLowerCase().includes( q )
	);
	const guides = GUIDES.filter(
		( g ) =>
			! q ||
			g.title.toLowerCase().includes( q ) ||
			g.text.toLowerCase().includes( q )
	);

	return (
		<div>
			<PageHead
				title={ __( 'Help', 'tokenwarden' ) }
				lede={ __(
					'Guides, answers and quick actions. Hover the ⓘ icons anywhere in Tokenwarden for short explanations.',
					'tokenwarden'
				) }
			/>

			<div className="tokenwarden-help-search">
				<Icon name="filter" size={ 16 } />
				<input
					type="search"
					value={ query }
					onChange={ ( e ) => setQuery( e.target.value ) }
					placeholder={ __(
						'Search help, for example “budget” or “approval”',
						'tokenwarden'
					) }
					aria-label={ __( 'Search help', 'tokenwarden' ) }
				/>
			</div>

			{ ! q && (
				<div className="tokenwarden-grid">
					<QuickAction
						icon="spark"
						title={ __( 'Run the setup guide', 'tokenwarden' ) }
						text={ __(
							'Connect a provider, approve plugins and set a budget, step by step.',
							'tokenwarden'
						) }
						action={ __( 'Open setup guide', 'tokenwarden' ) }
						onClick={ () => go( 'welcome' ) }
					/>
					<QuickAction
						icon="chart"
						title={
							boot.demo
								? __(
										'You are viewing demo data',
										'tokenwarden'
								  )
								: __( 'Explore with demo data', 'tokenwarden' )
						}
						text={ __(
							'Sample numbers in a separate sandbox. Your real data and settings aren’t touched.',
							'tokenwarden'
						) }
						action={
							boot.demo
								? __( 'Back to my data', 'tokenwarden' )
								: __( 'Show demo data', 'tokenwarden' )
						}
						onClick={ () =>
							boot.demo
								? send( 'demo', 'POST', {
										enabled: false,
								  } ).then( () => window.location.reload() )
								: startDemo()
						}
					/>
					<QuickAction
						icon="external"
						title={ __( 'Still stuck?', 'tokenwarden' ) }
						text={ __(
							'Ask on the support forum. Include your WordPress and Tokenwarden versions and what you expected to happen.',
							'tokenwarden'
						) }
						action={ __( 'Get support', 'tokenwarden' ) }
						href={ boot.supportUrl }
					/>
				</div>
			) }

			{ guides.length > 0 && (
				<section
					id="tokenwarden-help-guides"
					style={ { marginBottom: 18 } }
				>
					<h2 className="tokenwarden-section-title">
						{ __( 'Guides', 'tokenwarden' ) }
					</h2>
					<div className="tokenwarden-help-guides">
						{ guides.map( ( g ) => (
							<button
								key={ g.id }
								id={ `tokenwarden-help-${ g.id }` }
								type="button"
								className={ `tokenwarden-help-guide ${
									topic === g.id ? 'is-highlight' : ''
								}` }
								onClick={ () => go( g.route ) }
							>
								<Icon name={ g.icon } size={ 18 } />
								<strong>{ g.title }</strong>
								<span>{ g.text }</span>
								<em>
									{ __( 'Open', 'tokenwarden' ) }
									<Icon name="arrowRight" size={ 13 } />
								</em>
							</button>
						) ) }
					</div>
				</section>
			) }

			<Card
				title={ __( 'Questions and troubleshooting', 'tokenwarden' ) }
				bodyClass={ null }
				style={ { marginBottom: 18 } }
			>
				<div className="tokenwarden-faq" id="tokenwarden-help-faq">
					{ faq.length === 0 && (
						<p className="tokenwarden-card__body tokenwarden-muted">
							{ __(
								'No answers match your search.',
								'tokenwarden'
							) }
						</p>
					) }
					{ faq.map( ( f ) => (
						<details
							key={ f.id }
							id={ `tokenwarden-help-${ f.id }` }
							open={
								topic === f.id ||
								topic === f.topic ||
								( q && faq.length <= 3 )
							}
						>
							<summary>
								{ f.q }
								{ f.topic === 'troubleshooting' && (
									<Pill>
										{ __(
											'Troubleshooting',
											'tokenwarden'
										) }
									</Pill>
								) }
							</summary>
							<p>{ f.a }</p>
						</details>
					) ) }
				</div>
			</Card>

			{ glossary.length > 0 && (
				<Card
					title={ __( 'Glossary', 'tokenwarden' ) }
					style={ { marginBottom: 18 } }
				>
					<dl
						className="tokenwarden-dl"
						id="tokenwarden-help-glossary"
					>
						{ glossary.map( ( [ term, text ] ) => (
							<div key={ term } style={ { display: 'contents' } }>
								<dt
									style={ {
										color: 'var(--tokenwarden-ink)',
										fontWeight: 620,
									} }
								>
									{ term }
								</dt>
								<dd style={ { fontWeight: 450 } }>{ text }</dd>
							</div>
						) ) }
					</dl>
				</Card>
			) }

			{ boot.docsUrl && (
				<p className="tokenwarden-muted" style={ { fontSize: 13 } }>
					<a
						href={ boot.docsUrl }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ __( 'Full documentation', 'tokenwarden' ) }
					</a>
				</p>
			) }
		</div>
	);
}

function QuickAction( { icon, title, text, action, onClick, href } ) {
	return (
		<Card className="tokenwarden-span-4" bodyClass={ null }>
			<div className="tokenwarden-quick">
				<span
					className="tokenwarden-savings__icon"
					style={ { width: 36, height: 36 } }
				>
					<Icon name={ icon } size={ 18 } />
				</span>
				<div className="tokenwarden-quick__title">{ title }</div>
				<div
					className="tokenwarden-muted"
					style={ { fontSize: 13, flex: 1 } }
				>
					{ text }
				</div>
				{ href ? (
					<a
						className="tokenwarden-btn is-sm"
						href={ href }
						target="_blank"
						rel="noopener noreferrer"
					>
						{ action }
						<Icon name="external" size={ 13 } />
					</a>
				) : (
					<Button size="sm" onClick={ onClick }>
						{ action }
					</Button>
				) }
			</div>
		</Card>
	);
}
