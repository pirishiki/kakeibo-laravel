# db-backup.ps1・db-backup-daily.ps1・db-restore.ps1 で共有する部品（要件定義書 N-16）
# 使い方：各スクリプトの先頭で  . (Join-Path $PSScriptRoot 'db-backup-common.ps1')  と書いて読みこむ。

# バックアップのファイルが、最後まで書けているかを調べる。
# mysqldump は、最後まで書けたときだけ、終わりに「-- Dump completed on 日時」の行を書く。
# 途中で切れたファイル（電源が切れた・コピーが途中で止まった）には、この行がない。
function Test-DumpComplete([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) { return $false }
    if ((Get-Item -LiteralPath $Path).Length -eq 0) { return $false }
    $tail = Get-Content -LiteralPath $Path -Tail 3 -Encoding UTF8
    return [bool]($tail | Where-Object { $_ -like '-- Dump completed*' })
}

# 取る・戻す・整理が同時に動かないよう、1 つの鍵（Mutex）を共有する。
# 同時に動くと、戻している途中の空の DB を「今日のバックアップ」として保存したり、
# 書いている途中のファイルをコピーしたりしてしまうため。
# 取れなければ、待っても取れなかったことを例外で知らせる（黙って先へ進まない）。
function Enter-BackupLock([int]$WaitMinutes = 15) {
    # 同じ Windows ユーザーなら、管理者として開いた窓と、ふつうの窓・タスクスケジューラのあいだでも
    # 同じ鍵を使えるよう、自分のユーザーに権限を渡して作る（渡さないと、先に作った側だけが使えてしまう）
    $sid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User
    $security = New-Object System.Security.AccessControl.MutexSecurity
    $security.AddAccessRule((New-Object System.Security.AccessControl.MutexAccessRule($sid, 'FullControl', 'Allow')))
    $created = $false
    $mutex = New-Object System.Threading.Mutex($false, 'Local\kakeibo-db-backup', [ref]$created, $security)

    $script:BackupLockWasAbandoned = $false
    $got = $false
    try {
        $got = $mutex.WaitOne([TimeSpan]::FromMinutes($WaitMinutes))
    } catch [System.Threading.AbandonedMutexException] {
        # 前に鍵を持っていたスクリプトが、返さずに終わった（途中で窓を閉じた・強制終了した）。
        # 鍵は自分のものになっているが、前の作業が途中で止まった形跡なので、呼んだ側が知らせられるよう覚えておく
        $got = $true
        $script:BackupLockWasAbandoned = $true
    }
    if (-not $got) {
        $mutex.Dispose()
        throw "ほかのバックアップ・戻す作業が $WaitMinutes 分たっても終わらないため、待つのをやめました"
    }
    # 関数の返り値にすると、配列にくるまれないよう「,」をつける
    return , $mutex
}

function Exit-BackupLock($Mutex) {
    if ($Mutex) {
        try { $Mutex.ReleaseMutex() } catch { }
        $Mutex.Dispose()
    }
}
