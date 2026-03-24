<?php

namespace MCPAuditor;

defined( 'ABSPATH' ) || exit;

class Plugin {
	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var ReportRepository
	 */
	private $report_repository;

	/**
	 * @var AuditService
	 */
	private $audit_service;

	public static function boot(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		$this->report_repository = new ReportRepository();
		$this->audit_service     = new AuditService( $this->report_repository, new OpenAIClient() );

		add_action( 'init', array( $this, 'register_report_post_type' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_ability_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_action( 'mcp_adapter_init', array( $this, 'register_mcp_server' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CLI::register( $this->audit_service );
		}
	}

	public function register_report_post_type(): void {
		$this->report_repository->register();
	}

	public function render_dependency_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || $this->dependencies_ready() ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'MCP Auditor is active, but the WordPress Abilities API is not available yet. Activate the abilities-api and mcp-adapter plugins to expose the audit tool through MCP.', 'mcp-auditor' );
		echo '</p></div>';
	}

	public function register_admin_page(): void {
		add_management_page(
			__( 'WP Auditor', 'mcp-auditor' ),
			__( 'WP Auditor', 'mcp-auditor' ),
			'manage_options',
			'mcp-auditor',
			array( $this, 'render_admin_page' )
		);
	}

	public function render_admin_page(): void {
		$latest_reports = get_posts(
			array(
				'post_type'      => ReportRepository::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => 5,
			)
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Auditor', 'mcp-auditor' ); ?></h1>
			<p><?php echo esc_html__( 'The MCP Auditor plugin exposes a tool named mcp-auditor-run-audit through the WordPress MCP Adapter and stores completed runs as private audit reports.', 'mcp-auditor' ); ?></p>

			<table class="widefat striped" style="max-width:960px">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Component', 'mcp-auditor' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'mcp-auditor' ); ?></th>
						<th><?php echo esc_html__( 'Details', 'mcp-auditor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td><?php echo esc_html__( 'Abilities API', 'mcp-auditor' ); ?></td>
						<td><?php echo esc_html( function_exists( 'wp_register_ability' ) ? __( 'Ready', 'mcp-auditor' ) : __( 'Missing', 'mcp-auditor' ) ); ?></td>
						<td><code>wp_register_ability()</code></td>
					</tr>
					<tr>
						<td><?php echo esc_html__( 'OpenAI key', 'mcp-auditor' ); ?></td>
						<td><?php echo esc_html( defined( 'OPENAI_API_KEY' ) || getenv( 'OPENAI_API_KEY' ) ? __( 'Configured', 'mcp-auditor' ) : __( 'Not configured', 'mcp-auditor' ) ); ?></td>
						<td><code>OPENAI_API_KEY</code></td>
					</tr>
					<tr>
						<td><?php echo esc_html__( 'Default model', 'mcp-auditor' ); ?></td>
						<td><?php echo esc_html( defined( 'WP_AUDITOR_OPENAI_MODEL' ) ? WP_AUDITOR_OPENAI_MODEL : ( getenv( 'WP_AUDITOR_OPENAI_MODEL' ) ?: 'gpt-5.4' ) ); ?></td>
						<td><code>WP_AUDITOR_OPENAI_MODEL</code></td>
					</tr>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Useful Commands', 'mcp-auditor' ); ?></h2>
			<p><code>pwsh ./scripts/list-tools.ps1</code></p>
			<p><code>pwsh ./scripts/run-audit.ps1 -Slug hello-dolly -Type plugin</code></p>
			<p><code>wp wp-auditor audit hello-dolly --type=plugin --persist --format=summary</code></p>

			<h2><?php echo esc_html__( 'Recent Reports', 'mcp-auditor' ); ?></h2>
			<?php if ( empty( $latest_reports ) ) : ?>
				<p><?php echo esc_html__( 'No reports have been generated yet.', 'mcp-auditor' ); ?></p>
			<?php else : ?>
				<ul>
					<?php foreach ( $latest_reports as $report ) : ?>
						<li>
							<a href="<?php echo esc_url( get_edit_post_link( $report->ID, 'raw' ) ); ?>">
								<?php echo esc_html( get_the_title( $report ) ); ?>
							</a>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	public function register_ability_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'mcp-auditor' ) ) {
			return;
		}

		wp_register_ability_category(
			'mcp-auditor',
			array(
				'label'       => __( 'MCP Auditor', 'mcp-auditor' ),
				'description' => __( 'Abilities for auditing installed WordPress plugins and themes.', 'mcp-auditor' ),
			)
		);
	}

	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		wp_register_ability(
			'mcp-auditor/run-audit',
			array(
				'category'            => 'mcp-auditor',
				'label'               => __( 'Run Plugin or Theme Audit', 'mcp-auditor' ),
				'description'         => __( 'Audit an installed WordPress plugin or theme and return a structured report.', 'mcp-auditor' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'slug'           => array(
							'type'        => 'string',
							'description' => __( 'Installed plugin or theme slug.', 'mcp-auditor' ),
						),
						'type'           => array(
							'type'        => 'string',
							'enum'        => array( 'plugin', 'theme' ),
							'description' => __( 'Artifact type.', 'mcp-auditor' ),
						),
						'checks'         => array(
							'type'        => 'array',
							'description' => __( 'Checks to run. If omitted, all supported checks for the artifact type are used.', 'mcp-auditor' ),
							'items'       => array(
								'type' => 'string',
							),
						),
						'use_ai'         => array(
							'type'        => 'boolean',
							'description' => __( 'Enable OpenAI-powered deep analysis when an API key is configured.', 'mcp-auditor' ),
						),
						'persist_report' => array(
							'type'        => 'boolean',
							'description' => __( 'Save the completed report into WordPress admin.', 'mcp-auditor' ),
						),
					),
					'required'   => array( 'slug', 'type' ),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'status'  => array( 'type' => 'string' ),
						'summary' => array( 'type' => 'object' ),
						'issues'  => array( 'type' => 'array' ),
					),
				),
				'execute_callback'    => array( $this, 'handle_run_audit' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'annotations' => array(
						'readonly'    => false,
						'destructive' => false,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);

		wp_register_ability(
			'mcp-auditor/installed-plugins',
			array(
				'category'            => 'mcp-auditor',
				'label'               => __( 'Installed Plugins', 'mcp-auditor' ),
				'description'         => __( 'Lists installed plugins and accepted audit slugs.', 'mcp-auditor' ),
				'execute_callback'    => array( $this, 'handle_list_plugins' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'uri'         => 'wordpress://mcp-auditor/plugins',
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'resource',
					),
				),
			)
		);

		wp_register_ability(
			'mcp-auditor/installed-themes',
			array(
				'category'            => 'mcp-auditor',
				'label'               => __( 'Installed Themes', 'mcp-auditor' ),
				'description'         => __( 'Lists installed themes and accepted audit slugs.', 'mcp-auditor' ),
				'execute_callback'    => array( $this, 'handle_list_themes' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'manage_options' );
				},
				'meta'                => array(
					'uri'         => 'wordpress://mcp-auditor/themes',
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'mcp'         => array(
						'public' => true,
						'type'   => 'resource',
					),
				),
			)
		);
	}

	/**
	 * @param mixed $adapter
	 */
	public function register_mcp_server( $adapter ): void {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		if ( function_exists( 'wp_get_abilities' ) ) {
			wp_get_abilities();
		}

		$adapter->create_server(
			'mcp-auditor-server',
			'mcp',
			'mcp-auditor-server',
			'MCP Auditor Server',
			'Direct MCP tools and resources for auditing installed plugins and themes.',
			'1.0.0',
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			array( 'mcp-auditor/run-audit' ),
			array( 'mcp-auditor/installed-plugins', 'mcp-auditor/installed-themes' )
		);
	}

	/**
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>
	 */
	public function handle_run_audit( array $input ): array {
		$checks = isset( $input['checks'] ) && is_array( $input['checks'] ) ? array_map( 'sanitize_key', $input['checks'] ) : array();

		return $this->audit_service->run_audit(
			isset( $input['slug'] ) ? sanitize_text_field( (string) $input['slug'] ) : '',
			isset( $input['type'] ) ? sanitize_key( (string) $input['type'] ) : 'plugin',
			$checks,
			! empty( $input['use_ai'] ),
			! isset( $input['persist_report'] ) || (bool) $input['persist_report']
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function handle_list_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$items = array();

		foreach ( get_plugins() as $relative_path => $plugin ) {
			$items[] = array(
				'name'          => $plugin['Name'] ?? $relative_path,
				'accepted_slug' => $relative_path,
				'text_domain'   => $plugin['TextDomain'] ?? '',
				'version'       => $plugin['Version'] ?? '',
			);
		}

		return array(
			'plugins' => $items,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function handle_list_themes(): array {
		$items = array();

		foreach ( wp_get_themes() as $slug => $theme ) {
			$items[] = array(
				'name'          => $theme->get( 'Name' ),
				'accepted_slug' => $slug,
				'text_domain'   => $theme->get( 'TextDomain' ),
				'version'       => $theme->get( 'Version' ),
			);
		}

		return array(
			'themes' => $items,
		);
	}

	private function dependencies_ready(): bool {
		return function_exists( 'wp_register_ability' );
	}
}
