<?php

namespace MCPAuditor;

defined( 'ABSPATH' ) || exit;

class OpenAIClient {
	/**
	 * @var Settings
	 */
	private $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @param array<string,mixed>           $artifact
	 * @param array<int,array<string,mixed>> $files
	 * @param array<int,string>             $checks
	 * @return array<string,mixed>
	 */
	public function analyze_candidates( array $artifact, array $files, array $checks ): array {
		$selected = $this->select_candidate_files( $files );
		$result   = array(
			'issues'           => array(),
			'notes'            => array(),
			'files_considered' => array(),
		);

		if ( empty( $selected ) ) {
			$result['notes'][] = __( 'AI analysis was enabled, but no eligible source files were selected.', 'mcp-auditor' );
			return $result;
		}

		foreach ( $selected as $file ) {
			$code = file_get_contents( $file['path'] );

			if ( false === $code || '' === $code ) {
				$result['notes'][] = sprintf(
					/* translators: %s: relative file path. */
					__( 'Skipped AI analysis for %s because the file could not be read.', 'mcp-auditor' ),
					$file['relative_path']
				);
				continue;
			}

			$chunks = $this->chunk_code( $code );
			$result['files_considered'][] = $file['relative_path'];

			foreach ( $chunks as $chunk ) {
				$response = $this->request_chunk_analysis( $artifact, $file, $checks, $chunk );

				if ( ! empty( $response['notes'] ) ) {
					$result['notes'] = array_merge( $result['notes'], $response['notes'] );
				}

				if ( ! empty( $response['issues'] ) ) {
					$result['issues'] = array_merge( $result['issues'], $response['issues'] );
				}
			}
		}

		return $result;
	}

	public function is_configured(): bool {
		return '' !== $this->settings->get_api_key();
	}

	public function get_model(): string {
		return $this->settings->get_model();
	}

	public function get_reasoning_effort(): string {
		return $this->settings->get_reasoning_effort();
	}

	public function get_file_limit(): int {
		return $this->settings->get_file_limit();
	}

	/**
	 * @param array<int,array<string,mixed>> $files
	 * @return array<int,array<string,mixed>>
	 */
	private function select_candidate_files( array $files ): array {
		$preferred_extensions = array( 'php' => 0, 'js' => 1, 'html' => 2, 'json' => 3, 'css' => 4 );

		$filtered = array_values(
			array_filter(
				$files,
				static function ( array $file ) use ( $preferred_extensions ): bool {
					return isset( $preferred_extensions[ $file['extension'] ] ) && $file['size'] <= 180000;
				}
			)
		);

		usort(
			$filtered,
			static function ( array $left, array $right ) use ( $preferred_extensions ): int {
				$left_rank  = $preferred_extensions[ $left['extension'] ] ?? 99;
				$right_rank = $preferred_extensions[ $right['extension'] ] ?? 99;

				if ( $left_rank !== $right_rank ) {
					return $left_rank <=> $right_rank;
				}

				if ( ! empty( $left['is_entry'] ) && empty( $right['is_entry'] ) ) {
					return -1;
				}

				if ( empty( $left['is_entry'] ) && ! empty( $right['is_entry'] ) ) {
					return 1;
				}

				return strcmp( $left['relative_path'], $right['relative_path'] );
			}
		);

		return array_slice( $filtered, 0, $this->get_file_limit() );
	}

