#!/bin/sh
# テスト用のデータベース（<ふだんの名前>_testing）を作り、アプリのユーザーが使えるようにする
# MySQL の箱を初めて作るとき（データが空のとき）だけ、MySQL の公式イメージが自動で 1 回動かす
# テストはここを使い、ふだんの記録（kakeibo）には触らない
set -e

mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <<SQL
CREATE DATABASE IF NOT EXISTS \`${MYSQL_DATABASE}_testing\`;
GRANT ALL PRIVILEGES ON \`${MYSQL_DATABASE}_testing\`.* TO '${MYSQL_USER}'@'%';
SQL
