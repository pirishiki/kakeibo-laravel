# 家計簿アプリ

自分のお金の出入りを記録して、「今いくらあるか」「あといくら使えるか」「何に・どこでいくら使ったか」「いつ、いくら引き落とされるか」がわかる Web アプリ。

プログラミングスクール初級編の最終課題。はじめは競馬の収支を記録するアプリを考えたが、レースなどの情報を自分で入れる必要があり、そのデータを手に入れるのが難しいため、ふつうの家計簿アプリにした。

## 想定する使い方

```
① 最初に、口座と今の残高を登録する        →「銀行口座 350,000 円、現金 8,000 円」
② 給料・家賃など、毎月のお金を登録する     →「家賃 毎月 27 日 70,000 円」（あとは自動で記録される）
③ 買い物のたびに記録する                  →「10月9日 コンビニ 680 円 現金」
④ ホームで、あといくら使えるかを見る ★    →「使える額 37,900 円（1 日あたり 1,647 円）」
⑤ 月の終わりに、何に使ったかを見る         →「食費 42,300 円…外食が多いな」
```

★ がいちばん大事なところ。ときどき財布やカードの明細とくらべて、ずれていたら残高を合わせる。

## 大事にしたこと：めんどくさがりでも続けられる

家計簿は、続けないと意味がない。そこで「めんどくさがりでも続けられるアプリ」を目指し、機能や作りを選ぶときのものさしにした（以下は、これから作る予定のものを含む）。

- **入力の手間を減らす**
  - 給料・ローン・電気代などの毎月のお金は、一度登録すれば自動で記録される
  - 相手（お店など）を選ぶと、前回と同じ分類・口座が最初から入る
  - 相手は、最近使ったものほど上に並ぶ
  - ローンの終わりの月を登録しておけば、終わったあとに止め忘れない
  - 予算は、ふだんの金額を 1 回決めておけば、特別な月だけ変えればよい
- **忘れても気づける**
  - 金額が未入力の予定があると、アプリを開いたときに知らせる
  - ホームの今月のカレンダーで、どの日にいくら使ったかと、これからの引き落としがひと目でわかる
- **つけた結果が目に見える**
  - 「あといくら使えるか」をホームにいちばん大きく表示する（1 日あたり・1 週間あたりも）
  - 貯金の目標の進み具合を、口座の残高から出す（貯めた額を入力しなくてよい）
  - 残高の移り変わりをグラフで見られる

## 動いているところ

デプロイは 2 回行う（第 1 段階のあと・完成後）。回ごとに、操作の動画・日付・段階・URL を書く。動くことを確かめたら AWS は destroy するので、URL はそのときだけ開ける。

### 1 回目（第 1 段階）

- 日付：2026年10月15日
- 段階：第 1 段階（ログイン・口座・分類・相手・記録の登録と一覧・残高を合わせる・月のまとめ・設定・ホーム）
- URL：https://dbzl8cqvinyro.cloudfront.net （destroy したので、今は開けない）
- 入れたデータ：テスト用のデータだけ（口座 5・分類 20・相手 50・記録 1 万件。`TestDataSeeder`）。ログインの人は `testing@example.com`

https://github.com/user-attachments/assets/6fe70426-3b0c-4ad2-9628-f63edfd3e84f

動画の中身：アドレス（https）→ ログイン → ホーム → 記録する → 記録の一覧・月のまとめ → 口座・分類・相手 → ブラウザの幅を細くしてスマホの形（下のメニュー）→ ログアウト。ブラウザのツールバーとパスワードマネージャーの小窓は、ぼかしてある。

