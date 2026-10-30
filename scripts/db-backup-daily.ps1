# 開発用の MySQL の中身を 1 日 1 回自動で保存し、外付けドライブにもコピーする（要件定義書 N-16）
#
# 使い方：タスクスケジューラから動かす（登録は scripts\db-backup-schedule.ps1）。手で動かしてもよい。
#   powershell -ExecutionPolicy Bypass -File scripts\db-backup-daily.ps1 -ExternalLabel 'ドライブの名前'
#
# やること：
#   1. 今日の自動バックアップがなければ、scripts\db-backup.ps1 で取る（ファイル名の終わりが -auto）
#   2. 古い自動バックアップを消す（30 日より古く、しかも新しい 7 件より前のものだけ。手で取ったものと
#      戻す前のもの（before-restore）は消さない）
#   3. 外付けドライブ（ボリュームラベルで探す）の kakeibo-backup フォルダに、まだ無い・中身がちがうファイルをコピーする
#   4. 取れなかった・外付けに 7 日以上コピーできていないときは、画面で知らせる（黙って終わらない）
#
# 結果は backups\daily.log に残る。問題があるあいだは backups\backup-problem.txt ができる。
# どこかで思いがけないエラーが起きても、それも「問題」として知らせる（知らせずに落ちない）。

param(
    # 外付けドライブの名前（ボリュームラベル。エクスプローラーのドライブの名前）。空なら外付けへのコピーはしない
    [string]$ExternalLabel = '',

    # 外付けドライブの中のフォルダ名
    [string]$ExternalFolder = 'kakeibo-backup',

    # 自動バックアップを消してよくなる日数（新しい 7 件は、日数にかかわらず残す）
    [int]$KeepDays = 30,

    # 外付けへ最後にコピーしてから、この日数をこえたら知らせる
    [int]$WarnAfterDays = 7,

    # 画面での知らせを出さない（試すとき用。問題のファイルとログは今までどおり残る）
    [switch]$NoPopup
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
. (Join-Path $PSScriptRoot 'db-backup-common.ps1')

$backups = Join-Path $root 'backups'
$logFile = Join-Path $backups 'daily.log'
$problemFile = Join-Path $backups 'backup-problem.txt'
$lastCopyFile = Join-Path $backups 'last-external-copy.txt'

# ログに書けなくても、知らせは出す（ログのファイルが開けないときに、知らせまで落ちないため）
function Write-Log([string]$message) {
    $line = "{0:yyyy-MM-dd HH:mm:ss} {1}" -f (Get-Date), $message
    try { Add-Content -Path $logFile -Value $line -Encoding UTF8 } catch { }
    Write-Host $line
}

# 問題の一覧。ログにも残し、最後にまとめて知らせる
$problems = New-Object System.Collections.Generic.List[string]
function Add-Problem([string]$message) {
    $problems.Add($message)
    Write-Log $message
}

$lock = $null
try {
    New-Item -ItemType Directory -Force $backups | Out-Null

    # 取る・戻す・整理が同時に動かないよう、鍵を取る（取れなければ、待っても取れなかったと知らせる）
    $lock = Enter-BackupLock
    if ($BackupLockWasAbandoned) {
        # 前に鍵を持っていた作業（取る・戻す）が、最後まで行かずに止まった（窓を閉じた・強制終了した）
        Add-Problem '前のバックアップか、戻す作業が、途中で止まった形跡があります。戻す作業の途中だった場合は、データベースが空か途中までかもしれません'
    }

    # --- 0. 壊れたバックアップを、別のフォルダへ避ける ---
    # 最後まで書けていない .sql が backups\ に残っていると、外付けへコピーできない知らせが毎日出つづけ、
    # 本物の問題に埋もれてしまう。消さずに backups\broken\ へ移し、移したことを一度だけ知らせる
    try {
        $brokenDir = Join-Path $backups 'broken'
        foreach ($file in @(Get-ChildItem $backups -File | Where-Object { $_.Name -match '^kakeibo-.*\.sql$' })) {
            if (-not (Test-DumpComplete $file.FullName)) {
                New-Item -ItemType Directory -Force $brokenDir | Out-Null
                Move-Item -LiteralPath $file.FullName -Destination (Join-Path $brokenDir $file.Name) -Force
                Add-Problem "最後まで書けていないバックアップを、backups\broken\ に移しました：$($file.Name)"
            }
        }
    } catch {
        Add-Problem "壊れたバックアップを避ける処理に失敗しました：$($_.Exception.Message)"
    }

    # --- 1. 今日の自動バックアップ ---
    try {
        $today = Get-Date -Format 'yyyyMMdd'
        # 戻している途中で止まっていると、いまのデータベースは空か途中まで。それを今日のバックアップにしてしまわない
        $restoreMarker = Join-Path $backups 'restore-in-progress-kakeibo.txt'
        if (Test-Path -LiteralPath $restoreMarker) {
            throw "戻す作業が途中で止まったままです。自動バックアップは取りません。中身を確かめて、戻し直してください（$restoreMarker）"
        }
        # 名前だけでなく、最後まで書けているファイルだけを「取れている」と数える
        $already = @(Get-ChildItem $backups -File |
            Where-Object { $_.Name -match "^kakeibo-$today-\d{6}-auto\.sql$" -and (Test-DumpComplete $_.FullName) })

        if ($already.Count -gt 0) {
            Write-Log "今日の自動バックアップは取れているので、取りません：$($already[0].Name)"
        } else {
            # PC を起動した直後は Docker がまだ動いていないことがあるので、MySQL が答えるまで最大 10 分待つ。
            # cmd /c の中でエラー出力を捨てる（PowerShell 5.1 は、外部コマンドのエラー出力が 1 行でも出ると、
            # $ErrorActionPreference='Stop' のときは例外にして、待たずに失敗してしまうため）
            $ready = $false
            for ($i = 0; $i -lt 20; $i++) {
                cmd /c "docker compose exec -T mysql mysqladmin ping -h 127.0.0.1 --silent >nul 2>nul"
                if ($LASTEXITCODE -eq 0) { $ready = $true; break }
                Start-Sleep -Seconds 30
            }
            if (-not $ready) { throw 'MySQL（Docker）が 10 分待っても動きませんでした。Docker Desktop が起動しているか見てください' }

            # db-backup.ps1 が最後に返す、できたファイルの場所を使う（日付をまたいでも、まちがえない）
            $made = & (Join-Path $PSScriptRoot 'db-backup.ps1') -Suffix 'auto' -AlreadyLocked | Select-Object -Last 1
            if (-not $made -or -not (Test-DumpComplete ([string]$made))) {
                throw '自動バックアップのファイルが、できていないか、最後まで書けていません'
            }
            Write-Log "自動バックアップを取りました：$(Split-Path -Leaf ([string]$made))"
        }
    } catch {
        Add-Problem "自動バックアップを取れませんでした：$($_.Exception.Message)"
    }

    # --- 2. 古い自動バックアップの整理 ---
    # 日付は、ファイルの名前から読む（PC の時計がずれても、ファイルの更新日時にまどわされないため）
    try {
        $autos = @(Get-ChildItem $backups -File | ForEach-Object {
            $item = $_
            if ($item.Name -match '^kakeibo-(\d{8})-\d{6}-auto\.sql$') {
                try {
                    [pscustomobject]@{
                        File = $item
                        Date = [datetime]::ParseExact($Matches[1], 'yyyyMMdd', [cultureinfo]::InvariantCulture)
                    }
                } catch {
                    # 日付として読めない名前（99999999 など）は、整理の対象にせず残す。整理全体は止めない
                    Write-Log "日付が読めない名前のため、整理の対象にしません：$($item.Name)"
                }
            }
        })
        $todayDate = (Get-Date).Date
        if (@($autos | Where-Object { $_.Date -gt $todayDate }).Count -gt 0) {
            # 時計が先に飛んでいると、新しいバックアップまで「古い」と見なして消してしまう
            Add-Problem '未来の日付の自動バックアップがあります（PC の時計がずれているかもしれません）。整理は止めました'
        } else {
            $limit = $todayDate.AddDays(-$KeepDays)
            # 新しいものから 7 件は、日数にかかわらず残す
            $sorted = @($autos | Sort-Object -Property @{ Expression = { $_.File.Name }; Descending = $true })
            foreach ($item in ($sorted | Select-Object -Skip 7)) {
                if ($item.Date -lt $limit) {
                    Remove-Item -LiteralPath $item.File.FullName -Force
                    Write-Log "古い自動バックアップを消しました：$($item.File.Name)"
                }
            }
        }
    } catch {
        Add-Problem "古いバックアップの整理に失敗しました：$($_.Exception.Message)"
    }

    # --- 3. 外付けドライブへのコピー ---
    if ($ExternalLabel) {
        try {
            $volumes = @(Get-Volume | Where-Object { $_.DriveLetter -and $_.FileSystemLabel -eq $ExternalLabel })
            if ($volumes.Count -eq 0) {
                Write-Log "外付けドライブ『$ExternalLabel』は見つかりません（差していない）"
            }
            $allCopied = ($volumes.Count -gt 0)
            # 同じ名前のドライブが 2 つ差さっていたら、両方にコピーする
            foreach ($volume in $volumes) {
                try {
                    $destDir = "$($volume.DriveLetter):\$ExternalFolder"
                    New-Item -ItemType Directory -Force $destDir | Out-Null
                    $copied = 0
                    foreach ($file in (Get-ChildItem $backups -File | Where-Object { $_.Name -match '^kakeibo-.*\.sql$' })) {
                        # 途中で切れたファイルは、コピーしても守りにならないので、問題として知らせる
                        if (-not (Test-DumpComplete $file.FullName)) {
                            Add-Problem "バックアップが最後まで書けていません（コピーしません）：$($file.Name)"
                            $allCopied = $false
                            continue
                        }
                        $target = Join-Path $destDir $file.Name
                        $srcHash = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash
                        # すでにあっても、中身が同じとわかったものだけ飛ばす（前に途中で切れたファイルを直すため）
                        if ((Test-Path -LiteralPath $target) -and ((Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash -eq $srcHash)) {
                            continue
                        }
                        # 別の名前（.tmp）に写し、中身が同じと確かめてから本当の名前にする
                        # （写している途中でドライブを抜いても、途中のファイルが本物に見えないように）
                        $tmp = "$target.tmp"
                        Copy-Item -LiteralPath $file.FullName -Destination $tmp -Force
                        if ((Get-FileHash -LiteralPath $tmp -Algorithm SHA256).Hash -ne $srcHash) {
                            Remove-Item -LiteralPath $tmp -Force
                            throw "コピーした中身が元とちがいます：$($file.Name)"
                        }
                        Move-Item -LiteralPath $tmp -Destination $target -Force
                        $copied++
                    }
                    Write-Log "外付けドライブ（$($volume.DriveLetter):）に $copied 件コピーしました"
                } catch {
                    $allCopied = $false
                    Add-Problem "外付けドライブ（$($volume.DriveLetter):）へのコピーに失敗しました：$($_.Exception.Message)"
                }
            }
            # 全部のファイルを、すべてのドライブにコピーできたときだけ「最後にコピーした日」を進める。
            # 書いている途中で切れても読める形にするため、別の名前に書いてから入れかえる
            if ($allCopied) {
                $lastCopyTmp = "$lastCopyFile.tmp"
                Set-Content -Path $lastCopyTmp -Value (Get-Date -Format 'o') -Encoding UTF8
                Move-Item -LiteralPath $lastCopyTmp -Destination $lastCopyFile -Force
            }
        } catch {
            Add-Problem "外付けドライブへのコピーに失敗しました：$($_.Exception.Message)"
        }

        # 最後にコピーできた日から日数がたちすぎていないか（差し忘れに気づくため）
        $lastCopy = $null
        $unreadable = $false
        if (Test-Path -LiteralPath $lastCopyFile) {
            try {
                $lastCopyText = ((Get-Content -LiteralPath $lastCopyFile -Raw) | Out-String).Trim()
                $lastCopy = [datetime]::Parse($lastCopyText, [cultureinfo]::InvariantCulture, [System.Globalization.DateTimeStyles]::RoundtripKind)
            } catch {
                # 空・こわれているときは、「いつコピーしたかわからない」として知らせる
                $unreadable = $true
            }
        }
        if ($unreadable) {
            Add-Problem '外付けドライブへ最後にコピーした日が読めません。ドライブを差して、もう一度動かしてください'
        } elseif (-not $lastCopy) {
            Add-Problem '外付けドライブへは、まだ一度もコピーできていません。ドライブを差してください'
        } elseif ($lastCopy -lt (Get-Date).AddDays(-$WarnAfterDays)) {
            Add-Problem "外付けドライブへ最後にコピーしたのは $($lastCopy.ToString('yyyy-MM-dd')) です（$WarnAfterDays 日以上前）。ドライブを差してください"
        }
    }
} catch {
    # 思いがけないエラーも、黙って落ちずに知らせる
    Add-Problem "思いがけないエラーで、バックアップの処理が止まりました：$($_.Exception.Message)"
} finally {
    Exit-BackupLock $lock
}

# --- 4. 知らせる ---
if ($problems.Count -gt 0) {
    $text = "家計簿のバックアップに問題があります。`r`n`r`n" + ($problems -join "`r`n") + "`r`n`r`n（くわしくは backups\daily.log）"
    try {
        Set-Content -Path $problemFile -Value $text -Encoding UTF8
    } catch {
        Write-Log "問題のファイルを書けませんでした：$($_.Exception.Message)"
    }
    if (-not $NoPopup) {
        # 窓は別のプロセスで出し、「OK」を待たずにこのスクリプトを終わらせる。
        # 文は、backups\ の問題のファイルではなく、専用の一時ファイルで渡す
        # （問題のファイルを書けなかった・前の古い文が残っているときでも、今回の文を出すため。窓を出したあと、窓の側で消す）
        try {
            $noticeFile = Join-Path $env:TEMP "kakeibo-backup-notice-$([guid]::NewGuid().ToString('N')).txt"
            Set-Content -Path $noticeFile -Value $text -Encoding UTF8
            Start-Process -FilePath 'powershell.exe' -WindowStyle Hidden -ArgumentList @(
                '-NoProfile', '-ExecutionPolicy', 'Bypass', '-WindowStyle', 'Hidden',
                '-File', "`"$(Join-Path $PSScriptRoot 'db-backup-notify.ps1')`"",
                '-TextFile', "`"$noticeFile`"",
                '-LogFile', "`"$logFile`""
            )
        } catch {
            Write-Log "知らせの窓を出せませんでした：$($_.Exception.Message)"
        }
    }
    exit 1
}

# 問題がなくなったら、前の問題のファイルを消す
try {
    if (Test-Path -LiteralPath $problemFile) { Remove-Item -LiteralPath $problemFile -Force }
} catch {
    Write-Log "前の問題のファイルを消せませんでした：$($_.Exception.Message)"
}
Write-Log 'バックアップは問題ありません'
