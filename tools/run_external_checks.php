<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
	fwrite(STDERR, "This script must be run from the command line.\n");
	exit(1);
}

$options = parse_options($argv);
$root = isset($options['root']) ? (string) $options['root'] : '';
$type = isset($options['type']) ? (string) $options['type'] : 'plugin';

if ('' === $root || !is_dir($root)) {
	fwrite(STDERR, "A valid --root path is required.\n");
	exit(1);
}

$root = rtrim(str_replace('\\', '/', realpath($root) ?: $root), '/');
$files = collect_files($root);
$result = array(
	'issues' => array(),
	'notes' => array(),
	'analysis' => array(
		'tooling' => array(),
	),
);

$result['issues'] = array_merge($result['issues'], run_php_lint($root, $files, $result['analysis']['tooling']));
$result['issues'] = array_merge($result['issues'], run_phpcs($root, $files, $result['analysis']['tooling']));
$result['issues'] = array_merge($result['issues'], run_phpstan($root, $files, $result['analysis']['tooling']));
$result['issues'] = array_merge($result['issues'], run_composer_checks($root, $files, $result['analysis']['tooling']));
$result['issues'] = array_merge($result['issues'], run_npm_audit($root, $files, $result['analysis']['tooling']));
$result['issues'] = array_merge($result['issues'], run_eslint($root, $files, $result['analysis']['tooling']));
$result['notes'] = array_values(array_filter(array_unique($result['notes'])));

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

function parse_options(array $argv): array {
	$options = array();

	foreach (array_slice($argv, 1) as $argument) {
		if (0 !== strpos($argument, '--')) {
			continue;
		}

		$parts = explode('=', substr($argument, 2), 2);
		$key = $parts[0];
		$value = $parts[1] ?? 'true';
		$options[$key] = $value;
	}

	return $options;
}

function collect_files(string $root): array {
	$files = array();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
	);

	foreach ($iterator as $file) {
		if (!$file->isFile()) {
			continue;
		}

		$absolute = str_replace('\\', '/', $file->getPathname());
		$relative = ltrim(substr($absolute, strlen($root)), '/');

		$files[] = array(
			'absolute' => $absolute,
			'relative' => $relative,
			'extension' => strtolower(pathinfo($absolute, PATHINFO_EXTENSION)),
		);
	}

	return $files;
}

function run_php_lint(string $root, array $files, array &$tooling): array {
	$issues = array();
	$phpFiles = array_values(array_filter($files, static function (array $file): bool {
		return 'php' === $file['extension'] && !is_excluded_path($file['relative']);
	}));

	$tooling['php_lint'] = array(
		'status' => empty($phpFiles) ? 'skipped' : 'completed',
		'issues' => 0,
	);

	if (empty($phpFiles)) {
		$tooling['php_lint']['reason'] = 'No PHP files found.';
		return $issues;
	}

	foreach ($phpFiles as $file) {
		$run = run_command(array('php', '-l', $file['absolute']), $root);
		if (0 === $run['exit_code']) {
			continue;
		}

		$line = null;
		if (preg_match('/on line (\d+)/i', $run['stdout'] . "\n" . $run['stderr'], $matches)) {
			$line = (int) $matches[1];
		}

		$issues[] = build_issue(
			'medium',
			'quality',
			$file['relative'],
			$line,
			'PHP lint failure',
			trim(($run['stdout'] . "\n" . $run['stderr'])) ?: 'PHP lint reported a syntax error.',
			'Fix the PHP syntax error before packaging the artifact.',
			'php-lint'
		);
	}

	$tooling['php_lint']['issues'] = count($issues);
	return $issues;
}

