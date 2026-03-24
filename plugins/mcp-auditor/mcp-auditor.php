<?php
/**
 * Plugin Name:       MCP Auditor
 * Description:       Audits WordPress plugins and themes with MCP abilities, heuristic checks, and optional OpenAI analysis.
 * Version:           0.1.0
 * Author:            Codex
 * Requires at least: 6.7
 * Requires PHP:      7.4
 * Text Domain:       mcp-auditor
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-report-repository.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-openai-client.php';
require_once __DIR__ . '/includes/class-audit-service.php';
require_once __DIR__ . '/includes/class-cli.php';
require_once __DIR__ . '/includes/class-plugin.php';

\MCPAuditor\Plugin::boot();