	/**
	 * @param array<string,mixed> $artifact
	 * @param array<string,mixed> $file
	 * @param array<int,string>   $checks
	 * @param array<string,mixed> $chunk
	 * @return array<string,mixed>
	 */
	private function request_chunk_analysis( array $artifact, array $file, array $checks, array $chunk ): array {
		$developer_prompt = implode(
			"\n",
			array(
				'You are a WordPress plugin and theme security reviewer.',
				'Review the provided source code only for actionable issues.',
				'Focus on WordPress guideline compliance, security, privacy, uninstall behavior, code quality, and accessibility when relevant.',
				'Respond with JSON only using this shape: {"issues":[{"severity":"high|medium|low","category":"security|privacy|licensing|uninstall|code_quality|accessibility","title":"string","detail":"string","recommendation":"string","line":number|null,"confidence":"high|medium|low"}]}',
				'If there are no issues in the snippet, respond with {"issues":[]}.',
			)
		);

		$user_prompt = sprintf(
			"Artifact type: %s\nArtifact: %s\nFile: %s\nChunk starts at line: %d\nChecks: %s\n\n```%s\n%s\n```",
			$artifact['type'],
			$artifact['display_name'],
			$file['relative_path'],
			(int) $chunk['start_line'],
			implode( ', ', $checks ),
			$file['extension'],
			$chunk['content']
		);

		$payload = array(
			'model'             => $this->get_model(),
			'store'             => false,
			'max_output_tokens' => 1800,
			'reasoning'         => array(
				'effort' => $this->get_reasoning_effort(),
			),
			'input'             => array(
				array(
					'role'    => 'developer',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => $developer_prompt,
						),
					),
				),
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => $user_prompt,
						),
					),
				),
			),
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->settings->get_api_key(),
					'Content-Type'  => 'application/json',
				),
				'timeout' => 45,
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'issues' => array(),
				'notes'  => array(
					sprintf(
						/* translators: 1: file path, 2: error message. */
						__( 'OpenAI analysis failed for %1$s: %2$s', 'mcp-auditor' ),
						$file['relative_path'],
						$response->get_error_message()
					),
				),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$data   = json_decode( $body, true );

		if ( $status >= 400 || ! is_array( $data ) ) {
			return array(
				'issues' => array(),
				'notes'  => array(
					sprintf(
						/* translators: 1: file path, 2: HTTP status code. */
						__( 'OpenAI analysis returned an unexpected response for %1$s (HTTP %2$d).', 'mcp-auditor' ),
						$file['relative_path'],
						$status
					),
				),
			);
		}

		$text = $data['output_text'] ?? $this->extract_output_text( $data );
		$json = $this->decode_json_string( $text );

		if ( empty( $json['issues'] ) || ! is_array( $json['issues'] ) ) {
			return array(
				'issues' => array(),
				'notes'  => array(),
			);
		}

		$issues = array();

		foreach ( $json['issues'] as $issue ) {
			if ( ! is_array( $issue ) ) {
				continue;
			}

			$issues[] = array(
				'severity'       => $this->sanitize_severity( $issue['severity'] ?? 'low' ),
				'category'       => sanitize_key( (string) ( $issue['category'] ?? 'code_quality' ) ),
				'title'          => sanitize_text_field( (string) ( $issue['title'] ?? 'Potential issue' ) ),
				'detail'         => sanitize_textarea_field( (string) ( $issue['detail'] ?? '' ) ),
				'recommendation' => sanitize_textarea_field( (string) ( $issue['recommendation'] ?? '' ) ),
				'confidence'     => sanitize_key( (string) ( $issue['confidence'] ?? 'medium' ) ),
				'line'           => isset( $issue['line'] ) && null !== $issue['line'] ? max( 1, (int) $issue['line'] ) + ( (int) $chunk['start_line'] - 1 ) : null,
				'file'           => $file['relative_path'],
				'source'         => 'openai',
			);
		}

		return array(
			'issues' => $issues,
			'notes'  => array(),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function chunk_code( string $code ): array {
		$char_limit = $this->settings->get_char_limit();
		$lines      = preg_split( "/\r\n|\n|\r/", $code );
		$chunks     = array();
		$current    = array();
		$length     = 0;
		$start_line = 1;

		if ( false === $lines ) {
			return array();
		}

		foreach ( $lines as $index => $line ) {
			$line_length = strlen( $line ) + 1;

			if ( $length > 0 && ( $length + $line_length ) > $char_limit ) {
				$chunks[] = array(
					'start_line' => $start_line,
					'content'    => implode( "\n", $current ),
				);
				$current    = array();
				$length     = 0;
				$start_line = $index + 1;
			}

			$current[] = $line;
			$length   += $line_length;
		}

		if ( ! empty( $current ) ) {
			$chunks[] = array(
				'start_line' => $start_line,
				'content'    => implode( "\n", $current ),
			);
		}

		return $chunks;
	}

	private function decode_json_string( string $text ): array {
		$text = trim( $text );

		if ( '' === $text ) {
			return array();
		}

		$decoded = json_decode( $text, true );

		if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
			return $decoded;
		}

		if ( preg_match( '/\{.*\}/s', $text, $matches ) ) {
			$decoded = json_decode( $matches[0], true );
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return array();
	}

	private function extract_output_text( array $data ): string {
		if ( empty( $data['output'] ) || ! is_array( $data['output'] ) ) {
			return '';
		}

		foreach ( $data['output'] as $item ) {
			if ( empty( $item['content'] ) || ! is_array( $item['content'] ) ) {
				continue;
			}

			foreach ( $item['content'] as $content ) {
				if ( isset( $content['type'], $content['text'] ) && 'output_text' === $content['type'] ) {
					return (string) $content['text'];
				}
			}
		}

		return '';
	}

	private function sanitize_severity( string $severity ): string {
		$severity = sanitize_key( $severity );
		return in_array( $severity, array( 'high', 'medium', 'low' ), true ) ? $severity : 'low';
	}
}