function run_phpcs(string $root, array $files, array &$tooling): array {
	$phpFiles = array_values(array_filter($files, static function (array $file): bool {
		return 'php' === $file['extension'] && !is_excluded_path($file['relative']);
	}));

	$tooling['phpcs'] = array(
		'status' => empty($phpFiles) ? 'skipped' : 'completed',
		'issues' => 0,
	);

	if (empty($phpFiles)) {
		$tooling['phpcs']['reason'] = 'No PHP files found.';
		return array();
	}

	$run = run_command(
		array(
			'phpcs',
			'--report=json',
			'--standard=/workspace/tools/phpcs.xml.dist',
			$root,
		),
		$root
	);

	$payload = json_decode($run['stdout'], true);
	if (!is_array($payload) || empty($payload['files'])) {
		$tooling['phpcs']['status'] = 'failed';
		$tooling['phpcs']['error'] = trim($run['stdout'] . "\n" . $run['stderr']);
		return array();
	}

	$issues = array();
	foreach ($payload['files'] as $filePath => $data) {
		$relative = relative_path($root, (string) $filePath);
		foreach (($data['messages'] ?? array()) as $message) {
			if (!is_array($message)) {
				continue;
			}

			$mapping = map_phpcs_issue((string) ($message['source'] ?? ''), (string) ($message['message'] ?? ''));
			$issues[] = build_issue(
				$mapping['severity'],
				$mapping['category'],
				$relative,
				isset($message['line']) ? (int) $message['line'] : null,
				'PHPCS: ' . trim((string) ($message['message'] ?? 'Coding standards finding')),
				trim((string) ($message['message'] ?? 'WordPress coding standards found an issue.')),
				$mapping['recommendation'],
				'phpcs:' . (string) ($message['source'] ?? 'unknown')
			);
		}
	}

	$issues = limit_issues($issues, 25, $tooling['phpcs']);
	$tooling['phpcs']['issues'] = count($issues);
	return $issues;
}

function run_phpstan(string $root, array $files, array &$tooling): array {
	$phpFiles = array_values(array_filter($files, static function (array $file): bool {
		return 'php' === $file['extension'] && !is_excluded_path($file['relative']);
	}));

	$tooling['phpstan'] = array(
		'status' => empty($phpFiles) ? 'skipped' : 'completed',
		'issues' => 0,
	);

	if (empty($phpFiles)) {
		$tooling['phpstan']['reason'] = 'No PHP files found.';
		return array();
	}

	$run = run_command(
		array(
			'phpstan',
			'analyse',
			$root,
			'--no-progress',
			'--error-format=json',
			'--configuration=/workspace/tools/phpstan.neon',
		),
		$root
	);

	$payload = json_decode($run['stdout'], true);
	if (!is_array($payload)) {
		$tooling['phpstan']['status'] = 'failed';
		$tooling['phpstan']['error'] = trim($run['stdout'] . "\n" . $run['stderr']);
		return array();
	}

	$issues = array();
	foreach (($payload['files'] ?? array()) as $filePath => $fileData) {
		$relative = relative_path($root, (string) $filePath);
		foreach (($fileData['messages'] ?? array()) as $message) {
			$issues[] = build_issue(
				'medium',
				'quality',
				$relative,
				null,
				'PHPStan: ' . trim((string) $message),
				trim((string) $message),
				'Review the static analysis finding and update the code or baseline accordingly.',
				'phpstan'
			);
		}
	}

	$issues = limit_issues($issues, 20, $tooling['phpstan']);
	$tooling['phpstan']['issues'] = count($issues);
	return $issues;
}

function run_composer_checks(string $root, array $files, array &$tooling): array {
	$hasComposer = file_exists($root . '/composer.json') || file_exists($root . '/composer.lock');
	$tooling['composer'] = array(
		'status' => $hasComposer ? 'completed' : 'skipped',
		'issues' => 0,
	);

	if (!$hasComposer) {
		$tooling['composer']['reason'] = 'No composer.json or composer.lock found.';
		return array();
	}

	$issues = array();

	if (file_exists($root . '/composer.json')) {
		$validate = run_command(
			array('composer', 'validate', '--no-check-publish', '--format=json', '--working-dir=' . $root),
			$root
		);
		$validatePayload = json_decode($validate['stdout'], true);
		if (is_array($validatePayload)) {
			foreach (($validatePayload['errors'] ?? array()) as $message) {
				$issues[] = build_issue(
					'medium',
					'package',
					'composer.json',
					null,
					'Composer validation error',
					trim((string) $message),
					'Fix the Composer manifest so dependency tooling can parse it reliably.',
					'composer-validate'
				);
			}
			foreach (($validatePayload['warnings'] ?? array()) as $message) {
				$issues[] = build_issue(
					'low',
					'package',
					'composer.json',
					null,
					'Composer validation warning',
					trim((string) $message),
					'Review the Composer warning and tighten the package metadata if needed.',
					'composer-validate'
				);
			}
		}
	}

	if (file_exists($root . '/composer.lock')) {
		$audit = run_command(array('composer', 'audit', '--locked', '--format=json', '--working-dir=' . $root), $root);
		$auditPayload = json_decode($audit['stdout'], true);
		if (is_array($auditPayload)) {
			foreach (($auditPayload['advisories'] ?? array()) as $package => $advisories) {
				foreach ((array) $advisories as $advisory) {
					$severity = map_severity((string) ($advisory['severity'] ?? 'medium'));
					$issues[] = build_issue(
						$severity,
						'dependencies',
						'composer.lock',
						null,
						'Composer advisory for ' . $package,
						trim((string) ($advisory['title'] ?? 'Composer reported a dependency advisory.')),
						'Upgrade or replace the affected dependency before release.',
						'composer-audit'
					);
				}
			}

			foreach (($auditPayload['abandoned'] ?? array()) as $package => $replacement) {
				$replacementText = is_string($replacement) && '' !== $replacement ? ' Suggested replacement: ' . $replacement . '.' : '';
				$issues[] = build_issue(
					'low',
					'dependencies',
					'composer.lock',
					null,
					'Abandoned Composer package detected',
					'Composer reported the package "' . $package . '" as abandoned.' . $replacementText,
					'Replace abandoned dependencies with maintained alternatives when possible.',
					'composer-audit'
				);
			}
		}
	}

	$issues = limit_issues($issues, 20, $tooling['composer']);
	$tooling['composer']['issues'] = count($issues);
	return $issues;
}

