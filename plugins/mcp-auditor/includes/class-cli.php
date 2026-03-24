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

	 * [--external-file=<external-file>]
	 * : Optional path to a JSON file containing supplemental tooling findings.
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
	 *   - email
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp wp-auditor audit hello-dolly --type=plugin --persist
	 */
	public function audit( array $args, array $assoc_args ): void {
		$slug          = isset( $args[0] ) ? (string) $args[0] : '';
		$type          = isset( $assoc_args['type'] ) ? (string) $assoc_args['type'] : 'plugin';
		$checks        = isset( $assoc_args['checks'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['checks'] ) ) ) : array();
		$use_ai        = isset( $assoc_args['use-ai'] );
		$persist       = isset( $assoc_args['persist'] );
		$format        = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'json';
		$external_file = isset( $assoc_args['external-file'] ) ? (string) $assoc_args['external-file'] : '';
		$external      = array();

		if ( '' !== $external_file ) {
			if ( ! file_exists( $external_file ) ) {
				\WP_CLI::error( sprintf( 'External findings file was not found: %s', $external_file ) );
			}

			$contents = file_get_contents( $external_file );
			if ( false === $contents ) {
				\WP_CLI::error( sprintf( 'External findings file could not be read: %s', $external_file ) );
			}

			$decoded = json_decode( $contents, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
				\WP_CLI::error( sprintf( 'External findings file did not contain valid JSON: %s', $external_file ) );
			}

			$external = $decoded;
		}

		$report = $this->audit_service->run_audit( $slug, $type, $checks, $use_ai, $persist, $external );

		if ( 'summary' === $format ) {
			\WP_CLI::line( $report['summary']['text'] ?? 'Audit completed.' );
			return;
		}

		if ( 'email' === $format ) {
			\WP_CLI::line( (string) ( $report['email']['body'] ?? '' ) );
			return;
		}

		\WP_CLI::line( wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Resolve an installed artifact to its on-disk paths and metadata.
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
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - summary
	 * ---
	 */
	public function resolve( array $args, array $assoc_args ): void {
		$slug   = isset( $args[0] ) ? (string) $args[0] : '';
		$type   = isset( $assoc_args['type'] ) ? (string) $assoc_args['type'] : 'plugin';
		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'json';

		$artifact = $this->audit_service->inspect_artifact( $slug, $type );

		if ( is_wp_error( $artifact ) ) {
			\WP_CLI::error( $artifact->get_error_message() );
		}

		if ( 'summary' === $format ) {
			\WP_CLI::line(
				sprintf(
					'%s -> %s',
					(string) ( $artifact['accepted_slug'] ?? $slug ),
					(string) ( $artifact['root_path'] ?? '' )
				)
			);
			return;
		}

		\WP_CLI::line( wp_json_encode( $artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}
