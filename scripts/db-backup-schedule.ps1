# 毎日のバックアップ（scripts\db-backup-daily.ps1）を、タスクスケジューラに登録する（要件定義書 N-16）
#
# 使い方（リポジトリのいちばん上のフォルダで）：
#   登録する：powershell -ExecutionPolicy Bypass -File scripts\db-backup-schedule.ps1 -ExternalLabel 'ドライブの名前'
#   外付けなし：powershell -ExecutionPolicy Bypass -File scripts\db-backup-schedule.ps1
#   やめる　：powershell -ExecutionPolicy Bypass -File scripts\db-backup-schedule.ps1 -Remove
#
# 動くとき：ログオンしたとき（PC を使い始めたとき）と、毎日 12 時（-At '21:00' のように変えられる）。
# 同じ日に取れていれば、db-backup-daily.ps1 のほうが取らずに終わるので、何回動いても困らない。

param(
    # 外付けドライブの名前（エクスプローラーに出る名前）。空なら外付けへのコピーはしない
    [string]$ExternalLabel = '',

    # 毎日動かす時刻（24 時間の「時:分」）。ログオンしたときにも動くので、PC をつけっぱなしにした日の保険
    [string]$At = '12:00',

    # 登録をやめる
    [switch]$Remove
)

$ErrorActionPreference = 'Stop'

$taskName = 'kakeibo-db-backup'

if ($Remove) {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    Write-Host "登録をやめました：$taskName"
    return
}

# 名前に " が入ると、下で組み立てるコマンドが壊れるので入れない
if ($ExternalLabel -match '["`$]') {
    throw 'ドライブの名前に使えない文字（" ` $）が入っています'
}

$script = Join-Path $PSScriptRoot 'db-backup-daily.ps1'
$arguments = "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File `"$script`""
if ($ExternalLabel) {
    $arguments += " -ExternalLabel `"$ExternalLabel`""
}

$action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument $arguments -WorkingDirectory (Split-Path -Parent $PSScriptRoot)

# ログオンしたときと、毎日 12 時
$triggers = @(
    (New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME),
    (New-ScheduledTaskTrigger -Daily -At $At)
)

# StartWhenAvailable：12 時に PC が止まっていたら、次に使えるようになったときに動かす
# バッテリーでも動かす／電源を抜いても止めない（ノート PC で取りそこねないため）
# ExecutionTimeLimit：Docker を待つ時間（最大 10 分）より長くしておく
$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 30) -MultipleInstances IgnoreNew

# 今ログオンしている自分として動かす（画面での知らせを出すため）
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $triggers -Settings $settings -Principal $principal `
    -Description '家計簿の DB を 1 日 1 回保存し、外付けドライブにもコピーする' -Force | Out-Null

Write-Host "登録しました：$taskName"
Write-Host "今すぐ試すには：Start-ScheduledTask -TaskName $taskName"
