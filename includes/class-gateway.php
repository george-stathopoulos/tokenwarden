<?php
/**
 * The gateway: attribution, policy enforcement, request rewriting and logging.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks into the WordPress AI Client and the HTTP API.
 *
 * Flow for one `generate_*()` call:
 * 1. `wp_ai_client_prevent_prompt`  - attribute the caller, block it when paused or over budget.
 * 2. `wp_ai_client_before_generate_result` - start the timer.
 * 3. `http_request_args`            - detect personal data; replace it only for sources set to redact.
 * 4. `http_response`                - restore redacted values in the answer.
 * 5. `wp_ai_client_after_generate_result`  - record model, tokens and cost.
 * Failed provider calls are recorded from `http_api_debug`.
 *
 * Direct calls: plugins that call an AI provider themselves through the WordPress HTTP API, with
 * their own API key, skip the AI Client. Tokenwarden sees those at the HTTP level instead:
 * `http_request_args` attributes the call and checks it for personal data, `pre_http_request`
 * blocks it when the source is paused or over budget, and `http_response` records model, tokens
 * and cost from the provider's answer. Calls made with other HTTP libraries (or from the browser)
 * are not visible to any WordPress plugin.
 */
final class Tokenwarden_Gateway {

	/**
	 * State of the call in flight.
	 *
	 * @var array
	 */
	private static $call = array();

