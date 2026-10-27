import { __, _n, sprintf } from '@wordpress/i18n';
import { useMemo, useState } from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';
import { useApi, siteNow } from '../lib/hooks';
import {
	money,
	compact,
	duration,
	dateTime,
	relative,
	integer,
} from '../lib/format';
import { makeColorer, providerColor } from '../lib/colors';
import {
	Card,
	SourceAvatar,
	RequestStatus,
	Segmented,
	PageHead,
	Empty,
	ErrorNotice,
	Button,
	Drawer,
	Switch,
	Term,
} from '../components/ui';
import Icon from '../components/Icon';

const PER_PAGE = 25;

export default function Requests() {
	const [ filters, setFilters ] = useState( {
		source: '',
		status: '',
		model: '',
		pii: false,
	} );
	const [ page, setPage ] = useState( 1 );
	const [ open, setOpen ] = useState( null );

	const path = addQueryArgs( 'requests', {
		page,
		per_page: PER_PAGE,
		source: filters.source || undefined,
		status: filters.status || undefined,
		model: filters.model || undefined,
		pii: filters.pii || undefined,
	} );
	const { data, error, loading, reload } = useApi( path );
	const colorOf = useMemo(
		() => makeColorer( data?.facets?.order ),
		[ data?.facets?.order ]
	);

	const update = ( key ) => ( value ) => {
		setFilters( ( f ) => ( { ...f, [ key ]: value } ) );
		setPage( 1 );
	};
	const filtered =
		filters.source || filters.status || filters.model || filters.pii;

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}

	return (
		<div className={ loading ? 'tokenwarden-is-loading' : '' }>
			<PageHead
				help="requests"
				title={ __( 'Requests', 'tokenwarden' ) }
				lede={ __(
					'AI calls made through the WordPress AI Client, and direct calls plugins make to AI providers with their own API key: completed, blocked by a policy, or failed at the provider.',
					'tokenwarden'
				) }
			>
				<Button icon="refresh" onClick={ reload }>
					{ __( 'Refresh', 'tokenwarden' ) }
				</Button>
			</PageHead>

			<div
				className="tokenwarden-row"
				style={ { marginBottom: 16, gap: 10 } }
				role="group"
				aria-label={ __( 'Filters', 'tokenwarden' ) }
			>
				<Segmented
					label={ __( 'Status', 'tokenwarden' ) }
					value={ filters.status }
					onChange={ update( 'status' ) }
					options={ [
						{ value: '', label: __( 'All', 'tokenwarden' ) },
						{
							value: 'ok',
							label: __( 'Completed', 'tokenwarden' ),
						},
						{
							value: 'blocked',
							label: __( 'Blocked', 'tokenwarden' ),
						},
						{
							value: 'error',
							label: __( 'Failed', 'tokenwarden' ),
						},
					] }
				/>
				<select
					className="tokenwarden-select"
					style={ { width: 'auto', minWidth: 180 } }
					value={ filters.source }
					onChange={ ( e ) => update( 'source' )( e.target.value ) }
					aria-label={ __( 'Source', 'tokenwarden' ) }
				>
					<option value="">
						{ __( 'All sources', 'tokenwarden' ) }
					</option>
					{ data?.facets.sources.map( ( s ) => (
						<option key={ s.id } value={ s.id }>
							{ s.label }
						</option>
					) ) }
				</select>
				<select
					className="tokenwarden-select"
					style={ { width: 'auto', minWidth: 180 } }
					value={ filters.model }
					onChange={ ( e ) => update( 'model' )( e.target.value ) }
					aria-label={ __( 'Model', 'tokenwarden' ) }
				>
					<option value="">
						{ __( 'All models', 'tokenwarden' ) }
					</option>
					{ data?.facets.models.map( ( m ) => (
						<option key={ m } value={ m }>
							{ m }
						</option>
					) ) }
				</select>
				<div
					className="tokenwarden-row"
					style={ {
						gap: 8,
						fontSize: 13,
						fontWeight: 540,
						cursor: 'pointer',
					} }
				>
					<Switch
						checked={ filters.pii }
						onChange={ update( 'pii' ) }
						label={ __(
							'Only calls with personal data',
							'tokenwarden'
						) }
					/>
					{ __( 'With personal data', 'tokenwarden' ) }
				</div>
				{ filtered && (
					<Button
						variant="ghost"
						size="sm"
						icon="x"
						onClick={ () => {
							setFilters( {
								source: '',
								status: '',
								model: '',
								pii: false,
							} );
							setPage( 1 );
						} }
					>
						{ __( 'Clear filters', 'tokenwarden' ) }
					</Button>
				) }
			</div>

			<Card bodyClass={ null }>
				{ ! data && (
					<div className="tokenwarden-boot">
						{ __( 'Loading…', 'tokenwarden' ) }
					</div>
				) }
				{ data && data.rows.length === 0 && (
					<Empty
						title={
							filtered
								? __(
										'No requests match these filters',
										'tokenwarden'
								  )
								: __( 'No requests yet', 'tokenwarden' )
						}
						text={
							filtered
								? __(
										'Try a different source, model or status.',
										'tokenwarden'
								  )
								: __(
										'AI calls appear here as soon as a plugin or theme makes one.',
										'tokenwarden'
								  )
						}
					/>
				) }
				{ data && data.rows.length > 0 && (
					<>
						<div className="tokenwarden-table-wrap">
							<table className="tokenwarden-table">
								<thead>
									<tr>
										<th scope="col">
											{ __( 'When', 'tokenwarden' ) }
										</th>
										<th scope="col">
											{ __( 'Source', 'tokenwarden' ) }
										</th>
										<th scope="col">
											{ __( 'Model', 'tokenwarden' ) }
										</th>
										<th scope="col" className="is-num">
											<Term
												label={ __(
													'Tokens in / out',
													'tokenwarden'
												) }
												tip={ __(
													'Tokens sent to the provider and received back. Output includes any “thinking” tokens, which providers bill as output.',
													'tokenwarden'
												) }
												align="right"
											/>
										</th>
										<th scope="col" className="is-num">
											{ __( 'Cost', 'tokenwarden' ) }
										</th>
										<th scope="col" className="is-num">
											{ __( 'Time', 'tokenwarden' ) }
										</th>
										<th scope="col">
											<Term
												label={ __(
													'Gateway',
													'tokenwarden'
												) }
												tip={ __(
													'Personal data in the request. Outlined shield: found and sent unchanged. Filled shield: replaced with placeholders.',
													'tokenwarden'
												) }
												align="right"
											/>
										</th>
										<th scope="col">
											{ __( 'Status', 'tokenwarden' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ data.rows.map( ( r ) => (
										<tr
											key={ r.id }
											className="is-clickable"
											tabIndex={ 0 }
											onClick={ () => setOpen( r ) }
											onKeyDown={ ( e ) =>
												( e.key === 'Enter' ||
													e.key === ' ' ) &&
												( e.preventDefault(),
												setOpen( r ) )
											}
										>
											<td
												style={ {
													whiteSpace: 'nowrap',
												} }
												title={ r.created_at }
											>
												{ relative(
													r.created_at,
													siteNow()
												) }
											</td>
											<td>
												<div className="tokenwarden-source">
													<SourceAvatar
														label={ r.source_label }
														color={ colorOf(
															r.source
														) }
													/>
													<span
														className="tokenwarden-source__name"
														style={ {
															fontWeight: 560,
														} }
													>
														{ r.source_label }
													</span>
												</div>
											</td>
											<td>
												{ r.model ? (
													<span className="tokenwarden-chip">
														{ r.model }
													</span>
												) : (
													<span className="tokenwarden-muted">
														–
													</span>
												) }
											</td>
											<td className="is-num">
												{ r.status === 'ok' ? (
													`${ compact(
														r.input_tokens
													) } / ${ compact(
														r.output_tokens
													) }`
												) : (
													<span className="tokenwarden-muted">
														–
													</span>
												) }
											</td>
											<CostCell row={ r } />
											<td className="is-num">
												{ r.latency_ms ? (
													duration( r.latency_ms )
												) : (
													<span className="tokenwarden-muted">
														–
													</span>
												) }
											</td>
											<td>
												<Flags row={ r } />
											</td>
											<td>
												<RequestStatus
													status={ r.status }
													cached={ r.cached }
												/>
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
						<div className="tokenwarden-pager">
							<span>
								{ sprintf(
									/* translators: 1: first row, 2: last row, 3: total rows. */ __(
										'%1$s–%2$s of %3$s',
										'tokenwarden'
									),
									integer( ( page - 1 ) * PER_PAGE + 1 ),
									integer(
										Math.min( page * PER_PAGE, data.total )
									),
									integer( data.total )
								) }
							</span>
							<div
								className="tokenwarden-row"
								style={ { gap: 6 } }
							>
								<Button
									size="sm"
									disabled={ page <= 1 }
									onClick={ () => setPage( page - 1 ) }
								>
									{ __( 'Previous', 'tokenwarden' ) }
								</Button>
								<Button
									size="sm"
									disabled={ page >= data.pages }
									onClick={ () => setPage( page + 1 ) }
								>
									{ __( 'Next', 'tokenwarden' ) }
								</Button>
							</div>
						</div>
					</>
				) }
			</Card>

			{ open && (
				<RequestDrawer
					row={ open }
					color={ colorOf( open.source ) }
					onClose={ () => setOpen( null ) }
				/>
			) }
		</div>
	);
}

function CostCell( { row } ) {
	if ( row.cached ) {
		return (
			<td className="is-num">
				<strong>{ money( 0 ) }</strong>
				<div className="tokenwarden-muted" style={ { fontSize: 11.5 } }>
					{ sprintf(
						/* translators: %s: amount saved. */
						__( 'saved %s', 'tokenwarden' ),
						money( row.saved )
					) }
				</div>
			</td>
		);
	}
	if ( row.status !== 'ok' ) {
		return (
			<td className="is-num">
				<span className="tokenwarden-muted">–</span>
			</td>
		);
	}
	if ( ! row.priced ) {
		return (
			<td className="is-num">
				<span
					className="tokenwarden-muted"
					title={ __( 'No price for this model', 'tokenwarden' ) }
				>
					{ __( 'n/a', 'tokenwarden' ) }
				</span>
			</td>
		);
	}
	return (
		<td className="is-num">
			<strong>{ money( row.cost ) }</strong>
		</td>
	);
}

function Flags( { row } ) {
	const found = Object.values( row.pii || {} ).reduce( ( a, n ) => a + n, 0 );
	let title = __( 'No personal data found', 'tokenwarden' );
	let label = __( 'No personal data', 'tokenwarden' );
	if ( row.redactions ) {
		title = sprintf(
			/* translators: %d: count. */ _n(
				'%d item of personal data replaced',
				'%d items of personal data replaced',
				row.redactions,
				'tokenwarden'
			),
			row.redactions
		);
		label = __( 'Personal data replaced', 'tokenwarden' );
	} else if ( found ) {
		title = sprintf(
			/* translators: %d: count. */ _n(
				'%d item of personal data found, sent unchanged',
				'%d items of personal data found, sent unchanged',
				found,
				'tokenwarden'
			),
			found
		);
		label = __( 'Personal data sent', 'tokenwarden' );
	}
	let state = '';
	if ( row.redactions ) {
		state = 'is-on';
	} else if ( found ) {
		state = 'is-found';
	}
	return (
		<span className="tokenwarden-flags">
			<span className={ state } title={ title }>
				<Icon name="shield" size={ 13 } />
				<span className="tokenwarden-sr">{ label }</span>
			</span>
		</span>
	);
}

function RequestDrawer( { row, color, onClose } ) {
	return (
		<Drawer
			onClose={ onClose }
			title={
				<div className="tokenwarden-source">
					<SourceAvatar
						label={ row.source_label }
						color={ color }
						large
					/>
					<div>
						<div
							className="tokenwarden-source__name"
							style={ { fontSize: 16 } }
						>
							{ row.source_label }
						</div>

						<div className="tokenwarden-source__id">
							{ sprintf(
								/* translators: %d: request id. */ __(
									'Request #%d',
									'tokenwarden'
								),
								row.id
							) }
						</div>
					</div>
				</div>
			}
		>
			<div className="tokenwarden-row">
				<RequestStatus status={ row.status } cached={ row.cached } />
				{ row.note && (
					<span
						className="tokenwarden-muted"
						style={ { fontSize: 13 } }
					>
						{ row.note }
					</span>
				) }
			</div>
			<dl className="tokenwarden-dl">
				<dt>{ __( 'Time', 'tokenwarden' ) }</dt>
				<dd>{ dateTime( row.created_at ) }</dd>
				<dt>{ __( 'Source', 'tokenwarden' ) }</dt>
				<dd>
					<code>{ row.source }</code>
				</dd>
				<dt>{ __( 'Provider', 'tokenwarden' ) }</dt>
				<dd className="tokenwarden-row" style={ { gap: 6 } }>
					{ row.provider && (
						<span
							style={ {
								width: 8,
								height: 8,
								borderRadius: '50%',
								background: providerColor( row.provider ),
								display: 'inline-block',
							} }
						/>
					) }
					{ row.provider || '–' }
				</dd>
				<dt>{ __( 'Model', 'tokenwarden' ) }</dt>
				<dd>
					{ row.model ? (
						<span className="tokenwarden-chip">{ row.model }</span>
					) : (
						'–'
					) }
				</dd>
				<dt>{ __( 'Capability', 'tokenwarden' ) }</dt>
				<dd>
					{ row.capability
						? row.capability.replace( /_/g, ' ' )
						: '–' }
				</dd>
				<dt>{ __( 'Input tokens', 'tokenwarden' ) }</dt>
				<dd className="tokenwarden-num">
					{ integer( row.input_tokens ) }
				</dd>
				<dt>{ __( 'Output tokens', 'tokenwarden' ) }</dt>
				<dd className="tokenwarden-num">
					{ integer( row.output_tokens ) }
				</dd>
				<dt>{ __( 'Estimated cost', 'tokenwarden' ) }</dt>
				<dd className="tokenwarden-num">
					{ row.priced
						? money( row.cost )
						: __( 'No price for this model', 'tokenwarden' ) }
				</dd>
				{ row.cached && (
					<>
						<dt>{ __( 'Answered from cache', 'tokenwarden' ) }</dt>
						<dd className="tokenwarden-num">
							{ sprintf(
								/* translators: %s: amount saved. */
								__( 'Yes, saved %s', 'tokenwarden' ),
								money( row.saved )
							) }
						</dd>
					</>
				) }
				<dt>{ __( 'Route', 'tokenwarden' ) }</dt>
				<dd>
					{ row.channel === 'direct'
						? __(
								'Direct: the plugin called the provider with its own API key',
								'tokenwarden'
						  )
						: __( 'WordPress AI Client', 'tokenwarden' ) }
				</dd>
				<dt>{ __( 'Response time', 'tokenwarden' ) }</dt>
				<dd>{ row.latency_ms ? duration( row.latency_ms ) : '–' }</dd>
				<dt>{ __( 'Personal data', 'tokenwarden' ) }</dt>
				<dd>
					{ Object.keys( row.pii || {} ).length
						? Object.entries( row.pii )
								.map( ( [ type, n ] ) => `${ type } × ${ n }` )
								.join( ', ' )
						: __( 'None found', 'tokenwarden' ) }
				</dd>
				<dt>{ __( 'Sent to the provider', 'tokenwarden' ) }</dt>
				<dd>
					{ row.redactions
						? sprintf(
								/* translators: %d: count. */ _n(
									'With %d item replaced by a placeholder',
									'With %d items replaced by placeholders',
									row.redactions,
									'tokenwarden'
								),
								row.redactions
						  )
						: __( 'Unchanged', 'tokenwarden' ) }
				</dd>
			</dl>
			{ row.prompt_excerpt || row.response_excerpt ? (
				<>
					<div>
						<h3 className="tokenwarden-section-title">
							{ __(
								'Prompt (personal data masked)',
								'tokenwarden'
							) }
						</h3>
						<div className="tokenwarden-excerpt">
							{ row.prompt_excerpt || '–' }
						</div>
					</div>
					<div>
						<h3 className="tokenwarden-section-title">
							{ __( 'Response', 'tokenwarden' ) }
						</h3>
						<div className="tokenwarden-excerpt">
							{ row.response_excerpt || '–' }
						</div>
					</div>
				</>
			) : (
				<p
					className="tokenwarden-muted"
					style={ { fontSize: 12.5, display: 'flex', gap: 8 } }
				>
					<Icon
						name="eye"
						size={ 15 }
						style={ { flexShrink: 0, marginTop: 1 } }
					/>
					{ __(
						'Prompt and response text are not stored. You can turn on excerpts under Settings → Logging.',
						'tokenwarden'
					) }
				</p>
			) }
		</Drawer>
	);
}
