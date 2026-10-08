# Claude Code PreToolUse hook: blocks commit/push on master and force pushes.
# Exit code 2 blocks the tool call; the stderr message is shown to Claude.
# Keep this file ASCII-only: Windows PowerShell 5.1 misreads UTF-8 without BOM.

$payload = [Console]::In.ReadToEnd() | ConvertFrom-Json
$command = $payload.tool_input.command
if (-not $command) { exit 0 }

$isPush = $command -match '\bgit\b[^;&|]*\bpush\b'
$isCommit = $command -match '\bgit\b[^;&|]*\bcommit\b'
if (-not ($isPush -or $isCommit)) { exit 0 }

$cwd = $payload.cwd
if (-not $cwd) { $cwd = (Get-Location).Path }
$branch = (git -C $cwd branch --show-current 2>$null)

function Block($reason) {
    [Console]::Error.WriteLine("BLOCKED: $reason Follow the workflow in CLAUDE.md (issue -> branch -> PR).")
    exit 2
}

if ($isPush -and $command -match '(\s--force\b|\s--force-with-lease\b|\s-f\b)') {
    Block "Force push is not allowed."
}
if ($isPush -and $command -match '\bmaster\b') {
    Block "Pushing to master directly is not allowed."
}
if ($branch -eq 'master') {
    Block "You are on master. Commit/push on master is not allowed; create a working branch first."
}

exit 0
