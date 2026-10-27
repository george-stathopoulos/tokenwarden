<?php
/**
 * Personal-data redaction with reversible placeholders.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Replaces personal data with placeholders such as `[EMAIL_1]` and can put the originals back.
 *
 * One instance covers one provider request, so the same value always gets the same placeholder
 * within that request and the map can be used to restore the response.
 */
final class Tokenwarden_Redactor {

	/**
	 * Placeholder => original value.
	 *
	 * @var array<string,string>
	 */
	private $map = array();

	/**
	 * Original value => placeholder.
	 *
	 * @var array<string,string>
	 */
	private $reverse = array();

	/**
	 * Counts per type.
	 *
	 * @var array<string,int>
	 */
	private $counts = array();

	/**
	 * Enabled detectors.
	 *
	 * @var array
	 */
	private $config;

	/**
	 * JSON keys whose string values are never prose and must not be touched.
	 *
	 * @var string[]
	 */
	const SKIP_KEYS = array( 'model', 'role', 'type', 'id', 'call_id', 'tool_call_id', 'tool_use_id', 'name', 'mime_type', 'mimeType', 'media_type', 'data', 'url', 'image_url', 'file_id', 'file_data', 'fileUri', 'format', 'voice', 'response_format', 'effort', 'stop_reason', 'signature' );

	/**
	 * Detector keys available in settings.
	 *
	 * @return string[]
	 */
	public static function detector_keys() {
		return array( 'email', 'phone', 'card', 'iban', 'ssn', 'ip' );
	}

	/**
	 * Constructor.
	 *
	 * @param array|null $config Redaction settings (defaults to saved settings).
	 */
	public function __construct( $config = null ) {
		$this->config = null === $config ? Tokenwarden_Settings::get( 'redaction' ) : $config;
	}

	/**
	 * Detector patterns keyed by placeholder type.
	 *
	 * Order matters: specific formats run before the generic phone pattern.
	 *
	 * @return array<string,string>
	 */
	private function patterns() {
		$all = array(
			'email' => array( 'EMAIL', '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' ),
			// Candidates only: IBANs must pass the mod-97 checksum, cards a known prefix and Luhn.
			'iban'  => array( 'IBAN', '/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){2,7}(?:[ ]?[A-Z0-9]{1,3})?\b/' ),
			'card'  => array( 'CARD', '/\b(?:\d[ \-]?){12,18}\d\b/' ),
			'ssn'   => array( 'SSN', '/\b\d{3}-\d{2}-\d{4}\b/' ),
			'ip'    => array( 'IP', '/\b(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)\b/' ),
			// Grouped numbers such as "+1 415 555 0134", "(020) 7946 0958" or "Call 415-555-0134".
			// Without "+" or "(" a number also needs a word such as "phone" or "call" just before it.
			'phone' => array( 'PHONE', '/(?<![\w@.\-])(?:\+\d{1,3}[ \-.]?)?(?:\(\d{1,4}\)[ \-.]?)?\d{2,5}(?:[ \-.]\d{2,5}){1,4}(?![\w.\-]?\d)/' ),
		);
		$out = array();
		foreach ( $all as $key => $pattern ) {
			if ( ! empty( $this->config[ $key ] ) ) {
				$out[ $pattern[0] ] = $pattern[1];
			}
		}
		return $out;
	}

