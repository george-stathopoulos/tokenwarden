import { __, _n, sprintf } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';
import { useApi, useStored, siteNow } from '../lib/hooks';
import {
	dateLong,
	money,
	moneyParts,
	compact,
	duration,
	change,
	relative,
	integer,
} from '../lib/format';
import { makeColorer, providerColor } from '../lib/colors';
import {
	Card,
	Delta,
	Meter,
	SourceAvatar,
	SourceStatus,
	RequestStatus,
	Segmented,
	PageHead,
	Empty,
	ErrorNotice,
	RANGE_OPTIONS,
	rangeLabel,
	Pill,
	Button,
	Loading,
	InfoTip,
	Term,
} from '../components/ui';
import {
	StackedColumns,
	Sparkline,
	BarList,
	Heatmap,
} from '../components/charts';
import Icon from '../components/Icon';
import { startDemo } from './Welcome';

export default function Overview( { go } ) {
	const [ days, setDays ] = useStored( 'range', 30 );
	const { data, error, loading, reload } = useApi(
		`overview?days=${ days }`
	);

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

	const { kpis, previous, series, month } = data;
	const range = rangeLabel( days );

	const vs = sprintf(
		/* translators: %s: period, e.g. "last 30 days". */ __(
			'compared with the previous %s',
			'tokenwarden'
		),
		range.replace( /^last /, '' )
	);
	const isEmpty = kpis.requests === 0 && data.recent.length === 0;

	return (
		<div className={ loading ? 'tokenwarden-is-loading' : '' }>
			<PageHead
				help="overview"
				title={ __( 'Overview', 'tokenwarden' ) }
				lede={ __(
					'The AI calls plugins on this site make through WordPress: who made it, what it cost, and what the gateway changed or blocked.',
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

			<Banners data={ data } go={ go } />

			{ isEmpty ? (
				<Onboarding />
			) : (
				<>
					<div className="tokenwarden-grid">
						<Card className="tokenwarden-span-8" bodyClass={ null }>
							<div
								className="tokenwarden-hero"
								style={ { height: 'auto', paddingBottom: 8 } }
							>
								<div
									className="tokenwarden-row"
									style={ {
										justifyContent: 'space-between',
										alignItems: 'flex-start',
										position: 'relative',
									} }
								>
									<div>
										<div className="tokenwarden-hero__label">
											<Icon name="coins" size={ 15 } />

											{ sprintf(
												/* translators: %s: period, e.g. "last 30 days". */ __(
													'AI spend, %s',
													'tokenwarden'
												),
												range
											) }
										</div>
										<HeroFigure value={ kpis.cost } />
										<div className="tokenwarden-hero__meta">
											<Delta
												value={ change(
													kpis.cost,
													previous.cost
												) }
												goodWhen="down"
												label={ vs }
											/>
											<span>{ vs }</span>
										</div>
									</div>
									<div
										style={ {
											textAlign: 'right',
											fontSize: 13,
											color: 'var(--tokenwarden-ink-2)',
											position: 'relative',
										} }
									>
										<div>
											<strong
												style={ {
													color: 'var(--tokenwarden-ink)',
												} }
											>
												{ integer( kpis.ok ) }
											</strong>{ ' ' }
											{ _n(
												'completed call',
												'completed calls',
												kpis.ok,
												'tokenwarden'
											) }
										</div>
										<div>
											<strong
												style={ {
													color: 'var(--tokenwarden-ink)',
												} }
											>
												{
													data.sources.filter(
														( s ) => s.requests > 0
													).length
												}
											</strong>{ ' ' }
											{ __(
												'plugins and themes',
												'tokenwarden'
											) }
										</div>
									</div>
								</div>
							</div>
							<div style={ { padding: '0 20px 18px' } }>
								<StackedColumns
									title={ __(
										'Daily AI spend by source',
										'tokenwarden'
									) }
									dates={ series.dates }
									series={ series.stack.map( ( s ) => ( {
										id: s.source,
										label: s.label,
										values: s.values,
										color: colorOf( s.source ),
									} ) ) }
									format={ ( v ) =>
										money( v, { short: true } )
									}
									height={ 210 }
								/>
							</div>
						</Card>

						<div
							className="tokenwarden-span-4"
							style={ {
								display: 'flex',
								flexDirection: 'column',
								gap: 18,
							} }
						>
							<BudgetCard month={ month } go={ go } />
							<AttentionCard
								sources={ data.sources }
								spikes={ data.spikes || [] }
								kpis={ kpis }
								go={ go }
							/>
						</div>
					</div>

					<div className="tokenwarden-kpis">
						<Stat
							icon="bolt"
							label={ __( 'Requests', 'tokenwarden' ) }
							tip={ __(
								'Every AI call in the period: completed, blocked and failed.',
								'tokenwarden'
							) }
							value={ compact( kpis.requests ) }
							delta={ change( kpis.requests, previous.requests ) }
							goodWhen="neutral"
							trend={ series.requests }
							vs={ vs }
						/>
						<Stat
							icon="tokens"
							label={ __( 'Tokens', 'tokenwarden' ) }
							tip={ __(
								'Tokens are the units AI providers charge by, roughly ¾ of a word. This is input plus output for completed calls.',
								'tokenwarden'
							) }
							value={ compact( kpis.tokens ) }
							delta={ change( kpis.tokens, previous.tokens ) }
							goodWhen="neutral"
							trend={ series.tokens }
							vs={ vs }
						/>
						<Stat
							icon="coins"
							label={ __( 'Avg. cost per call', 'tokenwarden' ) }
							tip={ __(
								'Estimated spend divided by completed calls. Lower is better.',
								'tokenwarden'
							) }
							value={ money( kpis.avg_cost ) }
							delta={ change( kpis.avg_cost, previous.avg_cost ) }
							goodWhen="down"
							vs={ vs }
							trend={ series.cost.map( ( c, i ) =>
								series.requests[ i ]
									? c / series.requests[ i ]
									: 0
							) }
						/>
						<Stat
							icon="clock"
							label={ __( 'Avg. response time', 'tokenwarden' ) }
							tip={ __(
								'How long completed calls took, from sending the request to receiving the answer.',
								'tokenwarden'
							) }
							value={ duration( kpis.avg_latency ) }
							delta={ change(
								kpis.avg_latency,
								previous.avg_latency
							) }
							goodWhen="down"
							vs={ vs }
						/>
						<Stat
							icon="shield"
							label={ __(
								'Calls with personal data',
								'tokenwarden'
							) }
							tip={ __(
								'AI calls that contained emails, phone numbers or other personal data. See which plugins send it, and turn on redaction where it isn’t needed, on the Privacy page.',
								'tokenwarden'
							) }
							value={ compact( kpis.pii_calls ) }
							delta={ change(
								kpis.pii_calls,
								previous.pii_calls
							) }
							goodWhen="down"
							trend={ series.pii_calls }
							vs={ vs }
						/>
						<Stat
							icon="ban"
							label={ __( 'Blocked calls', 'tokenwarden' ) }
							tip={ __(
								'Calls stopped before reaching the provider by a pause, a budget, or Connector Approval. They cost nothing.',
								'tokenwarden'
							) }
							value={ compact( kpis.blocked ) }
							delta={ change( kpis.blocked, previous.blocked ) }
							goodWhen="neutral"
							trend={ series.blocked }
							vs={ vs }
						/>
					</div>

					<div className="tokenwarden-grid">
						<Card
							className="tokenwarden-span-7"
							title={
								<Term
									label={ __( 'Top sources', 'tokenwarden' ) }
									tip={ __(
										'The plugins and themes that spent the most in this period. The bar shows this month against each budget: solid is spent, striped is the forecast, and the tick marks the budget.',
										'tokenwarden'
									) }
									align="left"
								/>
							}
							sub={ __(
								'Spend in this period, and this month against each budget',
								'tokenwarden'
							) }
							action={
								<Button
									variant="ghost"
									size="sm"
									onClick={ () => go( 'sources' ) }
								>
									{ __( 'Manage', 'tokenwarden' ) }
									<Icon name="arrowRight" size={ 14 } />
								</Button>
							}
							bodyClass={ null }
						>
							<TopSources
								sources={ data.sources.slice( 0, 6 ) }
								colorOf={ colorOf }
							/>
						</Card>
						<Card
							className="tokenwarden-span-5"
							title={
								<Term
									label={ __( 'Model mix', 'tokenwarden' ) }
									tip={ __(
										'Which AI models the money went to. Models without a price count as $0 until you add one in Settings.',
										'tokenwarden'
									) }
									align="left"
								/>
							}
							sub={ __(
								'Spend by model, completed calls',
								'tokenwarden'
							) }
						>
							{ data.models.length ? (
								<BarList
									items={ data.models
										.slice( 0, 6 )
										.map( ( m ) => ( {
											id: `${ m.provider }/${ m.model }`,
											label: m.model,
											value: m.cost,
											color: 'var(--tokenwarden-s7)',
											prefix: (
												<span
													className="tokenwarden-pill__dot"
													style={ {
														width: 8,
														height: 8,
														borderRadius: '50%',
														background:
															providerColor(
																m.provider
															),
														flexShrink: 0,
													} }
												/>
											),

											meta:
												sprintf(
													/* translators: 1: provider, 2: number of calls, 3: tokens. */ __(
														'%1$s · %2$s calls · %3$s tokens',
														'tokenwarden'
													),
													m.provider ||
														__(
															'unknown',
															'tokenwarden'
														),
													compact( m.requests ),
													compact( m.tokens )
												) +
												( m.priced
													? ''
													: ` · ${ __(
															'no price set',
															'tokenwarden'
													  ) }` ),
										} ) ) }
									format={ ( v ) => money( v ) }
								/>
							) : (
								<Empty
									compact
									title={ __(
										'No completed calls yet',
										'tokenwarden'
									) }
								/>
							) }
						</Card>
					</div>

					<div className="tokenwarden-grid">
						<Card
							className="tokenwarden-span-7"
							title={
								<Term
									label={ __(
										'When AI runs',
										'tokenwarden'
									) }
									tip={ __(
										'Requests by weekday and hour in your site’s timezone. Darker squares mean more calls. Useful for spotting scheduled jobs.',
										'tokenwarden'
									) }
									align="left"
								/>
							}
							sub={ __(
								'Requests by weekday and hour',
								'tokenwarden'
							) }
						>
							<Heatmap grid={ data.heatmap } />
						</Card>
						<Card
							className="tokenwarden-span-5"
							title={ __( 'Recent activity', 'tokenwarden' ) }
							action={
								<Button
									variant="ghost"
									size="sm"
									onClick={ () => go( 'requests' ) }
								>
									{ __( 'All requests', 'tokenwarden' ) }
									<Icon name="arrowRight" size={ 14 } />
								</Button>
							}
							bodyClass={ null }
						>
							<ul
								className="tokenwarden-feed"
								style={ { marginTop: 10 } }
							>
								{ data.recent.map( ( r ) => (
									<li
										key={ r.id }
										className="tokenwarden-feed__item"
									>
										<SourceAvatar
											label={ r.source_label }
											color={ colorOf( r.source ) }
										/>
										<div className="tokenwarden-feed__main">
											<div>{ r.source_label }</div>
											<div>
												{ r.status === 'ok' ? (
													r.model
												) : (
													<RequestStatus
														status={ r.status }
														cached={ r.cached }
													/>
												) }
												{ r.status === 'ok' &&
													r.redactions > 0 &&
													` · ${ sprintf(
														/* translators: %d: number of items redacted. */ _n(
															'%d item replaced',
															'%d items replaced',
															r.redactions,
															'tokenwarden'
														),
														r.redactions
													) }` }
											</div>
										</div>
										<div className="tokenwarden-feed__end">
											<div>
												{ r.status === 'ok'
													? money( r.cost )
													: '–' }
											</div>
											<div>
												{ relative(
													r.created_at,
													siteNow()
												) }
											</div>
										</div>
									</li>
								) ) }
							</ul>
						</Card>
					</div>
					<PricingNote pricing={ data.pricing } go={ go } />
				</>
			) }
		</div>
	);
}

function PricingNote( { pricing, go } ) {
	const text = pricing.live
		? sprintf(
				/* translators: 1: date, 2: source name. */
				__(
					'Costs are estimates: tokens reported by the provider × current prices, updated automatically from %2$s (last update %1$s).',
					'tokenwarden'
				),
				dateLong( pricing.updated_at.slice( 0, 10 ) ),
				pricing.source
		  )
		: sprintf(
				/* translators: 1: date, 2: plugin version. */
				__(
					'Costs are estimates: tokens reported by the provider × prices built into Tokenwarden %2$s (checked %1$s). Turn on automatic price updates in Settings to keep them current.',
					'tokenwarden'
				),
				dateLong( pricing.checked ),
				pricing.version
		  );
	return (
		<p
			className="tokenwarden-muted tokenwarden-row"
			style={ { fontSize: 12.5, gap: 8, marginTop: 6 } }
		>
			<Icon name="info" size={ 14 } />
			<span>
				{ text }{ ' ' }
				<a
					href="#/settings"
					onClick={ ( e ) => {
						e.preventDefault();
						go( 'settings' );
					} }
				>
					{ __( 'Review prices', 'tokenwarden' ) }
				</a>
			</span>
		</p>
	);
}

function HeroFigure( { value } ) {
	const { whole, fraction } = moneyParts( value );
	return (
		<div className="tokenwarden-hero__value">
			{ whole }
			<span className="tokenwarden-hero__cents">{ fraction }</span>
		</div>
	);
}

function Stat( { icon, label, value, delta, goodWhen, trend, vs, tip } ) {
	return (
		<Card bodyClass={ null }>
			<div className="tokenwarden-stat">
				<div className="tokenwarden-stat__label">
					<Icon name={ icon } size={ 14 } />
					{ label }
					{ tip && <InfoTip align="left">{ tip }</InfoTip> }
				</div>
				<div className="tokenwarden-stat__value">{ value }</div>
				<div className="tokenwarden-stat__foot">
					<Delta value={ delta } goodWhen={ goodWhen } label={ vs } />
					{ trend && (
						<Sparkline
							values={ trend.slice( -14 ) }
							width={ 84 }
							height={ 28 }
						/>
					) }
				</div>
			</div>
		</Card>
	);
}

function BudgetCard( { month, go } ) {
	const hasBudget = month.budget > 0;
	const over = hasBudget && month.projection > month.budget;
	return (
		<Card bodyClass={ null }>
			<div className="tokenwarden-budget">
				<div
					className="tokenwarden-row"
					style={ { justifyContent: 'space-between' } }
				>
					<div className="tokenwarden-hero__label">
						<Icon name="gauge" size={ 15 } />
						{ month.label }
						<InfoTip align="left">
							{ __(
								'This calendar month, whatever period you picked. The solid bar is what you’ve spent; the striped part is the forecast: spend so far plus the last 7 days’ daily average for each day left.',
								'tokenwarden'
							) }
						</InfoTip>
					</div>
					{ hasBudget &&
						( over ? (
							<Pill tone="serious" icon="alert">
								{ __( 'Over pace', 'tokenwarden' ) }
							</Pill>
						) : (
							<Pill tone="good" icon="check">
								{ __( 'On track', 'tokenwarden' ) }
							</Pill>
						) ) }
				</div>
				<div className="tokenwarden-budget__figures">
					<span className="tokenwarden-budget__spent">
						{ money( month.spend ) }
					</span>
					<span className="tokenwarden-budget__of">
						{ hasBudget
							? /* translators: %s: monthly budget. */ sprintf(
									__( 'of %s budget', 'tokenwarden' ),
									money( month.budget )
							  )
							: __( 'spent this month', 'tokenwarden' ) }
					</span>
				</div>
				{ hasBudget && (
					<Meter
						value={ month.spend }
						max={ month.budget }
						forecast={ month.projection }
						label={ __(
							'Site-wide monthly budget',
							'tokenwarden'
						) }
					/>
				) }
				<div className="tokenwarden-budget__legend">
					<span>
						<i style={ { background: 'var(--tokenwarden-s1)' } } />
						{ __( 'Spent', 'tokenwarden' ) }
					</span>
					<span>
						<i
							style={ {
								background:
									'repeating-linear-gradient(135deg, color-mix(in srgb, var(--tokenwarden-s1) 45%, transparent) 0 3px, transparent 3px 6px)',
								border: '1px solid var(--tokenwarden-border)',
							} }
						/>
						{ __( 'Forecast', 'tokenwarden' ) }
					</span>

					<span
						style={ {
							marginLeft: 'auto',
							color: 'var(--tokenwarden-muted)',
						} }
					>
						{ sprintf(
							/* translators: 1: day of month, 2: days in month. */ __(
								'Day %1$d of %2$d',
								'tokenwarden'
							),
							month.day,
							month.days
						) }
					</span>
				</div>
				<div className="tokenwarden-callout-inline">
					<Icon
						name="chart"
						size={ 16 }
						style={ { flexShrink: 0, marginTop: 2 } }
					/>
					<span>
						{ __( 'Month-end forecast:', 'tokenwarden' ) }{ ' ' }
						<strong>{ money( month.projection ) }</strong>
						{ ! hasBudget && (
							<>
								{ ' · ' }
								<a
									href="#/settings"
									onClick={ ( e ) => {
										e.preventDefault();
										go( 'settings' );
									} }
								>
									{ __(
										'Set a site-wide budget',
										'tokenwarden'
									) }
								</a>
							</>
						) }
					</span>
				</div>
			</div>
		</Card>
	);
}

function AttentionCard( { sources, spikes, kpis, go } ) {
	const items = [];
	spikes.forEach( ( spike ) => {
		items.push( {
			id: `${ spike.source }-${ spike.kind }`,
			tone: 'serious',
			icon: 'up',
			title: spike.label,
			text:
				spike.kind === 'calls'
					? sprintf(
							/* translators: 1: calls in the last hour, 2: usual calls per hour. */ __(
								'Unusual activity: %1$s calls in the last hour, usually about %2$s',
								'tokenwarden'
							),
							spike.now,
							spike.normal
					  )
					: sprintf(
							/* translators: 1: spend in the last 24 hours, 2: usual daily spend. */ __(
								'Unusual spending: %1$s in the last 24 hours, usually about %2$s a day',
								'tokenwarden'
							),
							money( spike.now ),
							money( spike.normal )
					  ),
		} );
	} );
	sources.forEach( ( s ) => {
		if ( s.status === 'paused' ) {
			items.push( {
				id: s.id,
				tone: 'critical',
				icon: 'pause',
				title: s.label,
				text: __( 'Paused: its AI calls are blocked', 'tokenwarden' ),
			} );
		} else if ( s.status === 'limited' ) {
			items.push( {
				id: s.id,
				tone: 'critical',
				icon: 'gauge',
				title: s.label,
				text: sprintf(
					/* translators: 1: calls in the last hour, 2: hourly limit. */ __(
						'Hit its hourly limit: %1$s calls (limit %2$s). Possible loop or spam wave',
						'tokenwarden'
					),
					s.last_hour,
					s.rate_limit
				),
			} );
		} else if ( s.status === 'capped' ) {
			items.push( {
				id: s.id,
				tone: 'critical',
				icon: 'ban',
				title: s.label,
				text: __( 'Reached its monthly budget', 'tokenwarden' ),
			} );
		} else if ( s.status === 'near' ) {
			items.push( {
				id: s.id,
				tone: 'warning',
				icon: 'alert',
				title: s.label,
				text: sprintf(
					/* translators: %s: amount spent and budget. */ __(
						'Near its budget: %s',
						'tokenwarden'
					),
					`${ money( s.month ) } / ${ money( s.policy.budget ) }`
				),
			} );
		} else if ( s.status === 'forecast' ) {
			items.push( {
				id: s.id,
				tone: 'serious',
				icon: 'gauge',
				title: s.label,
				text: sprintf(
					/* translators: 1: forecast, 2: budget. */ __(
						'On pace for %1$s against a %2$s budget',
						'tokenwarden'
					),
					money( s.forecast ),
					money( s.policy.budget )
				),
			} );
		}
	} );
	if ( kpis.errors > 0 ) {
		items.push( {
			id: 'errors',
			tone: 'warning',
			icon: 'alert',
			title: __( 'Provider errors', 'tokenwarden' ),
			text: sprintf(
				/* translators: %d: number of failed calls. */ _n(
					'%d call failed at the provider',
					'%d calls failed at the provider',
					kpis.errors,
					'tokenwarden'
				),
				kpis.errors
			),
			route: 'requests',
		} );
	}

	return (
		<Card
			title={
				<Term
					label={ __( 'Needs attention', 'tokenwarden' ) }
					tip={ __(
						'Sources that are paused, over or near their budget, or on pace to exceed it, plus calls that failed at the provider.',
						'tokenwarden'
					) }
					align="left"
				/>
			}
			bodyClass={ null }
			style={ { flex: 1 } }
		>
			{ items.length ? (
				<ul className="tokenwarden-feed" style={ { marginTop: 8 } }>
					{ items.slice( 0, 4 ).map( ( it ) => (
						<li
							key={ it.id }
							className="tokenwarden-feed__item"
							style={ {
								gridTemplateColumns: '30px minmax(0,1fr) auto',
							} }
						>
							<span
								className={ `tokenwarden-pill is-${ it.tone }` }
								style={ {
									width: 30,
									height: 30,
									padding: 0,
									justifyContent: 'center',
									borderRadius: 9,
								} }
							>
								<Icon name={ it.icon } size={ 15 } />
							</span>
							<div className="tokenwarden-feed__main">
								<div>{ it.title }</div>
								<div title={ it.text }>{ it.text }</div>
							</div>
							<Button
								variant="ghost"
								size="sm"
								icon="arrowRight"
								aria-label={ __( 'Open', 'tokenwarden' ) }
								onClick={ () => go( it.route || 'sources' ) }
							/>
						</li>
					) ) }
				</ul>
			) : (
				<div
					className="tokenwarden-card__body tokenwarden-row"
					style={ { color: 'var(--tokenwarden-ink-2)' } }
				>
					<Pill tone="good" icon="check">
						{ __( 'All clear', 'tokenwarden' ) }
					</Pill>
					{ __( 'Every source is within budget.', 'tokenwarden' ) }
				</div>
			) }
		</Card>
	);
}

function TopSources( { sources, colorOf } ) {
	if ( ! sources.length ) {
		return (
			<Empty compact title={ __( 'No sources yet', 'tokenwarden' ) } />
		);
	}
	return (
		<div className="tokenwarden-table-wrap" style={ { marginTop: 10 } }>
			<table className="tokenwarden-table">
				<thead>
					<tr>
						<th scope="col">{ __( 'Source', 'tokenwarden' ) }</th>
						<th scope="col" className="is-num">
							{ __( 'Spend', 'tokenwarden' ) }
						</th>
						<th scope="col" style={ { width: '34%' } }>
							{ __( 'This month', 'tokenwarden' ) }
						</th>
						<th scope="col">{ __( 'Status', 'tokenwarden' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ sources.map( ( s ) => (
						<tr key={ s.id }>
							<td>
								<div className="tokenwarden-source">
									<SourceAvatar
										label={ s.label }
										color={ colorOf( s.id ) }
									/>
									<div style={ { minWidth: 0 } }>
										<div className="tokenwarden-source__name">
											{ s.label }
										</div>

										<div
											className="tokenwarden-muted"
											style={ { fontSize: 12 } }
										>
											{ sprintf(
												/* translators: %s: number of calls. */ __(
													'%s calls',
													'tokenwarden'
												),
												compact( s.requests )
											) }
										</div>
									</div>
								</div>
							</td>
							<td className="is-num">
								<strong>{ money( s.cost ) }</strong>
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
											className="tokenwarden-muted tokenwarden-num"
											style={ {
												fontSize: 12,
												marginTop: 6,
											} }
										>{ `${ money( s.month ) } / ${ money(
											s.policy.budget
										) }` }</div>
									</div>
								) : (
									<span
										className="tokenwarden-muted"
										style={ { fontSize: 12.5 } }
									>{ `${ money( s.month ) } · ${ __(
										'no budget',
										'tokenwarden'
									) }` }</span>
								) }
							</td>
							<td>
								<SourceStatus status={ s.status } />
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</div>
	);
}

function Banners( { data, go } ) {
	const boot = window.tokenwardenBoot || {};
	const configured = data.providers.filter( ( p ) => p.configured );
	const waiting = ( data.approval?.pending || [] ).filter(
		( p ) => ! p.self
	);
	return (
		<>
			{ data.approval?.active && waiting.length > 0 && (
				<div className="tokenwarden-banner" role="status">
					<span className="tokenwarden-banner__icon">
						<Icon name="clock" />
					</span>
					<div className="tokenwarden-banner__text">
						<strong>
							{ sprintf(
								/* translators: %s: plugin names. */
								_n(
									'%s is waiting for AI approval.',
									'%s are waiting for AI approval.',
									waiting.length,
									'tokenwarden'
								),
								waiting.map( ( p ) => p.name ).join( ', ' )
							) }
						</strong>{ ' ' }
						{ __(
							'The AI plugin’s Connector Approval blocks their AI calls until you approve them.',
							'tokenwarden'
						) }
					</div>
					<a
						className="tokenwarden-btn is-sm"
						href={ data.approval.url }
					>
						{ __( 'Review approvals', 'tokenwarden' ) }
					</a>
				</div>
			) }
			{ boot.aiEnabled === false && (
				<div className="tokenwarden-banner" role="status">
					<span className="tokenwarden-banner__icon">
						<Icon name="alert" />
					</span>
					<div className="tokenwarden-banner__text">
						<strong>
							{ __(
								'AI features are turned off on this site.',
								'tokenwarden'
							) }
						</strong>{ ' ' }
						{ __(
							'Something has disabled the WordPress AI Client, so no AI calls can run.',
							'tokenwarden'
						) }
					</div>
				</div>
			) }
			{ boot.aiEnabled !== false && configured.length === 0 && (
				<div className="tokenwarden-banner is-info" role="status">
					<span className="tokenwarden-banner__icon">
						<Icon name="plug" />
					</span>
					<div className="tokenwarden-banner__text">
						{ data.providers.length ? (
							<>
								<strong>
									{ sprintf(
										/* translators: %s: provider names. */ __(
											'%s is installed but not connected.',
											'tokenwarden'
										),
										data.providers
											.map( ( p ) => p.name )
											.join( ', ' )
									) }
								</strong>{ ' ' }
								{ __(
									'Its API key hasn’t been added yet, so AI calls will fail until it is.',
									'tokenwarden'
								) }
							</>
						) : (
							<>
								<strong>
									{ __(
										'No AI provider is connected.',
										'tokenwarden'
									) }
								</strong>{ ' ' }
								{ __(
									'Connect Anthropic, OpenAI, Google or another provider so plugins can use AI. Plugins that use their own API key are recorded too, as soon as they make a call.',
									'tokenwarden'
								) }{ ' ' }
								<a
									href={ boot.localAi?.url }
									target="_blank"
									rel="noopener noreferrer"
								>
									{ __(
										'On a development site, you can also try AI Provider for WebLLM, which runs a small model in your browser.',
										'tokenwarden'
									) }
								</a>
							</>
						) }
					</div>
					<a
						className="tokenwarden-btn is-sm"
						href={ boot.connectorsUrl }
					>
						{ __( 'Open Connectors', 'tokenwarden' ) }
						<Icon name="external" size={ 13 } />
					</a>
				</div>
			) }
			{ boot.localAi?.active && ! boot.localAi?.worker && (
				<div className="tokenwarden-banner is-info" role="status">
					<span className="tokenwarden-banner__icon">
						<Icon name="plug" />
					</span>
					<div className="tokenwarden-banner__text">
						<strong>
							{ __(
								'AI Provider for WebLLM is active, but its in-browser worker is off.',
								'tokenwarden'
							) }
						</strong>{ ' ' }
						{ __(
							'AI calls started by plugins on the server need it, and a dashboard tab open. Local calls cost $0 in Tokenwarden.',
							'tokenwarden'
						) }
					</div>
					<a
						className="tokenwarden-btn is-sm"
						href={ boot.localAi.settings }
					>
						{ __( 'Open WebLLM settings', 'tokenwarden' ) }
					</a>
				</div>
			) }
			{ data.kpis.unpriced > 0 && (
				<div className="tokenwarden-banner" role="status">
					<span className="tokenwarden-banner__icon">
						<Icon name="coins" />
					</span>
					<div className="tokenwarden-banner__text">
						<strong>
							{ sprintf(
								/* translators: %s: number of calls. */ _n(
									'%s call used a model with no price.',
									'%s calls used models with no price.',
									data.kpis.unpriced,
									'tokenwarden'
								),
								integer( data.kpis.unpriced )
							) }
						</strong>{ ' ' }
						{ __(
							'Their cost shows as $0 until you add a price.',
							'tokenwarden'
						) }
					</div>
					<Button size="sm" onClick={ () => go( 'settings' ) }>
						{ __( 'Add prices', 'tokenwarden' ) }
					</Button>
				</div>
			) }
			{ data.pricing?.stale && (
				<div className="tokenwarden-banner" role="status">
					<span className="tokenwarden-banner__icon">
						<Icon name="coins" />
					</span>
					<div className="tokenwarden-banner__text">
						<strong>
							{ sprintf(
								/* translators: %d: number of days. */
								__(
									'Built-in model prices are %d days old.',
									'tokenwarden'
								),
								data.pricing.age_days
							) }
						</strong>{ ' ' }
						{ __(
							'Turn on automatic price updates in Settings, or update Tokenwarden.',
							'tokenwarden'
						) }
					</div>
					<Button size="sm" onClick={ () => go( 'settings' ) }>
						{ __( 'Review prices', 'tokenwarden' ) }
					</Button>
				</div>
			) }
		</>
	);
}

function Onboarding() {
	return (
		<Card bodyClass={ null }>
			<Empty
				art={ <GatewayArt /> }
				title={ __( 'Waiting for the first AI call', 'tokenwarden' ) }
				text={ __(
					'Tokenwarden is active. As soon as a plugin or theme calls AI, through the WordPress AI Client or directly with its own API key, its calls appear here with cost, model and any personal data they contained.',
					'tokenwarden'
				) }
			>
				<div className="tokenwarden-steps">
					<div className="tokenwarden-step">
						<div className="tokenwarden-step__n">1</div>
						<div className="tokenwarden-step__title">
							{ __( 'Attribute', 'tokenwarden' ) }
						</div>
						<div className="tokenwarden-step__text">
							{ __(
								'Each call is traced to the plugin or theme that made it.',
								'tokenwarden'
							) }
						</div>
					</div>
					<div className="tokenwarden-step">
						<div className="tokenwarden-step__n">2</div>
						<div className="tokenwarden-step__title">
							{ __( 'Check', 'tokenwarden' ) }
						</div>
						<div className="tokenwarden-step__text">
							{ __(
								'Requests are checked for personal data such as emails and phone numbers, so you see which plugins send it. Turn on redaction per plugin when you want it replaced.',
								'tokenwarden'
							) }
						</div>
					</div>
					<div className="tokenwarden-step">
						<div className="tokenwarden-step__n">3</div>
						<div className="tokenwarden-step__title">
							{ __( 'Control', 'tokenwarden' ) }
						</div>
						<div className="tokenwarden-step__text">
							{ __(
								'Budgets and pauses stop runaway spend before the provider is ever called.',
								'tokenwarden'
							) }
						</div>
					</div>
				</div>
				<div
					className="tokenwarden-row"
					style={ { justifyContent: 'center', marginTop: 16 } }
				>
					<Button
						variant="primary"
						icon="spark"
						onClick={ () => startDemo() }
					>
						{ __( 'Explore with demo data', 'tokenwarden' ) }
					</Button>
					<a className="tokenwarden-btn" href="#/welcome">
						{ __( 'Run the setup guide', 'tokenwarden' ) }
					</a>
				</div>
				<p
					className="tokenwarden-muted"
					style={ { fontSize: 12.5, marginTop: 6 } }
				>
					{ __(
						'Demo data uses fictional plugins in a separate sandbox. Your real data and settings are not touched, and you can switch back at any time.',
						'tokenwarden'
					) }
				</p>
			</Empty>
		</Card>
	);
}

function GatewayArt() {
	return (
		<svg width="168" height="96" viewBox="0 0 168 96" aria-hidden="true">
			<defs>
				<linearGradient
					id="tokenwarden-art"
					gradientUnits="userSpaceOnUse"
					x1="84"
					y1="0"
					x2="164"
					y2="0"
				>
					<stop offset="0" stopColor="var(--tokenwarden-s1)" />
					<stop offset="1" stopColor="var(--tokenwarden-accent)" />
				</linearGradient>
			</defs>
			{ [ 22, 40, 58, 76 ].map( ( y ) => (
				<path
					key={ y }
					d={ `M4 ${ y } C 40 ${ y }, 52 48, 84 48` }
					fill="none"
					stroke="var(--tokenwarden-axis)"
					strokeWidth="1.5"
				/>
			) ) }
			<path
				d="M84 48 H 164"
				stroke="url(#tokenwarden-art)"
				strokeWidth="2.5"
				strokeLinecap="round"
			/>
			<rect
				x="66"
				y="26"
				width="36"
				height="44"
				rx="12"
				fill="var(--tokenwarden-surface)"
				stroke="var(--tokenwarden-accent)"
				strokeWidth="2"
			/>
			<path
				d="M76 62v-10a8 8 0 0 1 16 0v10"
				fill="none"
				stroke="var(--tokenwarden-accent)"
				strokeWidth="2"
				strokeLinecap="round"
			/>
			<circle cx="84" cy="38" r="2.5" fill="var(--tokenwarden-accent)" />
			{ [ 22, 40, 58, 76 ].map( ( cy, i ) => (
				<circle
					key={ cy }
					cx="4"
					cy={ cy }
					r="4"
					fill={ `var(--tokenwarden-s${ i + 1 })` }
					stroke="var(--tokenwarden-surface)"
					strokeWidth="2"
				/>
			) ) }
			<circle
				cx="164"
				cy="48"
				r="4.5"
				fill="var(--tokenwarden-accent)"
				stroke="var(--tokenwarden-surface)"
				strokeWidth="2"
			/>
		</svg>
	);
}
