# 開発用の MySQL の中身を 1 日 1 回自動で保存し、外付けドライブにもコピーする（要件定義書 N-16）
#
# 使い方：タスクスケジューラから動かす（登録は scripts\db-backup-schedule.ps1）。手で動かしてもよい。
#   powershell -ExecutionPolicy Bypass -File scripts\db-backup-daily.ps1 -ExternalLabel 'ドライブの名前'
#
# やること：
#   1. 今日の自動バックアップがなければ、scripts\db-backup.ps1 で取る（ファイル名の終わりが -auto）
#   2. 30 日より古い自動バックアップを消す（手で取ったものと、戻す前のもの（before-restore）は消さない）
#   3. 外付けドライブ（名前で探す）の kakeibo-backup フォルダに、まだ無いファイルをコピーする
#   4. 取れなかった・外付けに 7 日以上コピーできていないときは、画面で知らせる（黙って終わらない）
#
# 結果は backups\daily.log に残る。問題があるあいだは backups\backup-problem.txt ができる。

param(
    # 外付けドライブの名前（エクスプローラーに出る名前）。空なら外付けへのコピーはしない
    [string]$ExternalLabel = '',

    # 外付けドライブの中のフォルダ名
    [string]$ExternalFolder = 'kakeibo-backup',

    # 自動バックアップを残す日数
    [int]$KeepDays = 30,

    # 外付けへ最後にコピーしてから、この日数をこえたら知らせる
    [int]$WarnAfterDays = 7,

    # 画面での知らせを出さない（試すとき用。問題のファイルとログは今までどおり残る）
    [switch]$NoPopup
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
$backups = Join-Path $root 'backups'
New-Item -ItemType Directory -Force $backups | Out-Null
$logFile = Join-Path $backups 'daily.log'
$problemFile = Join-Path $backups 'backup-problem.txt'
$lastCopyFile = Join-Path $backups 'last-external-copy.txt'

function Write-Log([string]$message) {
    $line = "{0:yyyy-MM-dd HH:mm:ss} {1}" -f (Get-Date), $message
    Add-Content -Path $logFile -Value $line -Encoding UTF8
    Write-Host $line
}

# 問題の一覧。最後にまとめて知らせる
$problems = New-Object System.Collections.Generic.List[string]

# --- 1. 今日の自動バックアップ ---
try {
    $today = Get-Date -Format 'yyyyMMdd'
    $already = Get-ChildItem $backups -Filter "kakeibo-$today-*-auto.sql" -ErrorAction SilentlyContinue |
        Where-Object { $_.Length -gt 0 }

    if ($already) {
        Write-Log "今日の自動バックアップは取れているので、取りません：$($already[0].Name)"
    } else {
        # PC を起動した直後は Docker がまだ動いていないことがあるので、MySQL が答えるまで最大 10 分待つ
        $ready = $false
        for ($i = 0; $i -lt 20; $i++) {
            docker compose exec -T mysql mysqladmin ping -h 127.0.0.1 --silent 2>$null | Out-Null
            if ($LASTEXITCODE -eq 0) { $ready = $true; break }
            Start-Sleep -Seconds 30
        }
        if (-not $ready) { throw 'MySQL（Docker）が 10 分待っても動きませんでした' }

        & (Join-Path $PSScriptRoot 'db-backup.ps1') -Suffix 'auto'

        # 取れたファイルが本当にあり、空でないことを確かめる
        $made = Get-ChildItem $backups -Filter "kakeibo-$today-*-auto.sql" |
            Where-Object { $_.Length -gt 0 }
        if (-not $made) { throw '自動バックアップのファイルができていません' }
        Write-Log "自動バックアップを取りました：$($made[0].Name)"
    }
} catch {
    $problems.Add("自動バックアップを取れませんでした：$($_.Exception.Message)")
    Write-Log $problems[$problems.Count - 1]
}

# --- 2. 古い自動バックアップの整理 ---
# 取れていない日が続いたときに全部消えないよう、新しいほうの 1 件は日数にかかわらず残す
try {
    $autos = @(Get-ChildItem $backups -Filter 'kakeibo-*-auto.sql' | Sort-Object LastWriteTime -Descending)
    $limit = (Get-Date).AddDays(-$KeepDays)
    foreach ($file in ($autos | Select-Object -Skip 1)) {
        if ($file.LastWriteTime -lt $limit) {
            Remove-Item $file.FullName
            Write-Log "古い自動バックアップを消しました：$($file.Name)"
        }
    }
} catch {
    $problems.Add("古いバックアップの整理に失敗しました：$($_.Exception.Message)")
    Write-Log $problems[$problems.Count - 1]
}

# --- 3. 外付けドライブへのコピー ---
if ($ExternalLabel) {
    try {
        $volume = Get-Volume | Where-Object { $_.DriveLetter -and $_.FileSystemLabel -eq $ExternalLabel } | Select-Object -First 1
        if ($volume) {
            $destDir = "$($volume.DriveLetter):\$ExternalFolder"
            New-Item -ItemType Directory -Force $destDir | Out-Null
            $copied = 0
            # 同じ名前のファイルがすでにあれば、上書きしない（バックアップは中身が変わらないため）
            foreach ($file in Get-ChildItem $backups -Filter 'kakeibo-*.sql') {
                $target = Join-Path $destDir $file.Name
                if (-not (Test-Path $target)) {
                    Copy-Item $file.FullName $target
                    # コピーできたかを、大きさで確かめる
                    if ((Get-Item $target).Length -ne $file.Length) { throw "コピーが途中で切れています：$($file.Name)" }
                    $copied++
                }
            }
            Set-Content -Path $lastCopyFile -Value (Get-Date -Format 'o') -Encoding UTF8
            Write-Log "外付けドライブ（$($volume.DriveLetter):）に $copied 件コピーしました"
        } else {
            Write-Log "外付けドライブ『$ExternalLabel』は見つかりません（差していない）"
        }
    } catch {
        $problems.Add("外付けドライブへのコピーに失敗しました：$($_.Exception.Message)")
        Write-Log $problems[$problems.Count - 1]
    }

    # 最後にコピーできた日から日数がたちすぎていないか（差し忘れに気づくため）
    $lastCopy = $null
    if (Test-Path $lastCopyFile) {
        $lastCopy = [datetime]::Parse((Get-Content $lastCopyFile -Raw).Trim())
    }
    if (-not $lastCopy) {
        $problems.Add('外付けドライブへは、まだ一度もコピーできていません。ドライブを差してください')
    } elseif ($lastCopy -lt (Get-Date).AddDays(-$WarnAfterDays)) {
        $problems.Add("外付けドライブへ最後にコピーしたのは $($lastCopy.ToString('yyyy-MM-dd')) です（$WarnAfterDays 日以上前）。ドライブを差してください")
    }
}

# --- 4. 知らせる ---
if ($problems.Count -gt 0) {
    $text = "家計簿のバックアップに問題があります。`r`n`r`n" + ($problems -join "`r`n") + "`r`n`r`n（くわしくは backups\daily.log）"
    Set-Content -Path $problemFile -Value $text -Encoding UTF8
    if (-not $NoPopup) {
        Add-Type -AssemblyName PresentationFramework
        [System.Windows.MessageBox]::Show($text, '家計簿のバックアップ') | Out-Null
    }
    exit 1
}

# 問題がなくなったら、前の問題のファイルを消す
if (Test-Path $problemFile) { Remove-Item $problemFile }
Write-Log 'バックアップは問題ありません'