function run_npm_audit(string $root, array $files, array &$tooling): array {
	$hasPackage = file_exists($root . '/package.json') || file_exists($root . '/package-lock.json') || file_exists($root . '/npm-shrinkwrap.json');
	$tooling['npm_audit'] = array(
		'status' => $hasPackage ? 'completed' : 'skipped',
		'issues' => 0,
	);

	if (!$hasPackage) {
		$tooling['npm_audit']['reason'] = 'No JavaScript dependency manifest found.';
		return array();
	}

	$run = run_command(array('npm', 'audit', '--json', '--omit=dev', '--prefix', $root), $root);
	$payload = json_decode($run['stdout'], true);

	if (!is_array($payload)) {
		$tooling['npm_audit']['status'] = 'failed';
		$tooling['npm_audit']['error'] = trim($run['stdout'] . "\n" . $run['stderr']);
		return array();
	}

	$issues = array();
	foreach (($payload['vulnerabilities'] ?? array()) as $package => $vulnerability) {
		if (!is_array($vulnerability)) {
			continue;
		}

		$via = $vulnerability['via'] ?? array();
		$primary = is_array($via) && isset($via[0]) && is_array($via[0]) ? $via[0] : array();
		$detail = trim((string) ($primary['title'] ?? $vulnerability['title'] ?? $vulnerability['name'] ?? 'npm audit reported a dependency vulnerability.'));
		$recommendation = 'Upgrade the affected package to a patched version before release.';

		if (isset($vulnerability['fixAvailable']) && is_array($vulnerability['fixAvailable']) && !empty($vulnerability['fixAvailable']['version'])) {
			$recommendation = sprintf(
				'Upgrade the affected package to %s or later before release.',
				(string) $vulnerability['fixAvailable']['version']
			);
		}

		$issues[] = build_issue(
			map_severity((string) ($vulnerability['severity'] ?? 'medium')),
			'dependencies',
			'package-lock.json',
			null,
			'NPM advisory for ' . $package,
			$detail,
			$recommendation,
			'npm-audit'
		);
	}

	$issues = limit_issues($issues, 20, $tooling['npm_audit']);
	$tooling['npm_audit']['issues'] = count($issues);
	return $issues;
}

function run_eslint(string $root, array $files, array &$tooling): array {
	$jsFiles = array_values(array_filter($files, static function (array $file): bool {
		return 'js' === $file['extension'] && !is_excluded_path($file['relative']);
	}));

	$tooling['eslint'] = array(
		'status' => empty($jsFiles) ? 'skipped' : 'completed',
		'issues' => 0,
	);

	if (empty($jsFiles)) {
		$tooling['eslint']['reason'] = 'No JavaScript files found.';
		return array();
	}

	$command = array('eslint', '-c', '/workspace/tools/eslint.config.cjs', '-f', 'json', '--no-error-on-unmatched-pattern');
	foreach ($jsFiles as $file) {
		$command[] = $file['absolute'];
	}

	$run = run_command($command, $root);
	$payload = json_decode($run['stdout'], true);
	if (!is_array($payload)) {
		$tooling['eslint']['status'] = 'failed';
		$tooling['eslint']['error'] = trim($run['stdout'] . "\n" . $run['stderr']);
		return array();
	}

	$issues = array();
	foreach ($payload as $entry) {
		if (!is_array($entry)) {
			continue;
		}

		$relative = relative_path($root, (string) ($entry['filePath'] ?? ''));
		foreach (($entry['messages'] ?? array()) as $message) {
			if (!is_array($message)) {
				continue;
			}

			$ruleId = (string) ($message['ruleId'] ?? 'eslint');
			$severity = 2 === (int) ($message['severity'] ?? 1) ? 'medium' : 'low';
			$category = in_array($ruleId, array('no-eval', 'no-implied-eval'), true) ? 'security' : 'quality';

			$issues[] = build_issue(
				$severity,
				$category,
				$relative,
				isset($message['line']) ? (int) $message['line'] : null,
				'ESLint: ' . trim((string) ($message['message'] ?? 'JavaScript lint finding')),
				trim((string) ($message['message'] ?? 'ESLint reported a JavaScript issue.')),
				'Fix the JavaScript lint finding or adjust the lint configuration if it is a reviewed false positive.',
				'eslint:' . $ruleId
			);
		}
	}

	$issues = limit_issues($issues, 20, $tooling['eslint']);
	$tooling['eslint']['issues'] = count($issues);
	return $issues;
}

