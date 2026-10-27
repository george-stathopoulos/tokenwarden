<?php
/**
 * Tokenwarden integration tests.
 *
 * Runs inside a real WordPress (WordPress Playground) with the Anthropic provider plugin,
 * a mock Anthropic API and a demo plugin that calls the AI Client. See tests/run.sh.
 *
 * @package Tokenwarden
 */

require '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$results = array();
$current = '';

/**
 * Start a test group.
 *
 * @param string $name Name.
 */
function group( $name ) {
	global $current;
	$current = $name;
	reset_state();
}

/**
 * Record an assertion.
 *
 * @param bool   $ok      Passed.
 * @param string $message Description.
 * @param mixed  $detail  Shown on failure.
 */
function check( $ok, $message, $detail = null ) {
	global $results, $current;
	$results[] = array(
		'group'  => $current,
		'ok'     => (bool) $ok,
		'test'   => $message,
		'detail' => $ok ? null : $detail,
	);
}

/**
 * Equality assertion.
 *
 * @param mixed  $expected Expected.
 * @param mixed  $actual   Actual.
 * @param string $message  Description.
 */
function eq( $expected, $actual, $message ) {
	check( $expected === $actual, $message, array( 'expected' => $expected, 'actual' => $actual ) );
}

/**
 * Fresh ledger, settings and mock state.
 */
function reset_state() {
	Tokenwarden_Ledger::clear();
	delete_option( Tokenwarden_Settings::OPTION );
	Tokenwarden_Settings::flush();
	Tokenwarden_Alerts::uninstall();
	$GLOBALS['mock']['status']   = 200;
	$GLOBALS['mock']['usage']    = array( 'input_tokens' => 1200, 'output_tokens' => 300 );
	$GLOBALS['mock']['requests'] = array();
	$GLOBALS['mails']            = array();
	wp_set_current_user( 1 );
}

/**
 * All ledger rows, oldest first.
 *
 * @return array
 */
function rows() {
	global $wpdb;
	return $wpdb->get_results( 'SELECT * FROM ' . Tokenwarden_Ledger::table() . ' ORDER BY id ASC', ARRAY_A ); // phpcs:ignore
}

/**
 * Last outgoing provider body.
 *
 * @return array
 */
function last_body() {
	$r = end( $GLOBALS['mock']['requests'] );
	return $r ? json_decode( $r['body'], true ) : array();
}

/* ------------------------------------------------------------------ */
group( 'Environment' );
check( function_exists( 'wp_ai_client_prompt' ), 'WordPress AI Client is available' );
check( is_plugin_active( 'tokenwarden/tokenwarden.php' ), 'Tokenwarden is active' );
check( is_plugin_active( 'ai-provider-for-anthropic/plugin.php' ), 'Anthropic provider is active' );
check( is_plugin_active( 'demo-caller/demo-caller.php' ), 'Demo caller plugin is active' );

/* ------------------------------------------------------------------ */
group( 'Redactor' );
$r   = new Tokenwarden_Redactor( Tokenwarden_Settings::get( 'redaction' ) );
$out = $r->redact( 'Mail jane@example.com or JANE@example.com, call +1 415 555 0134 or (020) 7946 0958.' );
eq( 'Mail [EMAIL_1] or [EMAIL_2], call [PHONE_1] or [PHONE_2].', $out, 'emails and grouped phone numbers' );

$r   = new Tokenwarden_Redactor( Tokenwarden_Settings::get( 'redaction' ) );
$out = $r->redact( 'Order #100245 placed on 2026-09-28 with version 6.4.3 for 1234.5678 total, 01.10.2026.' );
eq( 'Order #100245 placed on 2026-09-28 with version 6.4.3 for 1234.5678 total, 01.10.2026.', $out, 'order numbers, dates, versions and amounts are left alone' );

$r   = new Tokenwarden_Redactor( Tokenwarden_Settings::get( 'redaction' ) );
$out = $r->redact( 'Valid 4242 4242 4242 4242, invalid 4242 4242 4242 4241.' );
eq( 'Valid [CARD_1], invalid 4242 4242 4242 4241.', $out, 'card numbers must pass the Luhn check' );

$r   = new Tokenwarden_Redactor( Tokenwarden_Settings::get( 'redaction' ) );
$out = $r->redact( 'IBAN GB33BUKB20201555555555, SSN 078-05-1120, IP 203.0.113.42.' );
eq( 'IBAN [IBAN_1], SSN [SSN_1], IP 203.0.113.42.', $out, 'IBAN and SSN redacted; IP detector is off by default' );