	/**
	 * Redact a single string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function redact( $text ) {
		if ( ! is_string( $text ) || '' === $text ) {
			return $text;
		}

		foreach ( (array) ( $this->config['custom'] ?? array() ) as $term ) {
			if ( '' !== $term && false !== stripos( $text, $term ) ) {
				// Whole words only: the term "Ann" must not match "annual" or "planned".
				$replaced = preg_replace_callback(
					'/(?<![\p{L}\p{N}])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/iu',
					function ( $m ) {
						return $this->placeholder( 'TERM', $m[0] );
					},
					$text
				);
				// null means the text isn't valid UTF-8; leave it as it is rather than losing it.
				if ( null !== $replaced ) {
					$text = $replaced;
				}
			}
		}

		foreach ( $this->patterns() as $type => $regex ) {
			$source = $text;
			$text   = preg_replace_callback(
				$regex,
				function ( $m ) use ( $type, $source ) {
					$value = $m[0][0];
					// Leave placeholders created by an earlier detector alone.
					if ( isset( $this->map[ $value ] ) ) {
						return $value;
					}
					if ( 'CARD' === $type && ! self::looks_like_card( $value ) ) {
						return $value;
					}
					if ( 'IBAN' === $type && ! self::valid_iban( $value ) ) {
						return $value;
					}
					if ( 'PHONE' === $type && ! self::looks_like_phone( $value, substr( $source, max( 0, $m[0][1] - 40 ), min( 40, $m[0][1] ) ) ) ) {
						return $value;
					}
					return $this->placeholder( $type, $value );
				},
				$text,
				-1,
				$count,
				PREG_OFFSET_CAPTURE
			);
		}

		return $text;
	}

	/**
	 * Redact every prose string inside a decoded JSON request body.
	 *
	 * @param mixed  $node Decoded JSON node.
	 * @param string $key  Key of this node in its parent.
	 * @return mixed
	 */
	public function redact_tree( $node, $key = '' ) {
		if ( is_string( $node ) ) {
			return in_array( $key, self::SKIP_KEYS, true ) ? $node : $this->redact( $node );
		}
		if ( is_array( $node ) ) {
			// Inline binary payloads (images, audio) are skipped as a whole.
			if ( isset( $node['type'] ) && in_array( $node['type'], array( 'image', 'input_image', 'document', 'input_file', 'input_audio' ), true ) ) {
				return $node;
			}
			foreach ( $node as $k => $child ) {
				if ( in_array( $k, array( 'inlineData', 'inline_data', 'fileData', 'tools', 'functionDeclarations', 'text_format', 'json_schema', 'response_schema', 'responseSchema' ), true ) ) {
					continue;
				}
				$node[ $k ] = $this->redact_tree( $child, is_string( $k ) ? $k : $key );
			}
		}
		return $node;
	}

	/**
	 * Put original values back into a response body.
	 *
	 * Placeholders are restored both as plain text and in their JSON-escaped form.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	public function restore( $body ) {
		if ( ! $this->map || ! is_string( $body ) ) {
			return $body;
		}
		$pairs = array();
		foreach ( $this->map as $token => $original ) {
			$pairs[ $token ] = substr( wp_json_encode( $original ), 1, -1 );
		}
		$body = strtr( $body, $pairs );

		// Models sometimes rewrite a placeholder slightly ("[EMAIL 1]", "[email_1]", "EMAIL_1").
		// Put those back too, so a placeholder never ends up in published content.
		return (string) preg_replace_callback(
			'/\[\s*(EMAIL|PHONE|CARD|IBAN|SSN|IP|TERM)[ _\-]?(\d+)\s*\]|\b(EMAIL|PHONE|CARD|IBAN|SSN|IP|TERM)_(\d+)\b/i',
			function ( $m ) use ( $pairs ) {
				$type  = strtoupper( '' !== $m[1] ? $m[1] : $m[3] );
				$token = '[' . $type . '_' . ( '' !== $m[2] ? $m[2] : $m[4] ) . ']';
				return isset( $pairs[ $token ] ) ? $pairs[ $token ] : $m[0];
			},
			$body
		);
	}

	/**
	 * Placeholders that survived restoring (the model changed them beyond recognition).
	 *
	 * @param string $text Restored text.
	 * @return int
	 */
	public static function leftover( $text ) {
		return (int) preg_match_all( '/\[(?:EMAIL|PHONE|CARD|IBAN|SSN|IP|TERM)_\d+\]/', (string) $text );
	}

	/**
	 * Detected values per type as a compact string for the log, e.g. "email:2,phone:1".
	 *
	 * @param array<string,int> $counts Counts keyed by placeholder type.
	 * @return string
	 */
	public static function encode_counts( array $counts ) {
		$parts = array();
		foreach ( $counts as $type => $n ) {
			if ( $n > 0 ) {
				$parts[] = strtolower( $type ) . ':' . (int) $n;
			}
		}
		return implode( ',', $parts );
	}

	/**
	 * Inverse of encode_counts().
	 *
	 * @param string $encoded Encoded counts.
	 * @return array<string,int>
	 */
	public static function decode_counts( $encoded ) {
		$out = array();
		foreach ( array_filter( explode( ',', (string) $encoded ) ) as $part ) {
			$bits = explode( ':', $part );
			if ( 2 === count( $bits ) && '' !== $bits[0] ) {
				$out[ $bits[0] ] = ( $out[ $bits[0] ] ?? 0 ) + (int) $bits[1];
			}
		}
		return $out;
	}

	/**
	 * Total number of distinct values redacted.
	 *
	 * @return int
	 */
	public function count() {
		return count( $this->map );
	}

	/**
	 * Distinct values redacted per type.
	 *
	 * @return array<string,int>
	 */
	public function counts() {
		return $this->counts;
	}

