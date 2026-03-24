# wp-auditor

A local WordPress plugin and theme audit stack built from the PDF brief in [docs/audit-tool-setup.md](docs/audit-tool-setup.md).

## What this does

The repo now runs audits at multiple levels:

- WordPress policy and packaging checks
- security and privacy heuristics
- uninstall and runtime smoke tests
- performance and quality checks
- external tooling via a dedicated `audit-tools` container:
  - PHP lint
  - PHPCS with WordPress standards
  - PHPStan
  - Composer audit
  - npm audit
  - ESLint

Reports are saved in WordPress under the top-level `WP Auditor` menu and rendered in a review-email style that includes the issue type, file location, why it matters, and how to fix it. The plugin also includes a dedicated `Run Audit` screen and a `Settings` screen for storing an OpenAI API key in WordPress.

## Stack

- Dockerized WordPress + MariaDB
- official `abilities-api` and `mcp-adapter` dependencies in `.wordpress/plugins`
- custom `mcp-auditor` plugin in `plugins/mcp-auditor`
- fake intentionally-broken demo plugin in `plugins/review-team-demo`
- `audit-tools` Docker image for external analyzers

## Quick start

1. Copy `.env.example` to `.env` if you want to customize ports or credentials.
2. Run:

```powershell
pwsh ./scripts/bootstrap.ps1
```

3. Open `http://localhost:8081`
4. Log into `/wp-admin` with:
   - username: `admin`
   - password: `admin123!`

## Useful commands

List the direct MCP tools:

```powershell
pwsh ./scripts/list-tools.ps1
```

Run the full end-to-end audit pipeline against the demo plugin and print the review email:

```powershell
pwsh ./scripts/run-demo-audit.ps1
```

Run the full pipeline against any installed plugin or theme:

```powershell
pwsh ./scripts/run-audit.ps1 -Slug review-team-demo -Type plugin -Format json
```

Resolve an installed artifact to its runtime paths:

```powershell
docker compose run --rm wp-cli wp wp-auditor resolve review-team-demo --type=plugin --format=json
```

Run the WordPress-side audit command directly:

```powershell
docker compose run --rm wp-cli wp wp-auditor audit review-team-demo --type=plugin --persist --format=email
```

## Demo plugin

`plugins/review-team-demo` is intentionally bad. It includes:

- missing licensing and uninstall metadata
- raw superglobals
- unprepared SQL
- `eval()` and `unserialize()`
- insecure upload and redirect handling
- missing REST `permission_callback`
- AJAX handlers without capability and nonce checks
- remote tracking requests and browser-side telemetry
- cron scheduling and oversized autoloaded options on activation
- vulnerable Composer and npm dependency locks

The saved report shows how the combined heuristic, runtime, and external-tooling findings are merged into one review email.
