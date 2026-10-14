# TFLint（Terraform の設計図の校正係）の設定（kakeibo）
# 使い方（infra で）：tflint --init（初回だけ。AWS のルールを取ってくる）→ tflint

# Terraform の書き方のルール。all＝全部のルールを使う（説明 description の書き忘れなども見る）
plugin "terraform" {
  enabled = true
  preset  = "all"
}

# AWS のルール（使えないインスタンスの大きさ・まちがった値など）
plugin "aws" {
  enabled = true
  version = "0.43.0"
  source  = "github.com/terraform-linters/tflint-ruleset-aws"
}
