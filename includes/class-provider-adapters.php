<?php
/**
 * Provider request formats.
 *
 * @package Tokenwarden
 */

defined( 'ABSPATH' ) || exit;

/**
 * Recognises outgoing AI provider requests and their text format.
 */
final class Tokenwarden_Provider_Adapters {

	/**
	 * Known provider hosts.
	 *
	 * Each entry maps a host to a provider id. Extra hosts (for example a self-hosted,
	 * OpenAI-compatible server) can be added with the `tokenwarden_provider_hosts` filter.
	 *
	 * @return array<string,string> Host => provider id.
	 */
	public static function hosts() {
		return (array) apply_filters(
			'tokenwarden_provider_hosts',
			array(
				'api.anthropic.com'                 => 'anthropic',
				'api.openai.com'                    => 'openai',
				'generativelanguage.googleapis.com' => 'google',
				// OpenAI-compatible APIs that plugins call directly with their own keys.
				'openrouter.ai'                     => 'openrouter',
				'api.x.ai'                          => 'xai',
				'api.mistral.ai'                    => 'mistral',
				'api.deepseek.com'                  => 'deepseek',
				'api.groq.com'                      => 'groq',
				'api.perplexity.ai'                 => 'perplexity',
			)
		);
	}

	/**
	 * Provider id for a request URL, or null when the URL is not an AI provider generation call.
	 *
	 * @param string $url    Request URL.
	 * @param string $method HTTP method.
	 * @return string|null
	 */
	public static function provider_for( $url, $method = 'POST' ) {
		if ( 'POST' !== strtoupper( (string) $method ) ) {
			return null;
		}
		$host  = wp_parse_url( $url, PHP_URL_HOST );
		$hosts = self::hosts();
		if ( ! $host || ! isset( $hosts[ $host ] ) ) {
			return null;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#/models/?$#', $path ) ) {
			return null;
		}
		return $hosts[ $host ];
	}

	/**
	 * Whether this request is a text generation call that carries a system prompt.
	 *
	 * @param string $url Request URL.
	 * @return string|null Format: `anthropic`, `openai-responses`, `openai-chat` or `google`.
	 */
	public static function text_format( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( preg_match( '#/v1/messages$#', $path ) ) {
			return 'anthropic';
		}
		if ( preg_match( '#/responses$#', $path ) ) {
			return 'openai-responses';
		}
		if ( preg_match( '#/chat/completions$#', $path ) ) {
			return 'openai-chat';
		}
		if ( preg_match( '#:(stream)?generateContent$#i', $path ) ) {
			return 'google';
		}
		return null;
	}

	/**
	 * Model and token usage from a provider response body, plain JSON or a streamed (SSE) body.
	 *
	 * Handles the Anthropic Messages API, the OpenAI Chat Completions and Responses APIs (and
	 * OpenAI-compatible APIs) and Google's generateContent. For a stream, the usage the provider
	 * reports in its events is used; it is missing when the calling plugin read the stream itself.
	 *
	 * @param string $body Response body.
	 * @return array{model:string,input:int,output:int,found:bool}
	 */
	public static function usage_from_body( $body ) {
		$out    = array(
			'model'  => '',
			'input'  => 0,
			'output' => 0,
			'found'  => false,
		);
		$body   = (string) $body;
		$events = array();
		$json   = json_decode( $body, true );
		if ( is_array( $json ) ) {
			// Google can return a JSON array of chunks.
			$events = wp_is_numeric_array( $json ) ? $json : array( $json );
		} elseif ( false !== strpos( $body, 'data:' ) ) {
			foreach ( preg_split( '/\r?\n/', $body ) as $line ) {
				if ( 0 === strpos( $line, 'data:' ) ) {
					$event = json_decode( trim( substr( $line, 5 ) ), true );
					if ( is_array( $event ) ) {
						$events[] = $event;
					}
				}
			}
		}

		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			foreach ( array( $event, $event['response'] ?? null, $event['message'] ?? null ) as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				foreach ( array( 'model', 'modelVersion' ) as $key ) {
					if ( '' === $out['model'] && ! empty( $node[ $key ] ) && is_string( $node[ $key ] ) ) {
						$out['model'] = $node[ $key ];
					}
				}
				$u = isset( $node['usage'] ) && is_array( $node['usage'] ) ? $node['usage'] : null;
				if ( $u ) {
					// Anthropic: cached input is billed too, so it counts as input here.
					$in  = (int) ( $u['input_tokens'] ?? $u['prompt_tokens'] ?? 0 ) + (int) ( $u['cache_creation_input_tokens'] ?? 0 ) + (int) ( $u['cache_read_input_tokens'] ?? 0 );
					$gen = (int) ( $u['output_tokens'] ?? $u['completion_tokens'] ?? 0 );
					if ( $in || $gen ) {
						$out['input']  = max( $out['input'], $in );
						$out['output'] = max( $out['output'], $gen );
						$out['found']  = true;
					}
				}
				$g = isset( $node['usageMetadata'] ) && is_array( $node['usageMetadata'] ) ? $node['usageMetadata'] : null;
				if ( $g ) {
					$out['input']  = max( $out['input'], (int) ( $g['promptTokenCount'] ?? 0 ) );
					$out['output'] = max( $out['output'], (int) ( $g['candidatesTokenCount'] ?? 0 ) + (int) ( $g['thoughtsTokenCount'] ?? 0 ) );
					$out['found']  = true;
				}
			}
		}
		return $out;
	}
}
