# scripts\db-backup.ps1 で保存したファイルから、開発用の MySQL（Docker の箱）に中身を戻す（要件定義書 N-16）
#
# 使い方（リポジトリのいちばん上のフォルダで）：
#   powershell -ExecutionPolicy Bypass -File scripts\db-restore.ps1 -File backups\kakeibo-20261012-153000.sql
#   別の名前のデータベースに戻すとき：
#   powershell -ExecutionPolicy Bypass -File scripts\db-restore.ps1 -File backups\kakeibo-20261012-153000.sql -Database kakeibo_restore
#
# 戻す先のデータベースに同じ名前の表があると、ファイルの中身で上書きする（ファイルにない表は残る）。

param(
    # 戻すファイル（backups\ の中の .sql）
    [Parameter(Mandatory = $true)]
    [string]$File,

    # 戻す先のデータベースの名前。なければ作る
    [string]$Database = 'kakeibo',

    # 上書きの確認を聞かない
    [switch]$Force
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

function Assert-Success([string]$step) {
    if ($LASTEXITCODE -ne 0) {
        throw "$step に失敗しました（終了コード $LASTEXITCODE）"
    }
}

if (-not (Test-Path $File)) {
    throw "ファイルが見つかりません：$File"
}
# データベースの名前は、英数字と _ だけにする（コマンドの中にそのまま入れるため）
if ($Database -notmatch '^[A-Za-z0-9_]+$') {
    throw "データベースの名前に使えるのは、英数字と _ だけです：$Database"
}

if (-not $Force) {
    $answer = Read-Host "データベース $Database を、$File の中身で上書きします。よろしいですか？（y で進む）"
    if ($answer -ne 'y') {
        Write-Host 'やめました。'
        exit 1
    }
}

$tmpInBox = '/tmp/restore.sql'

# ファイルを箱の中に写してから、箱の中で読ませる（PowerShell 5.1 の「<」や「|」で流すと、文字の形が変わるため）
docker compose cp $File "mysql:$tmpInBox"
Assert-Success 'ファイルを箱に写す'

# 戻す先のデータベースがなければ作り、アプリのユーザー（MYSQL_USER）も使えるようにする
# （.env の DB_DATABASE をその名前にすると、アプリで中身を見られる）
docker compose exec -T mysql sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysql -uroot -e 'CREATE DATABASE IF NOT EXISTS $Database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON $Database.* TO '`$MYSQL_USER';'"
Assert-Success 'データベースを用意する'

docker compose exec -T mysql sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysql -uroot --default-character-set=utf8mb4 $Database < $tmpInBox"
Assert-Success '中身を戻す'

docker compose exec -T mysql rm -f $tmpInBox
Assert-Success '箱の中のファイルを消す'

Write-Host "戻しました：$File → データベース $Database"
