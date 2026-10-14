#!/bin/bash
# EC2 で、GitHub の master を取ってきて動かす（全体構成設計書 6.2）。何度動かしてもよい（コードを新しくするときも、これをもう一度動かす）
#
# 使い方：Session Manager で EC2 に入り、root で動かす。アドレスは手元の infra で terraform output app_url を見る
#   sudo bash /var/www/kakeibo/scripts/deploy.sh https://〇〇〇.cloudfront.net
#
# 先に、EC2 が最初に起きたときの準備（infra/user_data.sh.tftpl）が終わっている必要がある。
# このスクリプト自体も GitHub から新しくなるので、全体を 1 つの関数にして、読み終えてから動かす（動いている途中でファイルが変わっても困らないように）

set -euo pipefail

main() {
    local app_url="${1:?1 つ目に、アプリのアドレス（terraform output app_url。https:// から）を書いてください}"
    local app_dir=/var/www/kakeibo

    if [ "$(id -u)" -ne 0 ]; then
        echo "root で動かしてください（sudo bash $0 $app_url）" >&2
        exit 1
    fi
    case "$app_url" in
        https://*) ;;
        *) echo "アドレスは https:// で始めてください（http はすべて CloudFront が https に移すため）" >&2; exit 1 ;;
    esac

    # RDS の住所・金庫の場所（EC2 の準備で書いたもの）
    # shellcheck source=/dev/null
    source /etc/kakeibo/deploy.env

    cd "$app_dir"

    echo "== 1. GitHub から master を取る"
    as_app git fetch origin master
    as_app git reset --hard origin/master

    echo "== 2. PHP の部品を入れる（本番に要らない開発用の部品は入れない）"
    as_app /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction --no-progress

    echo "== 3. 画面のファイル（CSS・JS）を作る"
    as_app npm ci --no-audit --no-fund
    as_app npm run build

    echo "== 4. .env を作る（DB のパスワードは金庫から。APP_KEY は前の .env にあればそのまま使う）"
    write_env "$app_url"

    echo "== 5. データベースの表を作る"
    # 前の回にまとめた設定・URL・画面（bootstrap/cache など）を消してから動かす（新しいコードで設定の項目が変わっていても、古いまとめを読まないように）。
    # optimize:clear は使わない。キャッシュの表も空にしようとするので、表がまだない最初の回に止まるため（1 回目のデプロイで、ここで止まった）
    as_app php artisan config:clear
    as_app php artisan route:clear
    as_app php artisan view:clear
    as_app php artisan event:clear
    as_app php artisan clear-compiled
    as_app php artisan migrate --force

    echo "== 6. 設定・URL・画面をまとめて、速く読めるようにする"
    as_app php artisan optimize

    echo "== 7. nginx・PHP-FPM・定期実行のタイマーを置いて、動かし直す"
    install -m 644 deploy/nginx.conf /etc/nginx/nginx.conf
    install -m 644 deploy/php-fpm.conf /etc/php-fpm.d/kakeibo.conf
    # Amazon Linux の最初の PHP-FPM の設定（利用者 apache）は使わない
    if [ -f /etc/php-fpm.d/www.conf ]; then
        mv /etc/php-fpm.d/www.conf /etc/php-fpm.d/www.conf.disabled
    fi
    install -m 644 deploy/kakeibo-schedule.service deploy/kakeibo-schedule.timer /etc/systemd/system/
    systemctl daemon-reload
    nginx -t
    systemctl enable --now php-fpm nginx kakeibo-schedule.timer
    systemctl restart php-fpm
    systemctl reload nginx

    echo "== 8. 動いているかを確かめる（Laravel の /up が 200 を返すか）"
    if curl -fsS -o /dev/null http://127.0.0.1/up; then
        echo "OK：アプリが動いています。$app_url を開いてください"
        echo "はじめての環境では、ログインする人を作ります：sudo -u kakeibo php $app_dir/artisan user:create"
    else
        echo "アプリが応答しません。記録を見てください：$app_dir/storage/logs/laravel.log・/var/log/nginx/error.log" >&2
        exit 1
    fi
}

# アプリを動かす利用者 kakeibo で動かす（部品やログのファイルが kakeibo の持ち物になるように）
as_app() {
    sudo -u kakeibo -H "$@"
}

# .env を作る。DB のパスワードは RDS が作った金庫から取り、ほかの場所には書かない（全体構成設計書 4 章）
write_env() {
    local app_url="$1"
    local secret db_username db_password app_key="" bs="\\"

    secret="$(aws secretsmanager get-secret-value --region "$AWS_REGION" --secret-id "$DB_SECRET_ARN" --query SecretString --output text)"
    db_username="$(jq -r .username <<<"$secret")"
    db_password="$(jq -r .password <<<"$secret")"
    # .env では二重引用符で囲む。その中で特別な意味をもつ \ " $ の前に \ をつけ、パスワードの記号をそのままの文字として読ませる
    # （$ をそのままにすると、${...} がほかの値に置きかわってしまうため）
    db_password="${db_password//"$bs"/"$bs$bs"}"
    db_password="${db_password//'"'/"$bs\""}"
    db_password="${db_password//'$'/"$bs\$"}"

    # APP_KEY（ログインの状態を守る鍵）は、前の .env にあればそのまま使う（デプロイし直しても、ログインし直しにならないように）
    if [ -f .env ]; then
        app_key="$(grep -E '^APP_KEY=' .env | cut -d= -f2- || true)"
    fi

    # アプリの利用者 kakeibo だけが読める .env を作る
    install -m 600 -o kakeibo -g kakeibo /dev/null .env.new
    cat > .env.new <<ENV
APP_NAME=家計簿
APP_ENV=production
APP_KEY=${app_key}
APP_DEBUG=false
APP_URL=${app_url}
APP_LOCALE=ja
APP_FALLBACK_LOCALE=en
APP_MAINTENANCE_DRIVER=file

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=${DB_HOST}
DB_PORT=${DB_PORT}
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${db_username}
DB_PASSWORD="${db_password}"
# RDS とは暗号化（TLS）してつなぐ。RDS の証明書の元（EC2 の準備で取ってきたもの）で、つなぐ先が本物の RDS かを確かめる
MYSQL_ATTR_SSL_CA=/etc/kakeibo/rds-ca.pem

SESSION_DRIVER=database
# Cookie の名前。決めないとアプリの名前（家計簿）から作られ、日本語が消えて「-session」になるため（1 回目のデプロイで気づいた）
SESSION_COOKIE=kakeibo_session
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
# ログインの状態の Cookie は、https のときだけ送る（全体構成設計書 2 章）
SESSION_SECURE_COOKIE=true

CACHE_STORE=database
QUEUE_CONNECTION=database
ENV
    mv .env.new .env

    if [ -z "$app_key" ]; then
        as_app php artisan key:generate --force
    fi
}

main "$@"
exit
