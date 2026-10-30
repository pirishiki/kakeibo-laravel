# バックアップの問題を、画面の窓で知らせる（db-backup-daily.ps1 が、別のプロセスとして動かす）
#
# 別のプロセスにするのは、窓が「OK」を押されるまで開きっぱなしでも、毎日のスクリプトがそれを待たずに
# 終わり、タスクスケジューラの時間切れ（窓ごと消える）や、次の日の実行が待たされるのを防ぐため。
# 窓は、ほかの窓の後ろに隠れないよう、いちばん手前に出す。

param(
    # 知らせる文が書いてあるファイル（backups\backup-problem.txt）
    [Parameter(Mandatory = $true)]
    [string]$TextFile
)

$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName System.Windows.Forms

$text = Get-Content -LiteralPath $TextFile -Raw -Encoding UTF8

# 持ち主の窓を「いちばん手前」にして、その持ち主の窓として出す
$owner = New-Object System.Windows.Forms.Form
$owner.TopMost = $true
[void][System.Windows.Forms.MessageBox]::Show($owner, $text, '家計簿のバックアップ', 'OK', 'Warning')
