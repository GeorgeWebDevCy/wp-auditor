<?php

namespace MCPAuditor;

defined( 'ABSPATH' ) || exit;

class ReportRepository {
	const POST_TYPE = 'mcp_audit_report';

	public function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Audit Reports', 'mcp-auditor' ),
					'singular_name' => __( 'Audit Report', 'mcp-auditor' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'tools.php',
				'supports'            => array( 'title', 'editor' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'exclude_from_search' => true,
				'show_in_rest'        => false,
			)
		);

		add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
	}

	public function register_meta_boxes(): void {
		add_meta_box(
			'mcp-auditor-email-preview',
			__( 'Review Email Preview', 'mcp-auditor' ),
			array( $this, 'render_email_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'mcp-auditor-report-payload',
			__( 'Structured Report', 'mcp-auditor' ),
			array( $this, 'render_payload_meta_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);
	}

	public function render_email_meta_box( \WP_Post $post ): void {
		$email_preview = get_post_meta( $post->ID, '_mcp_auditor_email_preview', true );

		if ( empty( $email_preview ) ) {
			echo '<p>' . esc_html__( 'No review email preview is stored for this entry.', 'mcp-auditor' ) . '</p>';
			return;
		}

		echo '<textarea readonly rows="22" style="width:100%;font-family:monospace;">' . esc_textarea( $email_preview ) . '</textarea>';
	}

	public function render_payload_meta_box( \WP_Post $post ): void {
		$payload = get_post_meta( $post->ID, '_mcp_auditor_payload', true );

		if ( empty( $payload ) ) {
			echo '<p>' . esc_html__( 'No structured report is stored for this entry.', 'mcp-auditor' ) . '</p>';
			return;
		}

		echo '<textarea readonly rows="28" style="width:100%;font-family:monospace;">' . esc_textarea( $payload ) . '</textarea>';
	}

	public function save( array $report ): int {
		$artifact_label = isset( $report['artifact']['display_name'] ) ? $report['artifact']['display_name'] : __( 'Unknown artifact', 'mcp-auditor' );
		$type           = isset( $report['artifact']['type'] ) ? ucfirst( (string) $report['artifact']['type'] ) : __( 'Artifact', 'mcp-auditor' );
		$summary        = isset( $report['summary']['text'] ) ? (string) $report['summary']['text'] : __( 'Audit completed.', 'mcp-auditor' );
		$email_subject  = isset( $report['email']['subject'] ) ? (string) $report['email']['subject'] : $summary;
		$email_body     = isset( $report['email']['body'] ) ? (string) $report['email']['body'] : $summary;

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_status'  => 'private',
				'post_title'   => sprintf(
					'%s audit: %s (%s)',
					$type,
					$artifact_label,
					wp_date( 'Y-m-d H:i:s' )
				),
				'post_content' => $email_body,
				'meta_input'   => array(
					'_mcp_auditor_payload'       => wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
					'_mcp_auditor_email_subject' => $email_subject,
					'_mcp_auditor_email_preview' => $email_body,
				),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		return (int) $post_id;
	}
}
