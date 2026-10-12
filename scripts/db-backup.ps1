# 開発用の MySQL（Docker の箱）の中身を、ファイルに保存する（要件定義書 N-16）
#
# 使い方（リポジトリのいちばん上のフォルダで）：
#   powershell -ExecutionPolicy Bypass -File scripts\db-backup.ps1
#   powershell -ExecutionPolicy Bypass -File scripts\db-backup.ps1 -Database kakeibo_testing
#
# できるファイル：backups\kakeibo-年月日-時分秒.sql（Git には入れない。中身は自分のお金の記録のため）
# 元に戻すときは scripts\db-restore.ps1 を使う。

param(
    # 保存するデータベースの名前
    [string]$Database = 'kakeibo'
)

$ErrorActionPreference = 'Stop'

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
$fileName = "$Database-$stamp.sql"
$tmpInBox = "/tmp/$fileName"
New-Item -ItemType Directory -Force (Join-Path $root 'backups') | Out-Null
$dest = Join-Path $root "backups\$fileName"

# ファイルは箱の中で作る。PowerShell 5.1 の「>」でファイルに書くと、文字の形が UTF-16 に変わり、日本語が化けるため。
# パスワードは箱の中の MYSQL_ROOT_PASSWORD（compose.yaml で .env から渡したもの）を使い、Windows のコマンドには出さない。
# --single-transaction：動かしたまま、ある瞬間の中身をそろえて取る
# --routines --triggers：表のほかに、関数やトリガーも取る（いまは使っていないが、取りもれを防ぐ）
# --databases をつけないので、戻すときに別の名前のデータベースにも戻せる
docker compose exec -T mysql sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysqldump -uroot --single-transaction --routines --triggers --default-character-set=utf8mb4 --result-file=$tmpInBox $Database"
Assert-Success 'mysqldump'

# 箱の中のファイルを PC に写し、箱の中のほうは消す
docker compose cp "mysql:$tmpInBox" $dest
Assert-Success 'ファイルを PC に写す'
docker compose exec -T mysql rm -f $tmpInBox
Assert-Success '箱の中のファイルを消す'

$size = [math]::Round((Get-Item $dest).Length / 1KB, 1)
Write-Host "保存しました：backups\$fileName（$size KB）"