function run_command(array $parts, string $cwd): array {
	$command = array_map('escape_shell_argument', $parts);
	$descriptorSpec = array(
		0 => array('pipe', 'r'),
		1 => array('pipe', 'w'),
		2 => array('pipe', 'w'),
	);

	$process = proc_open(implode(' ', $command), $descriptorSpec, $pipes, $cwd);
	if (!is_resource($process)) {
		return array('stdout' => '', 'stderr' => 'Failed to start process.', 'exit_code' => 1);
	}

	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[2]);
	$exitCode = proc_close($process);

	return array(
		'stdout' => false === $stdout ? '' : $stdout,
		'stderr' => false === $stderr ? '' : $stderr,
		'exit_code' => (int) $exitCode,
	);
}

function escape_shell_argument(string $value): string {
	return escapeshellarg($value);
}

function build_issue(string $severity, string $category, string $file, ?int $line, string $title, string $detail, string $recommendation, string $source): array {
	return array(
		'severity' => $severity,
		'category' => $category,
		'file' => $file,
		'line' => $line,
		'title' => $title,
		'detail' => $detail,
		'recommendation' => $recommendation,
		'source' => $source,
	);
}

function relative_path(string $root, string $path): string {
	$normalized = str_replace('\\', '/', $path);
	if (0 === strpos($normalized, $root)) {
		return ltrim(substr($normalized, strlen($root)), '/');
	}

	return $normalized;
}

function is_excluded_path(string $relativePath): bool {
	return 0 === strpos($relativePath, 'vendor/') || 0 === strpos($relativePath, 'node_modules/');
}

function map_phpcs_issue(string $source, string $message): array {
	$haystack = strtolower($source . ' ' . $message);

	if (false !== strpos($haystack, 'preparedsql') || false !== strpos($haystack, 'nonce') || false !== strpos($haystack, 'sanitize') || false !== strpos($haystack, 'escapeoutput') || false !== strpos($haystack, 'validatedsanitizedinput')) {
		return array(
			'severity' => false !== strpos($haystack, 'preparedsql') || false !== strpos($haystack, 'nonce') ? 'high' : 'medium',
			'category' => 'security',
			'recommendation' => 'Address the security coding standards finding and align the code with the relevant WordPress escaping, sanitization, nonce, or SQL APIs.',
		);
	}

	if (false !== strpos($haystack, 'i18n') || false !== strpos($haystack, 'prefixallglobals') || false !== strpos($haystack, 'textdomain')) {
		return array(
			'severity' => 'medium',
			'category' => 'wordpress',
			'recommendation' => 'Update the code to match WordPress packaging and i18n conventions.',
		);
	}

	if (false !== strpos($haystack, 'phpcompatibility')) {
		return array(
			'severity' => 'medium',
			'category' => 'quality',
			'recommendation' => 'Fix the PHP compatibility issue so the declared support range matches the code.',
		);
	}

	return array(
		'severity' => 'low',
		'category' => 'quality',
		'recommendation' => 'Fix the coding standards issue or document why the reviewed deviation is intentional.',
	);
}

function map_severity(string $severity): string {
	$normalized = strtolower($severity);
	if (in_array($normalized, array('critical', 'high'), true)) {
		return 'high';
	}

	if (in_array($normalized, array('moderate', 'medium'), true)) {
		return 'medium';
	}

	return 'low';
}

function limit_issues(array $issues, int $limit, array &$tooling): array {
	$total = count($issues);
	if ($total <= $limit) {
		return $issues;
	}

	$tooling['truncated'] = true;
	$tooling['reported'] = $limit;
	$tooling['total'] = $total;

	return array_slice($issues, 0, $limit);
}
