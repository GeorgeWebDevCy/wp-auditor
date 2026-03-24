$ErrorActionPreference = "Stop"

pwsh -NoProfile -File (Join-Path $PSScriptRoot "run-audit.ps1") -Slug "review-team-demo" -Type "plugin" -Format "email"
