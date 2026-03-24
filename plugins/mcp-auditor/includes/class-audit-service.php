<?php

namespace MCPAuditor;

defined( 'ABSPATH' ) || exit;

class AuditService {
	/**
	 * @var ReportRepository
	 */
	private $report_repository;

	/**
	 * @var OpenAIClient
	 */
	private $openai_client;

	public function __construct( ReportRepository $report_repository, OpenAIClient $openai_client ) {
		$this->report_repository = $report_repository;
		$this->openai_client     = $openai_client;
	}

	/**
	 * @param array<int,string>        $checks
	 * @param array<string,mixed>|null $external_result
	 * @return array<string,mixed>
	 */
	public function run_audit( string $slug, string $type, array $checks = array(), bool $use_ai = false, bool $persist_report = true, array $external_result = array() ): array {
		$type     = in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : 'plugin';
		$slug     = trim( $slug );
		$checks   = $this->normalize_checks( $checks, $type );
		$artifact = $this->resolve_artifact( $slug, $type );

		if ( is_wp_error( $artifact ) ) {
			return array(
				'status'  => 'error',
				'summary' => array(
					'text' => $artifact->get_error_message(),
				),
				'issues'  => array(),
			);
		}

		$files            = $this->collect_files( $artifact );
		$issues           = $this->run_heuristics( $artifact, $files, $checks );
		$notes            = array();
		$analysis_details = array(
			'runtime' => array(
				'status' => in_array( 'runtime', $checks, true ) ? 'not_run' : 'skipped',
				'reason' => in_array( 'runtime', $checks, true ) ? '' : 'Runtime checks were not requested.',
			),
			'tooling' => array(),
		);

		if ( in_array( 'runtime', $checks, true ) ) {
			$runtime_result = $this->check_runtime_behaviour( $artifact );
			$issues         = array_merge( $issues, $runtime_result['issues'] );
			$notes          = array_merge( $notes, $runtime_result['notes'] );
			$analysis_details['runtime'] = $runtime_result['analysis'];
		}

		if ( $use_ai ) {
			if ( $this->openai_client->is_configured() ) {
				$ai_result = $this->openai_client->analyze_candidates( $artifact, $files, $checks );
				$issues    = array_merge( $issues, $ai_result['issues'] );
				$notes     = array_merge( $notes, $ai_result['notes'] );
			} else {
				$notes[] = __( 'OpenAI analysis was requested, but OPENAI_API_KEY is not configured.', 'mcp-auditor' );
			}
		}

		if ( ! empty( $external_result ) ) {
			$normalized_external = $this->normalize_external_result( $external_result );
			$issues              = array_merge( $issues, $normalized_external['issues'] );
			$notes               = array_merge( $notes, $normalized_external['notes'] );
			$analysis_details    = array_replace_recursive( $analysis_details, $normalized_external['analysis'] );
		}

		$issues = $this->deduplicate_issues( $issues );
		$report = $this->build_report( $artifact, $checks, $issues, $notes, $use_ai, count( $files ), $analysis_details, $files );

		if ( $persist_report && 'completed' === $report['status'] ) {
			$report_id = $this->report_repository->save( $report );

			if ( $report_id > 0 ) {
				$report['report_post_id']  = $report_id;
				$report['report_edit_url'] = get_edit_post_link( $report_id, 'raw' );
			}
		}

		return $report;
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	public function inspect_artifact( string $slug, string $type ) {
		$type     = in_array( $type, array( 'plugin', 'theme' ), true ) ? $type : 'plugin';
		$artifact = $this->resolve_artifact( trim( $slug ), $type );

		if ( is_wp_error( $artifact ) ) {
			return $artifact;
		}

		return array(
			'type'          => $artifact['type'],
			'requested'     => $artifact['slug'],
			'accepted_slug' => $artifact['accepted_slug'],
			'display_name'  => $artifact['display_name'],
			'root_path'     => wp_normalize_path( $artifact['root_path'] ),
			'entry_file'    => wp_normalize_path( $artifact['entry_file'] ),
			'readme_path'   => wp_normalize_path( $artifact['readme_path'] ),
			'metadata'      => $artifact['metadata'],
		);
	}

	/**
	 * @param array<int,string> $checks
	 * @return array<int,string>
	 */
	private function normalize_checks( array $checks, string $type ): array {
		$allowed = array(
			'licensing',
			'package',
			'wordpress',
			'security',
			'privacy',
			'uninstall',
			'dependencies',
			'performance',
			'quality',
			'runtime',
		);

		if ( 'theme' === $type ) {
			$allowed[] = 'accessibility';
		}

		$normalized = array();

		foreach ( array_map( 'sanitize_key', $checks ) as $check ) {
			if ( 'code_quality' === $check ) {
				$normalized[] = 'package';
				$normalized[] = 'quality';
				continue;
			}

			if ( in_array( $check, $allowed, true ) ) {
				$normalized[] = $check;
			}
		}

		$normalized = array_values( array_unique( $normalized ) );

		if ( empty( $normalized ) ) {
			$normalized = $allowed;
		}

		return $normalized;
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function resolve_artifact( string $slug, string $type ) {
		if ( 'plugin' === $type ) {
			return $this->resolve_plugin( $slug );
		}

		return $this->resolve_theme( $slug );
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function resolve_plugin( string $slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugins = get_plugins();

		foreach ( $plugins as $relative_path => $headers ) {
			$directory_slug = dirname( $relative_path );
			$main_basename  = basename( $relative_path, '.php' );
			$text_domain    = isset( $headers['TextDomain'] ) ? sanitize_key( (string) $headers['TextDomain'] ) : '';
			$name_slug      = isset( $headers['Name'] ) ? sanitize_title( (string) $headers['Name'] ) : '';
			$candidates     = array_filter(
				array_unique(
					array(
						$relative_path,
						'.' !== $directory_slug ? $directory_slug : '',
						$main_basename,
						$text_domain,
						$name_slug,
					)
				)
			);

			if ( ! in_array( $slug, $candidates, true ) ) {
				continue;
			}

			$entry_file = trailingslashit( WP_PLUGIN_DIR ) . $relative_path;

			return array(
				'type'          => 'plugin',
				'slug'          => $slug,
				'display_name'  => $headers['Name'] ?? $slug,
				'root_path'     => '.' !== $directory_slug ? trailingslashit( WP_PLUGIN_DIR ) . $directory_slug : dirname( $entry_file ),
				'entry_file'    => $entry_file,
				'single_file'   => '.' === $directory_slug,
				'metadata'      => array(
					'version'      => $headers['Version'] ?? '',
					'license'      => $headers['License'] ?? '',
					'text_domain'  => $headers['TextDomain'] ?? '',
					'description'  => $headers['Description'] ?? '',
					'requires_php' => $headers['RequiresPHP'] ?? '',
					'requires_wp'  => $headers['RequiresWP'] ?? '',
				),
				'readme_path'   => '.' !== $directory_slug ? trailingslashit( WP_PLUGIN_DIR ) . $directory_slug . '/readme.txt' : trailingslashit( WP_PLUGIN_DIR ) . 'readme.txt',
				'accepted_slug' => $relative_path,
			);
		}

		return new \WP_Error(
			'mcp_auditor_missing_plugin',
			sprintf(
				/* translators: %s: plugin slug. */
				__( 'No installed plugin matched slug "%s". Use the installed-plugins MCP resource or WP-CLI to inspect available slugs.', 'mcp-auditor' ),
				$slug
			)
		);
	}

	/**
	 * @return array<string,mixed>|\WP_Error
	 */
	private function resolve_theme( string $slug ) {
		$themes = wp_get_themes();

		foreach ( $themes as $stylesheet => $theme ) {
			$candidates = array_filter(
				array_unique(
					array(
						$stylesheet,
						$theme->get_template(),
						sanitize_title( $theme->get( 'Name' ) ),
						sanitize_key( (string) $theme->get( 'TextDomain' ) ),
					)
				)
			);

			if ( ! in_array( $slug, $candidates, true ) ) {
				continue;
			}

			return array(
				'type'          => 'theme',
				'slug'          => $slug,
				'display_name'  => $theme->get( 'Name' ),
				'root_path'     => $theme->get_stylesheet_directory(),
				'entry_file'    => trailingslashit( $theme->get_stylesheet_directory() ) . 'style.css',
				'single_file'   => false,
				'metadata'      => array(
					'version'      => $theme->get( 'Version' ),
					'license'      => $theme->get( 'License' ),
					'text_domain'  => $theme->get( 'TextDomain' ),
					'description'  => $theme->get( 'Description' ),
					'requires_php' => '',
					'requires_wp'  => '',
				),
				'readme_path'   => trailingslashit( $theme->get_stylesheet_directory() ) . 'README.md',
				'accepted_slug' => $stylesheet,
			);
		}

		return new \WP_Error(
			'mcp_auditor_missing_theme',
			sprintf(
				/* translators: %s: theme slug. */
				__( 'No installed theme matched slug "%s". Use the installed-themes MCP resource or WP-CLI to inspect available slugs.', 'mcp-auditor' ),
				$slug
			)
		);
	}

	/**
	 * @param array<string,mixed> $artifact
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_files( array $artifact ): array {
		$text_extensions = array( 'php', 'js', 'css', 'scss', 'less', 'html', 'twig', 'txt', 'md', 'json', 'yml', 'yaml', 'xml', 'svg', 'map', 'lock' );
		$files           = array();

		if ( ! empty( $artifact['single_file'] ) ) {
			$files[] = $this->build_file_entry( $artifact['entry_file'], basename( $artifact['entry_file'] ), true, $text_extensions );
			return $files;
		}

		$root_path = untrailingslashit( wp_normalize_path( $artifact['root_path'] ) );
		$iterator  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$artifact['root_path'],
				\FilesystemIterator::SKIP_DOTS
			)
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$absolute_path = wp_normalize_path( $file->getPathname() );
			$relative_path = ltrim( substr( $absolute_path, strlen( $root_path ) ), '/' );
			$files[]       = $this->build_file_entry(
				$absolute_path,
				$relative_path,
				wp_normalize_path( $absolute_path ) === wp_normalize_path( $artifact['entry_file'] ),
				$text_extensions
			);
		}

		usort(
			$files,
			static function ( array $left, array $right ): int {
				return strcmp( (string) $left['relative_path'], (string) $right['relative_path'] );
			}
		);

		return $files;
	}

	/**
	 * @param array<int,string> $text_extensions
	 * @return array<string,mixed>
	 */
	private function build_file_entry( string $absolute_path, string $relative_path, bool $is_entry, array $text_extensions ): array {
		$extension = strtolower( pathinfo( $absolute_path, PATHINFO_EXTENSION ) );

		return array(
			'path'          => wp_normalize_path( $absolute_path ),
			'relative_path' => ltrim( wp_normalize_path( $relative_path ), '/' ),
			'extension'     => $extension,
			'size'          => file_exists( $absolute_path ) ? (int) filesize( $absolute_path ) : 0,
			'is_entry'      => $is_entry,
			'is_text'       => in_array( $extension, $text_extensions, true ),
		);
	}

	/**
	 * @param array<string,mixed>            $artifact
	 * @param array<int,array<string,mixed>> $files
	 * @param array<int,string>              $checks
	 * @return array<int,array<string,mixed>>
	 */
	private function run_heuristics( array $artifact, array $files, array $checks ): array {
		$issues = array();

		if ( in_array( 'licensing', $checks, true ) ) {
			$issues = array_merge( $issues, $this->check_licensing( $artifact ) );
		}

		if ( in_array( 'package', $checks, true ) ) {
			$issues = array_merge( $issues, $this->check_package_contents( $artifact, $files ) );
		}

		if ( in_array( 'wordpress', $checks, true ) ) {
			$issues = array_merge( $issues, $this->check_wordpress_patterns( $artifact, $files ) );
		}

		if ( in_array( 'uninstall', $checks, true ) && 'plugin' === $artifact['type'] ) {
			$issues = array_merge( $issues, $this->check_uninstall( $files ) );
		}

		if ( in_array( 'security', $checks, true ) ) {
			$issues = array_merge( $issues, $this->check_security_patterns( $artifact, $files ) );
		}

		if ( in_array( 'privacy', $checks, true ) ) {
			$issues = array_merge( $issues, $this->check_privacy_patterns( $files ) );
		}

		if ( in_array( 'dependencies', $checks, true ) ) {
			$issues = array_merge( $issues, $this->check_dependency_manifests( $files ) );
		}

		if ( in_array( 'performance', $checks, true ) ) {
			$issues = array_merge( $issues, $this->check_performance_patterns( $files ) );
		}

		if ( in_array( 'quality', $checks, true ) ) {
			$issues = array_merge( $issues, $this->check_quality( $artifact, $files ) );
		}

		if ( in_array( 'accessibility', $checks, true ) && 'theme' === $artifact['type'] ) {
			$issues = array_merge( $issues, $this->check_accessibility( $files ) );
		}

		return $issues;
	}

	/**
	 * @param array<string,mixed> $artifact
	 * @return array<int,array<string,mixed>>
	 */
	private function check_licensing( array $artifact ): array {
		$issues  = array();
		$license = strtolower( (string) ( $artifact['metadata']['license'] ?? '' ) );

		if ( '' === $license ) {
			$issues[] = $this->build_issue(
				'medium',
				'licensing',
				basename( $artifact['entry_file'] ),
				1,
				__( 'Missing license metadata', 'mcp-auditor' ),
				__( 'The artifact header does not declare a license.', 'mcp-auditor' ),
				__( 'Declare a GPL-compatible license in the main plugin file or theme stylesheet header.', 'mcp-auditor' ),
				'heuristic'
			);
		} elseif ( false === strpos( $license, 'gpl' ) ) {
			$issues[] = $this->build_issue(
				'high',
				'licensing',
				basename( $artifact['entry_file'] ),
				1,
				__( 'Non-GPL license detected', 'mcp-auditor' ),
				__( 'The declared license does not appear to be GPL-compatible.', 'mcp-auditor' ),
				__( 'Review the license header and ensure the distributed code is GPL-compatible before submission.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		if ( empty( $artifact['metadata']['text_domain'] ) ) {
			$issues[] = $this->build_issue(
				'low',
				'wordpress',
				basename( $artifact['entry_file'] ),
				1,
				__( 'Missing text domain metadata', 'mcp-auditor' ),
				__( 'The main artifact header does not declare a text domain.', 'mcp-auditor' ),
				__( 'Set a text domain that matches the plugin or theme slug to improve i18n compatibility.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		if ( ! file_exists( $artifact['readme_path'] ) ) {
			$issues[] = $this->build_issue(
				'low',
				'package',
				basename( $artifact['entry_file'] ),
				null,
				__( 'Readme file not found', 'mcp-auditor' ),
				__( 'The expected readme file was not found in the artifact root.', 'mcp-auditor' ),
				__( 'Add a readme file with installation, changelog, and licensing details.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		return $issues;
	}

	/**
	 * @param array<string,mixed>            $artifact
	 * @param array<int,array<string,mixed>> $files
	 * @return array<int,array<string,mixed>>
	 */
	private function check_package_contents( array $artifact, array $files ): array {
		$issues            = array();
		$total_size        = 0;
		$has_composer      = false;
		$has_composer_lock = false;
		$has_package       = false;
		$has_package_lock  = false;

		foreach ( $files as $file ) {
			$relative_path = (string) $file['relative_path'];
			$total_size   += (int) $file['size'];

			if ( 'composer.json' === $relative_path ) {
				$has_composer = true;
			}

			if ( 'composer.lock' === $relative_path ) {
				$has_composer_lock = true;
			}

			if ( 'package.json' === $relative_path ) {
				$has_package = true;
			}

			if ( in_array( $relative_path, array( 'package-lock.json', 'npm-shrinkwrap.json', 'pnpm-lock.yaml', 'yarn.lock' ), true ) ) {
				$has_package_lock = true;
			}

			if ( $this->is_package_noise( $relative_path ) ) {
				$issues[] = $this->build_issue(
					'medium',
					'package',
					$relative_path,
					null,
					__( 'Unexpected packaging artifact found', 'mcp-auditor' ),
					__( 'The package contains a file or directory that usually should not be shipped in a reviewable release package.', 'mcp-auditor' ),
					__( 'Remove local-only files such as backups, editor settings, logs, archives, and secrets before packaging the release artifact.', 'mcp-auditor' ),
					'heuristic'
				);
			}

			if ( in_array( $file['extension'], array( 'exe', 'dll', 'so', 'dylib', 'jar', 'class' ), true ) ) {
				$issues[] = $this->build_issue(
					'high',
					'package',
					$relative_path,
					null,
					__( 'Compiled binary found in package', 'mcp-auditor' ),
					__( 'The package includes a compiled binary or bytecode artifact that deserves manual review before distribution.', 'mcp-auditor' ),
					__( 'Remove unexpected binaries or document exactly why they are needed and how they are built from source.', 'mcp-auditor' ),
					'heuristic'
				);
			}

			if ( (int) $file['size'] > 1024 * 1024 ) {
				$issues[] = $this->build_issue(
					'low',
					'package',
					$relative_path,
					null,
					__( 'Large packaged file', 'mcp-auditor' ),
					__( 'A single packaged file is larger than 1 MB, which can make review and distribution heavier than expected.', 'mcp-auditor' ),
					__( 'Review whether the file needs to ship in the release package and keep built assets as small as practical.', 'mcp-auditor' ),
					'heuristic'
				);
			}
		}

		if ( $has_composer && ! $has_composer_lock ) {
			$issues[] = $this->build_issue(
				'low',
				'dependencies',
				'composer.json',
				null,
				__( 'Composer manifest without lock file', 'mcp-auditor' ),
				__( 'A Composer manifest exists but the corresponding lock file is missing, which makes dependency review less deterministic.', 'mcp-auditor' ),
				__( 'Commit composer.lock alongside composer.json when the package relies on Composer-managed dependencies.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		if ( $has_package && ! $has_package_lock ) {
			$issues[] = $this->build_issue(
				'low',
				'dependencies',
				'package.json',
				null,
				__( 'JavaScript manifest without lock file', 'mcp-auditor' ),
				__( 'A JavaScript package manifest exists but no lock file was found, which makes dependency review less reproducible.', 'mcp-auditor' ),
				__( 'Commit a lock file such as package-lock.json, pnpm-lock.yaml, or yarn.lock with the reviewed package.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		if ( $total_size > 5 * 1024 * 1024 ) {
			$issues[] = $this->build_issue(
				'low',
				'package',
				$artifact['accepted_slug'],
				null,
				__( 'Package size may be excessive', 'mcp-auditor' ),
				__( 'The overall package size exceeds 5 MB and may contain review overhead or unnecessary release artifacts.', 'mcp-auditor' ),
				__( 'Trim non-essential assets and generated files from the distributed package.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		if ( file_exists( $artifact['readme_path'] ) && ! empty( $artifact['metadata']['version'] ) ) {
			$readme = file_get_contents( $artifact['readme_path'] );

			if ( false !== $readme && preg_match( '/^Stable tag:\s*(.+)$/mi', $readme, $matches, PREG_OFFSET_CAPTURE ) ) {
				$stable_tag = trim( (string) $matches[1][0] );
				$line       = $this->line_from_offset( $readme, (int) $matches[0][1] );

				if ( '' !== $stable_tag && strtolower( $stable_tag ) !== strtolower( (string) $artifact['metadata']['version'] ) ) {
					$issues[] = $this->build_issue(
						'medium',
						'package',
						basename( $artifact['readme_path'] ),
						$line,
						__( 'Readme stable tag does not match the artifact version', 'mcp-auditor' ),
						__( 'The readme stable tag and the main artifact version do not match, which can confuse release review and packaging.', 'mcp-auditor' ),
						__( 'Update the readme stable tag or the main artifact version so they describe the same release.', 'mcp-auditor' ),
						'heuristic'
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * @param array<string,mixed>            $artifact
	 * @param array<int,array<string,mixed>> $files
	 * @return array<int,array<string,mixed>>
	 */
	private function check_wordpress_patterns( array $artifact, array $files ): array {
		$issues          = array();
		$expected_domain = 'plugin' === $artifact['type']
			? sanitize_title( basename( dirname( (string) $artifact['accepted_slug'] ) ) )
			: sanitize_title( (string) $artifact['accepted_slug'] );
		$current_domain  = sanitize_title( (string) ( $artifact['metadata']['text_domain'] ?? '' ) );

		if ( 'plugin' === $artifact['type'] ) {
			if ( empty( $artifact['metadata']['requires_php'] ) ) {
				$issues[] = $this->build_issue(
					'low',
					'wordpress',
					basename( $artifact['entry_file'] ),
					1,
					__( 'Requires PHP header is missing', 'mcp-auditor' ),
					__( 'The main plugin header does not declare the minimum supported PHP version.', 'mcp-auditor' ),
					__( 'Add a Requires PHP header so reviewers and site owners can see the supported runtime floor immediately.', 'mcp-auditor' ),
					'heuristic'
				);
			}

			if ( empty( $artifact['metadata']['requires_wp'] ) ) {
				$issues[] = $this->build_issue(
					'low',
					'wordpress',
					basename( $artifact['entry_file'] ),
					1,
					__( 'Requires at least header is missing', 'mcp-auditor' ),
					__( 'The main plugin header does not declare the minimum supported WordPress version.', 'mcp-auditor' ),
					__( 'Add a Requires at least header so release metadata is explicit about the supported WordPress floor.', 'mcp-auditor' ),
					'heuristic'
				);
			}
		}

		if ( '' !== $current_domain && '' !== $expected_domain && $current_domain !== $expected_domain ) {
			$issues[] = $this->build_issue(
				'low',
				'wordpress',
				basename( $artifact['entry_file'] ),
				1,
				__( 'Text domain does not match the expected slug', 'mcp-auditor' ),
				__( 'The declared text domain does not appear to match the package slug, which can complicate translation loading and review expectations.', 'mcp-auditor' ),
				__( 'Align the text domain with the plugin or theme slug unless there is a documented reason not to.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		foreach ( $files as $file ) {
			if ( 'php' !== $file['extension'] || $this->is_vendor_path( (string) $file['relative_path'] ) ) {
				continue;
			}

			$relative_path = (string) $file['relative_path'];
			$contents      = $this->get_text_contents( $file );

			if ( null === $contents ) {
				continue;
			}

			if ( 'uninstall.php' === strtolower( basename( $relative_path ) ) ) {
				continue;
			}

			$first_lines = preg_split( "/\r\n|\n|\r/", $contents );
			if ( false === $first_lines ) {
				continue;
			}

			$header_window = implode( "\n", array_slice( $first_lines, 0, 20 ) );

			if ( false === strpos( $header_window, 'ABSPATH' ) && false === strpos( $header_window, 'WP_UNINSTALL_PLUGIN' ) ) {
				$issues[] = $this->build_issue(
					'medium',
					'wordpress',
					$relative_path,
					1,
					__( 'Direct access guard not detected', 'mcp-auditor' ),
					__( 'The PHP file does not show an obvious ABSPATH or uninstall guard near the top of the file.', 'mcp-auditor' ),
					__( 'Add a standard direct-access guard near the top of the file to make the entrypoint expectations explicit.', 'mcp-auditor' ),
					'heuristic'
				);
			}
		}

		return $issues;
	}

	/**
	 * @param array<int,array<string,mixed>> $files
	 * @return array<int,array<string,mixed>>
	 */
	private function check_uninstall( array $files ): array {
		$issues          = array();
		$uninstall_found = false;
		$hook_found      = false;

		foreach ( $files as $file ) {
			if ( 'php' !== $file['extension'] || $this->is_vendor_path( (string) $file['relative_path'] ) ) {
				continue;
			}

			$contents = $this->get_text_contents( $file );
			if ( null === $contents ) {
				continue;
			}

			if ( 'uninstall.php' === strtolower( (string) $file['relative_path'] ) ) {
				$uninstall_found = true;
				if ( false === strpos( $contents, 'WP_UNINSTALL_PLUGIN' ) ) {
					$issues[] = $this->build_issue(
						'medium',
						'uninstall',
						(string) $file['relative_path'],
						1,
						__( 'Uninstall guard is missing', 'mcp-auditor' ),
						__( 'The uninstall entrypoint does not appear to guard against direct access with WP_UNINSTALL_PLUGIN.', 'mcp-auditor' ),
						__( 'Add the standard WP_UNINSTALL_PLUGIN guard before executing uninstall logic.', 'mcp-auditor' ),
						'heuristic'
					);
				}
			}

			if ( false !== strpos( $contents, 'register_uninstall_hook' ) ) {
				$hook_found = true;
			}
		}

		if ( ! $uninstall_found && ! $hook_found ) {
			$issues[] = $this->build_issue(
				'low',
				'uninstall',
				'plugin',
				null,
				__( 'No uninstall routine detected', 'mcp-auditor' ),
				__( 'Neither uninstall.php nor register_uninstall_hook() was found.', 'mcp-auditor' ),
				__( 'Implement an uninstall routine so options, scheduled events, and custom tables can be cleaned up safely.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		return $issues;
	}

	/**
	 * @param array<string,mixed>            $artifact
	 * @param array<int,array<string,mixed>> $files
	 * @return array<int,array<string,mixed>>
	 */
	private function check_security_patterns( array $artifact, array $files ): array {
		$issues       = array();
		$function_map = $this->build_php_function_map( $files );

		foreach ( $files as $file ) {
			if ( ! in_array( $file['extension'], array( 'php', 'js' ), true ) || $this->is_vendor_path( (string) $file['relative_path'] ) ) {
				continue;
			}

			$contents = $this->get_text_contents( $file );
			if ( null === $contents ) {
				continue;
			}

			$lines = preg_split( "/\r\n|\n|\r/", $contents );
			if ( false === $lines ) {
				continue;
			}

			foreach ( $lines as $index => $line ) {
				$line_number = $index + 1;
				$trimmed     = trim( $line );

				if ( preg_match( '/\$_(POST|GET|REQUEST|COOKIE|FILES)\s*\[/', $trimmed ) && ! preg_match( '/sanitize_|absint|intval|floatval|boolval|wp_unslash|filter_input|rest_sanitize_/', $trimmed ) ) {
					$issues[] = $this->build_issue(
						'medium',
						'security',
						(string) $file['relative_path'],
						$line_number,
						__( 'Raw superglobal access', 'mcp-auditor' ),
						__( 'Request data appears to be used without a nearby sanitization helper.', 'mcp-auditor' ),
						__( 'Sanitize request values before use, for example with sanitize_text_field(), absint(), or a more specific sanitizer.', 'mcp-auditor' ),
						'heuristic'
					);
				}

				if ( preg_match( '/\$wpdb->(query|get_var|get_row|get_results|get_col)\s*\(/', $trimmed ) && ! preg_match( '/prepare\s*\(/', $trimmed ) ) {
					$context = implode( "\n", array_slice( $lines, max( 0, $index - 1 ), 4 ) );
					if ( false === strpos( $context, 'prepare(' ) ) {
						$issues[] = $this->build_issue(
							'high',
							'security',
							(string) $file['relative_path'],
							$line_number,
							__( 'Direct database call without prepare()', 'mcp-auditor' ),
							__( 'A $wpdb query helper is used without evidence of parameter preparation nearby.', 'mcp-auditor' ),
							__( 'Wrap dynamic SQL in $wpdb->prepare() before executing it.', 'mcp-auditor' ),
							'heuristic'
						);
					}
				}

				if ( preg_match( '/\b(eval|base64_decode|gzinflate|shell_exec|exec|passthru|system|assert|unserialize)\s*\(/', $trimmed, $matches ) ) {
					$title = 'unserialize' === strtolower( (string) $matches[1] )
						? __( 'Potentially unsafe unserialize() usage detected', 'mcp-auditor' )
						: __( 'Dangerous function usage detected', 'mcp-auditor' );

					$issues[] = $this->build_issue(
						'high',
						'security',
						(string) $file['relative_path'],
						$line_number,
						$title,
						__( 'The file uses a function frequently associated with code execution, object injection, or obfuscation.', 'mcp-auditor' ),
						__( 'Remove the dangerous function or replace it with a safer supported pattern.', 'mcp-auditor' ),
						'heuristic'
					);
				}

				if ( preg_match( '/\b(move_uploaded_file|wp_handle_upload)\s*\(/', $trimmed ) || preg_match( '/\$_FILES\s*\[/', $trimmed ) ) {
					$issues[] = $this->build_issue(
						'medium',
						'security',
						(string) $file['relative_path'],
						$line_number,
						__( 'File upload handling detected', 'mcp-auditor' ),
						__( 'The code handles uploaded files, which usually requires nonce validation, capability checks, strict file type controls, and storage review.', 'mcp-auditor' ),
						__( 'Review the upload path carefully and use WordPress upload APIs with explicit validation and authorization checks.', 'mcp-auditor' ),
						'heuristic'
					);
				}

				if ( preg_match( '/\bwp_redirect\s*\(/', $trimmed ) && false === strpos( $trimmed, 'wp_safe_redirect' ) ) {
					$issues[] = $this->build_issue(
						'medium',
						'security',
						(string) $file['relative_path'],
						$line_number,
						__( 'Unsafe redirect helper found', 'mcp-auditor' ),
						__( 'The code uses wp_redirect() directly, which deserves review when user-controlled destinations are involved.', 'mcp-auditor' ),
						__( 'Prefer wp_safe_redirect() for user-influenced destinations and validate allowed redirect targets explicitly.', 'mcp-auditor' ),
						'heuristic'
					);
				}

				if ( preg_match( '/\b(include|require|require_once|include_once|file_get_contents|fopen|readfile)\s*\(.*\$_(GET|POST|REQUEST|COOKIE|FILES)/', $trimmed ) ) {
					$issues[] = $this->build_issue(
						'high',
						'security',
						(string) $file['relative_path'],
						$line_number,
						__( 'User-controlled file access pattern found', 'mcp-auditor' ),
						__( 'A file access helper appears to receive user-controlled input directly, which can lead to local file inclusion or traversal issues.', 'mcp-auditor' ),
						__( 'Do not pass raw request values into file access helpers; validate against an explicit allowlist instead.', 'mcp-auditor' ),
						'heuristic'
					);
				}

				if ( preg_match( '/\b(echo|print)\b/', $trimmed ) && false !== strpos( $trimmed, '$' ) && ! preg_match( '/esc_html|esc_attr|esc_url|wp_kses|wp_json_encode/', $trimmed ) ) {
					$issues[] = $this->build_issue(
						'medium',
						'security',
						(string) $file['relative_path'],
						$line_number,
						__( 'Output appears unescaped', 'mcp-auditor' ),
						__( 'A variable appears to be sent to output without an obvious escaping helper on the same line.', 'mcp-auditor' ),
						__( 'Escape dynamic output with the helper that matches the output context, such as esc_html(), esc_attr(), esc_url(), or wp_kses().', 'mcp-auditor' ),
						'heuristic'
					);
				}
			}

			if ( 'php' !== $file['extension'] ) {
				continue;
			}

			if ( preg_match_all( '/add_action\s*\(\s*[\'"]((?:admin_post(?:_nopriv)?|wp_ajax(?:_nopriv)?)[^\'"]*)[\'"]\s*,\s*(?:[\'"]([A-Za-z0-9_\\\\]+)[\'"]|array\s*\(\s*\$this\s*,\s*[\'"]([A-Za-z0-9_]+)[\'"]\s*\))/m', $contents, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
				foreach ( $matches as $match ) {
					$hook_name     = (string) $match[1][0];
					$callback_name = ! empty( $match[2][0] ) ? (string) $match[2][0] : (string) $match[3][0];
					$line_number   = $this->line_from_offset( $contents, (int) $match[0][1] );
					$function_info = $function_map[ strtolower( $callback_name ) ] ?? null;
					$function_body = is_array( $function_info ) && isset( $function_info['body'] ) ? (string) $function_info['body'] : $contents;
					$function_file = is_array( $function_info ) && isset( $function_info['file'] ) ? (string) $function_info['file'] : (string) $file['relative_path'];
					$function_line = is_array( $function_info ) && isset( $function_info['line'] ) ? (int) $function_info['line'] : $line_number;

					if ( false === strpos( $hook_name, '_nopriv' ) && false === strpos( $function_body, 'current_user_can' ) ) {
						$issues[] = $this->build_issue(
							'medium',
							'security',
							$function_file,
							$function_line,
							__( 'Missing capability check in request handler', 'mcp-auditor' ),
							__( 'A privileged AJAX or admin-post handler was found without an obvious current_user_can() check in the callback body.', 'mcp-auditor' ),
							__( 'Add a capability check in the request handler before processing input or changing state.', 'mcp-auditor' ),
							'heuristic'
						);
					}

					if ( false === strpos( $function_body, 'check_ajax_referer' ) && false === strpos( $function_body, 'check_admin_referer' ) && false === strpos( $function_body, 'wp_verify_nonce' ) ) {
						$issues[] = $this->build_issue(
							'medium',
							'security',
							$function_file,
							$function_line,
							__( 'Missing nonce verification in request handler', 'mcp-auditor' ),
							__( 'A state-changing request handler was found without an obvious nonce verification call in the callback body.', 'mcp-auditor' ),
							__( 'Add a nonce field on the submitting side and verify it in the callback with check_ajax_referer(), check_admin_referer(), or wp_verify_nonce().', 'mcp-auditor' ),
							'heuristic'
						);
					}
				}
			}

			if ( preg_match_all( '/register_rest_route\s*\(/', $contents, $rest_matches, PREG_OFFSET_CAPTURE ) ) {
				foreach ( $rest_matches[0] as $rest_match ) {
					$line_number = $this->line_from_offset( $contents, (int) $rest_match[1] );
					$block       = implode( "\n", array_slice( $lines, max( 0, $line_number - 1 ), 24 ) );

					if ( false === strpos( $block, 'permission_callback' ) ) {
						$issues[] = $this->build_issue(
							'high',
							'security',
							(string) $file['relative_path'],
							$line_number,
							__( 'REST route missing permission_callback', 'mcp-auditor' ),
							__( 'A REST route registration was found without an explicit permission_callback.', 'mcp-auditor' ),
							__( 'Register every REST route with an explicit permission_callback that enforces the intended authorization model.', 'mcp-auditor' ),
							'heuristic'
						);
					} elseif ( false !== strpos( $block, '__return_true' ) ) {
						$issues[] = $this->build_issue(
							'high',
							'security',
							(string) $file['relative_path'],
							$line_number,
							__( 'REST route uses a public permission callback', 'mcp-auditor' ),
							__( 'A REST route appears to use __return_true as the permission callback, which makes the endpoint public.', 'mcp-auditor' ),
							__( 'Replace public permission callbacks with a callback that validates capabilities, authentication, and request intent.', 'mcp-auditor' ),
							'heuristic'
						);
					}
				}
			}
		}

		return $issues;
	}

	/**
	 * @param array<int,array<string,mixed>> $files
	 * @return array<int,array<string,mixed>>
	 */
	private function check_privacy_patterns( array $files ): array {
		$issues             = array();
		$privacy_hook_found = false;

		foreach ( $files as $file ) {
			if ( ! in_array( $file['extension'], array( 'php', 'js', 'html' ), true ) || $this->is_vendor_path( (string) $file['relative_path'] ) ) {
				continue;
			}

			$contents = $this->get_text_contents( $file );
			if ( null === $contents ) {
				continue;
			}

			if ( false !== strpos( $contents, 'wp_add_privacy_policy_content' ) ) {
				$privacy_hook_found = true;
			}

			$patterns = array(
				'/wp_remote_(get|post|request)\s*\(/' => array(
					'title'  => __( 'Outbound remote request helper found', 'mcp-auditor' ),
					'detail' => __( 'External requests may require an explicit opt-in flow, documentation, and privacy disclosures.', 'mcp-auditor' ),
				),
				'/navigator\.sendBeacon|fetch\s*\(|XMLHttpRequest/' => array(
					'title'  => __( 'Browser-side network request found', 'mcp-auditor' ),
					'detail' => __( 'Browser-side requests can become telemetry, analytics, or tracking paths depending on the payload and endpoint.', 'mcp-auditor' ),
				),
				'/google-analytics|googletagmanager|facebook\.com\/tr|mixpanel|segment|posthog|hotjar/i' => array(
					'title'  => __( 'Tracking or analytics endpoint reference found', 'mcp-auditor' ),
					'detail' => __( 'Tracking or analytics code usually requires a clear opt-in experience and corresponding disclosures.', 'mcp-auditor' ),
				),
				'/setcookie\s*\(|document\.cookie/' => array(
					'title'  => __( 'Cookie access detected', 'mcp-auditor' ),
					'detail' => __( 'Cookie usage may require consent handling and explicit privacy disclosures depending on purpose and jurisdiction.', 'mcp-auditor' ),
				),
				'/navigator\.userAgent|window\.screen|document\.referrer|localStorage|sessionStorage/' => array(
					'title'  => __( 'Browser fingerprinting signal found', 'mcp-auditor' ),
					'detail' => __( 'The code references browser metadata often used in telemetry or fingerprinting flows.', 'mcp-auditor' ),
				),
			);

			foreach ( $patterns as $pattern => $payload ) {
				if ( preg_match( $pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
					$offset   = isset( $matches[0][1] ) ? (int) $matches[0][1] : 0;
					$issues[] = $this->build_issue(
						'low',
						'privacy',
						(string) $file['relative_path'],
						$this->line_from_offset( $contents, $offset ),
						(string) $payload['title'],
						(string) $payload['detail'],
						__( 'Review the code path to ensure tracking, cookies, and external requests are opt-in, justified, and clearly documented.', 'mcp-auditor' ),
						'heuristic'
					);
				}
			}
		}

		if ( ! $privacy_hook_found ) {
			$issues[] = $this->build_issue(
				'low',
				'privacy',
				'plugin',
				null,
				__( 'Privacy policy content hook not detected', 'mcp-auditor' ),
				__( 'The package did not show an obvious wp_add_privacy_policy_content() registration for plugin-generated privacy disclosures.', 'mcp-auditor' ),
				__( 'If the plugin collects, sends, or stores personal data, register appropriate privacy policy content in WordPress.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		return $issues;
	}

	/**
	 * @param array<int,array<string,mixed>> $files
	 * @return array<int,array<string,mixed>>
	 */
	private function check_dependency_manifests( array $files ): array {
		$issues = array();

		foreach ( $files as $file ) {
			$relative_path = (string) $file['relative_path'];

			if ( in_array( $relative_path, array( 'vendor/composer/installed.json', 'package-lock.json', 'pnpm-lock.yaml', 'yarn.lock', 'composer.lock' ), true ) ) {
				$issues[] = $this->build_issue(
					'low',
					'dependencies',
					$relative_path,
					null,
					__( 'Dependency lockfile detected', 'mcp-auditor' ),
					__( 'The package includes a dependency lockfile, which is good for reproducibility but should be paired with an audit of the locked dependency set.', 'mcp-auditor' ),
					__( 'Run dependency auditing as part of release review and keep the locked dependency graph minimal.', 'mcp-auditor' ),
					'heuristic'
				);
			}
		}

		return $issues;
	}

	/**
	 * @param array<int,array<string,mixed>> $files
	 * @return array<int,array<string,mixed>>
	 */
	private function check_performance_patterns( array $files ): array {
		$issues = array();

		foreach ( $files as $file ) {
			if ( ! in_array( $file['extension'], array( 'php', 'js', 'css' ), true ) || $this->is_vendor_path( (string) $file['relative_path'] ) ) {
				continue;
			}

			$contents = $this->get_text_contents( $file );
			if ( null === $contents ) {
				continue;
			}

			if ( 'php' === $file['extension'] && preg_match( '/wp_enqueue_(script|style)\s*\([^,]+,\s*[\'"]https?:\/\//i', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
				$issues[] = $this->build_issue(
					'medium',
					'performance',
					(string) $file['relative_path'],
					$this->line_from_offset( $contents, (int) $matches[0][1] ),
					__( 'Remote asset enqueue detected', 'mcp-auditor' ),
					__( 'A script or stylesheet appears to be loaded from a remote URL, which can affect performance, privacy, and reviewability.', 'mcp-auditor' ),
					__( 'Bundle reviewable assets with the package when possible instead of depending on remote CDNs at runtime.', 'mcp-auditor' ),
					'heuristic'
				);
			}

			if ( 'php' === $file['extension'] && preg_match( '/(posts_per_page|numberposts)\s*=>\s*-1/', $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
				$issues[] = $this->build_issue(
					'medium',
					'performance',
					(string) $file['relative_path'],
					$this->line_from_offset( $contents, (int) $matches[0][1] ),
					__( 'Unbounded query arguments detected', 'mcp-auditor' ),
					__( 'The code appears to request an unbounded result set, which can create heavy queries on large sites.', 'mcp-auditor' ),
					__( 'Add limits, pagination, or batching when retrieving large collections of content.', 'mcp-auditor' ),
					'heuristic'
				);
			}

			if ( 'php' === $file['extension'] && preg_match( '/SELECT\s+.+\s+FROM\s+.+/i', $contents ) && ! preg_match( '/LIMIT\s+\d+/i', $contents ) ) {
				$issues[] = $this->build_issue(
					'low',
					'performance',
					(string) $file['relative_path'],
					null,
					__( 'Direct SQL query without an obvious limit', 'mcp-auditor' ),
					__( 'A direct SQL query was found without an obvious LIMIT clause, which can become expensive on larger datasets.', 'mcp-auditor' ),
					__( 'Add explicit limits or batching when direct SQL can return large result sets.', 'mcp-auditor' ),
					'heuristic'
				);
			}

			if ( 'php' === $file['extension'] && preg_match( '/add_action\s*\(\s*[\'"](init|admin_init|plugins_loaded|admin_enqueue_scripts|wp_enqueue_scripts)[\'"]/', $contents ) && preg_match( '/wp_remote_(get|post|request)\s*\(/', $contents ) ) {
				$issues[] = $this->build_issue(
					'medium',
					'performance',
					(string) $file['relative_path'],
					null,
					__( 'Remote request may run on frequently executed hooks', 'mcp-auditor' ),
					__( 'The file contains both high-frequency hooks and outbound request helpers, which can become a performance issue if combined on hot paths.', 'mcp-auditor' ),
					__( 'Move remote requests off hot hooks, cache their results, or trigger them only from explicit user actions.', 'mcp-auditor' ),
					'heuristic'
				);
			}

			if ( in_array( $file['extension'], array( 'js', 'css' ), true ) && (int) $file['size'] > 256 * 1024 ) {
				$issues[] = $this->build_issue(
					'low',
					'performance',
					(string) $file['relative_path'],
					null,
					__( 'Large front-end asset detected', 'mcp-auditor' ),
					__( 'A front-end asset is larger than 256 KB, which can noticeably affect page weight.', 'mcp-auditor' ),
					__( 'Review whether the asset can be reduced, split, or replaced with a smaller reviewed source asset.', 'mcp-auditor' ),
					'heuristic'
				);
			}
		}

		return $issues;
	}

	/**
	 * @param array<string,mixed>            $artifact
	 * @param array<int,array<string,mixed>> $files
	 * @return array<int,array<string,mixed>>
	 */
	private function check_quality( array $artifact, array $files ): array {
		$issues = array();
		$lookup = array();

		foreach ( $files as $file ) {
			$lookup[ (string) $file['relative_path'] ] = true;
		}

		foreach ( $files as $file ) {
			if ( ! in_array( $file['extension'], array( 'php', 'js', 'css', 'json', 'yml', 'yaml' ), true ) || $this->is_vendor_path( (string) $file['relative_path'] ) ) {
				continue;
			}

			$contents = $this->get_text_contents( $file );
			if ( null === $contents ) {
				continue;
			}

			$lines = preg_split( "/\r\n|\n|\r/", $contents );
			if ( false === $lines ) {
				continue;
			}

			foreach ( $lines as $index => $line ) {
				$length         = strlen( $line );
				$non_whitespace = strlen( (string) preg_replace( '/\s+/', '', $line ) );

				if ( $length > 400 && $non_whitespace / max( 1, $length ) > 0.92 ) {
					$issues[] = $this->build_issue(
						'medium',
						'quality',
						(string) $file['relative_path'],
						$index + 1,
						__( 'Potentially minified or obfuscated code', 'mcp-auditor' ),
						__( 'A very long dense line suggests minified or difficult-to-review code.', 'mcp-auditor' ),
						__( 'Include readable source files alongside any minified assets and avoid obfuscated PHP entirely.', 'mcp-auditor' ),
						'heuristic'
					);
					break;
				}
			}

			if ( 'php' === $file['extension'] && preg_match( '/file_put_contents\s*\(\s*(plugin_dir_path|__DIR__|dirname\s*\(__FILE__\)|WP_PLUGIN_DIR)/', $contents ) ) {
				$issues[] = $this->build_issue(
					'medium',
					'quality',
					(string) $file['relative_path'],
					null,
					__( 'Writes into the plugin directory', 'mcp-auditor' ),
					__( 'The code appears to write files into the plugin directory, which is discouraged because plugin directories are replaced on update.', 'mcp-auditor' ),
					__( 'Store mutable files in uploads or another writable location outside the plugin directory.', 'mcp-auditor' ),
					'heuristic'
				);
			}

			if ( preg_match( '/\.min\.(js|css)$/', (string) $file['relative_path'], $matches ) ) {
				$source_candidate = preg_replace( '/\.min\.(js|css)$/', '.' . $matches[1], (string) $file['relative_path'] );
				if ( ! empty( $source_candidate ) && ! isset( $lookup[ $source_candidate ] ) ) {
					$issues[] = $this->build_issue(
						'low',
						'quality',
						(string) $file['relative_path'],
						null,
						__( 'Minified asset without source file', 'mcp-auditor' ),
						__( 'A minified asset was found without an obvious unminified source file in the package.', 'mcp-auditor' ),
						__( 'Ship the original source file alongside minified assets to keep the code reviewable.', 'mcp-auditor' ),
						'heuristic'
					);
				}
			}

			if ( 'json' === $file['extension'] ) {
				json_decode( $contents, true );
				if ( JSON_ERROR_NONE !== json_last_error() ) {
					$issues[] = $this->build_issue(
						'medium',
						'quality',
						(string) $file['relative_path'],
						null,
						__( 'Invalid JSON file detected', 'mcp-auditor' ),
						__( 'A JSON file in the package could not be decoded successfully.', 'mcp-auditor' ),
						__( 'Fix the JSON syntax before shipping the package so tooling and build steps can rely on it.', 'mcp-auditor' ),
						'heuristic'
					);
				}
			}
		}

		if ( empty( $artifact['metadata']['description'] ) ) {
			$issues[] = $this->build_issue(
				'low',
				'quality',
				basename( $artifact['entry_file'] ),
				1,
				__( 'Main artifact description is empty', 'mcp-auditor' ),
				__( 'The main artifact header does not include a description.', 'mcp-auditor' ),
				__( 'Add a short description to the primary plugin or theme header.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		return $issues;
	}

	/**
	 * @param array<int,array<string,mixed>> $files
	 * @return array<int,array<string,mixed>>
	 */
	private function check_accessibility( array $files ): array {
		$issues         = array();
		$skip_link_seen = false;

		foreach ( $files as $file ) {
			if ( ! in_array( $file['extension'], array( 'php', 'html', 'twig' ), true ) || $this->is_vendor_path( (string) $file['relative_path'] ) ) {
				continue;
			}

			$contents = $this->get_text_contents( $file );
			if ( null === $contents ) {
				continue;
			}

			if ( preg_match( '/skip-link|screen-reader-text/i', $contents ) && preg_match( '/href=["\']#([a-z0-9_-]+)["\']/i', $contents ) ) {
				$skip_link_seen = true;
				break;
			}
		}

		if ( ! $skip_link_seen ) {
			$issues[] = $this->build_issue(
				'low',
				'accessibility',
				'theme',
				null,
				__( 'Skip link not detected', 'mcp-auditor' ),
				__( 'The theme templates did not show an obvious skip link pattern.', 'mcp-auditor' ),
				__( 'Add a visible-on-focus skip link to the main content region for keyboard and screen-reader users.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		return $issues;
	}

	/**
	 * @param array<string,mixed> $artifact
	 * @return array<string,mixed>
	 */
	private function check_runtime_behaviour( array $artifact ): array {
		$result = array(
			'issues'   => array(),
			'notes'    => array(),
			'analysis' => array(
				'status' => 'skipped',
				'reason' => __( 'Runtime checks currently support plugins only.', 'mcp-auditor' ),
			),
		);

		if ( 'plugin' !== $artifact['type'] ) {
			return $result;
		}

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_basename = (string) $artifact['accepted_slug'];
		$was_active      = is_plugin_active( $plugin_basename );
		$analysis        = array(
			'status'                 => 'completed',
			'was_active'             => $was_active,
			'activated_for_test'     => false,
			'activation_ms'          => 0,
			'activation_output'      => '',
			'new_rest_routes'        => array(),
			'insecure_rest_routes'   => array(),
			'new_cron_hooks'         => array(),
			'new_autoload_options'   => array(),
			'new_tables'             => array(),
			'initial_autoload_bytes' => 0,
			'final_autoload_bytes'   => 0,
		);

		$before_routes                 = $this->snapshot_rest_routes( true );
		$before_cron                   = $this->snapshot_cron_events();
		$before_autoload               = $this->snapshot_autoload_options();
		$before_tables                 = $this->snapshot_tables();
		$analysis['initial_autoload_bytes'] = array_sum( $before_autoload );

		if ( ! $was_active ) {
			$start = microtime( true );
			ob_start();
			$activation_result = activate_plugin( $plugin_basename, '', false, true );
			$output            = trim( (string) ob_get_clean() );

			$analysis['activation_ms']     = (int) round( ( microtime( true ) - $start ) * 1000 );
			$analysis['activation_output'] = $output;

			if ( is_wp_error( $activation_result ) ) {
				$result['issues'][] = $this->build_issue(
					'high',
					'runtime',
					$plugin_basename,
					null,
					__( 'Plugin activation failed during runtime smoke test', 'mcp-auditor' ),
					$activation_result->get_error_message(),
					__( 'Fix activation errors before submitting the package and rerun the runtime smoke test.', 'mcp-auditor' ),
					'heuristic'
				);
				$analysis['status'] = 'activation_failed';
				$analysis['reason'] = $activation_result->get_error_message();
				$result['analysis'] = $analysis;
				return $result;
			}

			$analysis['activated_for_test'] = true;
		}

		$after_routes                = $this->snapshot_rest_routes( true );
		$after_cron                  = $this->snapshot_cron_events();
		$after_autoload              = $this->snapshot_autoload_options();
		$after_tables                = $this->snapshot_tables();
		$analysis['final_autoload_bytes'] = array_sum( $after_autoload );

		$new_routes = array_diff_key( $after_routes, $before_routes );
		if ( empty( $new_routes ) && $was_active ) {
			$route_tokens = $this->artifact_tokens( $artifact );
			foreach ( $after_routes as $route => $route_details ) {
				foreach ( $route_tokens as $token ) {
					if ( '' !== $token && false !== strpos( strtolower( (string) $route ), $token ) ) {
						$new_routes[ $route ] = $route_details;
						break;
					}
				}
			}
		}

		foreach ( $new_routes as $route => $route_details ) {
			$analysis['new_rest_routes'][] = $route;

			foreach ( $route_details['permission_callbacks'] as $permission_callback ) {
				if ( '' === $permission_callback || '__return_true' === $permission_callback ) {
					$analysis['insecure_rest_routes'][] = $route;
					$result['issues'][] = $this->build_issue(
						'high',
						'runtime',
						(string) $route,
						null,
						__( 'Runtime REST route looks publicly accessible', 'mcp-auditor' ),
						__( 'A route exposed during the runtime smoke test did not show a restrictive permission callback.', 'mcp-auditor' ),
						__( 'Require an explicit permission callback for each REST endpoint and enforce capabilities or authentication as needed.', 'mcp-auditor' ),
						'heuristic'
					);
					break;
				}
			}
		}

		foreach ( $after_cron as $hook_name => $count ) {
			$previous_count = $before_cron[ $hook_name ] ?? 0;

			if ( $count > $previous_count ) {
				$analysis['new_cron_hooks'][] = $hook_name;
			}
		}

		if ( ! empty( $analysis['new_cron_hooks'] ) ) {
			$result['issues'][] = $this->build_issue(
				'low',
				'runtime',
				$plugin_basename,
				null,
				__( 'Plugin scheduled cron events during activation', 'mcp-auditor' ),
				__( 'The runtime smoke test observed new scheduled events after activation.', 'mcp-auditor' ),
				__( 'Document scheduled tasks clearly and make sure they are unscheduled on uninstall or deactivation when appropriate.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		foreach ( $after_autoload as $option_name => $bytes ) {
			$previous_bytes = $before_autoload[ $option_name ] ?? null;

			if ( null === $previous_bytes ) {
				$analysis['new_autoload_options'][ $option_name ] = $bytes;
			}
		}

		$new_autoload_bytes = array_sum( $analysis['new_autoload_options'] );
		if ( $new_autoload_bytes > 64 * 1024 ) {
			$result['issues'][] = $this->build_issue(
				'medium',
				'performance',
				$plugin_basename,
				null,
				__( 'Activation added a large autoloaded option payload', 'mcp-auditor' ),
				__( 'The runtime smoke test observed more than 64 KB of new autoloaded options after activation.', 'mcp-auditor' ),
				__( 'Avoid adding large autoloaded options on activation. Use non-autoloaded storage or lazy loading for heavier data.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		foreach ( $analysis['new_autoload_options'] as $option_name => $bytes ) {
			if ( $bytes > 32 * 1024 ) {
				$result['issues'][] = $this->build_issue(
					'medium',
					'performance',
					$option_name,
					null,
					__( 'Large autoloaded option created during activation', 'mcp-auditor' ),
					__( 'An autoloaded option created during activation exceeds 32 KB.', 'mcp-auditor' ),
					__( 'Store large data outside autoloaded options so it does not inflate every request.', 'mcp-auditor' ),
					'heuristic'
				);
			}
		}

		foreach ( $after_tables as $table_name ) {
			if ( ! in_array( $table_name, $before_tables, true ) ) {
				$analysis['new_tables'][] = $table_name;
			}
		}

		if ( $analysis['activation_ms'] > 300 ) {
			$result['issues'][] = $this->build_issue(
				'low',
				'performance',
				$plugin_basename,
				null,
				__( 'Activation is relatively slow', 'mcp-auditor' ),
				__( 'The runtime smoke test measured plugin activation above 300 ms in the local environment.', 'mcp-auditor' ),
				__( 'Review activation work and avoid unnecessary I/O, network calls, or large writes during activation.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		if ( '' !== $analysis['activation_output'] ) {
			$result['issues'][] = $this->build_issue(
				'low',
				'runtime',
				$plugin_basename,
				null,
				__( 'Plugin emitted output during activation', 'mcp-auditor' ),
				__( 'The runtime smoke test captured output while the plugin was being activated.', 'mcp-auditor' ),
				__( 'Avoid direct output during activation so the activation flow remains clean and predictable.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		if ( $analysis['activated_for_test'] ) {
			deactivate_plugins( $plugin_basename, true );
		}

		if ( ! empty( $analysis['new_tables'] ) ) {
			$result['notes'][] = sprintf(
				/* translators: %s: comma-separated table list. */
				__( 'Runtime smoke test observed new tables: %s', 'mcp-auditor' ),
				implode( ', ', $analysis['new_tables'] )
			);
		}

		if ( ! empty( $analysis['new_rest_routes'] ) ) {
			$result['notes'][] = sprintf(
				/* translators: %s: comma-separated route list. */
				__( 'Runtime smoke test observed REST routes: %s', 'mcp-auditor' ),
				implode( ', ', $analysis['new_rest_routes'] )
			);
		}

		$result['analysis'] = $analysis;

		return $result;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function snapshot_rest_routes( bool $refresh = false ): array {
		global $wp_rest_server;

		if ( $refresh ) {
			$wp_rest_server = null;
		}

		$server = rest_get_server();
		$routes = array();

		foreach ( $server->get_routes() as $route => $endpoints ) {
			$permission_callbacks = array();

			foreach ( $endpoints as $endpoint ) {
				if ( ! is_array( $endpoint ) ) {
					continue;
				}

				$permission_callbacks[] = $this->normalize_callback_name( $endpoint['permission_callback'] ?? '' );
			}

			$routes[ $route ] = array(
				'permission_callbacks' => array_values( array_unique( $permission_callbacks ) ),
			);
		}

		return $routes;
	}

	/**
	 * @return array<string,int>
	 */
	private function snapshot_cron_events(): array {
		$cron      = _get_cron_array();
		$flattened = array();

		if ( ! is_array( $cron ) ) {
			return $flattened;
		}

		foreach ( $cron as $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook_name => $events ) {
				$flattened[ $hook_name ] = isset( $flattened[ $hook_name ] ) ? $flattened[ $hook_name ] : 0;
				$flattened[ $hook_name ] += is_array( $events ) ? count( $events ) : 1;
			}
		}

		return $flattened;
	}

	/**
	 * @return array<string,int>
	 */
	private function snapshot_autoload_options(): array {
		global $wpdb;

		$query = "SELECT option_name, LENGTH(option_value) AS bytes FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')";
		$rows  = $wpdb->get_results( $query, ARRAY_A );
		$data  = array();

		if ( ! is_array( $rows ) ) {
			return $data;
		}

		foreach ( $rows as $row ) {
			if ( empty( $row['option_name'] ) ) {
				continue;
			}

			$data[ (string) $row['option_name'] ] = isset( $row['bytes'] ) ? (int) $row['bytes'] : 0;
		}

		return $data;
	}

	/**
	 * @return array<int,string>
	 */
	private function snapshot_tables(): array {
		global $wpdb;

		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		return is_array( $tables ) ? array_map( 'strval', $tables ) : array();
	}

	/**
	 * @param mixed $callback
	 */
	private function normalize_callback_name( $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( is_array( $callback ) ) {
			$target = array();

			foreach ( $callback as $part ) {
				if ( is_object( $part ) ) {
					$target[] = get_class( $part );
				} elseif ( is_string( $part ) ) {
					$target[] = $part;
				}
			}

			return implode( '::', $target );
		}

		if ( $callback instanceof \Closure ) {
			return 'Closure';
		}

		if ( is_object( $callback ) ) {
			return get_class( $callback );
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $artifact
	 * @return array<int,string>
	 */
	private function artifact_tokens( array $artifact ): array {
		$tokens = array(
			strtolower( sanitize_title( (string) $artifact['slug'] ) ),
			strtolower( sanitize_title( (string) $artifact['accepted_slug'] ) ),
			strtolower( sanitize_title( (string) ( $artifact['metadata']['text_domain'] ?? '' ) ) ),
			strtolower( sanitize_title( (string) $artifact['display_name'] ) ),
		);

		return array_values( array_filter( array_unique( $tokens ) ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $files
	 * @return array<string,array<string,mixed>>
	 */
	private function build_php_function_map( array $files ): array {
		$functions = array();

		foreach ( $files as $file ) {
			if ( 'php' !== $file['extension'] || $this->is_vendor_path( (string) $file['relative_path'] ) ) {
				continue;
			}

			$contents = $this->get_text_contents( $file );
			if ( null === $contents ) {
				continue;
			}

			foreach ( $this->extract_php_functions_from_contents( $contents, (string) $file['relative_path'] ) as $name => $payload ) {
				$functions[ $name ] = $payload;
			}
		}

		return $functions;
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function extract_php_functions_from_contents( string $contents, string $relative_path ): array {
		$tokens      = token_get_all( $contents );
		$functions   = array();
		$token_count = count( $tokens );
		$namespace   = '';

		for ( $index = 0; $index < $token_count; $index++ ) {
			$token = $tokens[ $index ];

			if ( is_array( $token ) && T_NAMESPACE === $token[0] ) {
				$namespace = '';
				$cursor    = $index + 1;

				while ( $cursor < $token_count ) {
					$current = $tokens[ $cursor ];
					$text    = is_array( $current ) ? $current[1] : $current;

					if ( ';' === $text || '{' === $text ) {
						break;
					}

					if ( is_array( $current ) && in_array( $current[0], array( T_STRING, T_NS_SEPARATOR ), true ) ) {
						$namespace .= $current[1];
					}

					$cursor++;
				}

				continue;
			}

			if ( ! is_array( $token ) || T_FUNCTION !== $token[0] ) {
				continue;
			}

			$cursor = $index + 1;

			while ( $cursor < $token_count && is_array( $tokens[ $cursor ] ) && in_array( $tokens[ $cursor ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$cursor++;
			}

			if ( $cursor >= $token_count || ! is_array( $tokens[ $cursor ] ) || T_STRING !== $tokens[ $cursor ][0] ) {
				continue;
			}

			$name = (string) $tokens[ $cursor ][1];
			$line = (int) $tokens[ $cursor ][2];

			while ( $cursor < $token_count && ( ! is_string( $tokens[ $cursor ] ) || '{' !== $tokens[ $cursor ] ) ) {
				$cursor++;
			}

			if ( $cursor >= $token_count ) {
				continue;
			}

			$depth = 1;
			$body  = '';
			$cursor++;

			while ( $cursor < $token_count && $depth > 0 ) {
				$current = $tokens[ $cursor ];
				$text    = is_array( $current ) ? $current[1] : $current;

				if ( '{' === $text ) {
					$depth++;
				} elseif ( '}' === $text ) {
					$depth--;
				}

				if ( $depth > 0 ) {
					$body .= $text;
				}

				$cursor++;
			}

			$key               = strtolower( $name );
			$functions[ $key ] = array(
				'name'      => $name,
				'namespace' => $namespace,
				'line'      => $line,
				'file'      => $relative_path,
				'body'      => $body,
			);
		}

		return $functions;
	}

	/**
	 * @param array<string,mixed> $file
	 */
	private function get_text_contents( array $file ): ?string {
		if ( empty( $file['is_text'] ) ) {
			return null;
		}

		$contents = file_get_contents( (string) $file['path'] );
		return false === $contents ? null : $contents;
	}

	private function is_vendor_path( string $relative_path ): bool {
		return 0 === strpos( $relative_path, 'vendor/' ) || 0 === strpos( $relative_path, 'node_modules/' );
	}

	private function is_package_noise( string $relative_path ): bool {
		$basename = basename( $relative_path );

		if ( preg_match( '#(^|/)(\.git|\.svn|\.hg|\.idea|\.vscode)(/|$)#i', $relative_path ) ) {
			return true;
		}

		if ( preg_match( '/(^|\/)\.env(\.|$)/i', $relative_path ) ) {
			return true;
		}

		if ( in_array( $basename, array( '.DS_Store', 'Thumbs.db', 'composer.phar' ), true ) ) {
			return true;
		}

		return (bool) preg_match( '/\.(bak|old|orig|tmp|log|sql|zip|tar|gz|rar)$/i', $relative_path );
	}

	private function line_from_offset( string $contents, int $offset ): int {
		return max( 1, substr_count( substr( $contents, 0, $offset ), "\n" ) + 1 );
	}

	/**
	 * @param array<string,mixed> $external_result
	 * @return array<string,mixed>
	 */
	private function normalize_external_result( array $external_result ): array {
		$issues   = array();
		$notes    = array();
		$analysis = array(
			'tooling' => array(),
		);

		if ( ! empty( $external_result['issues'] ) && is_array( $external_result['issues'] ) ) {
			foreach ( $external_result['issues'] as $issue ) {
				if ( ! is_array( $issue ) ) {
					continue;
				}

				$issues[] = $this->normalize_external_issue( $issue );
			}
		}

		if ( ! empty( $external_result['notes'] ) && is_array( $external_result['notes'] ) ) {
			foreach ( $external_result['notes'] as $note ) {
				if ( is_string( $note ) && '' !== trim( $note ) ) {
					$notes[] = trim( $note );
				}
			}
		}

		if ( ! empty( $external_result['analysis'] ) && is_array( $external_result['analysis'] ) ) {
			$analysis = array_replace_recursive( $analysis, $external_result['analysis'] );
		}

		return array(
			'issues'   => $issues,
			'notes'    => $notes,
			'analysis' => $analysis,
		);
	}

	/**
	 * @param array<string,mixed> $issue
	 * @return array<string,mixed>
	 */
	private function normalize_external_issue( array $issue ): array {
		$severity       = isset( $issue['severity'] ) && in_array( $issue['severity'], array( 'high', 'medium', 'low' ), true ) ? (string) $issue['severity'] : 'medium';
		$category       = isset( $issue['category'] ) ? sanitize_key( (string) $issue['category'] ) : 'quality';
		$file           = isset( $issue['file'] ) ? (string) $issue['file'] : 'artifact';
		$line           = isset( $issue['line'] ) && null !== $issue['line'] ? (int) $issue['line'] : null;
		$title          = isset( $issue['title'] ) ? (string) $issue['title'] : __( 'External tooling finding', 'mcp-auditor' );
		$detail         = isset( $issue['detail'] ) ? (string) $issue['detail'] : __( 'A supplemental tool reported a finding for this package.', 'mcp-auditor' );
		$recommendation = isset( $issue['recommendation'] ) ? (string) $issue['recommendation'] : __( 'Review the tooling output and update the package accordingly.', 'mcp-auditor' );
		$source         = isset( $issue['source'] ) ? (string) $issue['source'] : 'external-tooling';

		if ( 'code_quality' === $category ) {
			$category = 'quality';
		}

		return array(
			'severity'       => $severity,
			'category'       => $category,
			'file'           => $file,
			'line'           => $line,
			'location'       => null !== $line ? $file . ':' . $line : $file,
			'title'          => $title,
			'detail'         => $detail,
			'recommendation' => $recommendation,
			'handbook'       => $this->get_issue_handbook_reference( $category, $title ),
			'source'         => $source,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $issues
	 * @return array<int,array<string,mixed>>
	 */
	private function deduplicate_issues( array $issues ): array {
		$unique = array();
		$seen   = array();

		foreach ( $issues as $issue ) {
			$key = md5(
				implode(
					'|',
					array(
						(string) ( $issue['severity'] ?? '' ),
						(string) ( $issue['category'] ?? '' ),
						(string) ( $issue['file'] ?? '' ),
						(string) ( $issue['line'] ?? '' ),
						(string) ( $issue['title'] ?? '' ),
					)
				)
			);

			if ( isset( $seen[ $key ] ) ) {
				continue;
			}

			$seen[ $key ] = true;
			$unique[]     = $issue;
		}

		usort(
			$unique,
			static function ( array $left, array $right ): int {
				$order      = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
				$left_rank  = $order[ $left['severity'] ] ?? 99;
				$right_rank = $order[ $right['severity'] ] ?? 99;

				if ( $left_rank !== $right_rank ) {
					return $left_rank <=> $right_rank;
				}

				if ( (string) $left['category'] !== (string) $right['category'] ) {
					return strcmp( (string) $left['category'], (string) $right['category'] );
				}

				return strcmp( (string) $left['file'], (string) $right['file'] );
			}
		);

		return $unique;
	}

	/**
	 * @param array<string,mixed>            $artifact
	 * @param array<int,string>              $checks
	 * @param array<int,array<string,mixed>> $issues
	 * @param array<int,string>              $notes
	 * @param array<string,mixed>            $analysis_details
	 * @param array<int,array<string,mixed>> $files
	 * @return array<string,mixed>
	 */
	private function build_report( array $artifact, array $checks, array $issues, array $notes, bool $use_ai, int $files_scanned, array $analysis_details, array $files ): array {
		$severity_counts = array(
			'high'   => 0,
			'medium' => 0,
			'low'    => 0,
		);
		$category_counts = array();

		foreach ( $issues as $issue ) {
			$severity = $issue['severity'] ?? 'low';
			$category = $issue['category'] ?? 'quality';

			if ( isset( $severity_counts[ $severity ] ) ) {
				++$severity_counts[ $severity ];
			}

			$category_counts[ $category ] = isset( $category_counts[ $category ] ) ? $category_counts[ $category ] + 1 : 1;
		}

		ksort( $category_counts );

		$verdict_status = empty( $issues )
			? 'ready'
			: ( $severity_counts['high'] > 0 ? 'changes_requested' : 'needs_revision' );
		$verdict_label  = 'ready' === $verdict_status
			? __( 'Ready for review', 'mcp-auditor' )
			: ( 'changes_requested' === $verdict_status ? __( 'Changes requested', 'mcp-auditor' ) : __( 'Needs revision', 'mcp-auditor' ) );
		$package_size   = 0;

		foreach ( $files as $file ) {
			$package_size += (int) $file['size'];
		}

		$summary_text = empty( $issues )
			? __( 'Audit completed with no issues detected by the current checks.', 'mcp-auditor' )
			: sprintf(
				/* translators: 1: issue count, 2: artifact name. */
				__( 'Audit completed with %1$d issue(s) detected for %2$s.', 'mcp-auditor' ),
				count( $issues ),
				$artifact['display_name']
			);

		$report = array(
			'status'         => 'completed',
			'generated_at'   => current_time( 'c' ),
			'verdict'        => array(
				'status' => $verdict_status,
				'label'  => $verdict_label,
			),
			'artifact'       => array(
				'type'          => $artifact['type'],
				'requested'     => $artifact['slug'],
				'accepted_slug' => $artifact['accepted_slug'],
				'display_name'  => $artifact['display_name'],
				'entry_file'    => wp_normalize_path( $artifact['entry_file'] ),
				'metadata'      => $artifact['metadata'],
			),
			'checks'         => $checks,
			'summary'        => array(
				'text'       => $summary_text,
				'totals'     => array(
					'all'    => count( $issues ),
					'high'   => $severity_counts['high'],
					'medium' => $severity_counts['medium'],
					'low'    => $severity_counts['low'],
				),
				'categories' => $category_counts,
			),
			'issues'         => $issues,
			'analysis'       => array(
				'files_scanned'      => $files_scanned,
				'package_size_bytes' => $package_size,
				'ai'                 => array(
					'requested'  => $use_ai,
					'configured' => $this->openai_client->is_configured(),
					'model'      => $this->openai_client->get_model(),
				),
				'runtime'            => $analysis_details['runtime'] ?? array(),
				'tooling'            => $analysis_details['tooling'] ?? array(),
				'notes'              => array_values( array_unique( array_filter( array_map( 'strval', $notes ) ) ) ),
			),
			'report_post_id' => null,
			'report_edit_url'=> null,
		);

		$report['email'] = $this->build_review_email( $report );

		return $report;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_issue( string $severity, string $category, string $file, ?int $line, string $title, string $detail, string $recommendation, string $source ): array {
		$handbook = $this->get_issue_handbook_reference( $category, $title );

		return array(
			'severity'       => $severity,
			'category'       => $category,
			'file'           => $file,
			'line'           => $line,
			'location'       => null !== $line ? $file . ':' . $line : $file,
			'title'          => $title,
			'detail'         => $detail,
			'recommendation' => $recommendation,
			'handbook'       => $handbook,
			'source'         => $source,
		);
	}

	/**
	 * @param array<string,mixed> $report
	 * @return array<string,string>
	 */
	private function build_review_email( array $report ): array {
		$artifact_name = isset( $report['artifact']['display_name'] ) ? (string) $report['artifact']['display_name'] : __( 'your plugin', 'mcp-auditor' );
		$artifact_type = isset( $report['artifact']['type'] ) ? (string) $report['artifact']['type'] : 'plugin';
		$issues        = isset( $report['issues'] ) && is_array( $report['issues'] ) ? $report['issues'] : array();
		$notes         = isset( $report['analysis']['notes'] ) && is_array( $report['analysis']['notes'] ) ? $report['analysis']['notes'] : array();
		$totals        = isset( $report['summary']['totals'] ) && is_array( $report['summary']['totals'] ) ? $report['summary']['totals'] : array();
		$verdict       = isset( $report['verdict']['label'] ) ? (string) $report['verdict']['label'] : '';
		$lines         = array(
			__( 'Hello,', 'mcp-auditor' ),
			'',
		);

		if ( empty( $issues ) ) {
			$lines[] = sprintf(
				/* translators: %s: artifact name. */
				__( 'Thank you for submitting %s for review.', 'mcp-auditor' ),
				$artifact_name
			);
			$lines[] = __( 'We reviewed the current package and did not find any issues from the checks that were run.', 'mcp-auditor' );
			$lines[] = __( 'Please make sure the final package still reflects the reviewed code before you send it on.', 'mcp-auditor' );
		} else {
			$lines[] = sprintf(
				/* translators: 1: artifact name, 2: artifact type. */
				__( 'Thank you for submitting %1$s for %2$s review.', 'mcp-auditor' ),
				$artifact_name,
				$artifact_type
			);
			$lines[] = __( 'We reviewed the current code and found issues that need to be addressed before it would pass review today.', 'mcp-auditor' );
			$lines[] = sprintf(
				/* translators: 1: verdict label, 2: high count, 3: medium count, 4: low count. */
				__( 'Current status: %1$s. Findings summary: %2$d high, %3$d medium, %4$d low.', 'mcp-auditor' ),
				$verdict,
				(int) ( $totals['high'] ?? 0 ),
				(int) ( $totals['medium'] ?? 0 ),
				(int) ( $totals['low'] ?? 0 )
			);
			$lines[] = '';
			$lines[] = __( 'Please address the following:', 'mcp-auditor' );
			$lines[] = '';

			foreach ( $issues as $index => $issue ) {
				$lines[] = sprintf( '%d. %s', $index + 1, (string) $issue['title'] );
				$lines[] = sprintf(
					/* translators: %s: issue category. */
					__( 'Type: %s', 'mcp-auditor' ),
					ucwords( str_replace( '_', ' ', (string) $issue['category'] ) )
				);

				if ( ! empty( $issue['location'] ) ) {
					$lines[] = sprintf(
						/* translators: %s: issue location. */
						__( 'Where: %s', 'mcp-auditor' ),
						(string) $issue['location']
					);
				}

				$lines[] = sprintf(
					/* translators: %s: issue description. */
					__( 'Why this matters: %s', 'mcp-auditor' ),
					(string) $issue['detail']
				);
				$lines[] = sprintf(
					/* translators: %s: recommendation. */
					__( 'How to fix it: %s', 'mcp-auditor' ),
					(string) $issue['recommendation']
				);

				if ( ! empty( $issue['handbook']['label'] ) && ! empty( $issue['handbook']['url'] ) ) {
					$lines[] = sprintf(
						/* translators: %s: handbook label. */
						__( 'Reference: %s', 'mcp-auditor' ),
						(string) $issue['handbook']['label']
					);
					$lines[] = (string) $issue['handbook']['url'];
				}

				$lines[] = '';
			}

			$lines[] = __( 'When you have resolved all of the issues above, please reply with an updated package and a short summary of the changes you made.', 'mcp-auditor' );
			$lines[] = __( 'Please also remember to increment the version number and make sure the code would pass review today.', 'mcp-auditor' );
		}

		if ( ! empty( $notes ) ) {
			$lines[] = '';
			$lines[] = __( 'Additional notes:', 'mcp-auditor' );
			foreach ( $notes as $note ) {
				$lines[] = '- ' . $note;
			}
		}

		$lines[] = '';
		$lines[] = __( 'Regards,', 'mcp-auditor' );
		$lines[] = __( 'WordPress Plugin Review Team', 'mcp-auditor' );

		return array(
			'subject' => sprintf(
				/* translators: 1: artifact type, 2: artifact name. */
				__( '%1$s review for %2$s', 'mcp-auditor' ),
				ucfirst( $artifact_type ),
				$artifact_name
			),
			'body'    => implode( "\n", $lines ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function get_issue_handbook_reference( string $category, string $title ): array {
		$reference = array(
			'label' => __( 'Detailed Plugin Guidelines', 'mcp-auditor' ),
			'url'   => 'https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/',
		);

		switch ( $category ) {
			case 'security':
			case 'runtime':
				$reference = array(
					'label' => __( 'Review Walkthrough: Security', 'mcp-auditor' ),
					'url'   => 'https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/',
				);
				break;
			case 'privacy':
				$reference = array(
					'label' => __( 'Detailed Plugin Guidelines: no tracking without consent', 'mcp-auditor' ),
					'url'   => 'https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/',
				);
				break;
			case 'uninstall':
				$reference = array(
					'label' => __( 'Review Walkthrough: Uninstall and Deactivation', 'mcp-auditor' ),
					'url'   => 'https://make.wordpress.org/plugins/handbook/performing-reviews/review-walkthrough/',
				);
				break;
			case 'package':
			case 'quality':
			case 'dependencies':
			case 'performance':
				$reference = array(
					'label' => __( 'Common Issues', 'mcp-auditor' ),
					'url'   => 'https://developer.wordpress.org/plugins/wordpress-org/common-issues/',
				);
				break;
			case 'wordpress':
				$reference = array(
					'label' => __( 'Detailed Plugin Guidelines', 'mcp-auditor' ),
					'url'   => 'https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/',
				);
				break;
			case 'accessibility':
				$reference = array(
					'label' => __( 'Theme Review Required', 'mcp-auditor' ),
					'url'   => 'https://make.wordpress.org/themes/handbook/review/required/',
				);
				break;
		}

		if ( 'Missing license metadata' === $title || 'Non-GPL license detected' === $title ) {
			$reference = array(
				'label' => __( 'Detailed Plugin Guidelines: GPL', 'mcp-auditor' ),
				'url'   => 'https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/',
			);
		}

		return $reference;
	}
}