	/**
	 * Redactor for the provider request in flight.
	 *
	 * @var Tokenwarden_Redactor|null
	 */
	private static $redactor = null;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_filter( 'wp_ai_client_prevent_prompt', array( __CLASS__, 'enforce' ), 20, 2 );
		add_action( 'wp_ai_client_before_generate_result', array( __CLASS__, 'before_generate' ) );
		add_action( 'wp_ai_client_after_generate_result', array( __CLASS__, 'after_generate' ) );
		add_filter( 'http_request_args', array( __CLASS__, 'rewrite_request' ), 20, 2 );
		add_filter( 'http_response', array( __CLASS__, 'restore_response' ), 5, 3 );
		add_filter( 'pre_http_request', array( __CLASS__, 'enforce_direct' ), 7, 3 );
		add_filter( 'http_response', array( __CLASS__, 'record_direct' ), 6, 3 );
		add_action( 'http_api_debug', array( __CLASS__, 'record_failure' ), 10, 5 );
		add_filter( 'pre_http_request', array( __CLASS__, 'record_approval_block' ), 6, 3 );
	}

	/**
	 * Attribute the call and block it when policy says so.
	 *
	 * @param bool   $prevent Whether another filter already prevented the prompt.
	 * @param object $builder Clone of the prompt builder.
	 * @return bool
	 */
	public static function enforce( $prevent, $builder ) {
		$method = self::builder_method();
		$source = Tokenwarden_Attribution::detect();

		self::$call = array(
			'source'  => $source,
			'method'  => $method,
			'started' => microtime( true ),
			'prompt'  => '',
		);

		$reason = self::block_reason( $source );
		if ( null === $reason ) {
			return $prevent;
		}

		// is_supported_*() checks are blocked silently so calling plugins can hide their AI UI;
		// only real generation attempts are recorded.
		if ( $method && 0 !== strpos( $method, 'is_supported' ) ) {
			Tokenwarden_Ledger::insert(
				array(
					'source' => $source,
					'status' => 'blocked',
					'note'   => $reason,
				)
			);
		}
		return true;
	}

	/**
	 * State of the call in flight (source, prompt hash, redactions…), for the response cache and other hooks.
	 *
	 * @return array
	 */
	public static function current_call() {
		return self::$call;
	}

	/**
	 * Why a source may not call AI right now, or null when it may.
	 *
	 * @param string $source Source id.
	 * @return string|null
	 */
	public static function block_reason( $source ) {
		$policy = Tokenwarden_Settings::source( $source );
		if ( $policy['paused'] ) {
			return __( 'Source is paused', 'tokenwarden' );
		}
		$limit = Tokenwarden_Settings::rate_limit_for( $source );
		if ( $limit > 0 && Tokenwarden_Ledger::calls_last_hour( $source ) >= $limit ) {
			Tokenwarden_Alerts::rate_limited( $source, $limit );
			/* translators: %d: number of calls per hour. */
			return sprintf( __( 'Hourly limit reached (%d calls per hour)', 'tokenwarden' ), $limit );
		}
		if ( $policy['budget'] > 0 && Tokenwarden_Ledger::month_spend( $source ) >= $policy['budget'] ) {
			return __( 'Monthly budget reached', 'tokenwarden' );
		}
		$global = (float) Tokenwarden_Settings::get( 'global_budget' );
		if ( $global > 0 && Tokenwarden_Ledger::month_spend( '__total' ) >= $global ) {
			return __( 'Site-wide monthly budget reached', 'tokenwarden' );
		}
		return null;
	}

	/**
	 * Start timing a generation.
	 */
	public static function before_generate() {
		if ( ! self::$call ) {
			self::$call = array(
				'source'  => Tokenwarden_Attribution::detect(),
				'method'  => '',
				'prompt'  => '',
			);
		}
		self::$call['started']    = microtime( true );
		self::$call['generating'] = true;
	}

	/**
	 * Record a successful generation.
	 *
	 * @param object $event AfterGenerateResultEvent.
	 */
	public static function after_generate( $event ) {
		$call = self::$call ? self::$call : array( 'source' => Tokenwarden_Attribution::detect() );

		try {
			$model      = $event->getModel();
			$result     = $event->getResult();
			$usage      = $result->getTokenUsage();
			$model_id   = $model->metadata()->getId();
			$provider   = $model->providerMetadata()->getId();
			$capability = $event->getCapability() ? $event->getCapability()->value : '';
			$input      = (int) $usage->getPromptTokens();
			$output     = (int) $usage->getCompletionTokens() + (int) $usage->getThoughtTokens();
		} catch ( \Throwable $e ) {
			self::reset();
			return;
		}

		// Local models (WebLLM runs in the browser) cost nothing.
		$cost    = Tokenwarden_Pricing::is_local( $provider ) ? 0.0 : Tokenwarden_Pricing::cost( $model_id, $input, $output );
		$logging = Tokenwarden_Settings::get( 'logging' );
		$excerpt = array();
		if ( ! empty( $logging['store_excerpts'] ) ) {
			$excerpt = array(
				'prompt_excerpt'   => mb_substr( (string) ( $call['prompt'] ?? '' ), 0, 1000 ),
				'response_excerpt' => mb_substr( self::result_text( $result ), 0, 1000 ),
			);
		}

		Tokenwarden_Ledger::insert(
			array_merge(
				array(
					'source'        => $call['source'],
					'provider'      => $provider,
					'model'         => $model_id,
					'capability'    => $capability,
					'status'        => 'ok',
					'input_tokens'  => $input,
					'output_tokens' => $output,
					'cost'          => null === $cost ? 0 : $cost,
					'priced'        => null === $cost ? 0 : 1,
					'latency_ms'    => isset( $call['started'] ) ? (int) round( ( microtime( true ) - $call['started'] ) * 1000 ) : 0,
					'redactions'    => (int) ( $call['redactions'] ?? 0 ),
					'pii'           => (string) ( $call['pii'] ?? '' ),
					'channel'       => 'ai_client',
					'prompt_hash'   => (string) ( $call['prompt_hash'] ?? '' ),
				),
				$excerpt
			)
		);

		self::reset();
	}

	/**
	 * Detect personal data in an outgoing provider request, and replace it when the source's policy
	 * says so.
	 *
	 * Detection never changes the request. Replacing values can change what a plugin gets back (an AI
	 * can't check an email address it can't see), so it is off until the site owner turns it on for
	 * a source.
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public static function rewrite_request( $args, $url ) {
		$method = isset( $args['method'] ) ? $args['method'] : 'GET';
		if ( empty( $args['body'] ) || ! is_string( $args['body'] ) || null === Tokenwarden_Provider_Adapters::provider_for( $url, $method ) ) {
			return $args;
		}
		$body = json_decode( $args['body'], true );
		if ( ! is_array( $body ) ) {
			return $args;
		}

		// No AI Client generation in flight: a plugin is calling the provider directly.
		if ( empty( self::$call['generating'] ) && null !== Tokenwarden_Provider_Adapters::text_format( $url ) ) {
			self::$call = array(
				'source'  => Tokenwarden_Attribution::detect(),
				'method'  => '',
				'prompt'  => '',
				'started' => microtime( true ),
				'direct'  => $url,
				'stream'  => ! empty( $body['stream'] ) || false !== stripos( (string) $url, 'streamGenerateContent' ),
			);
		}

		$source    = self::$call['source'] ?? Tokenwarden_Attribution::detect();
		$policy    = Tokenwarden_Settings::source( $source );
		$redaction = Tokenwarden_Settings::get( 'redaction' );

		self::$redactor = null;
		$found          = array();
		$changed        = false;
		$masked         = $body;
		if ( ! empty( $redaction['enabled'] ) ) {
			$redactor = new Tokenwarden_Redactor( $redaction );
			$redacted = $redactor->redact_tree( $body );
			$found    = $redactor->counts();
			$masked   = $redacted;
			if ( $policy['redact'] && $redactor->count() > 0 ) {
				$body           = $redacted;
				$changed        = true;
				self::$redactor = $redactor;
			}
		}

		self::$call['source']     = $source;
		self::$call['redactions'] = self::$redactor ? self::$redactor->count() : 0;
		self::$call['pii']        = Tokenwarden_Redactor::encode_counts( $found );
		// Optional excerpts always use the masked text, even when the request itself was sent unchanged.
		self::$call['prompt']     = self::prompt_text( $masked );

		// Only re-encode when something changed, so detection alone sends the request byte for byte.
		if ( $changed ) {
			$args['body'] = wp_json_encode( $body );
		}

		// One-way fingerprint of the request exactly as sent. Identical fingerprints mean a repeated
		// prompt; the prompt itself is not stored. It must be the body as sent (not the masked copy):
		// two requests that differ only in an email address are different requests.
		self::$call['prompt_hash'] = sha1( (string) wp_parse_url( $url, PHP_URL_HOST ) . (string) wp_parse_url( $url, PHP_URL_PATH ) . '|' . $args['body'] );

		return $args;
	}

	/**
	 * Put redacted values back into the provider's answer.
	 *
	 * @param array|WP_Error $response Response.
	 * @param array          $args     Request arguments.
	 * @param string         $url      Request URL.
	 * @return array|WP_Error
	 */
	public static function restore_response( $response, $args, $url ) {
		if ( self::$redactor && is_array( $response ) && isset( $response['body'] ) && null !== Tokenwarden_Provider_Adapters::provider_for( $url, $args['method'] ?? 'GET' ) ) {
			$response['body'] = self::$redactor->restore( $response['body'] );
			if ( isset( $response['http_response'] ) && $response['http_response'] instanceof WP_HTTP_Requests_Response ) {
				$response['http_response']->set_data( $response['body'] );
			}
		}
		return $response;
	}

	/**
	 * Record provider errors and network failures.
	 *
	 * @param array|WP_Error $response Response.
	 * @param string         $context  Context, `response`.
	 * @param string         $class    Transport class.
	 * @param array          $args     Request arguments.
	 * @param string         $url      Request URL.
	 */
	public static function record_failure( $response, $context, $class, $args, $url ) {
		$provider = Tokenwarden_Provider_Adapters::provider_for( $url, $args['method'] ?? 'GET' );
		if ( 'response' !== $context || null === $provider ) {
			return;
		}

		if ( is_wp_error( $response ) ) {
			$note = $response->get_error_message();
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 400 ) {
				return;
			}
			$note = self::error_message( wp_remote_retrieve_body( $response ), $code );
		}

		$call = self::$call;
		Tokenwarden_Ledger::insert(
			array(
				'source'     => $call['source'] ?? Tokenwarden_Attribution::detect(),
				'provider'   => $provider,
				'model'      => self::request_model( $args, $url ),
				'status'     => 'error',
				'latency_ms' => isset( $call['started'] ) ? (int) round( ( microtime( true ) - $call['started'] ) * 1000 ) : 0,
				'redactions' => (int) ( $call['redactions'] ?? 0 ),
				'pii'        => (string) ( $call['pii'] ?? '' ),
				'channel'    => ! empty( $call['direct'] ) ? 'direct' : 'ai_client',
				'note'       => $note,
			)
		);
		self::reset();
	}

	/**
	 * Block a direct provider call when its source is paused or over budget.
	 *
	 * The plugin gets a WP_Error back, as it would for any failed request, so nothing is sent or
	 * charged.
	 *
	 * @param false|array|WP_Error $pre  Short-circuit value.
	 * @param array                $args Request arguments.
	 * @param string               $url  URL.
	 * @return false|array|WP_Error
	 */
	public static function enforce_direct( $pre, $args, $url ) {
		if ( false !== $pre || empty( self::$call['direct'] ) || self::$call['direct'] !== $url ) {
			return $pre;
		}
		$reason = self::block_reason( self::$call['source'] );
		if ( null === $reason ) {
			return $pre;
		}
		$hosts = Tokenwarden_Provider_Adapters::hosts();
		Tokenwarden_Ledger::insert(
			array(
				'source'   => self::$call['source'],
				'provider' => $hosts[ (string) wp_parse_url( $url, PHP_URL_HOST ) ] ?? '',
				'model'    => self::request_model( $args, $url ),
				'status'   => 'blocked',
				'channel'  => 'direct',
				'note'     => $reason,
			)
		);
		self::reset();
		/* translators: %s: reason, such as "Monthly budget reached". */
		return new WP_Error( 'tokenwarden_blocked', sprintf( __( 'Blocked by Tokenwarden: %s', 'tokenwarden' ), $reason ) );
	}

	/**
	 * Record a successful direct provider call: model, tokens and estimated cost.
	 *
	 * Failed calls are recorded by record_failure(), which runs first.
	 *
	 * @param array|WP_Error $response Response.
	 * @param array          $args     Request arguments.
	 * @param string         $url      Request URL.
	 * @return array|WP_Error Unchanged.
	 */
	public static function record_direct( $response, $args, $url ) {
		if ( empty( self::$call['direct'] ) || self::$call['direct'] !== $url || ! is_array( $response ) ) {
			return $response;
		}
		$call     = self::$call;
		$hosts    = Tokenwarden_Provider_Adapters::hosts();
		$provider = $hosts[ (string) wp_parse_url( $url, PHP_URL_HOST ) ] ?? '';
		$usage    = Tokenwarden_Provider_Adapters::usage_from_body( wp_remote_retrieve_body( $response ) );
		$model    = '' !== $usage['model'] ? $usage['model'] : self::request_model( $args, $url );
		$cost     = $usage['found'] ? Tokenwarden_Pricing::cost( $model, $usage['input'], $usage['output'] ) : null;
		$note     = '';
		if ( ! $usage['found'] && ! empty( $call['stream'] ) ) {
			$note = __( 'Streamed answer read by the plugin itself: token use and cost are not known', 'tokenwarden' );
		} elseif ( ! $usage['found'] ) {
			$note = __( 'The provider’s answer did not include token use', 'tokenwarden' );
		}

		Tokenwarden_Ledger::insert(
			array(
				'source'        => $call['source'],
				'provider'      => $provider,
				'model'         => $model,
				'capability'    => 'text_generation',
				'status'        => 'ok',
				'input_tokens'  => $usage['input'],
				'output_tokens' => $usage['output'],
				'cost'          => null === $cost ? 0 : $cost,
				'priced'        => null === $cost ? 0 : 1,
				'latency_ms'    => isset( $call['started'] ) ? (int) round( ( microtime( true ) - $call['started'] ) * 1000 ) : 0,
				'redactions'    => (int) ( $call['redactions'] ?? 0 ),
				'pii'           => (string) ( $call['pii'] ?? '' ),
				'channel'       => 'direct',
				'prompt_hash'   => (string) ( $call['prompt_hash'] ?? '' ),
				'note'          => $note,
			)
		);
		self::reset();
		return $response;
	}

	/**
	 * Record calls stopped by the AI plugin's Connector Approval feature.
	 *
	 * That feature blocks unapproved callers from `pre_http_request` (priority 5), which bypasses
	 * WordPress's normal error reporting, so these calls would otherwise never appear in the log.
	 *
	 * @param false|array|WP_Error $pre  Short-circuit value.
	 * @param array                $args Request arguments.
	 * @param string               $url  URL.
	 * @return false|array|WP_Error Unchanged.
	 */
	public static function record_approval_block( $pre, $args, $url ) {
		static $logged = array();
		if ( ! is_wp_error( $pre ) || Tokenwarden_Compat::APPROVAL_ERROR !== $pre->get_error_code() ) {
			return $pre;
		}
		// Any request to an AI provider counts, including the model lookup the AI Client makes
		// before generating, which is often the first request Connector Approval blocks.
		$hosts = Tokenwarden_Provider_Adapters::hosts();
		$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! isset( $hosts[ $host ] ) ) {
			return $pre;
		}
		$source = self::$call['source'] ?? Tokenwarden_Attribution::detect();
		$key    = $source . '|' . $host . '|' . ( self::$call['started'] ?? '' );
		if ( isset( $logged[ $key ] ) ) {
			return $pre;
		}
		$logged[ $key ] = true;

		Tokenwarden_Ledger::insert(
			array(
				'source'   => $source,
				'provider' => $hosts[ $host ],
				'model'    => self::request_model( $args, $url ),
				'status'   => 'blocked',
				'note'     => __( 'Not approved in AI → Connector Approval', 'tokenwarden' ),
			)
		);
		return $pre;
	}


	/**
	 * Name of the prompt builder method that triggered the prevent filter.
	 *
	 * @return string
	 */
	private static function builder_method() {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		foreach ( debug_backtrace( 0, 12 ) as $frame ) {
			if ( isset( $frame['class'], $frame['function'], $frame['args'][0] ) && '__call' === $frame['function'] && is_a( $frame['class'], 'WP_AI_Client_Prompt_Builder', true ) ) {
				return (string) $frame['args'][0];
			}
		}
		return '';
	}

	/**
	 * Readable error from a provider error body.
	 *
	 * @param string $body Response body.
	 * @param int    $code HTTP status.
	 * @return string
	 */
	private static function error_message( $body, $code ) {
		$data = json_decode( (string) $body, true );
		$msg  = '';
		if ( isset( $data['error']['message'] ) ) {
			$msg = $data['error']['message'];
		} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
			$msg = $data['error'];
		} elseif ( isset( $data['message'] ) ) {
			$msg = $data['message'];
		}
		return trim( 'HTTP ' . $code . ( $msg ? ': ' . $msg : '' ) );
	}

	/**
	 * Model id from a request body or Google-style URL.
	 *
	 * @param array  $args Request arguments.
	 * @param string $url  URL.
	 * @return string
	 */
	private static function request_model( $args, $url ) {
		$body = isset( $args['body'] ) && is_string( $args['body'] ) ? json_decode( $args['body'], true ) : null;
		if ( isset( $body['model'] ) && is_string( $body['model'] ) ) {
			return $body['model'];
		}
		if ( preg_match( '#/models/([^/:]+)#', (string) $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Last user text in a (redacted) request body, for optional excerpts.
	 *
	 * @param array $body Decoded body.
	 * @return string
	 */
	private static function prompt_text( array $body ) {
		$texts = array();
		array_walk_recursive(
			$body,
			function ( $value, $key ) use ( &$texts ) {
				if ( is_string( $value ) && in_array( $key, array( 'text', 'content', 'input', 'prompt' ), true ) ) {
					$texts[] = $value;
				}
			}
		);
		return $texts ? (string) end( $texts ) : '';
	}

	/**
	 * Plain text of a result, if any.
	 *
	 * @param object $result GenerativeAiResult.
	 * @return string
	 */
	private static function result_text( $result ) {
		try {
			return (string) $result->toText();
		} catch ( \Throwable $e ) {
			return '';
		}
	}

	/**
	 * Clear per-call state.
	 */
	private static function reset() {
		self::$call     = array();
		self::$redactor = null;
	}
}
