# scripts\db-backup.ps1 で保存したファイルから、開発用の MySQL（Docker の箱）に中身を戻す（要件定義書 N-16）
#
# 使い方（リポジトリのいちばん上のフォルダで）：
#   powershell -ExecutionPolicy Bypass -File scripts\db-restore.ps1 -File backups\kakeibo-20261012-153000.sql
#   別の名前のデータベースに戻すとき：
#   powershell -ExecutionPolicy Bypass -File scripts\db-restore.ps1 -File backups\kakeibo-20261012-153000.sql -Database kakeibo_restore
#
# 戻す先のデータベースは、いったん空にしてから戻す。戻したあとは、バックアップを取った瞬間とまったく同じになる。
# （空にしないと、バックアップにない表が残り、マイグレーションの帳簿と表がちぐはぐになるため）
# 空にする前に、戻す先の今の中身を backups\<名前>-<日時>-before-restore.sql に自動で保存する。

param(
    # 戻すファイル（backups\ の中の .sql）
    [Parameter(Mandatory = $true)]
    [string]$File,

    # 戻す先のデータベースの名前。なければ作る
    [string]$Database = 'kakeibo',

    # 確認を聞かない
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

# 戻す先のデータベースが、もうあるかを調べる（USE は、ないと失敗で終わる）
# 先に MySQL が動いているかを確かめる。止まっているのを「データベースがない」とまちがえないため
docker compose exec -T mysql sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysql -uroot -e 'SELECT 1' > /dev/null"
Assert-Success 'MySQL につなぐ（docker compose up -d --wait で動かしておく）'
docker compose exec -T mysql sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysql -uroot -e 'USE $Database' > /dev/null 2>&1"
$exists = ($LASTEXITCODE -eq 0)

if (-not $Force) {
    if ($exists) {
        $message = "データベース $Database の今の中身を全部消して、$File の中身に入れかえます（消す前の中身は backups\ に自動で保存します）。よろしいですか？（y で進む）"
    } else {
        $message = "データベース $Database を新しく作り、$File の中身を入れます。よろしいですか？（y で進む）"
    }
    $answer = Read-Host $message
    if ($answer -ne 'y') {
        Write-Host 'やめました。'
        exit 1
    }
}

# 消す前に、今の中身を保存しておく（まちがえて戻しても、すぐ元にもどせるように）
if ($exists) {
    & (Join-Path $PSScriptRoot 'db-backup.ps1') -Database $Database -Suffix 'before-restore'
}

$tmpInBox = '/tmp/restore.sql'

# ファイルを箱の中に写してから、箱の中で読ませる（PowerShell 5.1 の「<」や「|」で流すと、文字の形が変わるため）
docker compose cp $File "mysql:$tmpInBox"
Assert-Success 'ファイルを箱に写す'

# 戻す先を空にして作り直し、アプリのユーザー（MYSQL_USER）も使えるようにする
# （.env の DB_DATABASE をその名前にすると、アプリで中身を見られる）
# MYSQL_USER（.env の DB_USERNAME）は、英数字と _ だけの名前にしておくこと。ほかの文字だと GRANT が失敗して、ここで止まる
docker compose exec -T mysql sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysql -uroot -e 'DROP DATABASE IF EXISTS $Database; CREATE DATABASE $Database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON $Database.* TO '`$MYSQL_USER';'"
Assert-Success 'データベースを空にして作り直す'

docker compose exec -T mysql sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysql -uroot --default-character-set=utf8mb4 $Database < $tmpInBox"
Assert-Success '中身を戻す'

docker compose exec -T mysql rm -f $tmpInBox
Assert-Success '箱の中のファイルを消す'

Write-Host "戻しました：$File → データベース $Database"
