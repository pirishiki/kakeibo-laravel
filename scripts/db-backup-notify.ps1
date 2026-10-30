# バックアップの問題を、画面の窓で知らせる（db-backup-daily.ps1 が、別のプロセスとして動かす）
#
# 別のプロセスにするのは、窓が「OK」を押されるまで開きっぱなしでも、毎日のスクリプトがそれを待たずに
# 終わり、タスクスケジューラの時間切れ（窓ごと消える）や、次の日の実行が待たされるのを防ぐため。
# 窓は、ほかの窓の後ろに隠れないよう、いちばん手前に出す。
# 窓を出せなかったときは、理由を daily.log に残す（窓は見えない場所で動くので、失敗が誰にも見えないのを防ぐ）。

param(
    # 知らせる文が書いてあるファイル（daily が作った一時ファイル。窓を出したあとに消す）
    [Parameter(Mandatory = $true)]
    [string]$TextFile,

    # 窓を出せなかったときに、理由を書くログのファイル（backups\daily.log）
    [string]$LogFile = ''
)

$ErrorActionPreference = 'Stop'

try {
    Add-Type -AssemblyName System.Windows.Forms

    $text = Get-Content -LiteralPath $TextFile -Raw -Encoding UTF8

    # 持ち主の窓を「いちばん手前」にして、その持ち主の窓として出す
    $owner = New-Object System.Windows.Forms.Form
    $owner.TopMost = $true
    [void][System.Windows.Forms.MessageBox]::Show($owner, $text, '家計簿のバックアップ', 'OK', 'Warning')
} catch {
    if ($LogFile) {
        try {
            Add-Content -Path $LogFile -Encoding UTF8 -Value ("{0:yyyy-MM-dd HH:mm:ss} 知らせの窓を出せませんでした：{1}" -f (Get-Date), $_.Exception.Message)
        } catch { }
    }
} finally {
    # 一時ファイルは、窓を閉じたあとに消す
    Remove-Item -LiteralPath $TextFile -Force -ErrorAction SilentlyContinue
}
