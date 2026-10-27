/**
 * Series colors.
 *
 * Categorical slots are assigned by each source's first appearance (the `order` list from
 * the API), never by rank, so a source keeps its color across date ranges and filters.
 * Past eight sources the rest share the neutral "other" color.
 */

const SLOTS = 8;

/**
 * Build a color lookup from the stable source order.
 *
 * @param {string[]} order Source ids by first appearance.
 * @return {Function} source id → CSS color.
 */
export function makeColorer( order = [] ) {
	const map = new Map();
	order.forEach( ( id, i ) => {
		if ( i < SLOTS ) {
			map.set( id, `var(--tokenwarden-s${ i + 1 })` );
		}
	} );
	return ( id ) => {
		if ( id === 'other' ) {
			return 'var(--tokenwarden-other)';
		}
		return map.get( id ) || 'var(--tokenwarden-other)';
	};
}

/** Provider colors use the same slots in a fixed, provider-specific order. */
const PROVIDERS = {
	anthropic: 'var(--tokenwarden-s2)',
	openai: 'var(--tokenwarden-s3)',
	google: 'var(--tokenwarden-s1)',
};

/**
 * Color for a provider.
 *
 * @param {string} id Provider id.
 * @return {string} CSS color.
 */
export function providerColor( id ) {
	return PROVIDERS[ id ] || 'var(--tokenwarden-other)';
}

/**
 * Sequential blue step (0–7) for a value in [0, max].
 *
 * @param {number} value Value.
 * @param {number} max   Maximum.
 * @return {string} CSS color.
 */
export function sequential( value, max ) {
	if ( ! value || ! max ) {
		return 'var(--tokenwarden-q0)';
	}
	const step = Math.max( 1, Math.min( 7, Math.ceil( ( value / max ) * 7 ) ) );
	return `var(--tokenwarden-q${ step })`;
}
