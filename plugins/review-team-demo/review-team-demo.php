<?php
/*
Plugin Name: Review Team Demo
Description: Deliberately problematic plugin used to demonstrate MCP Auditor findings and review-email formatting.
Version: 0.1.0
Author: Codex
Text Domain: review-team-demo
*/

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/rest-endpoint.php';

register_activation_hook( __FILE__, 'review_team_demo_activate' );

add_action( 'admin_post_review_team_demo_export', 'review_team_demo_export' );
add_action( 'admin_enqueue_scripts', 'review_team_demo_enqueue_assets' );

function review_team_demo_activate() {
	add_option( 'review_team_demo_cache_blob', str_repeat( 'A', 180000 ), '', 'yes' );
	wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'review_team_demo_hourly_ping' );
}

function review_team_demo_export() {
	global $wpdb;

	$status  = isset( $_GET['status'] ) ? $_GET['status'] : 'publish';
	$email   = isset( $_POST['email'] ) ? $_POST['email'] : '';
	$message = isset( $_GET['message'] ) ? $_GET['message'] : 'Export complete.';
	$redirect = isset( $_GET['redirect_to'] ) ? $_GET['redirect_to'] : '';

	$results = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_status = '" . $status . "'" );

	wp_remote_post(
		'https://tracking.example.com/collect',
		array(
			'body' => array(
				'email' => $email,
				'site'  => home_url(),
			),
		)
	);

	if ( ! empty( $_POST['snippet'] ) ) {
		eval( $_POST['snippet'] );
	}

	if ( ! empty( $_POST['payload'] ) ) {
		unserialize( $_POST['payload'] );
	}

	if ( ! empty( $_FILES['package']['tmp_name'] ) ) {
		move_uploaded_file(
			$_FILES['package']['tmp_name'],
			plugin_dir_path( __FILE__ ) . 'exports/' . $_FILES['package']['name']
		);
	}

	file_put_contents(
		plugin_dir_path( __FILE__ ) . 'exports/latest-export.json',
		wp_json_encode( $results )
	);

	if ( ! empty( $redirect ) ) {
		wp_redirect( $redirect );
		exit;
	}

	echo '<div class="notice notice-success"><p>' . $message . '</p></div>';
	wp_die();
}

function review_team_demo_enqueue_assets() {
	wp_enqueue_script(
		'review-team-demo-analytics',
		'https://cdn.example.com/analytics.js',
		array(),
		null,
		true
	);

	wp_enqueue_script(
		'review-team-demo-minified',
		plugin_dir_url( __FILE__ ) . 'assets/review-team-demo.min.js',
		array(),
		'1.0.0',
		true
	);
}
