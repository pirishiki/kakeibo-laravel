# CloudFront：https の入口（全体構成設計書 2・3 章）。既定のアドレス（〇〇〇.cloudfront.net）と、それについている証明書を使う
#
#   ブラウザ ──https──▶ CloudFront ──VPC オリジン（AWS の中だけ）・http 80 番──▶ EC2
#
# 作るのも消すのも、世界中の窓口に広げるので 10〜20 分ほどかかる

# VPC オリジン：CloudFront から、外から入れない場所の EC2 へ、AWS の中だけでつなぐ道
resource "aws_cloudfront_vpc_origin" "app" {
  vpc_origin_endpoint_config {
    name = "${var.project_name}-app"
    arn  = aws_instance.app.arn
    # EC2 には証明書を置かないので、http（80 番）で届ける。AWS の中だけを通る
    http_port              = 80
    https_port             = 443
    origin_protocol_policy = "http-only"

    origin_ssl_protocols {
      items    = ["TLSv1.2"]
      quantity = 1
    }
  }

  tags = {
    Name = "${var.project_name}-app"
  }
}

# AWS が用意している決まった設定（マネージドポリシー）を読む
# 覚えない（キャッシュしない）：家計簿は毎回ちがう画面で、覚えると古い残高などが出るおそれがあるため
data "aws_cloudfront_cache_policy" "caching_disabled" {
  name = "Managed-CachingDisabled"
}

# ブラウザから来た Cookie・クエリ文字列・ヘッダー（Host もふくむ）を全部 EC2 に渡す。
# 渡さないと、ログインの状態（Cookie）やなりすまし防止の合言葉（CSRF トークン）が届かず、ログインできないため
data "aws_cloudfront_origin_request_policy" "all_viewer" {
  name = "Managed-AllViewer"
}

# わざとそうしているもの：
#   AWS-0010 アクセスの記録（ログ）を取らない … 記録の置き場所（S3）が destroy のあとも残るおそれがあるため（全体構成設計書 6.3）
#   AWS-0011 WAF を使わない … ログインの回数の制限で守る（全体構成設計書 2 章。公開する範囲は IP でしぼらない）
#   AWS-0013 古い TLS を許す … 自分のドメインがなく、既定の証明書を使うため。この形では TLS の下限を選べない
#trivy:ignore:AWS-0010
#trivy:ignore:AWS-0011
#trivy:ignore:AWS-0013
resource "aws_cloudfront_distribution" "app" {
  enabled = true
  comment = "${var.project_name} app"
  # 日本とオーストラリアをふくむ、北米・ヨーロッパ・アジアの窓口を使う（南米などの高い窓口は使わない）
  price_class = "PriceClass_200"

  origin {
    origin_id = "app"
    # VPC オリジンでは、EC2 の VPC の中の名前を住所にする
    domain_name = aws_instance.app.private_dns

    vpc_origin_config {
      vpc_origin_id = aws_cloudfront_vpc_origin.app.id
    }
  }

  default_cache_behavior {
    target_origin_id = "app"
    # http で開かれたときは https に移す（N-03）
    viewer_protocol_policy = "redirect-to-https"
    # ログインや記録の保存（POST）などもできるように、送り方は全部受けつける（最初の設定では GET・HEAD だけ）
    allowed_methods          = ["GET", "HEAD", "OPTIONS", "PUT", "POST", "PATCH", "DELETE"]
    cached_methods           = ["GET", "HEAD"]
    cache_policy_id          = data.aws_cloudfront_cache_policy.caching_disabled.id
    origin_request_policy_id = data.aws_cloudfront_origin_request_policy.all_viewer.id
    # 画面のファイル（CSS・JS）を小さくして届ける
    compress = true
  }

  restrictions {
    geo_restriction {
      restriction_type = "none"
    }
  }

  # 既定のアドレスについている、AWS の証明書を使う（取る手間も更新もいらない）
  viewer_certificate {
    cloudfront_default_certificate = true
  }

  tags = {
    Name = "${var.project_name}-cdn"
  }
}
