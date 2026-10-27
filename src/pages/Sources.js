import { __, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { useApi, useStored, send, siteNow } from '../lib/hooks';
import { money, compact, relative } from '../lib/format';
import { makeColorer } from '../lib/colors';
import {
	Card,
	Meter,
	SourceAvatar,
	SourceStatus,
	Segmented,
	PageHead,
	Empty,
	ErrorNotice,
	RANGE_OPTIONS,
	Button,
	Drawer,
	Switch,
	Setting,
	MoneyInput,
	CountInput,
	useToast,
	typeLabel,
	Pill,
	Loading,
	Term,
} from '../components/ui';
import Icon from '../components/Icon';

export default function Sources() {
	const [ days, setDays ] = useStored( 'range', 30 );
	const { data, error, loading, reload } = useApi( `sources?days=${ days }` );
	const [ editing, setEditing ] = useState( null );
	const colorOf = useMemo(
		() => makeColorer( data?.order ),
		[ data?.order ]
	);

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data ) {
		return <Loading />;
	}

	const sources = data.sources;
	const budgeted = sources.filter( ( s ) => s.policy.budget > 0 ).length;
	const paused = sources.filter( ( s ) => s.policy.paused ).length;
	const attention = sources.filter( ( s ) =>
		[ 'forecast', 'near', 'capped', 'limited' ].includes( s.status )
	).length;
	const current = editing ? sources.find( ( s ) => s.id === editing ) : null;

	return (
		<div className={ loading ? 'tokenwarden-is-loading' : '' }>
			<PageHead
				help="sources"
				title={ __( 'Sources', 'tokenwarden' ) }
				lede={ __(
					'Every plugin and theme that has called AI on this site. Give each one a monthly budget, pause it, or change how the gateway treats its requests.',
					'tokenwarden'
				) }
			>
				<Segmented
					label={ __( 'Date range', 'tokenwarden' ) }
					options={ RANGE_OPTIONS }
					value={ days }
					onChange={ setDays }
				/>
			</PageHead>

			<div
				className="tokenwarden-row"
				style={ { marginBottom: 16, gap: 8 } }
			>
				<Pill icon="sources">
					{ sprintf(
						/* translators: %d: number of sources. */ __(
							'%d sources',
							'tokenwarden'
						),
						sources.length
					) }
				</Pill>

				<Pill icon="gauge">
					{ sprintf(
						/* translators: %d: number of sources with a budget. */ __(
							'%d with a budget',
							'tokenwarden'
						),
						budgeted
					) }
				</Pill>

				{ attention > 0 && (
					<Pill tone="serious" icon="alert">
						{ sprintf(
							/* translators: %d: number of sources that need attention. */ __(
								'%d need attention',
								'tokenwarden'
							),
							attention
						) }
					</Pill>
				) }

				{ paused > 0 && (
					<Pill tone="critical" icon="pause">
						{ sprintf(
							/* translators: %d: number of paused sources. */ __(
								'%d paused',
								'tokenwarden'
							),
							paused
						) }
					</Pill>
				) }
				<span className="tokenwarden-spacer" />

				<span
					className="tokenwarden-muted"
					style={ { fontSize: 12.5 } }
				>
					{ sprintf(
						/* translators: %s: month and year. */ __(
							'Budgets reset on the 1st. Now: %s.',
							'tokenwarden'
						),
						data.month.label
					) }
				</span>
			</div>

			<Card bodyClass={ null }>
				{ sources.length === 0 ? (
					<Empty
						title={ __( 'No sources yet', 'tokenwarden' ) }
						text={ __(
							'Plugins and themes appear here the first time they call AI, through the WordPress AI Client or directly with their own API key.',
							'tokenwarden'
						) }
					/>
				) : (
					<div className="tokenwarden-table-wrap">
						<table className="tokenwarden-table">
							<thead>
								<tr>
									<th scope="col">
										{ __( 'Source', 'tokenwarden' ) }
									</th>
									<th scope="col" style={ { minWidth: 220 } }>
										<Term
											label={ __(
												'This month vs budget',
												'tokenwarden'
											) }
											tip={ __(
												'Spend since the 1st of this month against the source’s monthly budget. The striped part is the forecast for the month; the tick marks the budget.',
												'tokenwarden'
											) }
											align="left"
										/>
									</th>
									<th scope="col" className="is-num">
										{ __( 'Spend', 'tokenwarden' ) }
									</th>
									<th scope="col" className="is-num">
										{ __( 'Calls', 'tokenwarden' ) }
									</th>
									<th scope="col">
										{ __( 'Models', 'tokenwarden' ) }
									</th>
									<th scope="col">
										<Term
											label={ __(
												'Status',
												'tokenwarden'
											) }
											tip={ __(
												'Active: within budget. On pace to exceed: under budget now, but the forecast is over. Near budget: past your alert threshold. Budget reached or Paused: its AI calls are blocked.',
												'tokenwarden'
											) }
											align="right"
										/>
									</th>
									<th scope="col">
										<span className="tokenwarden-sr">
											{ __( 'Actions', 'tokenwarden' ) }
										</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{ sources.map( ( s ) => (
									<tr
										key={ s.id }
										className="is-clickable"
										onClick={ () => setEditing( s.id ) }
									>
										<td>
											<div className="tokenwarden-source">
												<SourceAvatar
													label={ s.label }
													color={ colorOf( s.id ) }
													large
												/>
												<div style={ { minWidth: 0 } }>
													<div className="tokenwarden-source__name">
														{ s.label }
													</div>
													<div
														className="tokenwarden-muted"
														style={ {
															fontSize: 12,
														} }
													>
														{ typeLabel( s.type ) }
														{ s.last_seen &&
															` · ${ relative(
																s.last_seen,
																siteNow()
															) }` }
													</div>
												</div>
											</div>
										</td>
										<td>
											{ s.policy.budget > 0 ? (
												<div>
													<Meter
														small
														value={ s.month }
														max={ s.policy.budget }
														forecast={ s.forecast }
														label={ s.label }
													/>
													<div
														className="tokenwarden-row tokenwarden-num"
														style={ {
															fontSize: 12,
															marginTop: 7,
															justifyContent:
																'space-between',
														} }
													>
														<span>{ `${ money(
															s.month
														) } / ${ money(
															s.policy.budget
														) }` }</span>

														<span className="tokenwarden-muted">
															{ sprintf(
																/* translators: %s: forecast amount. */ __(
																	'forecast %s',
																	'tokenwarden'
																),
																money(
																	s.forecast
																)
															) }
														</span>
													</div>
												</div>
											) : (
												<span
													className="tokenwarden-muted"
													style={ { fontSize: 12.5 } }
												>
													{ money( s.month ) } ·{ ' ' }
													{ __(
														'No budget',
														'tokenwarden'
													) }
												</span>
											) }
										</td>
										<td className="is-num">
											<strong>{ money( s.cost ) }</strong>
										</td>
										<td className="is-num">
											{ compact( s.requests ) }
										</td>
										<td>
											<div className="tokenwarden-chips">
												{ s.models
													.slice( 0, 2 )
													.map( ( m ) => (
														<span
															key={ m }
															className="tokenwarden-chip"
														>
															{ m }
														</span>
													) ) }
												{ s.models.length > 2 && (
													<span className="tokenwarden-chip">{ `+${
														s.models.length - 2
													}` }</span>
												) }
											</div>
										</td>
										<td>
											<SourceStatus status={ s.status } />
										</td>
										<td style={ { textAlign: 'right' } }>
											<Button
												variant="ghost"
												size="sm"
												icon="edit"
												onClick={ ( e ) => {
													e.stopPropagation();
													setEditing( s.id );
												} }
											>
												{ __(
													'Policy',
													'tokenwarden'
												) }
											</Button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</Card>

			{ current && (
				<PolicyDrawer
					source={ current }
					color={ colorOf( current.id ) }
					onClose={ () => setEditing( null ) }
					onSaved={ () => {
						reload();
						setEditing( null );
					} }
				/>
			) }
		</div>
	);
}

function PolicyDrawer( { source, color, onClose, onSaved } ) {
	const [ policy, setPolicy ] = useState( source.policy );
	const [ saving, setSaving ] = useState( false );
	const toast = useToast();
	const set = ( key ) => ( value ) =>
		setPolicy( ( p ) => ( { ...p, [ key ]: value } ) );

	const save = () => {
		setSaving( true );
		send( 'sources', 'POST', {
			source: source.id,
			policy: {
				...policy,
				budget: Number( policy.budget ) || 0,
				rate_limit: Number( policy.rate_limit ) || 0,
			},
		} )
			.then( () => {
				toast( __( 'Policy saved', 'tokenwarden' ) );
				onSaved();
			} )
			.catch( ( e ) =>
				toast(
					e.message || __( 'Could not save', 'tokenwarden' ),
					'alert'
				)
			)
			.finally( () => setSaving( false ) );
	};

	const budget = Number( policy.budget ) || 0;

	return (
		<Drawer
			onClose={ onClose }
			title={
				<div className="tokenwarden-source">
					<SourceAvatar
						label={ source.label }
						color={ color }
						large
					/>
					<div>
						<div
							className="tokenwarden-source__name"
							style={ { fontSize: 16 } }
						>
							{ source.label }
						</div>
						<div className="tokenwarden-source__id">
							{ source.id }
						</div>
					</div>
				</div>
			}
			footer={
				<>
					<Button onClick={ onClose }>
						{ __( 'Cancel', 'tokenwarden' ) }
					</Button>
					<Button
						variant="primary"
						onClick={ save }
						disabled={ saving }
					>
						{ saving
							? __( 'Saving…', 'tokenwarden' )
							: __( 'Save policy', 'tokenwarden' ) }
					</Button>
				</>
			}
		>
			<div>
				<h3 className="tokenwarden-section-title">
					{ __( 'This month', 'tokenwarden' ) }
				</h3>
				<div
					className="tokenwarden-row"
					style={ {
						alignItems: 'baseline',
						gap: 8,
						marginBottom: 10,
					} }
				>
					<span
						style={ {
							fontSize: 28,
							fontWeight: 680,
							letterSpacing: '-0.03em',
						} }
					>
						{ money( source.month ) }
					</span>
					<span className="tokenwarden-muted">
						{ budget > 0
							? /* translators: %s: budget. */ sprintf(
									__( 'of %s', 'tokenwarden' ),
									money( budget )
							  )
							: __( 'spent, no budget', 'tokenwarden' ) }
					</span>
				</div>
				{ budget > 0 && (
					<Meter
						value={ source.month }
						max={ budget }
						forecast={ source.forecast }
						label={ __( 'Monthly budget', 'tokenwarden' ) }
					/>
				) }

				<p
					className="tokenwarden-muted"
					style={ { fontSize: 12.5, marginTop: 8 } }
				>
					{ sprintf(
						/* translators: %s: forecast. */ __(
							'Month-end forecast at the current pace: %s',
							'tokenwarden'
						),
						money( source.forecast )
					) }
				</p>
			</div>

			<dl className="tokenwarden-dl">
				<dt>{ __( 'Calls in period', 'tokenwarden' ) }</dt>
				<dd className="tokenwarden-num">
					{ compact( source.requests ) }
				</dd>
				<dt>{ __( 'Tokens', 'tokenwarden' ) }</dt>
				<dd className="tokenwarden-num">
					{ compact( source.tokens ) }
				</dd>
				<dt>{ __( 'Calls with personal data', 'tokenwarden' ) }</dt>
				<dd className="tokenwarden-num">
					{ compact( source.pii_calls ) }
				</dd>
				<dt>{ __( 'Items redacted', 'tokenwarden' ) }</dt>
				<dd className="tokenwarden-num">
					{ compact( source.redactions ) }
				</dd>
				<dt>{ __( 'Blocked / failed', 'tokenwarden' ) }</dt>
				<dd className="tokenwarden-num">{ `${ compact(
					source.blocked
				) } / ${ compact( source.errors ) }` }</dd>
				<dt>{ __( 'Models', 'tokenwarden' ) }</dt>
				<dd>
					<div className="tokenwarden-chips">
						{ source.models.length
							? source.models.map( ( m ) => (
									<span
										key={ m }
										className="tokenwarden-chip"
									>
										{ m }
									</span>
							  ) )
							: '–' }
					</div>
				</dd>
			</dl>

			<div>
				<h3 className="tokenwarden-section-title">
					{ __( 'Policy', 'tokenwarden' ) }
				</h3>
				<div
					className="tokenwarden-card"
					style={ { boxShadow: 'none' } }
				>
					<Setting
						title={ __( 'Monthly budget', 'tokenwarden' ) }
						desc={ __(
							'Calls are blocked once this is reached. Leave empty for no limit.',
							'tokenwarden'
						) }
					>
						<div style={ { width: 130 } }>
							<MoneyInput
								value={ policy.budget }
								onChange={ set( 'budget' ) }
								label={ __(
									'Monthly budget in US dollars',
									'tokenwarden'
								) }
							/>
						</div>
					</Setting>
					<Setting
						title={ __( 'Hourly call limit', 'tokenwarden' ) }
						desc={
							source.rate_limit && ! policy.rate_limit
								? sprintf(
										/* translators: 1: site-wide calls per hour, 2: calls in the last hour. */ __(
											'Calls are blocked once this many were made in the last hour. Empty uses the site-wide limit (%1$d per hour, in Settings). Last hour: %2$s calls.',
											'tokenwarden'
										),
										source.rate_limit,
										compact( source.last_hour )
								  )
								: sprintf(
										/* translators: %s: calls in the last hour. */ __(
											'Calls are blocked once this many were made in the last hour. Empty uses the site-wide limit from Settings, if any. Last hour: %s calls.',
											'tokenwarden'
										),
										compact( source.last_hour )
								  )
						}
					>
						<div style={ { width: 150 } }>
							<CountInput
								value={ policy.rate_limit }
								onChange={ set( 'rate_limit' ) }
								suffix={ __( 'per hour', 'tokenwarden' ) }
								placeholder={
									source.rate_limit && ! policy.rate_limit
										? String( source.rate_limit )
										: ''
								}
								label={ __(
									'Hourly call limit for this source',
									'tokenwarden'
								) }
							/>
						</div>
					</Setting>
					<Setting
						title={ __( 'Pause AI', 'tokenwarden' ) }
						desc={ __(
							'Block the AI calls this source makes, through the WordPress AI Client or directly. The plugin gets an error instead of an answer; plugins that check whether AI is available may hide their AI features.',
							'tokenwarden'
						) }
						badge={
							policy.paused ? (
								<Pill tone="critical" icon="pause">
									{ __( 'Paused', 'tokenwarden' ) }
								</Pill>
							) : null
						}
					>
						<Switch
							checked={ policy.paused }
							onChange={ set( 'paused' ) }
							label={ __(
								'Pause AI for this source',
								'tokenwarden'
							) }
						/>
					</Setting>
					<Setting
						title={ __( 'Redact personal data', 'tokenwarden' ) }
						desc={ __(
							'Replace emails, phone numbers and other personal data with placeholders before this source’s requests leave your site, and put them back in the answer. Off by default: turn it on for plugins that don’t need the real values, such as a support reply writer. Leave it off for plugins that do, such as a spam checker.',
							'tokenwarden'
						) }
						badge={
							policy.redact ? (
								<Pill tone="good" icon="shield">
									{ __( 'Redacting', 'tokenwarden' ) }
								</Pill>
							) : null
						}
					>
						<Switch
							checked={ policy.redact }
							onChange={ set( 'redact' ) }
							label={ __(
								'Redact personal data for this source',
								'tokenwarden'
							) }
						/>
					</Setting>
				</div>
			</div>

			<p
				className="tokenwarden-muted"
				style={ { fontSize: 12.5, display: 'flex', gap: 8 } }
			>
				<Icon
					name="info"
					size={ 15 }
					style={ { flexShrink: 0, marginTop: 1 } }
				/>
				{ __(
					'Costs are estimates from token counts and the price table in Settings. Your provider’s invoice is the source of truth.',
					'tokenwarden'
				) }
			</p>
		</Drawer>
	);
}