$cfg       = Tokenwarden_Settings::get( 'redaction' );
$cfg['ip'] = true;
$cfg['custom'] = array( 'Project Falcon' );
$r   = new Tokenwarden_Redactor( $cfg );
$out = $r->redact( 'project falcon launches from 203.0.113.42; Project Falcon is secret.' );
eq( '[TERM_1] launches from [IP_1]; [TERM_2] is secret.', $out, 'custom terms match any case; each distinct spelling keeps its own placeholder so it restores exactly' );
$r   = new Tokenwarden_Redactor( $cfg );
eq( '[TERM_1] and [TERM_1]', $r->redact( 'Project Falcon and Project Falcon' ), 'a repeated value reuses its placeholder' );

$r    = new Tokenwarden_Redactor( Tokenwarden_Settings::get( 'redaction' ) );
$r->redact( 'Write to first@example.com' );
$tree = $r->redact_tree(
	array(
		'model'    => 'claude-sonnet-5-5',
		'messages' => array(
			array(
				'role'    => 'user',
				'content' => array(
					array( 'type' => 'text', 'text' => 'Email bob@example.com' ),
					array( 'type' => 'image', 'source' => array( 'data' => 'bob@example.com' ) ),
				),
			),
		),
	)
);
eq( 'Email [EMAIL_2]', $tree['messages'][0]['content'][0]['text'], 'redact_tree reaches nested text blocks' );
eq( 'bob@example.com', $tree['messages'][0]['content'][1]['source']['data'], 'redact_tree skips binary/image blocks' );
eq( 'claude-sonnet-5-5', $tree['model'], 'redact_tree leaves structural keys alone' );
$restored = $r->restore( wp_json_encode( array( 'text' => 'Hi [EMAIL_2]' ) ) );
eq( '{"text":"Hi bob@example.com"}', $restored, 'restore puts values back into JSON' );

/* ------------------------------------------------------------------ */
group( 'Pricing' );
eq( array( 1.0, 5.0 ), Tokenwarden_Pricing::price_for( 'claude-haiku-4-5' ), 'exact model id' );
eq( array( 1.0, 5.0 ), Tokenwarden_Pricing::price_for( 'claude-haiku-4-5-20251001' ), 'dated snapshot matches its family' );
eq( array( 4.0, 20.0 ), Tokenwarden_Pricing::price_for( 'claude-opus-5-5' ), 'longest prefix wins (opus-5-5 over opus-5)' );
eq( array( 15.0, 75.0 ), Tokenwarden_Pricing::price_for( 'claude-opus-4-1-20250805' ), 'older Opus 4.1 keeps its own price' );
eq( null, Tokenwarden_Pricing::price_for( 'gpt-5.6' ), 'unknown versions are unpriced instead of guessed' );
eq( 0.0081, Tokenwarden_Pricing::cost( 'claude-sonnet-4-6', 1200, 300 ), 'cost from tokens' );
Tokenwarden_Settings::update( array( 'prices' => array( 'llama-4-scout' => array( 0.5, 1 ) ) ) );
eq( array( 0.5, 1.0 ), Tokenwarden_Pricing::price_for( 'llama-4-scout' ), 'admin price overrides apply' );
$info = Tokenwarden_Pricing::info();
eq( Tokenwarden_Pricing::CHECKED, $info['checked'], 'pricing info reports the date prices were checked' );
eq( TOKENWARDEN_VERSION, $info['version'], 'pricing info reports the plugin version that ships the prices' );
eq( 1, $info['overrides'], 'pricing info counts admin overrides' );
eq( $info['age_days'] > Tokenwarden_Pricing::STALE_AFTER_DAYS, $info['stale'], 'stale flag follows the age threshold' );
check( isset( Tokenwarden_Stats::overview( 7 )['pricing']['checked'] ), 'overview exposes pricing info' );
check( Tokenwarden_Pricing::is_local( 'webllm' ) && ! Tokenwarden_Pricing::is_local( 'anthropic' ), 'WebLLM (in-browser) is a local, free provider' );
$local = Tokenwarden_Pricing::local_ai();
check( isset( $local['installed'], $local['active'], $local['worker'], $local['url'] ), 'local AI status is reported for the dashboard' );

