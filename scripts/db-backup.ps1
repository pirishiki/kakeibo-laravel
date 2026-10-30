# 開発用の MySQL（Docker の箱）の中身を、ファイルに保存する（要件定義書 N-16）
#
# 使い方（リポジトリのいちばん上のフォルダで）：
#   powershell -ExecutionPolicy Bypass -File scripts\db-backup.ps1
#   powershell -ExecutionPolicy Bypass -File scripts\db-backup.ps1 -Database kakeibo_testing
#
# できるファイル：backups\kakeibo-年月日-時分秒.sql（Git には入れない。中身は自分のお金の記録のため）
# 元に戻すときは scripts\db-restore.ps1 を使う。
#
# 保存の途中のファイルは .partial という名前で作り、最後まで書けたと確かめてから .sql に変える。
# 途中で切れたファイルが、取れたバックアップに見えないようにするため。
# 最後に、できたファイルの場所を返す（db-backup-daily.ps1 が使う）。

param(
    # 保存するデータベースの名前
    [string]$Database = 'kakeibo',

    # ファイル名の終わりにつける言葉（db-restore.ps1 が、戻す前の自動のバックアップに before-restore とつける）
    [string]$Suffix = '',

    # 呼んだスクリプトが、すでに鍵（Mutex）を持っているとき（db-backup-daily.ps1・db-restore.ps1 から呼ぶとき）
    [switch]$AlreadyLocked
)

$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'db-backup-common.ps1')

# 名前に使える文字をしぼる（コマンドの中にそのまま入れるため）
if ($Database -notmatch '^[A-Za-z0-9_]+$') {
    throw "データベースの名前に使えるのは、英数字と _ だけです：$Database"
}
if ($Suffix -notmatch '^[A-Za-z0-9_-]*$') {
    throw "Suffix に使えるのは、英数字と _ と - だけです：$Suffix"
}

# docker compose をリポジトリのいちばん上（compose.yaml がある場所）で動かす
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

# docker などのコマンドは、失敗しても PowerShell が止まらないので、終わるたびに結果を確かめる
function Assert-Success([string]$step) {
    if ($LASTEXITCODE -ne 0) {
        throw "$step に失敗しました（終了コード $LASTEXITCODE）"
    }
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$fileName = if ($Suffix) { "$Database-$stamp-$Suffix.sql" } else { "$Database-$stamp.sql" }
$tmpInBox = "/tmp/$fileName"
New-Item -ItemType Directory -Force (Join-Path $root 'backups') | Out-Null
$dest = Join-Path $root "backups\$fileName"
$partial = "$dest.partial"

$lock = $null
if (-not $AlreadyLocked) {
    $lock = Enter-BackupLock
}

try {
    # ファイルは箱の中で作る。PowerShell 5.1 の「>」でファイルに書くと、文字の形が UTF-16 に変わり、日本語が化けるため。
    # パスワードは箱の中の MYSQL_ROOT_PASSWORD（compose.yaml で .env から渡したもの）を使い、Windows のコマンドには出さない。
    # --single-transaction：動かしたまま、ある瞬間の中身をそろえて取る
    # --routines --triggers：表のほかに、関数やトリガーも取る（いまは使っていないが、取りもれを防ぐ）
    # --databases をつけないので、戻すときに別の名前のデータベースにも戻せる
    docker compose exec -T mysql sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysqldump -uroot --single-transaction --routines --triggers --default-character-set=utf8mb4 --result-file=$tmpInBox $Database"
    Assert-Success 'mysqldump'

    # 箱の中のファイルを PC に、まず .partial の名前で写す
    docker compose cp "mysql:$tmpInBox" $partial
    Assert-Success 'ファイルを PC に写す'

    # 最後まで書けているかを確かめてから、本当の名前にする
    if (-not (Test-DumpComplete $partial)) {
        throw 'バックアップが最後まで書けていません（終わりの印がありません）'
    }
    Move-Item -LiteralPath $partial -Destination $dest
} finally {
    # 成功しても失敗しても、途中のファイルと箱の中のファイルは消す（消すのに失敗しても止めない）
    if (Test-Path -LiteralPath $partial) { Remove-Item -LiteralPath $partial -Force }
    docker compose exec -T mysql rm -f $tmpInBox
    Exit-BackupLock $lock
}

$size = [math]::Round((Get-Item $dest).Length / 1KB, 1)
Write-Host "保存しました：backups\$fileName（$size KB）"

# できたファイルの場所を返す（いちばん最後の出力）
return $dest
