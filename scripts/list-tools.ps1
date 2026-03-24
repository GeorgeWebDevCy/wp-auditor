$ErrorActionPreference = "Stop"

$payload = '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'

docker compose run --rm wp-cli sh -lc "printf '%s' '$payload' | wp mcp-adapter serve --user=admin --server=mcp-auditor-server"
