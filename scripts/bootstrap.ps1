param(
    [switch]$RefreshDependencies
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$envFile = Join-Path $repoRoot ".env"
$exampleEnvFile = Join-Path $repoRoot ".env.example"

if (-not (Test-Path $envFile)) {
    Copy-Item $exampleEnvFile $envFile
}

& (Join-Path $PSScriptRoot "install-wordpress-deps.ps1") -Refresh:$RefreshDependencies

docker compose up -d db wordpress

$bootstrapCommand = @'
until wp db check --quiet; do
  sleep 2
done

if ! wp core is-installed; then
  wp core install \
    --url="$WORDPRESS_SITE_URL" \
    --title="$WORDPRESS_SITE_TITLE" \
    --admin_user="$WORDPRESS_ADMIN_USER" \
    --admin_password="$WORDPRESS_ADMIN_PASSWORD" \
    --admin_email="$WORDPRESS_ADMIN_EMAIL" \
    --skip-email
fi

wp option update home "$WORDPRESS_SITE_URL"
wp option update siteurl "$WORDPRESS_SITE_URL"

wp plugin activate abilities-api mcp-adapter mcp-auditor

if ! wp plugin is-installed hello-dolly; then
  wp plugin install hello-dolly --activate
else
  wp plugin activate hello-dolly
fi
'@

docker compose run --rm wp-cli sh -lc $bootstrapCommand

Write-Host "WordPress is ready at $(Get-Content $envFile | Where-Object { $_ -like 'WORDPRESS_SITE_URL=*' } | ForEach-Object { $_.Split('=', 2)[1] })"
Write-Host "Admin username: admin"
Write-Host "Admin password: admin123!"
