<?php
/**
 * Plugin Name: Demo Caller (test fixture)
 * Description: Stands in for any third-party plugin that uses the WordPress AI Client.
 */

defined( 'ABSPATH' ) || exit;

function demo_caller_generate( $prompt = 'Draft a reply to Jane at jane.doe@example.com or +1 415 555 0134 about order 42.' ) {
	return wp_ai_client_prompt( $prompt )
		->using_system_instruction( 'You are a helpful shop assistant.' )
		->generate_text();
}

function demo_caller_supported() {
	return wp_ai_client_prompt( 'ping' )->is_supported_for_text_generation();
}
