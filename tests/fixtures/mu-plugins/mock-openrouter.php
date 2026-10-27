<?php
/**
 * Plugin Name: Mock OpenRouter model list (test fixture)
 * Description: Answers openrouter.ai/api/v1/models locally. Set $GLOBALS['or_mode'] to 'ok', 'down', 'empty' or '500'.
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['or_mode']  = 'ok';
$GLOBALS['or_calls'] = array();

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( false !== $pre || false === strpos( $url, 'openrouter.ai/api/v1/models' ) ) {
			return $pre;
		}
		$GLOBALS['or_calls'][] = $args;
		if ( 'down' === $GLOBALS['or_mode'] ) {
			return new WP_Error( 'http_request_failed', 'timed out' );
		}
		$per = function ( $million ) {
			return (string) ( $million / 1000000 );
		};
		$data = array(
			array( 'id' => 'anthropic/claude-sonnet-5.5', 'pricing' => array( 'prompt' => $per( 2.5 ), 'completion' => $per( 12 ) ) ),
			array( 'id' => 'anthropic/claude-sonnet-5.5:batch', 'pricing' => array( 'prompt' => $per( 1 ), 'completion' => $per( 5 ) ) ),
			array( 'id' => 'anthropic/claude-3.5-haiku', 'pricing' => array( 'prompt' => $per( 0.8 ), 'completion' => $per( 4 ) ) ),
			array( 'id' => 'anthropic/claude-opus-5.5', 'pricing' => array( 'prompt' => $per( 4 ), 'completion' => $per( 20 ) ) ),
			array( 'id' => 'openai/gpt-5.6-luna', 'pricing' => array( 'prompt' => $per( 0.2 ), 'completion' => $per( 1.2 ) ) ),
			array( 'id' => 'openai/gpt-5.5', 'pricing' => array( 'prompt' => $per( 5 ), 'completion' => $per( 30 ) ) ),
			array( 'id' => 'openai/gpt-oss-free', 'pricing' => array( 'prompt' => '0', 'completion' => '0' ) ),
			array( 'id' => 'google/gemini-3.8-flash', 'pricing' => array( 'prompt' => $per( 0.75 ), 'completion' => $per( 3.75 ) ) ),
			array( 'id' => 'google/gemini-3.9-pro', 'pricing' => array( 'prompt' => $per( 2 ), 'completion' => $per( 12 ) ) ),
			array( 'id' => 'mistralai/mistral-large', 'pricing' => array( 'prompt' => $per( 2 ), 'completion' => $per( 6 ) ) ),
			array( 'id' => 'anthropic/claude-haiku-4.5', 'pricing' => array( 'prompt' => $per( 1 ), 'completion' => $per( 5 ) ) ),
			array( 'id' => 'openai/gpt-5-mini', 'pricing' => array( 'prompt' => $per( 0.25 ), 'completion' => $per( 2 ) ) ),
			array( 'id' => 'openai/gpt-5-nano', 'pricing' => array( 'prompt' => $per( 0.05 ), 'completion' => $per( 0.4 ) ) ),
			array( 'id' => 'google/gemini-2.5-flash', 'pricing' => array( 'prompt' => $per( 0.3 ), 'completion' => $per( 2.5 ) ) ),
		);
		if ( 'empty' === $GLOBALS['or_mode'] ) {
			$data = array();
		}
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( array( 'data' => $data ) ),
			'response' => array( 'code' => '500' === $GLOBALS['or_mode'] ? 500 : 200, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	100,
	3
);
