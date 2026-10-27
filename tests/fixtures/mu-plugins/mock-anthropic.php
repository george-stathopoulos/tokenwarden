<?php
/**
 * Plugin Name: Mock Anthropic API (test fixture)
 * Description: Answers api.anthropic.com locally. Records outgoing bodies. Behaviour is set through globals.
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'ANTHROPIC_API_KEY' ) ) {
	define( 'ANTHROPIC_API_KEY', 'sk-ant-FAKE-TEST-KEY' );
}

$GLOBALS['mock'] = array(
	'status'   => 200,
	'usage'    => array( 'input_tokens' => 1200, 'output_tokens' => 300 ),
	'model'    => 'claude-sonnet-5-5',
	'requests' => array(),
);

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		// Respect an answer from an earlier filter (such as a cache), like the real transport would.
		if ( false !== $pre || false === strpos( $url, 'api.anthropic.com' ) ) {
			return $pre;
		}
		$mock = &$GLOBALS['mock'];
		if ( false !== strpos( $url, '/v1/models' ) ) {
			$status = 200;
			$body   = array( 'data' => array( array( 'id' => $mock['model'], 'display_name' => 'Mock model' ) ) );
		} else {
			$mock['requests'][] = array( 'url' => $url, 'body' => $args['body'] );
			$request = json_decode( $args['body'], true );
			$last    = end( $request['messages'] );
			$text    = is_string( $last['content'] ) ? $last['content'] : $last['content'][0]['text'];
			$status  = $mock['status'];
			$body    = 200 === $status
				? array(
					'id'          => 'msg_' . count( $mock['requests'] ),
					'type'        => 'message',
					'role'        => 'assistant',
					'model'       => $request['model'],
					'content'     => array( array( 'type' => 'text', 'text' => 'Model saw: ' . $text ) ),
					'stop_reason' => 'end_turn',
					'usage'       => $mock['usage'],
				)
				: array( 'type' => 'error', 'error' => array( 'type' => 'overloaded_error', 'message' => 'Overloaded' ) );
		}
		$response = array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
			'response' => array( 'code' => $status, 'message' => 200 === $status ? 'OK' : 'Error' ),
			'cookies'  => array(),
			'filename' => null,
		);
		// A short-circuited response skips the transport, so fire what the transport would.
		do_action( 'http_api_debug', $response, 'response', 'Mock', $args, $url );
		return apply_filters( 'http_response', $response, $args, $url );
	},
	100,
	3
);

// Capture mail instead of sending.
$GLOBALS['mails'] = array();
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$GLOBALS['mails'][] = $atts;
		return true;
	},
	10,
	2
);
