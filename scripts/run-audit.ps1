param(
    [string]$Slug = "hello-dolly",
    [ValidateSet("plugin", "theme")]
    [string]$Type = "plugin",
    [string[]]$Checks = @("licensing", "security", "privacy", "uninstall", "code_quality"),
    [switch]$UseAI
)

$ErrorActionPreference = "Stop"

$checksJson = ($Checks | ConvertTo-Json -Compress)
$useAIJson = if ($UseAI) { "true" } else { "false" }
$payload = @"
{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"mcp-auditor-run-audit","arguments":{"slug":"$Slug","type":"$Type","checks":$checksJson,"use_ai":$useAIJson,"persist_report":true}}}
"@

docker compose run --rm wp-cli sh -lc "cat <<'EOF' | wp mcp-adapter serve --user=admin --server=mcp-auditor-server
$payload
EOF"