/* ------------------------------------------------------------------ */
group( 'Automatic prices' );
$GLOBALS['or_calls'] = array();
Tokenwarden_Price_Sync::run();
eq( 0, count( $GLOBALS['or_calls'] ), 'off by default: nothing is downloaded without consent' );
Tokenwarden_Settings::update( array( 'prices_auto' => true ) );
$state = Tokenwarden_Price_Sync::update();
check( ! is_wp_error( $state ), 'prices download when turned on', is_wp_error( $state ) ? $state->get_error_message() : null );
$ua = $GLOBALS['or_calls'][0]['user-agent'] ?? '';
check( false === strpos( $ua, home_url() ) && false === strpos( $ua, wp_parse_url( home_url(), PHP_URL_HOST ) ), 'the request does not include the site address', $ua );
$live = Tokenwarden_Price_Sync::prices();
eq( array( 2.5, 12.0 ), $live['claude-sonnet-5-5'] ?? null, 'Anthropic ids are mapped (claude-sonnet-5.5 → claude-sonnet-5-5)' );
eq( array( 0.8, 4.0 ), $live['claude-3-5-haiku'] ?? null, 'older Anthropic ids are mapped too' );
eq( array( 0.2, 1.2 ), $live['gpt-5.6-luna'] ?? null, 'OpenAI ids are kept as they are, including models the built-in table lacks' );
eq( array( 2.0, 12.0 ), $live['gemini-3.9-pro'] ?? null, 'Google ids are kept as they are' );
check( ! isset( $live['claude-sonnet-5-5:batch'] ) && ! isset( $live['gpt-oss-free'] ) && ! isset( $live['mistral-large'] ), 'batch and free variants and other providers are skipped' );
eq( array( 2.5, 12.0 ), Tokenwarden_Pricing::price_for( 'claude-sonnet-5-5' ), 'live prices replace built-in prices' );
eq( array( 2.5, 12.0 ), Tokenwarden_Pricing::price_for( 'claude-sonnet-5-5-20260601' ), 'live prices cover dated versions' );
eq( array( 0.2, 1.2 ), Tokenwarden_Pricing::price_for( 'gpt-5.6-luna' ), 'models missing from the built-in table get a price' );
Tokenwarden_Settings::update( array( 'prices' => array( 'claude-sonnet-5-5' => array( 1.5, 7 ) ) ) );
eq( array( 1.5, 7.0 ), Tokenwarden_Pricing::price_for( 'claude-sonnet-5-5' ), 'admin edits still win over live prices' );
Tokenwarden_Settings::update( array( 'prices' => array() ) );
$info = Tokenwarden_Pricing::info();
eq( true, $info['live'], 'pricing info reports live prices' );
eq( false, $info['stale'], 'live prices are never flagged as out of date' );
check( $info['count'] >= 10, 'pricing info reports how many prices were downloaded', $info['count'] );

foreach ( array( 'down', 'empty', '500' ) as $mode ) {
	$GLOBALS['or_mode'] = $mode;
	$r = Tokenwarden_Price_Sync::update();
	check( is_wp_error( $r ), "a failed download ($mode) is reported" );
	eq( array( 2.5, 12.0 ), Tokenwarden_Pricing::price_for( 'claude-sonnet-5-5' ), "a failed download ($mode) keeps the last good prices" );
}
$GLOBALS['or_mode'] = 'ok';
check( '' !== Tokenwarden_Pricing::info()['error'], 'the last error is shown' );

$req = new WP_REST_Request( 'POST', '/tokenwarden/v1/prices/update' );
eq( 200, rest_do_request( $req )->get_status(), '“Update now” works from the dashboard' );
eq( '', Tokenwarden_Pricing::info()['error'], 'a successful update clears the error' );

Tokenwarden_Settings::update( array( 'prices_auto' => false ) );
eq( array( 2.0, 10.0 ), Tokenwarden_Pricing::price_for( 'claude-sonnet-5-5' ), 'turning it off returns to built-in prices' );
eq( null, Tokenwarden_Pricing::price_for( 'gpt-5.6-luna' ), 'turning it off removes live-only models' );
eq( 400, rest_do_request( $req )->get_status(), '“Update now” is refused while turned off' );
Tokenwarden_Price_Sync::uninstall();

