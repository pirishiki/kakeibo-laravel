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
    [switch]$Force,

    # 途中で切れたファイル（最後の -- Dump completed がないもの）でも戻す。
    # PC が壊れて、残りが切れたバックアップだけのときに、取り出せる分だけでも戻したい場合のため。
    # 中身が足りないまま戻るので、ふだんは使わない
    [switch]$AllowIncomplete
)

$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'db-backup-common.ps1')

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

function Assert-Success([string]$step) {
    if ($LASTEXITCODE -ne 0) {
        throw "$step に失敗しました（終了コード $LASTEXITCODE）"
    }
}

# 消す前に、ファイルがあって空でないことを確かめる（まちがったファイルで、消したあとに失敗しないように）
if (-not (Test-Path $File -PathType Leaf)) {
    throw "ファイルが見つかりません：$File"
}
if ((Get-Item $File).Length -eq 0) {
    throw "ファイルが空です：$File"
}
# 途中で切れたファイルでは戻さない（戻す先を空にしたあとで、中身が足りないとわかるのを防ぐ）
if (-not $AllowIncomplete -and -not (Test-DumpComplete $File)) {
    throw "ファイルが最後まで書けていません（終わりの印がありません）。ほかのバックアップを使ってください。ほかに無いときは、中身が足りないのを承知で -AllowIncomplete をつけると戻せます：$File"
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

$tmpInBox = '/tmp/restore.sql'
$dropped = $false
$beforeFile = $null
# 戻している途中で止まった（窓を閉じた・PC が止まった）ことを、あとから見つけるための印。
# 消す直前に作り、最後まで戻せたときだけ消す。印があるあいだ、自動バックアップは取らない
# （空か途中までのデータベースを、「今日のバックアップ」として保存してしまわないため）
$markerFile = Join-Path $root "backups\restore-in-progress-$Database.txt"

# ここから先は、自動のバックアップなどと同時に動かさない（確認を待つあいだは鍵を持たない）。
# 鍵は、途中のどこで失敗しても、いちばん外側の finally で手放す
$lock = Enter-BackupLock

try {
    # 鍵を取ったあとで、戻す先があるかを調べ直す（待っているあいだに、ほかの作業が消して作り直している
    # 最中だと、「ない」と見まちがえて、今の中身を保存せずに消してしまうため）
    docker compose exec -T mysql sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysql -uroot -e 'USE $Database' > /dev/null 2>&1"
    $exists = ($LASTEXITCODE -eq 0)

    # 消す前に、今の中身を保存しておく（まちがえて戻しても、すぐ元にもどせるように）
    if ($exists) {
        # db-backup.ps1 が最後に返す、いま保存したファイルの場所を覚えておく。失敗したときに案内するため
        $beforePath = & (Join-Path $PSScriptRoot 'db-backup.ps1') -Database $Database -Suffix 'before-restore' -AlreadyLocked |
            Select-Object -Last 1
        if (-not $beforePath) { throw '戻す前の保存ができたか、わかりませんでした。消さずに止めます' }
        $beforeFile = "backups\$(Split-Path -Leaf ([string]$beforePath))"
    }

    # ファイルを箱の中に写してから、箱の中で読ませる（PowerShell 5.1 の「<」や「|」で流すと、文字の形が変わるため）
    docker compose cp $File "mysql:$tmpInBox"
    Assert-Success 'ファイルを箱に写す'

    # 戻す先を空にして作り直し、アプリのユーザー（MYSQL_USER）も使えるようにする
    # （.env の DB_DATABASE をその名前にすると、アプリで中身を見られる）
    # MYSQL_USER（.env の DB_USERNAME）は、英数字と _ だけの名前にしておくこと。ほかの文字だと GRANT が失敗して、ここで止まる
    Set-Content -Path $markerFile -Encoding UTF8 -Value "$(Get-Date -Format 'o') データベース $Database を $File から戻している途中です。消す前の中身：$beforeFile"
    $dropped = $true
    docker compose exec -T mysql sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysql -uroot -e 'DROP DATABASE IF EXISTS $Database; CREATE DATABASE $Database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON $Database.* TO '`$MYSQL_USER';'"
    Assert-Success 'データベースを空にして作り直す'

    docker compose exec -T mysql sh -c "MYSQL_PWD=`$MYSQL_ROOT_PASSWORD mysql -uroot --default-character-set=utf8mb4 $Database < $tmpInBox"
    Assert-Success '中身を戻す'

    # 最後まで戻せたので、印を消す
    Remove-Item -LiteralPath $markerFile -Force
} catch {
    # 消したあとで失敗したときは、戻す先が空か途中までになっている。消す前の中身のありかを知らせる
    if ($dropped -and $beforeFile) {
        Write-Host ''
        Write-Host "データベース $Database は、空か途中までの中身になっています。"
        Write-Host "消す前の中身は $beforeFile にあります。次のコマンドで元にもどせます："
        Write-Host "  powershell -ExecutionPolicy Bypass -File scripts\db-restore.ps1 -File $beforeFile -Database $Database"
        Write-Host "戻せたら、印のファイル backups\restore-in-progress-$Database.txt は消えます（それまで自動バックアップは止まり、知らせが出ます）。"
        Write-Host ''
    }
    throw
} finally {
    # 成功しても失敗しても、箱の中の一時ファイルは消す（消すのに失敗しても止めない）
    docker compose exec -T mysql rm -f $tmpInBox
    Exit-BackupLock $lock
}

Write-Host "戻しました：$File → データベース $Database"
