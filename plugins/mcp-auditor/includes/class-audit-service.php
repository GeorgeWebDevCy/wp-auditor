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
	 * @param array<int,string> $checks
	 * @return array<string,mixed>
	 */
	public function run_audit( string $slug, string $type, array $checks = array(), bool $use_ai = false, bool $persist_report = true ): array {
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

		$files  = $this->collect_files( $artifact );
		$notes  = array();
		$issues = $this->run_heuristics( $artifact, $files, $checks );

		if ( $use_ai ) {
			if ( $this->openai_client->is_configured() ) {
				$ai_result = $this->openai_client->analyze_candidates( $artifact, $files, $checks );
				$issues    = array_merge( $issues, $ai_result['issues'] );
				$notes     = array_merge( $notes, $ai_result['notes'] );
			} else {
				$notes[] = __( 'OpenAI analysis was requested, but OPENAI_API_KEY is not configured.', 'mcp-auditor' );
			}
		}

		$issues = $this->deduplicate_issues( $issues );
		$report = $this->build_report( $artifact, $checks, $issues, $notes, $use_ai, count( $files ) );

		if ( $persist_report && 'completed' === $report['status'] ) {
			$report_id = $this->report_repository->save( $report );

			if ( $report_id > 0 ) {
				$report['report_post_id']   = $report_id;
				$report['report_edit_url']  = get_edit_post_link( $report_id, 'raw' );
			}
		}

		return $report;
	}

	/**
	 * @param array<int,string> $checks
	 * @return array<int,string>
	 */
	private function normalize_checks( array $checks, string $type ): array {
		$allowed = array( 'licensing', 'security', 'privacy', 'uninstall', 'code_quality' );

		if ( 'theme' === $type ) {
			$allowed[] = 'accessibility';
		}

		$checks = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $checks ),
					static function ( string $check ) use ( $allowed ): bool {
						return in_array( $check, $allowed, true );
					}
				)
			)
		);

		if ( empty( $checks ) ) {
			$checks = $allowed;
		}

		return $checks;
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
					'version'     => $headers['Version'] ?? '',
					'license'     => $headers['License'] ?? '',
					'text_domain' => $headers['TextDomain'] ?? '',
					'description' => $headers['Description'] ?? '',
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
					'version'     => $theme->get( 'Version' ),
					'license'     => $theme->get( 'License' ),
					'text_domain' => $theme->get( 'TextDomain' ),
					'description' => $theme->get( 'Description' ),
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
		$allowed_extensions = array( 'php', 'js', 'css', 'html', 'twig', 'txt', 'md', 'json', 'yml', 'yaml' );
		$files              = array();

		if ( ! empty( $artifact['single_file'] ) ) {
			$files[] = $this->build_file_entry( $artifact['entry_file'], basename( $artifact['entry_file'] ), true );
			return $files;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(
				$artifact['root_path'],
				\FilesystemIterator::SKIP_DOTS
			)
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}

			$extension = strtolower( $file->getExtension() );

			if ( ! in_array( $extension, $allowed_extensions, true ) ) {
				continue;
			}

			$absolute_path = $file->getPathname();
			$relative_path = ltrim( str_replace( wp_normalize_path( $artifact['root_path'] ), '', wp_normalize_path( $absolute_path ) ), '/' );
			$files[]       = $this->build_file_entry( $absolute_path, $relative_path, wp_normalize_path( $absolute_path ) === wp_normalize_path( $artifact['entry_file'] ) );
		}

		return $files;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_file_entry( string $absolute_path, string $relative_path, bool $is_entry ): array {
		return array(
			'path'          => $absolute_path,
			'relative_path' => $relative_path,
			'extension'     => strtolower( pathinfo( $absolute_path, PATHINFO_EXTENSION ) ),
			'size'          => file_exists( $absolute_path ) ? (int) filesize( $absolute_path ) : 0,
			'is_entry'      => $is_entry,
		);
	}

	/**
	 * @param array<string,mixed>           $artifact
	 * @param array<int,array<string,mixed>> $files
	 * @param array<int,string>             $checks
	 * @return array<int,array<string,mixed>>
	 */
	private function run_heuristics( array $artifact, array $files, array $checks ): array {
		$issues = array();

		if ( in_array( 'licensing', $checks, true ) ) {
			$issues = array_merge( $issues, $this->check_licensing( $artifact ) );
		}

		if ( in_array( 'uninstall', $checks, true ) && 'plugin' === $artifact['type'] ) {
			$issues = array_merge( $issues, $this->check_uninstall( $files ) );
		}

		if ( in_array( 'security', $checks, true ) ) {
			$issues = array_merge( $issues, $this->check_security_patterns( $files ) );
		}

		if ( in_array( 'privacy', $checks, true ) ) {
			$issues = array_merge( $issues, $this->check_privacy_patterns( $files ) );
		}

		if ( in_array( 'code_quality', $checks, true ) ) {
			$issues = array_merge( $issues, $this->check_code_quality( $artifact, $files ) );
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
				'code_quality',
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
				'licensing',
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
	 * @param array<int,array<string,mixed>> $files
	 * @return array<int,array<string,mixed>>
	 */
	private function check_uninstall( array $files ): array {
		$issues          = array();
		$uninstall_found = false;
		$hook_found      = false;

		foreach ( $files as $file ) {
			if ( 'php' !== $file['extension'] ) {
				continue;
			}

			$contents = file_get_contents( $file['path'] );
			if ( false === $contents ) {
				continue;
			}

			if ( 'uninstall.php' === strtolower( $file['relative_path'] ) ) {
				$uninstall_found = true;
				if ( false === strpos( $contents, 'WP_UNINSTALL_PLUGIN' ) ) {
					$issues[] = $this->build_issue(
						'medium',
						'uninstall',
						$file['relative_path'],
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
				__( 'Implement an uninstall routine so options and custom tables can be cleaned up safely.', 'mcp-auditor' ),
				'heuristic'
			);
		}

		return $issues;
	}

	/**
	 * @param array<int,array<string,mixed>> $files
	 * @return array<int,array<string,mixed>>
	 */
	private function check_security_patterns( array $files ): array {
		$issues = array();

		foreach ( $files as $file ) {
			if ( ! in_array( $file['extension'], array( 'php', 'js' ), true ) ) {
				continue;
			}

			$contents = file_get_contents( $file['path'] );
			if ( false === $contents ) {
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
						$file['relative_path'],
						$line_number,
						__( 'Raw superglobal access', 'mcp-auditor' ),
						__( 'Request data appears to be used without a nearby sanitization helper.', 'mcp-auditor' ),
						__( 'Sanitize request values before use, for example with sanitize_text_field(), absint(), or a more specific sanitizer.', 'mcp-auditor' ),
						'heuristic'
					);
				}

				if ( preg_match( '/\$wpdb->(query|get_var|get_row|get_results|get_col)\s*\(/', $trimmed ) && ! preg_match( '/prepare\s*\(/', $trimmed ) ) {
					$context = implode( "\n", array_slice( $lines, max( 0, $index - 1 ), 3 ) );
					if ( false === strpos( $context, 'prepare(' ) ) {
						$issues[] = $this->build_issue(
							'high',
							'security',
							$file['relative_path'],
							$line_number,
							__( 'Direct database call without prepare()', 'mcp-auditor' ),
							__( 'A $wpdb query helper is used without evidence of parameter preparation nearby.', 'mcp-auditor' ),
							__( 'Wrap dynamic SQL in $wpdb->prepare() before executing it.', 'mcp-auditor' ),
							'heuristic'
						);
					}
				}

				if ( preg_match( '/\b(eval|base64_decode|gzinflate|shell_exec|exec|passthru|system|assert)\s*\(/', $trimmed ) ) {
					$issues[] = $this->build_issue(
						'high',
						'security',
						$file['relative_path'],
						$line_number,
						__( 'Dangerous function usage detected', 'mcp-auditor' ),
						__( 'The file uses a function frequently associated with code execution or obfuscation.', 'mcp-auditor' ),
						__( 'Remove the dangerous function or document the exact safe usage and surrounding safeguards.', 'mcp-auditor' ),
						'heuristic'
					);
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
		$issues = array();

		foreach ( $files as $file ) {
			if ( ! in_array( $file['extension'], array( 'php', 'js', 'html' ), true ) ) {
				continue;
			}

			$contents = file_get_contents( $file['path'] );
			if ( false === $contents ) {
				continue;
			}

			$patterns = array(
				'/wp_remote_(get|post|request)\s*\(/' => __( 'Outbound remote request helper found', 'mcp-auditor' ),
				'/navigator\.sendBeacon|fetch\s*\(|XMLHttpRequest/' => __( 'Browser-side network request found', 'mcp-auditor' ),
				'/google-analytics|googletagmanager|facebook\.com\/tr|mixpanel|segment|posthog|hotjar/i' => __( 'Tracking or analytics endpoint reference found', 'mcp-auditor' ),
			);

			foreach ( $patterns as $pattern => $title ) {
				if ( preg_match( $pattern, $contents, $matches, PREG_OFFSET_CAPTURE ) ) {
					$offset = isset( $matches[0][1] ) ? (int) $matches[0][1] : 0;
					$issues[] = $this->build_issue(
						'low',
						'privacy',
						$file['relative_path'],
						$this->line_from_offset( $contents, $offset ),
						$title,
						__( 'External requests or analytics code may require an explicit opt-in flow and privacy disclosures.', 'mcp-auditor' ),
						__( 'Review the code path to ensure user tracking is opt-in and clearly documented.', 'mcp-auditor' ),
						'heuristic'
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * @param array<string,mixed>           $artifact
	 * @param array<int,array<string,mixed>> $files
	 * @return array<int,array<string,mixed>>
	 */
	private function check_code_quality( array $artifact, array $files ): array {
		$issues = array();
		$lookup = array();

		foreach ( $files as $file ) {
			$lookup[ $file['relative_path'] ] = true;
		}

		foreach ( $files as $file ) {
			if ( ! in_array( $file['extension'], array( 'php', 'js', 'css' ), true ) ) {
				continue;
			}

			$contents = file_get_contents( $file['path'] );
			if ( false === $contents ) {
				continue;
			}

			$lines = preg_split( "/\r\n|\n|\r/", $contents );
			if ( false === $lines ) {
				continue;
			}

			foreach ( $lines as $index => $line ) {
				$length          = strlen( $line );
				$non_whitespace  = strlen( preg_replace( '/\s+/', '', $line ) );
				if ( $length > 400 && $non_whitespace / max( 1, $length ) > 0.92 ) {
					$issues[] = $this->build_issue(
						'medium',
						'code_quality',
						$file['relative_path'],
						$index + 1,
						__( 'Potentially minified or obfuscated code', 'mcp-auditor' ),
						__( 'A very long dense line suggests minified or difficult-to-review code.', 'mcp-auditor' ),
						__( 'Include readable source files alongside any minified assets and avoid obfuscated PHP entirely.', 'mcp-auditor' ),
						'heuristic'
					);
					break;
				}
			}

			if ( preg_match( '/file_put_contents\s*\(\s*(plugin_dir_path|__DIR__|dirname\s*\(__FILE__\)|WP_PLUGIN_DIR)/', $contents ) ) {
				$issues[] = $this->build_issue(
					'medium',
					'code_quality',
					$file['relative_path'],
					null,
					__( 'Writes into the plugin directory', 'mcp-auditor' ),
					__( 'The code appears to write files into the plugin directory, which is discouraged because plugin directories are replaced on update.', 'mcp-auditor' ),
					__( 'Store mutable files in uploads or another writable location outside the plugin directory.', 'mcp-auditor' ),
					'heuristic'
				);
			}

			if ( preg_match( '/\.min\.(js|css)$/', $file['relative_path'], $matches ) ) {
				$source_candidate = preg_replace( '/\.min\.(js|css)$/', '.' . $matches[1], $file['relative_path'] );
				if ( ! isset( $lookup[ $source_candidate ] ) ) {
					$issues[] = $this->build_issue(
						'low',
						'code_quality',
						$file['relative_path'],
						null,
						__( 'Minified asset without source file', 'mcp-auditor' ),
						__( 'A minified asset was found without an obvious unminified source file in the package.', 'mcp-auditor' ),
						__( 'Ship the original source file alongside minified assets to keep the code reviewable.', 'mcp-auditor' ),
						'heuristic'
					);
				}
			}
		}

		if ( empty( $artifact['metadata']['description'] ) ) {
			$issues[] = $this->build_issue(
				'low',
				'code_quality',
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
			if ( ! in_array( $file['extension'], array( 'php', 'html', 'twig' ), true ) ) {
				continue;
			}

			$contents = file_get_contents( $file['path'] );
			if ( false === $contents ) {
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

	private function line_from_offset( string $contents, int $offset ): int {
		return max( 1, substr_count( substr( $contents, 0, $offset ), "\n" ) + 1 );
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
				$order = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
				$left_rank  = $order[ $left['severity'] ] ?? 99;
				$right_rank = $order[ $right['severity'] ] ?? 99;

				if ( $left_rank !== $right_rank ) {
					return $left_rank <=> $right_rank;
				}

				return strcmp( (string) $left['file'], (string) $right['file'] );
			}
		);

		return $unique;
	}

	/**
	 * @param array<string,mixed>           $artifact
	 * @param array<int,string>             $checks
	 * @param array<int,array<string,mixed>> $issues
	 * @param array<int,string>             $notes
	 * @return array<string,mixed>
	 */
	private function build_report( array $artifact, array $checks, array $issues, array $notes, bool $use_ai, int $files_scanned ): array {
		$counts = array(
			'high'   => 0,
			'medium' => 0,
			'low'    => 0,
		);

		foreach ( $issues as $issue ) {
			$severity = $issue['severity'] ?? 'low';
			if ( isset( $counts[ $severity ] ) ) {
				++$counts[ $severity ];
			}
		}

		$summary_text = empty( $issues )
			? __( 'Audit completed with no issues detected by the current checks.', 'mcp-auditor' )
			: sprintf(
				/* translators: 1: issue count, 2: artifact name. */
				__( 'Audit completed with %1$d issue(s) detected for %2$s.', 'mcp-auditor' ),
				count( $issues ),
				$artifact['display_name']
			);

		return array(
			'status'         => 'completed',
			'generated_at'   => current_time( 'c' ),
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
				'text'   => $summary_text,
				'totals' => array(
					'all'    => count( $issues ),
					'high'   => $counts['high'],
					'medium' => $counts['medium'],
					'low'    => $counts['low'],
				),
			),
			'issues'         => $issues,
			'analysis'       => array(
				'files_scanned' => $files_scanned,
				'ai'            => array(
					'requested'  => $use_ai,
					'configured' => $this->openai_client->is_configured(),
					'model'      => $this->openai_client->get_model(),
				),
				'notes'         => array_values( array_unique( $notes ) ),
			),
			'report_post_id' => null,
			'report_edit_url'=> null,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_issue( string $severity, string $category, string $file, ?int $line, string $title, string $detail, string $recommendation, string $source ): array {
		return array(
			'severity'       => $severity,
			'category'       => $category,
			'file'           => $file,
			'line'           => $line,
			'title'          => $title,
			'detail'         => $detail,
			'recommendation' => $recommendation,
			'source'         => $source,
		);
	}
}
