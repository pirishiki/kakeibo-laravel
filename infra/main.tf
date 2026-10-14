# AWS に置くための設計図（Terraform。全体構成設計書）
# このファイルは土台（Terraform と AWS をつなぐ provider の設定）。作るものは役割ごとに別のファイルに書く
#   vpc.tf：VPC（敷地）と NAT ゲートウェイ（出口）　ec2.tf：EC2（アプリの家）　rds.tf：RDS（データベース）
#   cloudfront.tf：CloudFront（https の入口）　outputs.tf：作ったあとに見せる値
# アプリを EC2 に置く手順は ../scripts/deploy.sh、使い方は ../README.md の「デプロイ」
#
# 作った時点から、時間ごとにお金がかかる（NAT ゲートウェイ・EC2・RDS）。確かめて証拠を残したら、その日のうちに
#   terraform destroy
# で全部消す（全体構成設計書 3・6 章）

terraform {
  # Terraform 本体の版（古い Terraform で動かして、書き方が通じないのを防ぐ）
  required_version = ">= 1.16"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 6.0"
    }
  }
}

provider "aws" {
  # このプロジェクトで使えるリージョンは ap-southeast-2（シドニー）だけ。CloudFront は世界共通のサービスで、ここから作れる
  region = "ap-southeast-2"
  # aws login --profile dothething でログインしたプロファイル
  profile = "dothething"

  # この設計図で作るものすべてに、自動でつける名札。destroy のあとに、残っていないかをこの名札で探す（全体構成設計書 1 章）
  default_tags {
    tags = {
      Project   = var.project_name
      ManagedBy = "terraform"
    }
  }
}
