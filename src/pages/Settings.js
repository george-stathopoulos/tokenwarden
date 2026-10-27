import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { send } from '../lib/hooks';
import { useSettingsDraft, SaveBar } from '../lib/settings';
import {
	Card,
	PageHead,
	Switch,
	Pill,
	ErrorNotice,
	Setting,
	MoneyInput,
	CountInput,
	Button,
	useToast,
	Loading,
	InfoTip,
	Term,
} from '../components/ui';
import Icon from '../components/Icon';
import { dateLong, dateTime } from '../lib/format';

const RETENTION = [ 30, 90, 180, 365 ];

export default function Settings() {
	const {
		payload,
		draft,
		patch,
		setDraft,
		dirty,
		save,
		discard,
		saving,
		error,
		reload,
	} = useSettingsDraft();

	if ( error && ! payload ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! draft ) {
		return <Loading />;
	}

	return (
		<div>
			<PageHead
				help="settings"
				title={ __( 'Settings', 'tokenwarden' ) }
				lede={ __(
					'Site-wide budget, alerts, what gets logged, and the prices used to estimate cost.',
					'tokenwarden'
				) }
			/>

			<div className="tokenwarden-grid">
				<Card
					className="tokenwarden-span-6"
					title={ __( 'Budget and alerts', 'tokenwarden' ) }
					bodyClass={ null }
				>
					<div style={ { marginTop: 8 } }>
						<Setting
							title={ __(
								'Site-wide monthly budget',
								'tokenwarden'
							) }
							desc={ __(
								'AI calls Tokenwarden can see stop when total spend reaches this amount. Streamed answers whose cost isn’t known don’t count towards it. Leave empty for no limit.',
								'tokenwarden'
							) }
						>
							<div style={ { width: 130 } }>
								<MoneyInput
									value={ draft.global_budget }
									onChange={ ( v ) =>
										patch( 'global_budget', v )
									}
									label={ __(
										'Site-wide monthly budget in US dollars',
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
								'Runaway protection: the most AI calls any one plugin or theme may make in an hour. Further calls are blocked and you get an alert. It counts every call, including streamed ones with no known cost. A plugin can have its own limit on the Sources page. Leave empty for no limit.',
								'tokenwarden'
							) }
						>
							<div style={ { width: 170 } }>
								<CountInput
									value={ draft.rate_limit }
									onChange={ ( v ) =>
										patch( 'rate_limit', v )
									}
									suffix={ __( 'per hour', 'tokenwarden' ) }
									placeholder={ __(
										'No limit',
										'tokenwarden'
									) }
									label={ __(
										'Hourly call limit per plugin',
										'tokenwarden'
									) }
								/>
							</div>
						</Setting>
						<Setting
							title={ __( 'Email alerts', 'tokenwarden' ) }
							desc={ __(
								'One email per budget per month when it passes the threshold, and one when it is reached. Also one a day per plugin that hits its hourly limit.',
								'tokenwarden'
							) }
						>
							<Switch
								checked={ draft.alerts.enabled }
								onChange={ ( v ) =>
									patch( 'alerts', { enabled: v } )
								}
								label={ __( 'Email alerts', 'tokenwarden' ) }
							/>
						</Setting>
						<Setting
							title={ __( 'Alert threshold', 'tokenwarden' ) }
							badge={
								<InfoTip align="left">
									{ __(
										'For example, at 80 percent you get an email when a budget is 80 percent used, and another when it is fully used. Sources past this point show “Near budget”.',
										'tokenwarden'
									) }
								</InfoTip>
							}
							desc={ __(
								'Share of a budget used before the first alert.',
								'tokenwarden'
							) }
						>
							<span className="tokenwarden-range">
								<input
									type="range"
									min="50"
									max="95"
									step="5"
									value={ draft.alerts.threshold }
									disabled={ ! draft.alerts.enabled }
									aria-label={ __(
										'Alert threshold in percent',
										'tokenwarden'
									) }
									onChange={ ( e ) =>
										patch( 'alerts', {
											threshold: Number( e.target.value ),
										} )
									}
								/>
								<output>{ `${ draft.alerts.threshold }%` }</output>
							</span>
						</Setting>
						<Setting
							title={ __( 'Send alerts to', 'tokenwarden' ) }
						>
							<input
								type="email"
								className="tokenwarden-input"
								style={ { width: 240 } }
								value={ draft.alerts.email }
								disabled={ ! draft.alerts.enabled }
								aria-label={ __(
									'Alert email address',
									'tokenwarden'
								) }
								onChange={ ( e ) =>
									patch( 'alerts', { email: e.target.value } )
								}
							/>
						</Setting>
					</div>
				</Card>

				<Card
					className="tokenwarden-span-6"
					title={ __( 'Logging', 'tokenwarden' ) }
					bodyClass={ null }
				>
					<div style={ { marginTop: 8 } }>
						<Setting
							title={ __(
								'Store prompt and response excerpts',
								'tokenwarden'
							) }
							desc={ __(
								'Keeps the first 1,000 characters of each prompt (with detected personal data masked) and response, for debugging. Off by default: answers are stored as received and can contain customer data.',
								'tokenwarden'
							) }
							badge={
								draft.logging.store_excerpts ? (
									<Pill tone="warning" icon="eye">
										{ __( 'On', 'tokenwarden' ) }
									</Pill>
								) : null
							}
						>
							<Switch
								checked={ draft.logging.store_excerpts }
								onChange={ ( v ) =>
									patch( 'logging', { store_excerpts: v } )
								}
								label={ __( 'Store excerpts', 'tokenwarden' ) }
							/>
						</Setting>
						<Setting
							title={ __(
								'Keep request history for',
								'tokenwarden'
							) }
							badge={
								<InfoTip align="left">
									{ __(
										'How long the request log keeps each call. Month-to-date spend for budgets is stored separately, so deleting old calls never resets a budget.',
										'tokenwarden'
									) }
								</InfoTip>
							}
							desc={ __(
								'Older requests are deleted daily. Month-to-date budget totals are not affected.',
								'tokenwarden'
							) }
						>
							<select
								className="tokenwarden-select"
								style={ { width: 140 } }
								value={ draft.logging.retention_days }
								aria-label={ __(
									'Retention period',
									'tokenwarden'
								) }
								onChange={ ( e ) =>
									patch( 'logging', {
										retention_days: Number(
											e.target.value
										),
									} )
								}
							>
								{ RETENTION.map( ( d ) => (
									<option key={ d } value={ d }>
										{ sprintf(
											/* translators: %d: number of days. */ __(
												'%d days',
												'tokenwarden'
											),
											d
										) }
									</option>
								) ) }
								{ ! RETENTION.includes(
									draft.logging.retention_days
								) && (
									<option
										value={ draft.logging.retention_days }
									>
										{ sprintf(
											/* translators: %d: number of days. */ __(
												'%d days',
												'tokenwarden'
											),
											draft.logging.retention_days
										) }
									</option>
								) }
							</select>
						</Setting>
					</div>
				</Card>
			</div>

			<Prices
				payload={ payload }
				draft={ draft }
				setPrices={ ( prices ) =>
					setDraft( ( d ) => ( { ...d, prices } ) )
				}
				setAuto={ ( on ) =>
					setDraft( ( d ) => ( { ...d, prices_auto: on } ) )
				}
				dirty={ dirty }
				reload={ reload }
			/>

			<DangerZone onCleared={ reload } />

			<SaveBar
				dirty={ dirty }
				saving={ saving }
				save={ save }
				discard={ discard }
			/>
		</div>
	);
}

