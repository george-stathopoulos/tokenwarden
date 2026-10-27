import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import {
	Card,
	PageHead,
	Pill,
	Button,
	Switch,
	Setting,
	Segmented,
	Loading,
	ErrorNotice,
	SourceAvatar,
	useToast,
	typeLabel,
} from '../components/ui';
import Icon from '../components/Icon';
import { money, integer } from '../lib/format';
import * as colors from '../lib/colors';
import { SaveBar } from '../lib/settings';
import { send, useApi } from '../lib/hooks';

const TTL = [
	{ value: 1, label: __( '1 hour', 'tokenwarden' ) },
	{ value: 24, label: __( '24 hours', 'tokenwarden' ) },
	{ value: 168, label: __( '7 days', 'tokenwarden' ) },
	{ value: 720, label: __( '30 days', 'tokenwarden' ) },
];

const SIZES = [ 25, 50, 100, 250 ];

export default function Caching() {
	const { data, error, reload } = useApi( 'caching' );
	const [ draft, setDraft ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const toast = useToast();
	const colorOf = useMemo(
		() => colors.makeColorer( data?.order ),
		[ data?.order ]
	);

	useEffect( () => {
		if ( data ) {
			setDraft( data.settings );
		}
	}, [ data ] );

	if ( error && ! data ) {
		return <ErrorNotice error={ error } onRetry={ reload } />;
	}
	if ( ! data || ! draft ) {
		return <Loading />;
	}

	const dirty = JSON.stringify( draft ) !== JSON.stringify( data.settings );
	const set = ( key ) => ( value ) =>
		setDraft( ( d ) => ( { ...d, [ key ]: value } ) );
	const selected = new Set( draft.sources );
	const toggleSource = ( id, on ) =>
		set( 'sources' )(
			on
				? [ ...draft.sources, id ]
				: draft.sources.filter( ( s ) => s !== id )
		);

	const save = () => {
		setSaving( true );
		send( 'caching', 'POST', draft )
			.then( () => {
				toast( __( 'Caching settings saved', 'tokenwarden' ) );
				reload();
			} )
			.catch( ( e ) => toast( e.message, 'alert' ) )
			.finally( () => setSaving( false ) );
	};

	return (
		<div>
			<PageHead
				title={ __( 'Caching', 'tokenwarden' ) }
				lede={ __(
					'When a plugin sends exactly the same AI request again, Tokenwarden answers it instantly from a cache instead of paying the provider again.',
					'tokenwarden'
				) }
			>
				{ draft.enabled && (
					<Pill tone="good" icon="check">
						{ __( 'Caching on', 'tokenwarden' ) }
					</Pill>
				) }
			</PageHead>

			<div>
				<Stats data={ data } />

				<div className="tokenwarden-grid">
					<Card
						className="tokenwarden-span-5"
						title={ __( 'Cache settings', 'tokenwarden' ) }
						bodyClass={ null }
					>
						<div style={ { marginTop: 8 } }>
							<Setting
								title={ __( 'Cache responses', 'tokenwarden' ) }
								desc={ __(
									'Answer repeated requests from the sources you choose.',
									'tokenwarden'
								) }
							>
								<Switch
									checked={ draft.enabled }
									onChange={ set( 'enabled' ) }
									label={ __(
										'Cache responses',
										'tokenwarden'
									) }
								/>
							</Setting>
							<Setting
								title={ __(
									'Keep answers for',
									'tokenwarden'
								) }
								desc={ __(
									'After this, the next identical request goes to the provider again and refreshes the cache.',
									'tokenwarden'
								) }
							/>
							<div style={ { padding: '0 20px 16px' } }>
								<Segmented
									label={ __(
										'Cache lifetime',
										'tokenwarden'
									) }
									options={ TTL }
									value={ draft.ttl_hours }
									onChange={ set( 'ttl_hours' ) }
								/>
							</div>
							<Setting
								title={ __( 'Which sources', 'tokenwarden' ) }
								desc={ __(
									'“Selected” is safest: some plugins expect a fresh answer every time.',
									'tokenwarden'
								) }
							/>
							<div style={ { padding: '0 20px 16px' } }>
								<Segmented
									label={ __(
										'Sources to cache',
										'tokenwarden'
									) }
									options={ [
										{
											value: 'selected',
											label: __(
												'Selected sources',
												'tokenwarden'
											),
										},
										{
											value: 'all',
											label: __(
												'All sources',
												'tokenwarden'
											),
										},
									] }
									value={ draft.mode }
									onChange={ set( 'mode' ) }
								/>
							</div>
							<Setting
								title={ __(
									'Maximum cache size',
									'tokenwarden'
								) }
								desc={ __(
									'The least recently used answers are removed first.',
									'tokenwarden'
								) }
							>
								<select
									className="tokenwarden-select"
									style={ { width: 110 } }
									value={ draft.max_mb }
									aria-label={ __(
										'Maximum cache size',
										'tokenwarden'
									) }
									onChange={ ( e ) =>
										set( 'max_mb' )(
											Number( e.target.value )
										)
									}
								>
									{ SIZES.map( ( mb ) => (
										<option key={ mb } value={ mb }>
											{ `${ mb } MB` }
										</option>
									) ) }
								</select>
							</Setting>
						</div>
					</Card>

					<Card
						className="tokenwarden-span-7"
						title={ __( 'Sources', 'tokenwarden' ) }
						sub={ __(
							'Sorted by what caching could save, based on repeated requests in the last 30 days.',
							'tokenwarden'
						) }
						bodyClass={ null }
					>
						<Sources
							sources={ data.sources }
							selected={ selected }
							all={ draft.mode === 'all' }
							onToggle={ toggleSource }
							colorOf={ colorOf }
						/>
						<p
							className="tokenwarden-muted tokenwarden-row"
							style={ {
								fontSize: 12.5,
								padding: '12px 20px 16px',
								gap: 8,
								flexWrap: 'nowrap',
								alignItems: 'flex-start',
							} }
						>
							<Icon
								name="info"
								size={ 15 }
								style={ { flexShrink: 0, marginTop: 1 } }
							/>
							{ __(
								'Don’t cache plugins that should give a different answer each time, such as content generators with a “regenerate” button. Good candidates are spam checks, classification, alt text and FAQ-style answers.',
								'tokenwarden'
							) }
						</p>
					</Card>
				</div>

				<Maintenance data={ data } onChange={ reload } />
			</div>

			<SaveBar
				dirty={ dirty }
				saving={ saving }
				save={ save }
				discard={ () => setDraft( data.settings ) }
			/>
		</div>
	);
}

function Stats( { data } ) {
	const { cache, repeats } = data;
	const tiles = [
		{
			icon: 'coins',
			label: __( 'Saved, last 30 days', 'tokenwarden' ),
			value: money( cache.saved ),
			foot:
				repeats.potential > 0
					? sprintf(
							/* translators: %s: amount. */
							__(
								'%s more possible from uncached repeats',
								'tokenwarden'
							),
							money( repeats.potential )
					  )
					: __( 'No uncached repeats', 'tokenwarden' ),
		},
		{
			icon: 'bolt',
			label: __( 'Answered from cache', 'tokenwarden' ),
			value: integer( cache.cached ),
			foot: sprintf(
				/* translators: %s: percent. */
				__( '%s%% of completed calls', 'tokenwarden' ),
				Math.round( cache.hit_rate * 1000 ) / 10
			),
		},
		{
			icon: 'tokens',
			label: __( 'Cached answers', 'tokenwarden' ),
			value: integer( cache.entries ),
			foot: sprintf(
				/* translators: %s: size in MB. */
				__( '%s MB in use', 'tokenwarden' ),
				( cache.bytes / 1048576 ).toFixed( 1 )
			),
		},
	];
	return (
		<div className="tokenwarden-stats-strip">
			{ tiles.map( ( t ) => (
				<Card key={ t.label } bodyClass={ null }>
					<div className="tokenwarden-stat">
						<div className="tokenwarden-stat__label">
							<Icon name={ t.icon } size={ 14 } />
							{ t.label }
						</div>
						<div className="tokenwarden-stat__value">
							{ t.value }
						</div>
						<div
							className="tokenwarden-muted"
							style={ { fontSize: 12.5 } }
						>
							{ t.foot }
						</div>
					</div>
				</Card>
			) ) }
		</div>
	);
}

function Sources( { sources, selected, all, onToggle, colorOf } ) {
	if ( ! sources.length ) {
		return (
			<div className="tokenwarden-card__body tokenwarden-muted">
				{ __(
					'Sources appear here after their first AI call.',
					'tokenwarden'
				) }
			</div>
		);
	}
	const best = sources[ 0 ]?.potential > 0 ? sources[ 0 ].id : null;
	return (
		<div style={ { marginTop: 8 } }>
			{ sources.map( ( s ) => (
				<Setting
					key={ s.id }
					title={
						<span className="tokenwarden-source">
							<SourceAvatar
								label={ s.label }
								color={ colorOf( s.id ) }
							/>
							<span>{ s.label }</span>
						</span>
					}
					badge={
						s.id === best && ! selected.has( s.id ) && ! all ? (
							<Pill tone="accent" icon="spark">
								{ __( 'Recommended', 'tokenwarden' ) }
							</Pill>
						) : (
							<span
								className="tokenwarden-muted"
								style={ { fontWeight: 450, fontSize: 12.5 } }
							>
								{ typeLabel( s.type ) }
							</span>
						)
					}
					desc={
						s.repeats > 0
							? sprintf(
									/* translators: 1: number of repeated calls, 2: percent, 3: amount. */
									_n(
										'%1$s repeated call (%2$s%%) · could save %3$s',
										'%1$s repeated calls (%2$s%%) · could save %3$s',
										s.repeats,
										'tokenwarden'
									),
									integer( s.repeats ),
									Math.round( s.share * 100 ),
									money( s.potential )
							  )
							: __(
									'No repeated requests in the last 30 days',
									'tokenwarden'
							  )
					}
				>
					<Switch
						checked={ all || selected.has( s.id ) }
						disabled={ all }
						onChange={ ( on ) => onToggle( s.id, on ) }
						label={ sprintf(
							/* translators: %s: source name. */
							__( 'Cache responses for %s', 'tokenwarden' ),
							s.label
						) }
					/>
				</Setting>
			) ) }
		</div>
	);
}

function Maintenance( { data, onChange } ) {
	const [ armed, setArmed ] = useState( false );
	const toast = useToast();
	const clear = () => {
		if ( ! armed ) {
			setArmed( true );
			window.setTimeout( () => setArmed( false ), 4000 );
			return;
		}
		send( 'caching', 'DELETE' )
			.then( () => {
				toast( __( 'Cache cleared', 'tokenwarden' ) );
				onChange();
			} )
			.catch( ( e ) => toast( e.message, 'alert' ) )
			.finally( () => setArmed( false ) );
	};
	return (
		<Card bodyClass={ null } style={ { marginBottom: 18 } }>
			<Setting
				title={ __( 'Clear cache', 'tokenwarden' ) }
				desc={ sprintf(
					/* translators: %s: number of entries. */
					__(
						'Deletes all %s cached answers. Use it after changing a plugin’s settings or content that its answers depend on. Cached answers never contain redacted personal data.',
						'tokenwarden'
					),
					integer( data.cache.entries )
				) }
			>
				<Button variant="danger" icon="trash" onClick={ clear }>
					{ armed
						? __( 'Click again to confirm', 'tokenwarden' )
						: __( 'Clear cache', 'tokenwarden' ) }
				</Button>
			</Setting>
		</Card>
	);
}
