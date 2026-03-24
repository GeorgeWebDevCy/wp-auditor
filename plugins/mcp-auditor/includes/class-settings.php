<?php

namespace MCPAuditor;

defined( 'ABSPATH' ) || exit;

class Settings {
	const OPTION_GROUP = 'mcp_auditor_settings';
	const OPTION_NAME  = 'mcp_auditor_settings';

	/**
	 * @return array<string,mixed>
	 */
	public function get_defaults(): array {
		return array(
			'openai_api_key'    => '',
			'openai_model'      => 'gpt-5.4',
			'reasoning_effort'  => 'low',
			'ai_file_limit'     => 8,
			'ai_char_limit'     => 12000,
		);
	}

	public function register(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'default'           => $this->get_defaults(),
			)
		);
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$existing = $this->get();
		$defaults = $this->get_defaults();
		$output   = $defaults;

		$output['openai_api_key'] = (string) $existing['openai_api_key'];

		if ( ! empty( $input['clear_openai_api_key'] ) ) {
			$output['openai_api_key'] = '';
		} elseif ( isset( $input['openai_api_key'] ) ) {
			$openai_api_key = preg_replace( '/\s+/', '', (string) $input['openai_api_key'] );

			if ( is_string( $openai_api_key ) && '' !== $openai_api_key ) {
				$output['openai_api_key'] = $openai_api_key;
			}
		}

		$output['openai_model'] = isset( $input['openai_model'] ) ? sanitize_text_field( (string) $input['openai_model'] ) : (string) $defaults['openai_model'];

		$reasoning_effort = isset( $input['reasoning_effort'] ) ? sanitize_key( (string) $input['reasoning_effort'] ) : (string) $defaults['reasoning_effort'];
		if ( ! in_array( $reasoning_effort, array( 'minimal', 'low', 'medium', 'high' ), true ) ) {
			$reasoning_effort = (string) $defaults['reasoning_effort'];
		}

		$output['reasoning_effort'] = $reasoning_effort;
		$output['ai_file_limit']    = min( 20, max( 1, absint( $input['ai_file_limit'] ?? $defaults['ai_file_limit'] ) ) );
		$output['ai_char_limit']    = min( 40000, max( 2000, absint( $input['ai_char_limit'] ?? $defaults['ai_char_limit'] ) ) );

		return $output;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), $this->get_defaults() );
	}

	public function has_saved_api_key(): bool {
		$settings = $this->get();

		return ! empty( $settings['openai_api_key'] ) && is_string( $settings['openai_api_key'] );
	}

	public function get_masked_saved_api_key(): string {
		$settings = $this->get();
		$key      = isset( $settings['openai_api_key'] ) ? (string) $settings['openai_api_key'] : '';

		if ( '' === $key ) {
			return '';
		}

		if ( strlen( $key ) <= 8 ) {
			return str_repeat( '*', strlen( $key ) );
		}

		return substr( $key, 0, 3 ) . str_repeat( '*', max( 4, strlen( $key ) - 7 ) ) . substr( $key, -4 );
	}

	public function get_api_key(): string {
		$value = $this->get_effective_value( 'OPENAI_API_KEY', 'openai_api_key', '' );

		return is_string( $value['value'] ) ? trim( $value['value'] ) : '';
	}

	public function get_model(): string {
		$value = $this->get_effective_value( 'WP_AUDITOR_OPENAI_MODEL', 'openai_model', $this->get_defaults()['openai_model'] );

		return is_string( $value['value'] ) && '' !== trim( $value['value'] )
			? trim( $value['value'] )
			: (string) $this->get_defaults()['openai_model'];
	}

	public function get_reasoning_effort(): string {
		$value             = $this->get_effective_value( 'WP_AUDITOR_REASONING_EFFORT', 'reasoning_effort', $this->get_defaults()['reasoning_effort'] );
		$reasoning_effort  = sanitize_key( (string) $value['value'] );

		return in_array( $reasoning_effort, array( 'minimal', 'low', 'medium', 'high' ), true )
			? $reasoning_effort
			: (string) $this->get_defaults()['reasoning_effort'];
	}

	public function get_file_limit(): int {
		$value = $this->get_effective_value( 'WP_AUDITOR_AI_FILE_LIMIT', 'ai_file_limit', $this->get_defaults()['ai_file_limit'] );

		return min( 20, max( 1, (int) $value['value'] ) );
	}

	public function get_char_limit(): int {
		$value = $this->get_effective_value( 'WP_AUDITOR_AI_CHAR_LIMIT', 'ai_char_limit', $this->get_defaults()['ai_char_limit'] );

		return min( 40000, max( 2000, (int) $value['value'] ) );
	}

	public function get_api_key_source(): string {
		$value = $this->get_effective_value( 'OPENAI_API_KEY', 'openai_api_key', '' );

		return (string) $value['source'];
	}

	public function get_model_source(): string {
		$value = $this->get_effective_value( 'WP_AUDITOR_OPENAI_MODEL', 'openai_model', $this->get_defaults()['openai_model'] );

		return (string) $value['source'];
	}

	public function get_reasoning_effort_source(): string {
		$value = $this->get_effective_value( 'WP_AUDITOR_REASONING_EFFORT', 'reasoning_effort', $this->get_defaults()['reasoning_effort'] );

		return (string) $value['source'];
	}

	public function get_file_limit_source(): string {
		$value = $this->get_effective_value( 'WP_AUDITOR_AI_FILE_LIMIT', 'ai_file_limit', $this->get_defaults()['ai_file_limit'] );

		return (string) $value['source'];
	}

	public function get_char_limit_source(): string {
		$value = $this->get_effective_value( 'WP_AUDITOR_AI_CHAR_LIMIT', 'ai_char_limit', $this->get_defaults()['ai_char_limit'] );

		return (string) $value['source'];
	}

	public function get_source_label( string $source ): string {
		switch ( $source ) {
			case 'constant':
				return __( 'wp-config constant', 'mcp-auditor' );
			case 'environment':
				return __( 'environment variable', 'mcp-auditor' );
			case 'settings':
				return __( 'plugin settings', 'mcp-auditor' );
			default:
				return __( 'default value', 'mcp-auditor' );
		}
	}

	/**
	 * @param mixed $default
	 * @return array<string,mixed>
	 */
	private function get_effective_value( string $constant_name, string $option_key, $default ): array {
		if ( defined( $constant_name ) ) {
			return array(
				'value'  => constant( $constant_name ),
				'source' => 'constant',
			);
		}

		$environment_value = getenv( $constant_name );
		if ( false !== $environment_value && '' !== trim( (string) $environment_value ) ) {
			return array(
				'value'  => $environment_value,
				'source' => 'environment',
			);
		}

		$settings = $this->get();
		if ( array_key_exists( $option_key, $settings ) && '' !== trim( (string) $settings[ $option_key ] ) ) {
			return array(
				'value'  => $settings[ $option_key ],
				'source' => 'settings',
			);
		}

		return array(
			'value'  => $default,
			'source' => 'default',
		);
	}
}