	/**
	 * Placeholder for a value, reusing it when the value was seen before.
	 *
	 * @param string $type  Placeholder type.
	 * @param string $value Original value.
	 * @return string
	 */
	private function placeholder( $type, $value ) {
		if ( isset( $this->reverse[ $value ] ) ) {
			return $this->reverse[ $value ];
		}
		$this->counts[ $type ]   = ( $this->counts[ $type ] ?? 0 ) + 1;
		$token                   = '[' . $type . '_' . $this->counts[ $type ] . ']';
		$this->map[ $token ]     = $value;
		$this->reverse[ $value ] = $token;
		return $token;
	}

	/**
	 * Filters out dates, versions and decimals that the grouped-number pattern also matches.
	 *
	 * @param string $match Candidate.
	 * @return bool
	 */
	private static function looks_like_phone( $match, $before = '' ) {
		$digits = strlen( preg_replace( '/\D/', '', $match ) );
		if ( $digits < 8 || $digits > 15 ) {
			return false;
		}
		$international = false !== strpos( $match, '+' ) || false !== strpos( $match, '(' );
		// Thousands such as "12 500 000" or "1,250,000" are amounts.
		if ( ! $international && preg_match( '/^\d{1,3}(?:[ .,]\d{3})+$/', $match ) ) {
			return false;
		}
		// Without "+" or "(", only a nearby word makes a grouped number a phone number; otherwise it is
		// far more often an order, SKU or list of IDs.
		if ( ! $international && ! preg_match( '/(?:phone|tel\b|tel\.|telephone|call|mobile|cell|fax|whats\s?app|sms|text me|contact|τηλ|κινητ)[^\d\n]{0,15}$/iu', (string) $before ) ) {
			return false;
		}
		// Dates: 2026-10-01, 01.10.2026, 2026 10 01.
		if ( preg_match( '/^\d{4}[\-. ]\d{1,2}[\-. ]\d{1,2}$|^\d{1,2}[\-. ]\d{1,2}[\-. ]\d{4}$/', $match ) ) {
			return false;
		}
		// Dotted numbers without a leading + are far more often versions or amounts.
		if ( false === strpos( $match, '+' ) && preg_match( '/^[\d.]+$/', $match ) ) {
			return false;
		}
		return true;
	}

	/**
	 * A card number: 13 to 19 digits, a known card prefix and a valid Luhn checksum.
	 *
	 * @param string $match Candidate.
	 * @return bool
	 */
	private static function looks_like_card( $match ) {
		$digits = preg_replace( '/\D/', '', $match );
		$len    = strlen( $digits );
		if ( $len < 13 || $len > 19 ) {
			return false;
		}
		// Visa, Mastercard, American Express, Discover, Diners Club, JCB, UnionPay, Maestro.
		if ( ! preg_match( '/^(?:4|5[1-5]|2[2-7]|3[47]|6(?:011|5|4[4-9]|2)|3(?:0[0-5]|[689])|35|5[06-9])/', $digits ) ) {
			return false;
		}
		return self::luhn( $digits );
	}

	/**
	 * IBAN with a valid ISO 13616 mod-97 checksum.
	 *
	 * @param string $match Candidate.
	 * @return bool
	 */
	private static function valid_iban( $match ) {
		$iban = strtoupper( preg_replace( '/\s+/', '', $match ) );
		if ( strlen( $iban ) < 15 || strlen( $iban ) > 34 || ! preg_match( '/^[A-Z]{2}\d{2}[A-Z0-9]+$/', $iban ) ) {
			return false;
		}
		$moved     = substr( $iban, 4 ) . substr( $iban, 0, 4 );
		$remainder = 0;
		foreach ( str_split( $moved ) as $char ) {
			$chunk     = ctype_alpha( $char ) ? (string) ( ord( $char ) - 55 ) : $char;
			$remainder = (int) ( ( $remainder . $chunk ) % 97 );
		}
		return 1 === $remainder;
	}

	/**
	 * Luhn checksum, used to avoid flagging order numbers as card numbers.
	 *
	 * @param string $number Candidate number.
	 * @return bool
	 */
	private static function luhn( $number ) {
		$digits = preg_replace( '/\D/', '', $number );
		$len    = strlen( $digits );
		if ( $len < 13 || $len > 19 ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$d = (int) $digits[ $len - 1 - $i ];
			if ( $i % 2 ) {
				$d *= 2;
				if ( $d > 9 ) {
					$d -= 9;
				}
			}
			$sum += $d;
		}
		return 0 === $sum % 10;
	}
}