function Prices( { payload, draft, setPrices, setAuto, dirty, reload } ) {
	const [ newModel, setNewModel ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const defaults = payload.default_prices;
	const live = payload.live_prices || {};
	const overrides = draft.prices || {};
	const base = ( key ) => live[ key ] || defaults[ key ];
	const keys = Array.from(
		new Set( [
			...Object.keys( overrides ),
			...Object.keys( live ),
			...Object.keys( defaults ),
		] )
	).sort();
	const priced = ( model ) =>
		keys.some( ( k ) => model === k || model.startsWith( `${ k }-` ) );
	const unpriced = ( payload.seen_models || [] ).filter(
		( m ) => ! priced( m.toLowerCase() )
	);
	const q = query.trim().toLowerCase();
	const shown = q ? keys.filter( ( k ) => k.includes( q ) ) : keys;

	const setPrice = ( key, index, value ) => {
		const current = overrides[ key ] || base( key ) || [ 0, 0 ];
		const next = [ ...current ];
		next[ index ] = value === '' ? 0 : Number( value );
		setPrices( { ...overrides, [ key ]: next } );
	};

	const reset = ( key ) => {
		const next = { ...overrides };
		delete next[ key ];
		setPrices( next );
	};

	const add = ( model ) => {
		const key = model.trim().toLowerCase();
		if ( key && ! overrides[ key ] && ! base( key ) ) {
			setPrices( { ...overrides, [ key ]: [ 0, 0 ] } );
		}
		setNewModel( '' );
	};

	const sourceOf = ( key ) => {
		if ( overrides[ key ] ) {
			return base( key ) ? (
				<Pill tone="accent">{ __( 'Edited', 'tokenwarden' ) }</Pill>
			) : (
				<Pill tone="accent">{ __( 'Custom', 'tokenwarden' ) }</Pill>
			);
		}
		if ( live[ key ] ) {
			return (
				<Pill tone="good" icon="refresh">
					{ __( 'Live', 'tokenwarden' ) }
				</Pill>
			);
		}
		return (
			<span className="tokenwarden-muted">
				{ __( 'Built-in', 'tokenwarden' ) }
			</span>
		);
	};

	return (
		<Card
			title={
				<Term
					label={ __( 'Model prices', 'tokenwarden' ) }
					tip={ __(
						'Used to estimate the cost of each call: tokens × price. Your edits always win; otherwise live prices are used when automatic updates are on, and the built-in prices otherwise.',
						'tokenwarden'
					) }
					align="left"
				/>
			}
			sub={ __(
				'US dollars per million tokens, used to estimate the cost of each call.',
				'tokenwarden'
			) }
			bodyClass={ null }
			style={ { marginBottom: 18 } }
		>
			<AutoPrices
				pricing={ payload.pricing }
				enabled={ draft.prices_auto }
				saved={ payload.settings.prices_auto }
				onChange={ setAuto }
				dirty={ dirty }
				reload={ reload }
			/>
			{ unpriced.length > 0 && (
				<div
					className="tokenwarden-banner"
					style={ { margin: '14px 20px 0' } }
				>
					<span className="tokenwarden-banner__icon">
						<Icon name="coins" />
					</span>
					<div className="tokenwarden-banner__text">
						<strong>
							{ __(
								'Models in use without a price:',
								'tokenwarden'
							) }
						</strong>{ ' ' }
						{ unpriced.map( ( m ) => (
							<button
								key={ m }
								type="button"
								className="tokenwarden-chip"
								style={ {
									border: 0,
									cursor: 'pointer',
									marginRight: 4,
								} }
								onClick={ () => add( m ) }
							>
								{ `+ ${ m }` }
							</button>
						) ) }
					</div>
				</div>
			) }
			<div
				className="tokenwarden-row"
				style={ { padding: '14px 20px 0' } }
			>
				<input
					type="search"
					className="tokenwarden-input"
					style={ { maxWidth: 320 } }
					value={ query }
					placeholder={ __( 'Find a model', 'tokenwarden' ) }
					aria-label={ __( 'Find a model', 'tokenwarden' ) }
					onChange={ ( e ) => setQuery( e.target.value ) }
				/>
				<span
					className="tokenwarden-muted"
					style={ { fontSize: 12.5 } }
				>
					{ sprintf(
						/* translators: 1: rows shown, 2: all rows. */
						__( '%1$d of %2$d models', 'tokenwarden' ),
						shown.length,
						keys.length
					) }
				</span>
			</div>
			<div
				className="tokenwarden-table-wrap"
				style={ { maxHeight: 460, overflowY: 'auto', marginTop: 10 } }
			>
				<table className="tokenwarden-table">
					<thead style={ { position: 'sticky', top: 0, zIndex: 1 } }>
						<tr>
							<th scope="col">
								{ __( 'Model or prefix', 'tokenwarden' ) }
							</th>
							<th scope="col" className="is-num">
								{ __( 'Input', 'tokenwarden' ) }
							</th>
							<th scope="col" className="is-num">
								{ __( 'Output', 'tokenwarden' ) }
							</th>
							<th scope="col">
								{ __( 'Source', 'tokenwarden' ) }
							</th>
							<th scope="col">
								<span className="tokenwarden-sr">
									{ __( 'Actions', 'tokenwarden' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ shown.map( ( key ) => {
							const price = overrides[ key ] || base( key );
							return (
								<tr key={ key }>
									<td>
										<span className="tokenwarden-chip">
											{ key }
										</span>
									</td>
									{ [ 0, 1 ].map( ( i ) => (
										<td key={ i } className="is-num">
											<span
												className="tokenwarden-money"
												style={ {
													justifyContent: 'flex-end',
												} }
											>
												<input
													className="tokenwarden-input tokenwarden-price-input tokenwarden-num"
													type="number"
													min="0"
													step="0.01"
													value={ price[ i ] }
													aria-label={ sprintf(
														/* translators: 1: input or output, 2: model. */
														__(
															'%1$s price for %2$s',
															'tokenwarden'
														),
														i
															? __(
																	'Output',
																	'tokenwarden'
															  )
															: __(
																	'Input',
																	'tokenwarden'
															  ),
														key
													) }
													onChange={ ( e ) =>
														setPrice(
															key,
															i,
															e.target.value
														)
													}
												/>
											</span>
										</td>
									) ) }
									<td>{ sourceOf( key ) }</td>
									<td style={ { textAlign: 'right' } }>
										{ overrides[ key ] && (
											<Button
												variant="ghost"
												size="sm"
												onClick={ () => reset( key ) }
											>
												{ base( key )
													? __(
															'Reset',
															'tokenwarden'
													  )
													: __(
															'Remove',
															'tokenwarden'
													  ) }
											</Button>
										) }
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			</div>
			<footer className="tokenwarden-card__foot">
				<span>
					{ sprintf(
						/* translators: 1: model id, 2: dated model id. */
						__(
							'A row also covers dated versions: “%1$s” matches “%2$s”.',
							'tokenwarden'
						),
						'claude-haiku-4-5',
						'claude-haiku-4-5-20251001'
					) }
				</span>
				<form
					className="tokenwarden-row"
					style={ { gap: 6, flexWrap: 'nowrap' } }
					onSubmit={ ( e ) => {
						e.preventDefault();
						add( newModel );
					} }
				>
					<input
						className="tokenwarden-input"
						style={ { width: 200, minHeight: 32 } }
						value={ newModel }
						placeholder={ __( 'model-id', 'tokenwarden' ) }
						aria-label={ __( 'New model id', 'tokenwarden' ) }
						onChange={ ( e ) => setNewModel( e.target.value ) }
					/>
					<Button
						size="sm"
						icon="plus"
						type="submit"
						disabled={ ! newModel.trim() }
					>
						{ __( 'Add', 'tokenwarden' ) }
					</Button>
				</form>
			</footer>
		</Card>
	);
}

function AutoPrices( { pricing, enabled, saved, onChange, dirty, reload } ) {
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();

	const updateNow = () => {
		setBusy( true );
		send( 'prices/update', 'POST' )
			.then( () => {
				toast( __( 'Prices updated', 'tokenwarden' ) );
				reload();
			} )
			.catch( ( e ) => toast( e.message, 'alert' ) )
			.finally( () => setBusy( false ) );
	};

	let status;
	if ( enabled !== saved ) {
		status = enabled
			? __(
					'Save changes to download current prices now, and then once a day.',
					'tokenwarden'
			  )
			: __(
					'Save changes to switch back to the built-in prices.',
					'tokenwarden'
			  );
	} else if ( pricing.auto && pricing.updated_at ) {
		status = sprintf(
			/* translators: 1: date and time, 2: number of models, 3: source name. */
			__( 'Last updated %1$s · %2$d models from %3$s.', 'tokenwarden' ),
			dateTime( pricing.updated_at.slice( 0, 19 ).replace( 'T', ' ' ) ),
			pricing.count,
			pricing.source
		);
	} else if ( pricing.auto ) {
		status = __( 'Waiting for the first download.', 'tokenwarden' );
	} else {
		status = sprintf(
			/* translators: 1: date, 2: plugin version. */
			__(
				'Using the built-in prices from Tokenwarden %2$s, checked on %1$s. They change only when you update the plugin.',
				'tokenwarden'
			),
			dateLong( pricing.checked ),
			pricing.version
		);
	}

	return (
		<div
			className="tokenwarden-card"
			style={ { boxShadow: 'none', margin: '14px 20px 0' } }
		>
			<Setting
				title={ __( 'Update prices automatically', 'tokenwarden' ) }
				desc={ __(
					'Downloads current prices once a day from OpenRouter’s public model list, which covers Anthropic, OpenAI, Google, xAI, Mistral and DeepSeek models, plus every model on OpenRouter. Groq and Perplexity models aren’t priced: add their prices below. The request sends nothing about your site. Prices you edit below always take priority.',
					'tokenwarden'
				) }
				badge={
					pricing.live && enabled === saved ? (
						<Pill tone="good" icon="check">
							{ __( 'Live prices', 'tokenwarden' ) }
						</Pill>
					) : null
				}
			>
				<Switch
					checked={ enabled }
					onChange={ onChange }
					label={ __( 'Update prices automatically', 'tokenwarden' ) }
				/>
			</Setting>
			<div
				className="tokenwarden-setting"
				style={ { alignItems: 'center' } }
			>
				<div
					className="tokenwarden-setting__text"
					style={ { fontSize: 13 } }
				>
					<span
						className={ pricing.error ? '' : 'tokenwarden-muted' }
					>
						{ status }
					</span>
					{ pricing.error && enabled === saved && (
						<div
							style={ {
								color: 'var(--tokenwarden-critical-ink)',
								marginTop: 4,
							} }
						>
							{ sprintf(
								/* translators: %s: error message. */
								__(
									'Last attempt failed: %s The last downloaded prices are still used.',
									'tokenwarden'
								),
								pricing.error
							) }
						</div>
					) }
					{ pricing.stale && ! enabled && (
						<div
							style={ {
								color: 'var(--tokenwarden-warning-ink)',
								marginTop: 4,
								fontWeight: 600,
							} }
						>
							{ sprintf(
								/* translators: %d: number of days. */
								__(
									'These prices are %d days old. Turn on automatic updates, update the plugin, or review them below.',
									'tokenwarden'
								),
								pricing.age_days
							) }
						</div>
					) }
				</div>
				{ pricing.auto && enabled === saved && (
					<div className="tokenwarden-setting__control">
						<Button
							size="sm"
							icon="refresh"
							onClick={ updateNow }
							disabled={ busy || dirty }
						>
							{ busy
								? __( 'Updating…', 'tokenwarden' )
								: __( 'Update now', 'tokenwarden' ) }
						</Button>
					</div>
				) }
			</div>
		</div>
	);
}

function DangerZone( { onCleared } ) {
	const [ armed, setArmed ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const toast = useToast();

	const clear = () => {
		if ( ! armed ) {
			setArmed( true );
			window.setTimeout( () => setArmed( false ), 4000 );
			return;
		}
		setBusy( true );
		send( 'requests', 'DELETE' )
			.then( () => {
				toast( __( 'Request log cleared', 'tokenwarden' ) );
				onCleared();
			} )
			.catch( ( e ) => toast( e.message, 'alert' ) )
			.finally( () => {
				setBusy( false );
				setArmed( false );
			} );
	};

	return (
		<Card title={ __( 'Data', 'tokenwarden' ) } bodyClass={ null }>
			<div style={ { marginTop: 8 } }>
				<Setting
					title={ __( 'Clear request log', 'tokenwarden' ) }
					desc={ __(
						'Deletes every logged request and resets month-to-date spend. Settings, budgets and prices are kept. This cannot be undone.',
						'tokenwarden'
					) }
				>
					<Button
						variant="danger"
						icon="trash"
						onClick={ clear }
						disabled={ busy }
					>
						{ armed
							? __( 'Click again to confirm', 'tokenwarden' )
							: __( 'Clear log', 'tokenwarden' ) }
					</Button>
				</Setting>
			</div>
		</Card>
	);
}
