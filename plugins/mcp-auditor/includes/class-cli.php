<?php

namespace MCPAuditor;

defined( 'ABSPATH' ) || exit;

class CLI {
	/**
	 * @var AuditService
	 */
	private $audit_service;

	public function __construct( AuditService $audit_service ) {
		$this->audit_service = $audit_service;
	}

	public static function register( AuditService $audit_service ): void {
		\WP_CLI::add_command( 'wp-auditor', new self( $audit_service ) );
	}

	/**
	 * Run an audit from WP-CLI.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Plugin or theme slug.
	 *
	 * [--type=<type>]
	 * : Artifact type. Accepts plugin or theme.
	 * ---
	 * default: plugin
	 * options:
	 *   - plugin
	 *   - theme
	 * ---
	 *
	 * [--checks=<checks>]
	 * : Comma-separated checks to run.
	 *
	 * [--use-ai]
	 * : Include OpenAI analysis if OPENAI_API_KEY is configured.
	 *
	 * [--persist]
	 * : Save the generated report to the Audit Reports post type.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - summary
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp-auditor audit hello-dolly --type=plugin --persist
	 */
	public function audit( array $args, array $assoc_args ): void {
		$slug    = isset( $args[0] ) ? (string) $args[0] : '';
		$type    = isset( $assoc_args['type'] ) ? (string) $assoc_args['type'] : 'plugin';
		$checks  = isset( $assoc_args['checks'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['checks'] ) ) ) : array();
		$use_ai  = isset( $assoc_args['use-ai'] );
		$persist = isset( $assoc_args['persist'] );
		$format  = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'json';

		$report = $this->audit_service->run_audit( $slug, $type, $checks, $use_ai, $persist );

		if ( 'summary' === $format ) {
			\WP_CLI::line( $report['summary']['text'] ?? 'Audit completed.' );
			return;
		}

		\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}