/* ------------------------------------------------------------------ */
group( 'Brief adapters' );
$b = Tokenwarden_Provider_Adapters::add_brief( array( 'system' => 'Base.' ), 'anthropic', 'BRIEF' );
eq( "Base.\n\nBRIEF", $b['system'], 'Anthropic string system' );
$b = Tokenwarden_Provider_Adapters::add_brief( array( 'system' => array( array( 'type' => 'text', 'text' => 'Base.' ) ) ), 'anthropic', 'BRIEF' );
eq( 'BRIEF', $b['system'][1]['text'], 'Anthropic block system' );
$b = Tokenwarden_Provider_Adapters::add_brief( array( 'input' => array() ), 'openai-responses', 'BRIEF' );
eq( 'BRIEF', $b['instructions'], 'OpenAI Responses instructions' );
$b = Tokenwarden_Provider_Adapters::add_brief( array( 'messages' => array( array( 'role' => 'user', 'content' => 'Hi' ) ) ), 'openai-chat', 'BRIEF' );
eq( array( 'role' => 'system', 'content' => 'BRIEF' ), $b['messages'][0], 'OpenAI Chat adds a system message' );
$b = Tokenwarden_Provider_Adapters::add_brief( array( 'messages' => array( array( 'role' => 'system', 'content' => 'Base.' ) ) ), 'openai-chat', 'BRIEF' );
eq( "Base.\n\nBRIEF", $b['messages'][0]['content'], 'OpenAI Chat extends an existing system message' );
$b = Tokenwarden_Provider_Adapters::add_brief( array( 'contents' => array() ), 'google', 'BRIEF' );
eq( 'BRIEF', $b['systemInstruction']['parts'][0]['text'], 'Google systemInstruction' );
eq( 'google', Tokenwarden_Provider_Adapters::provider_for( 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent' ), 'Google generation URL recognised' );
eq( null, Tokenwarden_Provider_Adapters::provider_for( 'https://api.openai.com/v1/models', 'GET' ), 'model listing is ignored' );
eq( null, Tokenwarden_Provider_Adapters::provider_for( 'https://example.com/v1/messages' ), 'other hosts are ignored' );

/* ------------------------------------------------------------------ */
group( 'End-to-end call through the AI Client' );
Tokenwarden_Settings::update( array( 'brief' => array( 'enabled' => true, 'text' => 'BRAND BRIEF: British English.' ) ) );
$text = demo_caller_generate();
check( ! is_wp_error( $text ), 'generation succeeds', is_wp_error( $text ) ? $text->get_error_message() : null );
eq( 'Model saw: Draft a reply to Jane at jane.doe@example.com or +1 415 555 0134 about order 42.', $text, 'personal data is restored in the answer the plugin receives' );
$sent = last_body();
eq( 'Draft a reply to Jane at [EMAIL_1] or [PHONE_1] about order 42.', $sent['messages'][0]['content'][0]['text'] ?? ( $sent['messages'][0]['content'] ?? null ), 'provider receives placeholders, not personal data' );
check( false !== strpos( (string) wp_json_encode( $sent['system'] ?? '' ), 'BRAND BRIEF' ), 'brand brief added to the system prompt', $sent['system'] ?? null );
check( false !== strpos( (string) wp_json_encode( $sent['system'] ?? '' ), 'helpful shop assistant' ), 'plugin system prompt kept', $sent['system'] ?? null );
$rows = rows();
eq( 1, count( $rows ), 'one ledger row' );
eq( 'plugin:demo-caller', $rows[0]['source'], 'call attributed to the calling plugin' );
eq( 'ok', $rows[0]['status'], 'status ok' );
eq( 'anthropic', $rows[0]['provider'], 'provider recorded' );
eq( 'claude-sonnet-5-5', $rows[0]['model'], 'model recorded' );
eq( 1200, (int) $rows[0]['input_tokens'], 'input tokens recorded' );
eq( 300, (int) $rows[0]['output_tokens'], 'output tokens recorded' );
eq( 0.0054, round( (float) $rows[0]['cost'], 6 ), 'cost estimated from the price table' );
eq( 2, (int) $rows[0]['redactions'], 'redaction count recorded' );
eq( 1, (int) $rows[0]['brief'], 'brief flag recorded' );
eq( 0.0054, round( Tokenwarden_Ledger::month_spend( 'plugin:demo-caller' ), 6 ), 'month-to-date spend updated once (no double count)' );
eq( null, $rows[0]['prompt_excerpt'], 'no prompt stored by default' );

/* ------------------------------------------------------------------ */
group( 'Per-source opt-outs and excerpts' );
Tokenwarden_Settings::update(
	array(
		'brief'   => array( 'enabled' => true, 'text' => 'BRAND BRIEF' ),
		'logging' => array( 'store_excerpts' => true ),
		'sources' => array( 'plugin:demo-caller' => array( 'skip_redaction' => true, 'skip_brief' => true ) ),
	)
);
demo_caller_generate();
$sent = last_body();
check( false !== strpos( wp_json_encode( $sent ), 'jane.doe@example.com' ), 'skip_redaction sends the prompt unchanged' );
check( false === strpos( wp_json_encode( $sent ), 'BRAND BRIEF' ), 'skip_brief leaves the system prompt alone' );
$rows = rows();
check( false !== strpos( (string) $rows[0]['prompt_excerpt'], 'order 42' ), 'prompt excerpt stored when enabled', $rows[0]['prompt_excerpt'] );
check( false !== strpos( (string) $rows[0]['response_excerpt'], 'Model saw' ), 'response excerpt stored when enabled', $rows[0]['response_excerpt'] );

/* ------------------------------------------------------------------ */
group( 'Budgets and pausing' );
Tokenwarden_Settings::update( array( 'sources' => array( 'plugin:demo-caller' => array( 'budget' => 0.01 ) ) ) );
$a = demo_caller_generate();
$b = demo_caller_generate();
$c = demo_caller_generate();
check( ! is_wp_error( $a ) && ! is_wp_error( $b ), 'calls succeed while under budget' );
check( is_wp_error( $c ), 'call blocked once the budget is reached' );
eq( 2, count( $GLOBALS['mock']['requests'] ), 'blocked call never reaches the provider' );
$rows = rows();
eq( 'blocked', $rows[2]['status'], 'blocked call is logged' );
eq( 'Monthly budget reached', $rows[2]['note'], 'block reason recorded' );
eq( false, demo_caller_supported(), 'is_supported_* reports false while blocked' );
eq( 3, count( rows() ), 'is_supported_* checks are not logged' );

reset_state();
Tokenwarden_Settings::update( array( 'sources' => array( 'plugin:demo-caller' => array( 'paused' => true ) ) ) );
check( is_wp_error( demo_caller_generate() ), 'paused source is blocked' );
eq( 'Source is paused', rows()[0]['note'], 'pause reason recorded' );
eq( 0, count( $GLOBALS['mock']['requests'] ), 'paused source never reaches the provider' );

reset_state();
Tokenwarden_Settings::update( array( 'global_budget' => 0.005 ) );
demo_caller_generate();
check( is_wp_error( demo_caller_generate() ), 'site-wide budget blocks every source' );
eq( 'Site-wide monthly budget reached', rows()[1]['note'], 'site-wide reason recorded' );

/* ------------------------------------------------------------------ */
group( 'Provider failures' );
$GLOBALS['mock']['status'] = 529;
$res = demo_caller_generate();
check( is_wp_error( $res ), 'provider error surfaces to the calling plugin' );
$rows = rows();
eq( 1, count( $rows ), 'one row for the failed call' );
eq( 'error', $rows[0]['status'] ?? null, 'failure logged as error' );
eq( 'HTTP 529: Overloaded', $rows[0]['note'] ?? null, 'provider error message recorded' );
eq( 'plugin:demo-caller', $rows[0]['source'] ?? null, 'failure attributed to the plugin' );

/* ------------------------------------------------------------------ */
group( 'Alerts' );
Tokenwarden_Settings::update(
	array(
		'alerts'  => array( 'enabled' => true, 'threshold' => 50, 'email' => 'owner@example.com' ),
		'sources' => array( 'plugin:demo-caller' => array( 'budget' => 0.01 ) ),
	)
);
demo_caller_generate();
eq( 1, count( $GLOBALS['mails'] ), 'threshold alert sent at 54%' );
check( false !== strpos( $GLOBALS['mails'][0]['subject'] ?? '', 'Demo Caller' ), 'alert names the plugin', $GLOBALS['mails'][0]['subject'] ?? null );
demo_caller_generate();
eq( 2, count( $GLOBALS['mails'] ), 'limit alert sent at 108%' );
Tokenwarden_Ledger::insert( array( 'source' => 'plugin:demo-caller', 'cost' => 0.001 ) );
eq( 2, count( $GLOBALS['mails'] ), 'each alert is sent once per month' );

/* ------------------------------------------------------------------ */
group( 'Ledger' );
$old = gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 200 * DAY_IN_SECONDS );
Tokenwarden_Ledger::insert( array( 'source' => 'plugin:x', 'cost' => 1.5, 'created_at' => $old ) );
Tokenwarden_Ledger::insert( array( 'source' => 'plugin:x', 'cost' => 0.25 ) );
Tokenwarden_Ledger::insert( array( 'source' => 'plugin:y', 'cost' => 0.5 ) );
eq( 0.75, Tokenwarden_Ledger::month_spend(), 'month total sums only this month' );
delete_option( 'tokenwarden_spend_' . current_time( 'Ym' ) );
eq( 0.75, Tokenwarden_Ledger::month_spend(), 'month total rebuilds from the table' );
eq( 1, Tokenwarden_Ledger::prune(), 'prune deletes rows past retention' );
eq( 2, count( rows() ), 'recent rows kept' );

/* ------------------------------------------------------------------ */
group( 'Stats' );
demo_caller_generate();
$o = Tokenwarden_Stats::overview( 30 );
eq( 30, count( $o['series']['dates'] ), 'series covers the window' );
eq( 1, $o['kpis']['ok'], 'KPIs count completed calls' );
eq( 'plugin:demo-caller', $o['sources'][0]['id'], 'source listed' );
eq( 'Demo Caller (test fixture)', $o['sources'][0]['label'], 'source label from the plugin header' );
eq( 1, array_sum( array_map( 'array_sum', $o['heatmap'] ) ), 'heatmap counts the call' );
check( $o['month']['projection'] >= $o['month']['spend'], 'forecast is at least month-to-date spend' );
$list = Tokenwarden_Stats::requests( array( 'status' => 'ok' ) );
eq( 1, $list['total'], 'request log filters by status' );
eq( 'Demo Caller (test fixture)', $list['rows'][0]['source_label'], 'request rows carry labels' );
eq( 1, Tokenwarden_Stats::requests( array( 'source' => 'plugin:demo-caller' ) )['total'], 'request log filters by source' );
eq( 0, Tokenwarden_Stats::requests( array( 'source' => 'plugin:nobody' ) )['total'], 'unknown source matches nothing' );
eq( 1, Tokenwarden_Stats::requests( array( 'redacted' => true ) )['total'], 'request log filters to calls with redactions' );
Tokenwarden_Ledger::insert( array( 'source' => 'plugin:other', 'status' => 'blocked' ) );
eq( 2, Tokenwarden_Stats::requests( array() )['total'], 'no filters returns every row' );

/* ------------------------------------------------------------------ */
group( 'Repeated prompts' );
demo_caller_generate( 'Is this spam? From alice@example.com: cheap watches' );
demo_caller_generate( 'Is this spam? From bob@example.org: cheap watches' );
demo_caller_generate( 'Is this spam? From carol@example.net: hello there' );
$rows = rows();
eq( 40, strlen( $rows[0]['prompt_hash'] ), 'each call gets a request fingerprint' );
eq( $rows[0]['prompt_hash'], $rows[1]['prompt_hash'], 'requests that differ only in redacted personal data share a fingerprint' );
check( $rows[1]['prompt_hash'] !== $rows[2]['prompt_hash'], 'different requests get different fingerprints' );
$rep = Tokenwarden_Stats::repeats( strtotime( '-1 day', current_time( 'timestamp' ) ), current_time( 'timestamp' ) + 1 ); // phpcs:ignore
eq( 1, $rep['calls'], 'one repeated call found' );
eq( 3, $rep['total_calls'], 'repeat share is measured against all completed calls' );
eq( 0.0054, round( $rep['potential'], 6 ), 'potential saving is the cost of the repeats' );
eq( 'plugin:demo-caller', $rep['sources'][0]['id'] ?? null, 'repeats are broken down by source' );
check( isset( Tokenwarden_Stats::overview( 7 )['repeats']['potential'] ), 'overview exposes repeated prompts' );
add_filter( 'tokenwarden_record_row', $mark = function ( $row ) { $row['note'] = 'filtered'; return $row; } );
Tokenwarden_Ledger::insert( array( 'source' => 'plugin:x' ) );
remove_filter( 'tokenwarden_record_row', $mark );
eq( 'filtered', end( rows() )['note'] ?? null, 'tokenwarden_record_row lets extensions adjust a row before it is saved' );

/* ------------------------------------------------------------------ */
group( 'AI setup checks' );
$http = 0;
$count_http = function ( $pre ) use ( &$http ) { ++$http; return $pre; };
add_filter( 'pre_http_request', $count_http, 1 );
$providers = Tokenwarden_Compat::providers();
remove_filter( 'pre_http_request', $count_http, 1 );
eq( 0, $http, 'checking providers makes no network or AI request' );
eq( true, $providers[0]['configured'] ?? null, 'a provider with a key is reported as connected' );

// Simulate the AI plugin's Connector Approval blocking an unapproved plugin.
$deny = function ( $pre, $args, $url ) {
	return false !== strpos( $url, 'api.anthropic.com/v1/messages' ) ? new WP_Error( 'wpai_connector_not_approved', 'Not approved' ) : $pre;
};
add_filter( 'pre_http_request', $deny, 5, 3 );
check( is_wp_error( demo_caller_generate() ), 'call blocked by Connector Approval fails for the plugin' );
remove_filter( 'pre_http_request', $deny, 5 );
$row = end( rows() );
eq( 'blocked', $row['status'] ?? null, 'approval block is logged as blocked' );
eq( 'plugin:demo-caller', $row['source'] ?? null, 'approval block is attributed to the plugin' );
check( false !== strpos( (string) ( $row['note'] ?? '' ), 'Connector Approval' ), 'approval block explains where to approve', $row['note'] ?? null );

update_option( 'wpai_connector_approval_pending', array( 'k' => array( 'caller_type' => 'plugin', 'caller_basename' => 'demo-caller/demo-caller.php', 'caller_name' => 'Demo Caller', 'connector_id' => 'anthropic', 'attempts' => 3 ) ) );
add_filter( 'tokenwarden_connector_approval_active', '__return_true' );
$approval = Tokenwarden_Compat::connector_approval();
remove_filter( 'tokenwarden_connector_approval_active', '__return_true' );
delete_option( 'wpai_connector_approval_pending' );
eq( true, $approval['active'], 'Connector Approval detected' );
eq( 'Demo Caller', $approval['pending'][0]['name'] ?? null, 'plugins waiting for approval are listed' );
check( false !== strpos( $approval['url'], 'page=ai-connector-approval' ), 'link to the approval screen' );

/* ------------------------------------------------------------------ */
group( 'Demo mode' );
demo_caller_generate();
$live_rows = count( rows() );
$live_live = Tokenwarden_Settings::get( 'global_budget' );
$created   = Tokenwarden_Demo::run(
	function () {
		Tokenwarden_Ledger::install();
		return ( new Tokenwarden_Sample() )->generate( array( 'days' => 5, 'per_day' => 20 ) );
	}
);
check( $created > 20, 'sample data generated in the sandbox', $created );
eq( $live_rows, count( rows() ), 'live log untouched by sample data' );
eq( $live_live, Tokenwarden_Settings::get( 'global_budget' ), 'live settings untouched by sample settings' );
check( Tokenwarden_Demo::run( function () { return Tokenwarden_Settings::get( 'global_budget' ); } ) > 0, 'sandbox has its own budgets' );
check( Tokenwarden_Demo::run( function () { return Tokenwarden_Ledger::month_spend(); } ) !== Tokenwarden_Ledger::month_spend(), 'sandbox has its own spend totals' );

update_user_meta( 1, Tokenwarden_Demo::META, 1 );
$demo_view = rest_do_request( new WP_REST_Request( 'GET', '/tokenwarden/v1/requests' ) )->get_data();
check( $demo_view['total'] > $live_rows, 'dashboard shows sample data when demo mode is on', $demo_view['total'] );
eq( false, Tokenwarden_Demo::active(), 'demo scope ends with the dashboard request' );
check( ! is_wp_error( demo_caller_generate() ), 'real AI calls keep working in demo mode' );
eq( $live_rows + 1, count( rows() ), 'real AI calls in demo mode are logged to the live log' );
$req = new WP_REST_Request( 'POST', '/tokenwarden/v1/setup' );
$req->set_header( 'content-type', 'application/json' );
$req->set_body( wp_json_encode( array( 'global_budget' => 77, 'complete' => true ) ) );
rest_do_request( $req );
Tokenwarden_Settings::flush();
eq( 77.0, Tokenwarden_Settings::get( 'global_budget' ), 'setup guide saves live settings even in demo mode' );
eq( true, Tokenwarden_Onboarding::done(), 'setup guide can be completed' );
delete_user_meta( 1, Tokenwarden_Demo::META );
eq( $live_rows + 1, rest_do_request( new WP_REST_Request( 'GET', '/tokenwarden/v1/requests' ) )->get_data()['total'], 'live data shown again after demo mode is off' );

$req = new WP_REST_Request( 'POST', '/tokenwarden/v1/demo' );
$req->set_param( 'enabled', true );
eq( true, rest_do_request( $req )->get_data()['demo'] ?? null, 'demo switch turns demo mode on' );
rest_do_request( new WP_REST_Request( 'DELETE', '/tokenwarden/v1/demo' ) );
eq( false, Tokenwarden_Demo::run( array( 'Tokenwarden_Demo', 'has_data' ) ), 'removing sample data empties the sandbox' );
eq( false, Tokenwarden_Demo::enabled_for_user(), 'removing sample data switches back to live data' );
delete_option( Tokenwarden_Onboarding::OPTION );

/* ------------------------------------------------------------------ */
group( 'REST API' );
$sub = wp_insert_user( array( 'user_login' => 'sub' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
wp_set_current_user( $sub );
foreach ( array( '/tokenwarden/v1/overview', '/tokenwarden/v1/settings', '/tokenwarden/v1/requests', '/tokenwarden/v1/sources' ) as $route ) {
	$res = rest_do_request( new WP_REST_Request( 'GET', $route ) );
	eq( 403, $res->get_status(), "subscribers are refused: $route" );
}
wp_set_current_user( 0 );
eq( 401, rest_do_request( new WP_REST_Request( 'GET', '/tokenwarden/v1/overview' ) )->get_status(), 'logged-out visitors are refused' );
wp_set_current_user( 1 );
eq( 200, rest_do_request( new WP_REST_Request( 'GET', '/tokenwarden/v1/overview' ) )->get_status(), 'admins can read the overview' );

$req = new WP_REST_Request( 'POST', '/tokenwarden/v1/settings' );
$req->set_header( 'content-type', 'application/json' );
$req->set_body(
	wp_json_encode(
		array(
			'global_budget' => -5,
			'alerts'        => array( 'threshold' => 400, 'email' => 'not-an-email' ),
			'brief'         => array( 'text' => '<script>alert(1)</script>Be kind.' ),
			'logging'       => array( 'retention_days' => 0 ),
			'sources'       => array( 'Plugin:Evil<b>' => array( 'budget' => '12.5' ) ),
		)
	)
);
$saved = rest_do_request( $req )->get_data()['settings'];
eq( 0.0, $saved['global_budget'], 'negative budget becomes 0' );
eq( 100, $saved['alerts']['threshold'], 'threshold clamped to 100' );
eq( '', $saved['alerts']['email'], 'invalid email rejected' );
eq( 'Be kind.', $saved['brief']['text'], 'markup stripped from the brief' );
eq( 1, $saved['logging']['retention_days'], 'retention clamped to at least 1 day' );
check( isset( $saved['sources']['plugin:evilb'] ), 'source ids sanitized', array_keys( $saved['sources'] ) );

$req = new WP_REST_Request( 'POST', '/tokenwarden/v1/redact-preview' );
$req->set_param( 'text', 'mail a@b.co' );
eq( 'mail [EMAIL_1]', rest_do_request( $req )->get_data()['text'], 'redaction preview endpoint' );

/* ------------------------------------------------------------------ */
group( 'Privacy tools' );
$tokenwarden_uid = wp_insert_user( array( 'user_login' => 'privacy_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password(), 'user_email' => 'privacy-test@example.org' ) );
check( ! is_wp_error( $tokenwarden_uid ), 'test user created' );
wp_set_current_user( $tokenwarden_uid );
Tokenwarden_Ledger::insert( array( 'source' => 'plugin:privacy-test', 'model' => 'claude-haiku-4-5', 'cost' => 0.5, 'prompt_excerpt' => 'Hello from the test user' ) );
wp_set_current_user( 1 );
check( isset( apply_filters( 'wp_privacy_personal_data_exporters', array() )['tokenwarden'] ), 'exporter registered' );
check( isset( apply_filters( 'wp_privacy_personal_data_erasers', array() )['tokenwarden'] ), 'eraser registered' );
$tokenwarden_export = Tokenwarden_Privacy::export( 'privacy-test@example.org', 1 );
eq( 1, count( $tokenwarden_export['data'] ), 'export finds the user\'s request' );
check( in_array( 'Hello from the test user', array_column( $tokenwarden_export['data'][0]['data'], 'value' ), true ), 'export includes the stored excerpt' );
$tokenwarden_spend_before = Tokenwarden_Ledger::month_spend();
$tokenwarden_erase = Tokenwarden_Privacy::erase( 'privacy-test@example.org', 1 );
check( $tokenwarden_erase['items_removed'] && $tokenwarden_erase['done'], 'eraser anonymises the request' );
eq( 0, count( Tokenwarden_Privacy::export( 'privacy-test@example.org', 1 )['data'] ), 'nothing left to export after erasure' );
eq( $tokenwarden_spend_before, Tokenwarden_Ledger::month_spend(), 'erasure keeps spend totals' );
eq( 0, count( Tokenwarden_Privacy::export( 'nobody@example.org', 1 )['data'] ), 'unknown email exports nothing' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $tokenwarden_uid );

/* ------------------------------------------------------------------ */
require __DIR__ . '/cache.php';

/* ------------------------------------------------------------------ */
group( 'Uninstall' );
global $wpdb;
defined( 'WP_UNINSTALL_PLUGIN' ) || define( 'WP_UNINSTALL_PLUGIN', 'tokenwarden/tokenwarden.php' );
include WP_PLUGIN_DIR . '/tokenwarden/uninstall.php';
eq( null, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Tokenwarden_Ledger::table() ) ), 'request table dropped' );
eq( false, get_option( Tokenwarden_Settings::OPTION ), 'settings deleted' );
eq( '0', (string) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'tokenwarden\\_%'" ), 'no tokenwarden_ options left' );

/* ------------------------------------------------------------------ */
$passed = count( array_filter( array_column( $results, 'ok' ) ) );
file_put_contents(
	'/out/results.json',
	wp_json_encode(
		array(
			'passed'  => $passed,
			'failed'  => count( $results ) - $passed,
			'results' => $results,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	)
);
