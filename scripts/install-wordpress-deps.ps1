param(
    [switch]$Refresh
)

$ErrorActionPreference = "Stop"

$repoRoot = Split-Path -Parent $PSScriptRoot
$pluginsRoot = Join-Path $repoRoot ".wordpress\plugins"

$dependencies = @(
    @{
        Name = "abilities-api"
        Repo = "https://github.com/WordPress/abilities-api.git"
        Branch = "trunk"
    },
    @{
        Name = "mcp-adapter"
        Repo = "https://github.com/WordPress/mcp.git"
        Branch = "trunk"
    }
)

foreach ($dependency in $dependencies) {
    $target = Join-Path $pluginsRoot $dependency.Name

    if (-not (Test-Path $target)) {
        git clone --depth 1 --branch $dependency.Branch $dependency.Repo $target
    } elseif ($Refresh) {
        git -C $target pull --ff-only origin $dependency.Branch
    }

    docker run --rm -v "${target}:/app" -w /app composer:2 install --no-dev --prefer-dist --no-interaction
}
