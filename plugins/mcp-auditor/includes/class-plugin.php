<?php

namespace MCPAuditor;

defined( 'ABSPATH' ) || exit;

class Plugin {
	const MENU_SLUG      = 'mcp-auditor';
	const RUN_AUDIT_PAGE = 'mcp-auditor-run-audit';
	const SETTINGS_PAGE  = 'mcp-auditor-settings';

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var Settings
	 */
	private $settings;

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
		$this->settings          = new Settings();
		$this->report_repository = new ReportRepository();
		$this->audit_service     = new AuditService( $this->report_repository, new OpenAIClient( $this->settings ) );

		add_action( 'init', array( $this, 'register_report_post_type' ) );
		add_action( 'admin_init', array( $this->settings, 'register' ) );
		add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
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

	public function register_admin_menu(): void {
		add_menu_page(
			__( 'WP Auditor', 'mcp-auditor' ),
			__( 'WP Auditor', 'mcp-auditor' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' ),
			'dashicons-shield',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'mcp-auditor' ),
			__( 'Dashboard', 'mcp-auditor' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_dashboard_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Run Audit', 'mcp-auditor' ),
			__( 'Run Audit', 'mcp-auditor' ),
			'manage_options',
			self::RUN_AUDIT_PAGE,
			array( $this, 'render_run_audit_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'mcp-auditor' ),
			__( 'Settings', 'mcp-auditor' ),
			'manage_options',
			self::SETTINGS_PAGE,
			array( $this, 'render_settings_page' )
		);
	}

	public function render_dashboard_page(): void {
		$this->assert_manage_options();

		$latest_reports = $this->get_recent_reports( 5 );
		$status_rows    = $this->get_status_rows();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Auditor', 'mcp-auditor' ); ?></h1>
			<p><?php echo esc_html__( 'Manage audits, saved reports, and OpenAI settings from one place inside WordPress.', 'mcp-auditor' ); ?></p>

			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::RUN_AUDIT_PAGE ) ); ?>">
					<?php echo esc_html__( 'Run A New Audit', 'mcp-auditor' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . ReportRepository::POST_TYPE ) ); ?>">
					<?php echo esc_html__( 'Browse Saved Reports', 'mcp-auditor' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_PAGE ) ); ?>">
					<?php echo esc_html__( 'Open Settings', 'mcp-auditor' ); ?>
				</a>
			</p>

			<h2><?php echo esc_html__( 'System Status', 'mcp-auditor' ); ?></h2>
			<table class="widefat striped" style="max-width:1100px">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Component', 'mcp-auditor' ); ?></th>
						<th><?php echo esc_html__( 'Status', 'mcp-auditor' ); ?></th>
						<th><?php echo esc_html__( 'Details', 'mcp-auditor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $status_rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['component'] ); ?></td>
							<td><?php echo esc_html( $row['status'] ); ?></td>
							<td><?php echo esc_html( $row['details'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Recent Reports', 'mcp-auditor' ); ?></h2>
			<?php if ( empty( $latest_reports ) ) : ?>
				<p><?php echo esc_html__( 'No reports have been generated yet.', 'mcp-auditor' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="max-width:1100px">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Report', 'mcp-auditor' ); ?></th>
							<th><?php echo esc_html__( 'Verdict', 'mcp-auditor' ); ?></th>
							<th><?php echo esc_html__( 'Findings', 'mcp-auditor' ); ?></th>
							<th><?php echo esc_html__( 'Created', 'mcp-auditor' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $latest_reports as $report ) : ?>
							<?php $summary = $this->get_report_summary( $report->ID ); ?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $report->ID, 'raw' ) ); ?>"><?php echo esc_html( get_the_title( $report ) ); ?></a></td>
								<td><?php echo esc_html( $summary['verdict'] ); ?></td>
								<td><?php echo esc_html( $summary['counts'] ); ?></td>
								<td><?php echo esc_html( get_the_date( '', $report ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_run_audit_page(): void {
		$this->assert_manage_options();

		$artifact_choices = $this->get_artifact_choices();
		$form_state       = $this->get_default_run_form_state( $artifact_choices );
		$result           = null;

		if ( 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['mcp_auditor_run_audit'] ) ) {
			check_admin_referer( 'mcp_auditor_run_audit' );
			$form_state = $this->get_submitted_run_form_state( $artifact_choices );
			$artifact   = $this->parse_artifact_choice( $form_state['artifact'] );

			if ( '' === $artifact['slug'] ) {
				$result = array(
					'status'  => 'error',
					'summary' => array(
						'text' => __( 'Please choose an installed plugin or theme to audit.', 'mcp-auditor' ),
					),
				);
			} else {
				$result = $this->audit_service->run_audit(
					$artifact['slug'],
					$artifact['type'],
					$form_state['checks'],
					$form_state['use_ai'],
					$form_state['persist_report']
				);
			}
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Run Audit', 'mcp-auditor' ); ?></h1>
			<p><?php echo esc_html__( 'Launch a plugin or theme audit directly from WordPress.', 'mcp-auditor' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::RUN_AUDIT_PAGE ) ); ?>" style="max-width:1100px;">
				<?php wp_nonce_field( 'mcp_auditor_run_audit' ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="mcp-auditor-artifact"><?php echo esc_html__( 'Artifact', 'mcp-auditor' ); ?></label></th>
							<td>
								<select id="mcp-auditor-artifact" name="artifact" class="regular-text">
									<?php foreach ( $artifact_choices as $choice ) : ?>
										<option value="<?php echo esc_attr( $choice['value'] ); ?>" <?php selected( $form_state['artifact'], $choice['value'] ); ?>>
											<?php echo esc_html( $choice['label'] ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Checks', 'mcp-auditor' ); ?></th>
							<td>
								<fieldset>
									<?php foreach ( $this->get_available_checks() as $check => $label ) : ?>
										<label style="display:inline-block;min-width:220px;margin:0 16px 10px 0;">
											<input type="checkbox" name="checks[]" value="<?php echo esc_attr( $check ); ?>" <?php checked( in_array( $check, $form_state['checks'], true ) ); ?>>
											<?php echo esc_html( $label ); ?>
										</label>
									<?php endforeach; ?>
								</fieldset>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Options', 'mcp-auditor' ); ?></th>
							<td>
								<label style="display:block;margin-bottom:10px;">
									<input type="checkbox" name="use_ai" value="1" <?php checked( $form_state['use_ai'] ); ?>>
									<?php echo esc_html__( 'Use OpenAI-assisted analysis when a key is configured', 'mcp-auditor' ); ?>
								</label>
								<label style="display:block;">
									<input type="checkbox" name="persist_report" value="1" <?php checked( $form_state['persist_report'] ); ?>>
									<?php echo esc_html__( 'Save the completed report in WordPress', 'mcp-auditor' ); ?>
								</label>
							</td>
						</tr>
					</tbody>
				</table>
				<p>
					<input type="hidden" name="mcp_auditor_run_audit" value="1">
					<?php submit_button( __( 'Run Audit', 'mcp-auditor' ), 'primary', 'submit', false ); ?>
				</p>
			</form>

			<?php if ( ! empty( $result ) ) : ?>
				<?php $this->render_run_result( $result ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_settings_page(): void {
		$this->assert_manage_options();

		$settings = $this->settings->get();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WP Auditor Settings', 'mcp-auditor' ); ?></h1>
			<p><?php echo esc_html__( 'Store an OpenAI API key in WordPress, or leave these fields empty if you prefer to manage configuration through wp-config.php or environment variables.', 'mcp-auditor' ); ?></p>
			<?php settings_errors(); ?>
			<form method="post" action="options.php" style="max-width:980px;">
				<?php settings_fields( Settings::OPTION_GROUP ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="mcp-auditor-openai-api-key"><?php echo esc_html__( 'OpenAI API key', 'mcp-auditor' ); ?></label></th>
							<td>
								<input type="password" id="mcp-auditor-openai-api-key" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[openai_api_key]" value="" class="regular-text" autocomplete="new-password">
								<p class="description"><?php echo esc_html__( 'Leave this blank to keep the current saved key. The key stored here is only used when no OPENAI_API_KEY constant or environment variable is set.', 'mcp-auditor' ); ?></p>
								<?php if ( $this->settings->has_saved_api_key() ) : ?>
									<p class="description">
										<?php
										printf(
											/* translators: %s: masked API key. */
											esc_html__( 'Saved key: %s', 'mcp-auditor' ),
											esc_html( $this->settings->get_masked_saved_api_key() )
										);
										?>
									</p>
								<?php endif; ?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: configuration source label. */
										esc_html__( 'Current effective source: %s', 'mcp-auditor' ),
										esc_html( $this->settings->get_source_label( $this->settings->get_api_key_source() ) )
									);
									?>
								</p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[clear_openai_api_key]" value="1">
									<?php echo esc_html__( 'Remove the saved API key from plugin settings', 'mcp-auditor' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mcp-auditor-openai-model"><?php echo esc_html__( 'Default model', 'mcp-auditor' ); ?></label></th>
							<td>
								<input type="text" id="mcp-auditor-openai-model" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[openai_model]" value="<?php echo esc_attr( (string) $settings['openai_model'] ); ?>" class="regular-text">
								<p class="description">
									<?php
									printf(
										/* translators: 1: current model, 2: source label. */
										esc_html__( 'Effective value: %1$s from %2$s.', 'mcp-auditor' ),
										esc_html( $this->settings->get_model() ),
										esc_html( $this->settings->get_source_label( $this->settings->get_model_source() ) )
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mcp-auditor-reasoning-effort"><?php echo esc_html__( 'Reasoning effort', 'mcp-auditor' ); ?></label></th>
							<td>
								<select id="mcp-auditor-reasoning-effort" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[reasoning_effort]">
									<?php foreach ( array( 'minimal', 'low', 'medium', 'high' ) as $effort ) : ?>
										<option value="<?php echo esc_attr( $effort ); ?>" <?php selected( $settings['reasoning_effort'], $effort ); ?>>
											<?php echo esc_html( ucfirst( $effort ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description">
									<?php
									printf(
										/* translators: 1: current reasoning effort, 2: source label. */
										esc_html__( 'Effective value: %1$s from %2$s.', 'mcp-auditor' ),
										esc_html( $this->settings->get_reasoning_effort() ),
										esc_html( $this->settings->get_source_label( $this->settings->get_reasoning_effort_source() ) )
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mcp-auditor-ai-file-limit"><?php echo esc_html__( 'AI file limit', 'mcp-auditor' ); ?></label></th>
							<td>
								<input type="number" id="mcp-auditor-ai-file-limit" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[ai_file_limit]" value="<?php echo esc_attr( (string) $settings['ai_file_limit'] ); ?>" min="1" max="20" class="small-text">
								<p class="description">
									<?php
									printf(
										/* translators: 1: current file limit, 2: source label. */
										esc_html__( 'Effective value: %1$d from %2$s.', 'mcp-auditor' ),
										$this->settings->get_file_limit(),
										esc_html( $this->settings->get_source_label( $this->settings->get_file_limit_source() ) )
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="mcp-auditor-ai-char-limit"><?php echo esc_html__( 'AI character limit', 'mcp-auditor' ); ?></label></th>
							<td>
								<input type="number" id="mcp-auditor-ai-char-limit" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[ai_char_limit]" value="<?php echo esc_attr( (string) $settings['ai_char_limit'] ); ?>" min="2000" max="40000" step="500" class="small-text">
								<p class="description">
									<?php
									printf(
										/* translators: 1: current character limit, 2: source label. */
										esc_html__( 'Effective value: %1$d from %2$s.', 'mcp-auditor' ),
										$this->settings->get_char_limit(),
										esc_html( $this->settings->get_source_label( $this->settings->get_char_limit_source() ) )
									);
									?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Save Settings', 'mcp-auditor' ) ); ?>
			</form>
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

	private function assert_manage_options(): void {
		if ( current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_die( esc_html__( 'You do not have permission to access WP Auditor.', 'mcp-auditor' ) );
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function get_status_rows(): array {
		$api_key_detail = $this->settings->get_source_label( $this->settings->get_api_key_source() );

		if ( $this->settings->has_saved_api_key() ) {
			$api_key_detail .= ': ' . $this->settings->get_masked_saved_api_key();
		}

		return array(
			array(
				'component' => __( 'Abilities API', 'mcp-auditor' ),
				'status'    => function_exists( 'wp_register_ability' ) ? __( 'Ready', 'mcp-auditor' ) : __( 'Missing', 'mcp-auditor' ),
				'details'   => 'wp_register_ability()',
			),
			array(
				'component' => __( 'OpenAI key', 'mcp-auditor' ),
				'status'    => '' !== $this->settings->get_api_key() ? __( 'Configured', 'mcp-auditor' ) : __( 'Not configured', 'mcp-auditor' ),
				'details'   => $api_key_detail,
			),
			array(
				'component' => __( 'Default model', 'mcp-auditor' ),
				'status'    => $this->settings->get_model(),
				'details'   => $this->settings->get_source_label( $this->settings->get_model_source() ),
			),
			array(
				'component' => __( 'Reasoning effort', 'mcp-auditor' ),
				'status'    => $this->settings->get_reasoning_effort(),
				'details'   => $this->settings->get_source_label( $this->settings->get_reasoning_effort_source() ),
			),
			array(
				'component' => __( 'AI file limit', 'mcp-auditor' ),
				'status'    => (string) $this->settings->get_file_limit(),
				'details'   => $this->settings->get_source_label( $this->settings->get_file_limit_source() ),
			),
		);
	}

	/**
	 * @return array<int,\WP_Post>
	 */
	private function get_recent_reports( int $limit ): array {
		$reports = get_posts(
			array(
				'post_type'      => ReportRepository::POST_TYPE,
				'post_status'    => 'private',
				'posts_per_page' => $limit,
			)
		);

		return is_array( $reports ) ? $reports : array();
	}

	/**
	 * @return array<string,string>
	 */
	private function get_report_summary( int $post_id ): array {
		$payload = get_post_meta( $post_id, '_mcp_auditor_payload', true );

		if ( ! is_string( $payload ) || '' === $payload ) {
			return array(
				'verdict' => __( 'Unknown', 'mcp-auditor' ),
				'counts'  => __( 'Unavailable', 'mcp-auditor' ),
			);
		}

		$decoded = json_decode( $payload, true );
		$totals  = isset( $decoded['summary']['totals'] ) && is_array( $decoded['summary']['totals'] ) ? $decoded['summary']['totals'] : array();

		return array(
			'verdict' => isset( $decoded['verdict']['label'] ) ? (string) $decoded['verdict']['label'] : __( 'Unknown', 'mcp-auditor' ),
			'counts'  => sprintf(
				'%d high / %d medium / %d low',
				(int) ( $totals['high'] ?? 0 ),
				(int) ( $totals['medium'] ?? 0 ),
				(int) ( $totals['low'] ?? 0 )
			),
		);
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	private function get_artifact_choices(): array {
		$choices = array();
		$plugins = $this->handle_list_plugins();
		$themes  = $this->handle_list_themes();

		foreach ( $plugins['plugins'] ?? array() as $plugin ) {
			$name          = isset( $plugin['name'] ) ? (string) $plugin['name'] : '';
			$accepted_slug = isset( $plugin['accepted_slug'] ) ? (string) $plugin['accepted_slug'] : '';

			if ( '' !== $accepted_slug ) {
				$choices[] = array(
					'value' => 'plugin|' . $accepted_slug,
					'label' => sprintf( __( 'Plugin: %1$s (%2$s)', 'mcp-auditor' ), $name, $accepted_slug ),
				);
			}
		}

		foreach ( $themes['themes'] ?? array() as $theme ) {
			$name          = isset( $theme['name'] ) ? (string) $theme['name'] : '';
			$accepted_slug = isset( $theme['accepted_slug'] ) ? (string) $theme['accepted_slug'] : '';

			if ( '' !== $accepted_slug ) {
				$choices[] = array(
					'value' => 'theme|' . $accepted_slug,
					'label' => sprintf( __( 'Theme: %1$s (%2$s)', 'mcp-auditor' ), $name, $accepted_slug ),
				);
			}
		}

		usort(
			$choices,
			static function ( array $left, array $right ): int {
				return strcmp( $left['label'], $right['label'] );
			}
		);

		return $choices;
	}

	/**
	 * @return array<string,string>
	 */
	private function get_available_checks(): array {
		return array(
			'licensing'     => __( 'Licensing', 'mcp-auditor' ),
			'package'       => __( 'Package', 'mcp-auditor' ),
			'wordpress'     => __( 'WordPress compliance', 'mcp-auditor' ),
			'security'      => __( 'Security', 'mcp-auditor' ),
			'privacy'       => __( 'Privacy', 'mcp-auditor' ),
			'uninstall'     => __( 'Uninstall', 'mcp-auditor' ),
			'dependencies'  => __( 'Dependencies', 'mcp-auditor' ),
			'performance'   => __( 'Performance', 'mcp-auditor' ),
			'quality'       => __( 'Quality', 'mcp-auditor' ),
			'runtime'       => __( 'Runtime smoke test', 'mcp-auditor' ),
			'accessibility' => __( 'Accessibility (themes only)', 'mcp-auditor' ),
		);
	}

	/**
	 * @param array<int,array<string,string>> $artifact_choices
	 * @return array<string,mixed>
	 */
	private function get_default_run_form_state( array $artifact_choices ): array {
		return array(
			'artifact'       => ! empty( $artifact_choices ) ? $artifact_choices[0]['value'] : '',
			'checks'         => array_keys( $this->get_available_checks() ),
			'use_ai'         => false,
			'persist_report' => true,
		);
	}

	/**
	 * @param array<int,array<string,string>> $artifact_choices
	 * @return array<string,mixed>
	 */
	private function get_submitted_run_form_state( array $artifact_choices ): array {
		$allowed_artifacts = array_column( $artifact_choices, 'value' );
		$artifact          = isset( $_POST['artifact'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['artifact'] ) ) : '';
		$checks_input      = isset( $_POST['checks'] ) && is_array( $_POST['checks'] ) ? wp_unslash( $_POST['checks'] ) : array();
		$checks            = array_values(
			array_intersect(
				array_keys( $this->get_available_checks() ),
				array_map( 'sanitize_key', array_map( 'strval', $checks_input ) )
			)
		);

		if ( ! in_array( $artifact, $allowed_artifacts, true ) ) {
			$artifact = '';
		}

		return array(
			'artifact'       => $artifact,
			'checks'         => $checks,
			'use_ai'         => ! empty( $_POST['use_ai'] ),
			'persist_report' => ! empty( $_POST['persist_report'] ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function parse_artifact_choice( string $artifact_choice ): array {
		$parts = explode( '|', $artifact_choice, 2 );

		return array(
			'type' => isset( $parts[0] ) && in_array( $parts[0], array( 'plugin', 'theme' ), true ) ? $parts[0] : 'plugin',
			'slug' => isset( $parts[1] ) ? sanitize_text_field( $parts[1] ) : '',
		);
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private function render_run_result( array $result ): void {
		$is_error = 'error' === ( $result['status'] ?? '' );
		$summary  = isset( $result['summary']['text'] ) ? (string) $result['summary']['text'] : __( 'Audit completed.', 'mcp-auditor' );
		$totals   = isset( $result['summary']['totals'] ) && is_array( $result['summary']['totals'] ) ? $result['summary']['totals'] : array();
		$issues   = isset( $result['issues'] ) && is_array( $result['issues'] ) ? $result['issues'] : array();
		?>
		<div class="notice <?php echo esc_attr( $is_error ? 'notice-error' : 'notice-success' ); ?>" style="margin:18px 0 12px 0;">
			<p><?php echo esc_html( $summary ); ?></p>
		</div>
		<?php if ( $is_error ) : ?>
			<?php return; ?>
		<?php endif; ?>

		<p>
			<strong><?php echo esc_html__( 'Verdict:', 'mcp-auditor' ); ?></strong>
			<?php echo esc_html( (string) ( $result['verdict']['label'] ?? __( 'Completed', 'mcp-auditor' ) ) ); ?>
			|
			<strong><?php echo esc_html__( 'High:', 'mcp-auditor' ); ?></strong>
			<?php echo esc_html( (string) ( $totals['high'] ?? 0 ) ); ?>
			|
			<strong><?php echo esc_html__( 'Medium:', 'mcp-auditor' ); ?></strong>
			<?php echo esc_html( (string) ( $totals['medium'] ?? 0 ) ); ?>
			|
			<strong><?php echo esc_html__( 'Low:', 'mcp-auditor' ); ?></strong>
			<?php echo esc_html( (string) ( $totals['low'] ?? 0 ) ); ?>
		</p>

		<?php if ( ! empty( $result['report_edit_url'] ) ) : ?>
			<p><a class="button button-secondary" href="<?php echo esc_url( (string) $result['report_edit_url'] ); ?>"><?php echo esc_html__( 'Open Saved Report', 'mcp-auditor' ); ?></a></p>
		<?php endif; ?>

		<?php if ( ! empty( $result['email']['body'] ) ) : ?>
			<h2><?php echo esc_html__( 'Review Email Preview', 'mcp-auditor' ); ?></h2>
			<textarea readonly rows="18" style="width:100%;max-width:1100px;font-family:monospace;"><?php echo esc_textarea( (string) $result['email']['body'] ); ?></textarea>
		<?php endif; ?>

		<h2><?php echo esc_html__( 'Findings', 'mcp-auditor' ); ?></h2>
		<?php if ( empty( $issues ) ) : ?>
			<p><?php echo esc_html__( 'No issues were detected by the selected checks.', 'mcp-auditor' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1100px">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Severity', 'mcp-auditor' ); ?></th>
						<th><?php echo esc_html__( 'Category', 'mcp-auditor' ); ?></th>
						<th><?php echo esc_html__( 'Where', 'mcp-auditor' ); ?></th>
						<th><?php echo esc_html__( 'Issue', 'mcp-auditor' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $issues as $issue ) : ?>
						<tr>
							<td><?php echo esc_html( ucfirst( (string) ( $issue['severity'] ?? 'low' ) ) ); ?></td>
							<td><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) ( $issue['category'] ?? '' ) ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( $issue['location'] ?? '' ) ); ?></td>
							<td>
								<strong><?php echo esc_html( (string) ( $issue['title'] ?? '' ) ); ?></strong>
								<?php if ( ! empty( $issue['detail'] ) ) : ?>
									<div style="margin-top:6px;color:#50575e;"><?php echo esc_html( (string) $issue['detail'] ); ?></div>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}
}
