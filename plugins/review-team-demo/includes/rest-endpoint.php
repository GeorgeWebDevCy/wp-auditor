<?php

add_action( 'rest_api_init', 'review_team_demo_register_rest_routes' );
add_action( 'wp_ajax_review_team_demo_ping', 'review_team_demo_ajax_ping' );
add_action( 'wp_ajax_nopriv_review_team_demo_ping', 'review_team_demo_ajax_ping' );

function review_team_demo_register_rest_routes() {
	register_rest_route(
		'review-team-demo/v1',
		'/export',
		array(
			'methods'  => 'POST',
			'callback' => 'review_team_demo_rest_export',
		)
	);
}

function review_team_demo_rest_export( $request ) {
	$email = isset( $_REQUEST['email'] ) ? $_REQUEST['email'] : '';

	return rest_ensure_response(
		array(
			'ok'    => true,
			'email' => $email,
			'route' => $request->get_route(),
		)
	);
}

function review_team_demo_ajax_ping() {
	setcookie( 'review_team_demo_session', md5( microtime( true ) ), time() + HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN );

	echo wp_json_encode(
		array(
			'ok'   => true,
			'user' => isset( $_REQUEST['user'] ) ? $_REQUEST['user'] : '',
		)
	);

	wp_die();
}