確かめたことの結果と、destroy のあとに確かめたことは、下の「[AWS へのデプロイ](#aws-へのデプロイ)」の手順 5・6 に書いている。

### 2 回目（完成後）

（デプロイ後に書く）

## 設計で大事にした判断

主な判断を下にまとめた。くわしい理由と、比べて選ばなかった案（「ほかに考えた案」）は、次の文書のそれぞれの判断のすぐ横に書いている。

- 何を作るかの判断（口座・相手・分類・ログインなど）と技術の選び方：[要件定義書](要件定義書.md) 4.1・5 章
- データの持ち方の判断：[データベース設計書](データベース設計書.md)
- 画面の判断（何をどこに出すか、見た目）：[画面設計書](画面設計書.md)。比べた案は [画面の下書き](画面の下書き.html) の「選んだことの一覧」でも見られる
- AWS に置くときの判断（HTTPS・置き方・秘密の情報・定期的な記録・デプロイと destroy）：[全体構成設計書](全体構成設計書.md)。比べた案は [全体の構成の下書き](全体の構成の下書き.html) の「選んだことの一覧」でも見られる
- 機能以外に守る決まり：[要件定義書](要件定義書.md) 8 章（N-01〜N-21）

主な判断：

- **お金の数字を正しく保つ**
  - 今の残高は保存せず、表示するたびに記録から計算する（保存すると、記録を直したときに書き換え忘れてずれるおそれがあるため）
  - 残高が実際とずれたときは、最初の残高を書き換えず、差を「調整」の記録として残す（過去の月の残高やグラフを変えないため）
  - 口座から口座への移動は 1 行で記録する（出る行と入る行に分けると、片方だけ消えて残高がずれるおそれがあるため）
  - 金額は整数で持つ（小数の計算の誤差を防ぐため）
  - 日付・時刻は日本時間で扱う（サーバーがシドニーにあるため）
  - 定期的な記録は、同じ月の分を 2 回記録しない。記録に「何月の予定の分か」を覚えさせ、データベースのユニーク制約でも止める（土日・祝日でずらして日付がとなりの月になっても、二重にならないため）
- **過去の記録を壊さない**
  - 記録がある口座・分類・相手・定期的な記録は削除できず、かわりに「使わない」にして隠す。データベースの外部キーでも削除を止める
- **安全に使う**
  - 自分 1 人で使うので、ユーザー登録の画面は作らない（ほかの人が登録できないようにするため）
  - EC2・RDS は外から入れない場所に置き、CloudFront から AWS の中だけでつなぐ（自分のドメインがなくても、パスワードを暗号化なしでインターネットに出さないため）
  - データベースのパスワードは RDS に作らせて金庫に入れる（だれも知らず、Terraform の台帳にも自分の PC にも残らないため）
- **お金をかけすぎない**
  - AWS は動くことを確かめたら数時間で destroy し、NAT ゲートウェイもふくめて、destroy 1 回で全部消える形にする（別に止める手順があると、止め忘れるため）
  - 料金の見張り（予算アラート）だけはアプリと別に残し、消し忘れにメールで気づけるようにする
- **見やすい画面にする**
  - ホームはカード型にし、使える額をいちばん大きなカードにする。その下に今月のカレンダーを入れる
  - 赤は「気をつけて」（マイナス・予算こえ・入力のまちがい）だけに使い、ふだんの支出は赤くしない
  - 基本の色は、注意の赤とも収入の緑とも見まちがえない青緑にする

## 文書

**設計書が正しい。** 作ったものが設計書とちがうときは、作ったものを直す。設計を変えるときは、先に設計書を直す（[要件定義書](要件定義書.md) N-21）。

| 文書 | この文書が答える問い |
|---|---|
| [要件定義書](要件定義書.md) | なぜ作るのか。何を作り、何を作らないか。どんな順番で作るか。機能以外に守る決まり（非機能要件 N-01〜N-21） |
| [データベース設計書](データベース設計書.md) | データをどんな形で保存するか。なぜその形にしたか |
| [画面設計書](画面設計書.md) | 画面に何を出し、何を入力し、どの画面へ移るか。見た目（並べ方・色・字）をどう決めたか |
| [全体構成設計書](全体構成設計書.md) | AWS の中で何を・どこに・どうつなぐか。秘密の情報の渡し方、デプロイと destroy の手順の考え方。長く動かすことにしたら何を変えるか |
| [全体の構成の下書き](全体の構成の下書き.html) | AWS の構成をどの案と比べて選んだか。通り道の図と比べる表（ダウンロードしてブラウザで開く） |
| [画面の下書き](画面の下書き.html) | 画面がどんな見た目か。どの案と比べて選んだか（ダウンロードしてブラウザで開く） |
| [開発環境の下書き](開発環境の下書き.html) | 開発環境（PHP・MySQL の置き方、ログイン、Livewire、Bootstrap、CI、Dependabot、バックアップ）をどの案と比べて選んだか（ダウンロードしてブラウザで開く） |

はじめて読むときは、要件定義書から読む。目的と、作るもの・作らないものがわかると、ほかの文書の判断の理由が追いやすい。

## 開発の進め方

- 作業は「イシュー → ブランチ → PR → master にマージ」の順で進める。master への直接の変更は、GitHub の設定（ルールセット）で禁止している。
- 作る順番は「要件定義 → 要件の見直し → データベース設計 → 画面設計 → 全体の構成 → 開発 → デプロイ」。設計の前に要件を見直した理由（11.1）と、データベースを画面より先に設計した理由（11.2）は、要件定義書の 11 章に書いている。
- PR を作ると自動チェック（CI：テスト・書き方・画面のビルド）が動き、3 つとも通らないとマージできない（N-19）。
- 部品の更新は、GitHub の Dependabot が更新の PR を自動で作る（N-17。下の「開発環境のセットアップ」の 9）。

## 技術スタック

画面（フロントエンド）とサーバー（バックエンド）は、別々のアプリに分けず、Laravel の 1 つのアプリの中にある（サーバーが画面の HTML を作って返す形）。

| 層 | 技術 |
|---|---|
| フロントエンド | Blade（Laravel の画面のひな形）+ Livewire（画面の一部だけを書きかえる）+ Alpine.js（小さな動き）、Bootstrap 5.3（Sass で色を変え、Vite でビルド） |
| バックエンド | PHP 8.5 + Laravel 13、ログインは Fortify（部品の管理は Composer） |
| データベース | MySQL 8.4（LTS） |
| インフラ | AWS（CloudFront・EC2・RDS）を Terraform で作る |
| 開発・自動チェック | GitHub Actions（PHPUnit・Larastan・Pint・ビルド）、Dependabot、開発用の MySQL は Docker |

Livewire は、画面の部品を PHP で書き、ブラウザとサーバーのやりとりを自動でしてくれるもので、フロントエンドとバックエンドの両方にまたがる。

くわしくは [要件定義書](要件定義書.md) を参照。

## 開発環境のセットアップ

自分の PC（Windows 11）で動かす手順。PHP・Composer は Windows に直接入れ、MySQL は Docker で動かす。この形にした理由は [要件定義書](要件定義書.md) 4.1 の「開発環境」に書いている。

コマンドは Windows PowerShell で打つ。Windows の最初の設定では .ps1 のスクリプトをそのまま動かせないので、npm は `npm.cmd` と打つ。

### 1. 使うものを入れる（最初の 1 回だけ）

| ソフト | 入れ方 |
|---|---|
| PHP 8.5 | `winget install --id PHP.PHP.8.5 --source winget` |
| Composer 2 | [公式のインストーラー](https://getcomposer.org/download/)（ハッシュを確かめてから動かす） |
| Docker Desktop | 公式サイトから入れる。設定で「サインインしたら起動する」をオンにする |
| Node.js 24（LTS） | 公式サイトか winget で入れる |

PHP の設定（php.ini）は、PHP のフォルダの中ではなく、ユーザーのフォルダ（例：`C:\Users\<名前>\php-config\php.ini`）に置き、ユーザーの環境変数 `PHPRC` でその場所を教える。winget で PHP を更新しても、設定が消えないようにするため。

- `php.ini-development` をもとに作り、`extension_dir` を PHP の `ext` フォルダの絶対パスにする
- 使う機能をオンにする：`curl`・`fileinfo`・`intl`・`mbstring`・`openssl`・`pdo_mysql`・`zip`
- ウイルス対策ソフトが HTTPS の中身を調べている PC では、そのソフトの証明書を足した証明書の束を `curl.cainfo` と `openssl.cafile` に書く（ないと、Composer が「証明書が信用できない」で止まる）
- 設定のフォルダの名前は `.php` のようにしない。ファイル名に見えて、ウイルス対策ソフトが php.exe を止めることがあった

確かめる：`php -v`（8.5.x）、`composer diagnose`（全部 OK）、`docker version`、`node -v`（v24.x）。

### 2. リポジトリを取ってきて、部品を入れる

```powershell
git clone https://github.com/pirishiki/kakeibo-laravel.git kakeibo
cd kakeibo
composer install
npm.cmd ci
```

### 3. 設定ファイル（.env）を作る

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

`.env` を開いて、`DB_PASSWORD` と `DB_ROOT_PASSWORD` に自分で決めたパスワードを入れる（長い乱数がよい）。`.env` は Git に入れない（N-06）。

### 4. MySQL を動かして、表を作る

```powershell
docker compose up -d --wait
php artisan migrate
```

- `docker compose up -d --wait` で、MySQL 8.4 の箱が動く（`compose.yaml`）。`--wait` は、MySQL が受けつけられる状態になるまで待つ（初めてのときは数十秒かかる。待たずに migrate を打つと、つなげずに失敗する）。初めて動かすときは、ふだん使う `kakeibo` と、テスト用の `kakeibo_testing` の 2 つのデータベースができる。
- 止めるときは `docker compose stop`。データは Docker のボリュームに残る。

### 5. ログインするユーザーを作る（最初の 1 回だけ）

ユーザー登録とパスワードリセットの画面はない（N-04）。ユーザーの作成とパスワードの変更は、コマンドで行う。

```powershell
php artisan user:create
php artisan user:password 自分のメールアドレス
```

- `user:create` は、名前・メールアドレス・パスワードを順に聞く。パスワードは 12 文字以上で、打った字は画面に出ない。打ちまちがいを防ぐため、2 回聞く。
- `user:password` は、パスワードを変える。ほかの機械でのログイン（「ログインしたままにする」もふくむ）も終わる。
- パスワードは、コマンドの後ろに書いて渡すことはできない。書いて渡すと、コマンドの履歴に残るため。

画面を確かめるための、ためしのデータを入れるとき（入れなくても動く）：

```powershell
php artisan db:seed --class=SampleSeeder
```

- 口座 4 つ（「使わない」口座もふくむ）・小分類・相手と、今月・先月の記録 12 件（支出・収入・移動・調整）が入る。日付は動かした日から数えるので、いつ動かしても今月と先月の記録になる。
- 本番では動かない。口座か記録がもう入っているときも、本物のデータとまざらないように、入れずに止まる。入れ直すときは `php artisan migrate:fresh` で空にしてから（ユーザーも消えるので、`user:create` で作り直す）。

### 6. 動かす

```powershell
npm.cmd run build
php artisan serve
```

ブラウザで http://127.0.0.1:8000 を開く。画面の CSS・JS を直しながら見るときは、`npm.cmd run build` のかわりに、別のターミナルで `npm.cmd run dev` を動かしておく（保存すると画面にすぐ反映される）。

定期的な記録は、本番では毎日 0 時 5 分に自動で記録される（全体構成設計書 5 章）。手元では、自動では動かないので、確かめたいときに次のコマンドを動かす。今日までのまだ記録していない分を全部記録し、何度動かしても同じ月の分は 1 回だけ。

```powershell
php artisan recurrings:record
```

### 7. 自動チェックを手元で動かす

PR を作る前に、CI（`.github/workflows/ci.yml`）と同じチェックを手元で通しておく。MySQL を動かしておくこと（テストは `kakeibo_testing` を使う）。

| CI のチェック | 手元で動かすコマンド |
|---|---|
| `test` | `php artisan test` と `vendor/bin/phpstan analyse` |
| `lint` | `vendor/bin/pint --test`（直すときは `vendor/bin/pint`） |
| `build` | `npm.cmd run build` |

### 8. データベースのバックアップと、元に戻す（N-16）

データベースを作り直す前（`migrate:fresh` など）には、必ずバックアップを取る。

```powershell
# 取る：backups\kakeibo-年月日-時分秒.sql ができる
powershell -ExecutionPolicy Bypass -File scripts\db-backup.ps1

# 戻す：今の中身を全部消して入れかえてよいか聞かれるので y を打つ
powershell -ExecutionPolicy Bypass -File scripts\db-restore.ps1 -File backups\kakeibo-20261012-151927.sql

# 別の名前のデータベースに戻す（中身を確かめるとき）
powershell -ExecutionPolicy Bypass -File scripts\db-restore.ps1 -File backups\kakeibo-20261012-151927.sql -Database kakeibo_restore
```

- `-ExecutionPolicy Bypass` は「この 1 回だけスクリプトを動かしてよい」という意味で、PC の設定は変えない。
- 戻すときは、戻す先のデータベースをいったん空にしてから戻すので、バックアップを取った瞬間とまったく同じ中身になる（空にしないと、あとで足した表が残り、マイグレーションの帳簿とちぐはぐになるため）。空にする前の中身は `backups\<名前>-<日時>-before-restore.sql` に自動で保存されるので、まちがえて戻しても、そのファイルから戻せる。
- `backups\` は Git に入れない。中身は自分のお金の記録のため。
- PowerShell の `>` でファイルに書くと文字の形が変わり、日本語が化ける。スクリプトは MySQL の箱の中でファイルを作り、PC に写している。
- 戻したあとの確かめ方：`$env:DB_DATABASE = 'kakeibo_restore'; php artisan db:show --counts` で、表ごとの行の数が元と同じかを見る（ターミナルを閉じると元に戻る）。記録の機能ができたら、記録の件数と残高でも確かめる（要件定義書 10 章）。

### 9. 部品の更新

部品の新しい版が出ると、Dependabot が更新の PR を毎週月曜 9 時に作る（`.github/dependabot.yml`、N-17）。イシューは作らない。CI の 3 つが通ったのを見てマージする。あぶない穴が見つかったときの PR は、曜日に関係なくすぐ来る。

## AWS へのデプロイ

AWS に置いて動くことを確かめ、証拠を残したら destroy する（要件定義書 N-14・N-15）。何を・どこに・どうつなぐかと、そう決めた理由は [全体構成設計書](全体構成設計書.md) に書いている。

```
自分の PC ──terraform apply──→ AWS（CloudFront・EC2・RDS・NAT ゲートウェイができる）
自分 ──Session Manager──→ EC2 で scripts/deploy.sh を動かす（GitHub から master を取る）
```

- リージョンはプロジェクトのリージョン（ap-southeast-2）。CloudFront は世界共通のサービスで、us-east-1 の扱いになる。
- **作った時点から、NAT ゲートウェイ・EC2・RDS に 1 時間ごとにお金がかかる**（1 回のデプロイで 1 ドル前後）。確かめたら、その日のうちに destroy する。
- デプロイするのは master だけ（PR でマージして、CI が通ったもの）。

### 1. 使うものを入れる（最初の 1 回だけ）

- Terraform（`winget install Hashicorp.Terraform`）
- AWS CLI と Session Manager プラグイン（`winget install Amazon.AWSCLI Amazon.SessionManagerPlugin`）

### 2. 料金の見張りを作る（最初の 1 回だけ）

予算アラート（AWS Budgets）は、アプリの Terraform とは別に作り、destroy のあとも残しておく（消し忘れにメールで気づくため。全体構成設計書 6.3）。1 か月 5 ドル、クレジットを引く前の金額で、50%・100% をこえたときと、月末までに 100% をこえそうなときにメールが来る。`<メールアドレス>` は AWS にログインしているメールアドレスにする。

設定（JSON）はファイルに書いて渡す。Windows PowerShell 5.1 は、コマンドに渡す文字の中の `"` を消してしまい、そのまま渡すと AWS CLI が読めないため。ファイルは BOM なしの UTF-8 で書く。

```powershell
aws login --profile dothething
$account = aws sts get-caller-identity --profile dothething --query Account --output text
$budget = '{"BudgetName":"monthly-5usd","BudgetLimit":{"Amount":"5","Unit":"USD"},"TimeUnit":"MONTHLY","BudgetType":"COST","CostTypes":{"IncludeCredit":false,"IncludeRefund":false}}'
$notify = '[{"Notification":{"NotificationType":"ACTUAL","ComparisonOperator":"GREATER_THAN","Threshold":50,"ThresholdType":"PERCENTAGE"},"Subscribers":[{"SubscriptionType":"EMAIL","Address":"<メールアドレス>"}]},{"Notification":{"NotificationType":"ACTUAL","ComparisonOperator":"GREATER_THAN","Threshold":100,"ThresholdType":"PERCENTAGE"},"Subscribers":[{"SubscriptionType":"EMAIL","Address":"<メールアドレス>"}]},{"Notification":{"NotificationType":"FORECASTED","ComparisonOperator":"GREATER_THAN","Threshold":100,"ThresholdType":"PERCENTAGE"},"Subscribers":[{"SubscriptionType":"EMAIL","Address":"<メールアドレス>"}]}]'
[IO.File]::WriteAllText("$PWD\budget.json", $budget)
[IO.File]::WriteAllText("$PWD\notify.json", $notify)
aws budgets create-budget --profile dothething --account-id $account --budget file://budget.json --notifications-with-subscribers file://notify.json
Remove-Item budget.json, notify.json
```

### 3. AWS に作る

```powershell
aws login --profile dothething
cd infra
terraform init      # 最初の 1 回だけ（部品を取ってくる）
terraform apply     # 作るものの一覧が出るので、確かめて yes。CloudFront ができるまで 10〜20 分かかる
terraform output    # アプリのアドレス（app_url）と、EC2 の名前（ec2_instance_id）が出る
```

- 台帳（`infra/terraform.tfstate`）は自分の PC にだけ置き、Git には入れない（`.gitignore`）。**destroy が終わるまで消さない**（消すと destroy で何も消せなくなる）。
- EC2 は、できてから 5 分ほど準備（PHP などを入れる）をする。準備の記録は、EC2 の中の `/var/log/cloud-init-output.log`。

### 4. アプリを置く

Session Manager で EC2 に入り（SSH は使わない）、デプロイ用のスクリプトを動かす。アドレスは `terraform output app_url` のもの。

```powershell
aws ssm start-session --profile dothething --target (terraform output -raw ec2_instance_id)
```

EC2 の中で：

```bash
# 準備が終わるまで待つ（status: done なら終わり。error のときは sudo less /var/log/cloud-init-output.log で記録を見る）
cloud-init status --wait

# アプリを置いて動かす（コードを新しくするときも、これをもう一度動かす）
sudo bash /var/www/kakeibo/scripts/deploy.sh https://〇〇〇.cloudfront.net

# 最初の 1 回だけ：ログインする人を作る（パスワードは画面に出ない）
sudo -u kakeibo php /var/www/kakeibo/artisan user:create

# 最初の 1 回だけ：テスト用のデータ（口座 5・分類 20・相手 50・記録 1 万件）を入れる。本物の記録は入れない
sudo -u kakeibo php /var/www/kakeibo/artisan db:seed --class=TestDataSeeder --force
```

### 5. 確かめて、証拠を残す

- スマホとパソコンのブラウザで、アドレスを開いて受け入れ基準（要件定義書 10 章）を確かめ、操作の動画を撮る。
- 「動いているところ」に動画・日付・段階・アドレスを、この下の「結果」に確かめたことを書いて、**手元でコミットだけする**（push は destroy のあと。動いているあいだに、公開のリポジトリにアドレスを出さないため）。

**1 回目の結果**（2026年10月15日・第 1 段階。動画と URL は上の「[動いているところ](#動いているところ)」にある）

| 確かめたこと | 結果 |
|---|---|
| http で開くと https に移る（N-03） | 301 で https へ移った |
| ログインしないと中が見えない（N-01） | ログイン画面へ移った（移り先も https） |
| パスワードは元に戻せない形で保存される（N-02） | `$2y$12$…`（bcrypt）で保存されていた |
| 記録 1 万件でも 1 秒以内に開く（N-12） | EC2 の中で画面を作る時間は 0.03〜0.11 秒（ホーム 108ms・記録の一覧 67ms・月のまとめ 82ms）。10.4 で決めた「ブラウザの開発者ツールではかる」方法では、はかっていない（2 回目のデプロイではかる） |
| スマホとパソコンで使える（N-11・N-13） | パソコンの Chrome で、動画の画面を使えた。スマホの並べ方は、パソコンのブラウザの幅を細くして確かめた。実物のスマホでは、ログインだけ確かめた。スマホの Safari・Chrome での操作と、パソコンの Edge は、まだ確かめていない（2 回目のデプロイで確かめる） |
| EC2 から RDS へ暗号化（TLS）してつながる | RDS の証明書の元を指定した設定で、マイグレーションとテスト用のデータの書きこみができた |
| CloudFront から EC2 へ、決めた入口（CloudFront の住所の一覧）で届く（全体構成設計書 3 章） | 届いた |

### 6. destroy して、残っていないことを確かめる

```powershell
cd infra
terraform destroy   # 消すものの一覧が出るので、確かめて yes。CloudFront を消すのに 10〜20 分かかる
```

- VPC やサブネットを消すところで止まったときは、CloudFront が VPC の中に作ったものが消えるのを待っている。しばらく待ってから、もう一度 `terraform destroy` する。
- 終わったら、何も残っていないことを確かめる（全体構成設計書 6.3）。どれも空（`[]` か何も出ない）ならよい。AWS のコンソールでも同じものを見る。

```powershell
# 1. このアプリの名札（Project=kakeibo）がついたもの。消したばかりのものが少しのあいだ出ることがある（出たら、2 の種類ごとの一覧で、本当に残っているかを見る）
#    us-east-1（CloudFront）では、プロジェクトの決まり（サービスコントロールポリシー）でこの操作がことわられるので、2 の CloudFront の一覧で確かめる
aws resourcegroupstaggingapi get-resources --profile dothething --region ap-southeast-2 --tag-filters Key=Project,Values=kakeibo --query "ResourceTagMappingList[].ResourceARN"

# 2. 名札がつかないものもあるので、種類ごとに見る
aws cloudfront list-distributions --profile dothething --query "DistributionList.Items[?Comment=='kakeibo app'].Id"
aws cloudfront list-vpc-origins --profile dothething --query "VpcOriginList.Items[].Name"
aws ec2 describe-nat-gateways --profile dothething --region ap-southeast-2 --filter Name=state,Values=pending,available --query "NatGateways[].NatGatewayId"
aws ec2 describe-instances --profile dothething --region ap-southeast-2 --filters Name=instance-state-name,Values=pending,running,stopping,stopped --query "Reservations[].Instances[].InstanceId"
aws rds describe-db-instances --profile dothething --region ap-southeast-2 --query "DBInstances[].DBInstanceIdentifier"
aws ec2 describe-vpcs --profile dothething --region ap-southeast-2 --filters Name=tag:Project,Values=kakeibo --query "Vpcs[].VpcId"
aws ec2 describe-addresses --profile dothething --region ap-southeast-2 --query "Addresses[].PublicIp"
aws secretsmanager list-secrets --profile dothething --region ap-southeast-2 --query "SecretList[?starts_with(Name, 'rds!')].Name"
aws iam list-roles --profile dothething --query "Roles[?starts_with(RoleName, 'kakeibo')].RoleName"

# 3. VPC の中のもの（VPC が消えていれば、中のものも一緒に消えている。念のため、CloudFront が VPC の中に作るものも見る）
aws cloudfront list-functions --profile dothething --query "FunctionList.Items[].Name"
aws ec2 describe-security-groups --profile dothething --region ap-southeast-2 --filters Name=group-name,Values=CloudFront-VPCOrigins-Service-SG,kakeibo-ec2-sg,kakeibo-rds-sg --query "SecurityGroups[].GroupId"
aws ec2 describe-subnets --profile dothething --region ap-southeast-2 --filters Name=tag:Project,Values=kakeibo --query "Subnets[].SubnetId"
aws ec2 describe-network-interfaces --profile dothething --region ap-southeast-2 --filters Name=group-name,Values=CloudFront-VPCOrigins-Service-SG,kakeibo-ec2-sg,kakeibo-rds-sg --query "NetworkInterfaces[].NetworkInterfaceId"
```

- 残すのは、料金の見張り（予算アラート）と、自分の PC の台帳（中身は空になる）だけ。
- 確かめたことを、この下の「結果」に書き足して、5 のコミットといっしょに push する。

**1 回目の結果**

コマンドの種類ごとの一覧（CloudFront の配信・VPC オリジン・CloudFront Functions・NAT ゲートウェイ・EC2・RDS・VPC・サブネット・セキュリティグループ・ネットワークインターフェース・出口の IP・金庫・IAM ロール）で、このアプリのものが 0 件なことを確かめた。名札で探すと、消したばかりの 9 件が出たが、1 つずつ見るとすべて消えていた。AWS のコンソールでも、EC2・NAT ゲートウェイ・VPC・RDS（DB インスタンスとスナップショット）・CloudFront が残っていないことを見た。残したのは予算アラート（コンソールでも monthly-5usd があることを見た）と、手元の台帳（中身は空）だけ。

1 回目のデプロイで見つかったこと（直してある）：EC2 の最初の準備で Composer が止まった（HOME が空のため）、デプロイ用のスクリプトの最初の回だけ止まる手順（optimize:clear）、セッションの Cookie の名前、nginx の注意。
