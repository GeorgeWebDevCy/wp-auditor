param(
    [string]$Slug = "hello-dolly",
    [ValidateSet("plugin", "theme")]
    [string]$Type = "plugin",
    [string[]]$Checks = @("licensing", "package", "wordpress", "security", "privacy", "uninstall", "dependencies", "performance", "quality", "runtime"),
    [ValidateSet("json", "summary", "email")]
    [string]$Format = "json",
    [switch]$UseAI,
    [switch]$Persist = $true
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$cacheRoot = Join-Path $repoRoot ".mcp-auditor"

if (-not (Test-Path $cacheRoot)) {
    New-Item -ItemType Directory -Path $cacheRoot | Out-Null
}

$externalFileHost = Join-Path $cacheRoot "$Type-$Slug-external.json"
$externalFileContainer = "/workspace/.mcp-auditor/$Type-$Slug-external.json"
$checksCsv = ($Checks -join ",")

$resolveArgs = @(
    "compose", "run", "--rm", "wp-cli",
    "wp", "wp-auditor", "resolve", $Slug,
    "--type=$Type",
    "--format=json"
)

$resolvedJson = & docker @resolveArgs
$resolved = $resolvedJson | ConvertFrom-Json

$toolArgs = @(
    "compose", "run", "--rm", "audit-tools",
    "php", "/workspace/tools/run_external_checks.php",
    "--root=$($resolved.root_path)",
    "--type=$Type"
)

$externalJson = & docker @toolArgs
Set-Content -Path $externalFileHost -Value $externalJson -Encoding UTF8

$auditArgs = @(
    "compose", "run", "--rm", "wp-cli",
    "wp", "wp-auditor", "audit", $Slug,
    "--type=$Type",
    "--checks=$checksCsv",
    "--external-file=$externalFileContainer",
    "--format=$Format"
)

if ($UseAI) {
    $auditArgs += "--use-ai"
}

if ($Persist) {
    $auditArgs += "--persist"
}

& docker @auditArgs
