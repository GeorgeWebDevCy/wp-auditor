# wp-auditor

A local WordPress MCP auditing environment built from the PDF spec in [docs/audit-tool-setup.md](docs/audit-tool-setup.md).

## What is included

- A Dockerized WordPress + MariaDB stack.
- Official `abilities-api` and `mcp-adapter` dependencies installed into `.wordpress/plugins`.
- A custom `mcp-auditor` plugin that:
  - exposes a direct MCP tool: `mcp-auditor-run-audit`
  - exposes MCP resources for installed plugins and themes
  - runs heuristic audits for licensing, security, privacy, uninstall behavior, code quality, and theme accessibility
  - optionally calls the OpenAI Responses API when `OPENAI_API_KEY` is set
  - stores reports as private WordPress admin entries under `Tools -> Audit Reports`
- PowerShell scripts for bootstrap and end-to-end MCP testing.

## Quick start

1. Copy `.env.example` to `.env` if you want to customize anything.
2. Run:

```powershell
pwsh ./scripts/bootstrap.ps1
```

3. Open [http://localhost:8081](http://localhost:8081).
4. Log into `/wp-admin` with:
   - username: `admin`
   - password: `admin123!`

## Verified commands

List the direct MCP tools exposed by the custom server:

```powershell
pwsh ./scripts/list-tools.ps1
```

Run an audit against the installed Hello Dolly plugin:

```powershell
pwsh ./scripts/run-audit.ps1
```

Run the same audit through WP-CLI instead of MCP:

```powershell
docker compose run --rm wp-cli wp wp-auditor audit hello-dolly --type=plugin --format=summary
```

## OpenAI integration

Set `OPENAI_API_KEY` in `.env` to enable deeper code analysis.

Optional tuning:

- `WP_AUDITOR_OPENAI_MODEL`
- `WP_AUDITOR_REASONING_EFFORT`
- `WP_AUDITOR_AI_FILE_LIMIT`
- `WP_AUDITOR_AI_CHAR_LIMIT`

Without an API key, the system still works end to end using heuristic checks only.

## Files

- `docs/audit-tool-setup.md`: Markdown conversion of the original PDF brief.
- `docker-compose.yml`: local stack definition.
- `plugins/mcp-auditor`: custom WordPress plugin.
- `scripts/bootstrap.ps1`: installs dependencies, starts containers, installs WordPress, activates plugins.
- `scripts/list-tools.ps1`: verifies the MCP tool server.
- `scripts/run-audit.ps1`: runs a live MCP audit call.
