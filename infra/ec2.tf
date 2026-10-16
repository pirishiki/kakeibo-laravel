# EC2：アプリ（nginx ＋ PHP-FPM ＋ Laravel）が住む家（全体構成設計書 3 章）
#
#   ブラウザ ──https──▶ CloudFront ──AWS の中だけ・80 番──▶ EC2（外から入れない場所）──3306 番──▶ RDS
#   手入れ：PC ──Session Manager──▶ EC2（SSH の 22 番はどこにも開けない）

# ---------------------------------------------------------------------------
# 1. 門番（セキュリティグループ）
# ---------------------------------------------------------------------------

resource "aws_security_group" "ec2" {
  name        = "${var.project_name}-ec2-sg"
  description = "App EC2. HTTP from CloudFront only. No SSH (Session Manager)"
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "${var.project_name}-ec2-sg"
  }
}

# AWS が用意している「CloudFront の住所の一覧」（マネージドプレフィックスリスト）
data "aws_ec2_managed_prefix_list" "cloudfront" {
  name = "com.amazonaws.global.cloudfront.origin-facing"
}

# 入口：80 番を、CloudFront からだけ通す。この一覧はルールの数で 50 件以上に数えられる（上限 60）ので、ほかの入口は足さない。
# 1 回目のデプロイで、この形で CloudFront から届くかを確かめる（届かなければ、CloudFront が作る門番を許す形に変える）
resource "aws_vpc_security_group_ingress_rule" "ec2_http_from_cloudfront" {
  security_group_id = aws_security_group.ec2.id
  description       = "HTTP from CloudFront (VPC origin)"
  ip_protocol       = "tcp"
  from_port         = 80
  to_port           = 80
  prefix_list_id    = data.aws_ec2_managed_prefix_list.cloudfront.id
}

# 出口：443 番（https）で外へ。NAT を通って、Session Manager・部品（dnf・Composer・npm）・GitHub・金庫（Secrets Manager）と話す。入口ではない
#trivy:ignore:AWS-0104
resource "aws_vpc_security_group_egress_rule" "ec2_https_out" {
  security_group_id = aws_security_group.ec2.id
  description       = "HTTPS out via NAT (Session Manager, packages, GitHub, Secrets Manager)"
  ip_protocol       = "tcp"
  from_port         = 443
  to_port           = 443
  cidr_ipv4         = "0.0.0.0/0"
}

# 出口：3306 番で RDS へ。行き先は IP ではなく「RDS の門番」で指す
resource "aws_vpc_security_group_egress_rule" "ec2_to_rds" {
  security_group_id            = aws_security_group.ec2.id
  description                  = "MySQL to RDS"
  ip_protocol                  = "tcp"
  from_port                    = 3306
  to_port                      = 3306
  referenced_security_group_id = aws_security_group.rds.id
}

# ---------------------------------------------------------------------------
# 2. 許可証（IAM ロール）：EC2 が AWS のサービスを使ってよい範囲
# ---------------------------------------------------------------------------

data "aws_iam_policy_document" "ec2_assume" {
  statement {
    actions = ["sts:AssumeRole"]
    principals {
      type        = "Service"
      identifiers = ["ec2.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "ec2" {
  name               = "${var.project_name}-ec2-role"
  assume_role_policy = data.aws_iam_policy_document.ec2_assume.json
}

# 許可 1：Session Manager で入れるようにする（AWS が用意している決まった許可）
resource "aws_iam_role_policy_attachment" "ec2_ssm" {
  role       = aws_iam_role.ec2.name
  policy_arn = "arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore"
}

# 許可 2：RDS が作った金庫（DB のパスワード）の中を「読むだけ」。この RDS の金庫 1 つだけに絞る
data "aws_iam_policy_document" "ec2_read_db_secret" {
  statement {
    actions   = ["secretsmanager:GetSecretValue"]
    resources = [aws_db_instance.main.master_user_secret[0].secret_arn]
  }
}

resource "aws_iam_role_policy" "ec2_read_db_secret" {
  name   = "read-rds-master-secret"
  role   = aws_iam_role.ec2.id
  policy = data.aws_iam_policy_document.ec2_read_db_secret.json
}

resource "aws_iam_instance_profile" "ec2" {
  name = "${var.project_name}-ec2-profile"
  role = aws_iam_role.ec2.name
}

# ---------------------------------------------------------------------------
# 3. 家（EC2 本体）と、最初に起きたときに 1 回だけ動く準備（user_data）
# ---------------------------------------------------------------------------

# AWS が公開している、Amazon Linux 2023（arm64＝t4g 用）のいちばん新しい OS の番号
data "aws_ssm_parameter" "al2023_arm64" {
  name = "/aws/service/ami-amazon-linux-latest/al2023-ami-kernel-default-arm64"
}

resource "aws_instance" "app" {
  ami = data.aws_ssm_parameter.al2023_arm64.value
  # メモリ 1GB（ひとつ下の t4g.nano は 0.5GB で足りない）。部品をそろえるときに足りない分は、user_data で作る swap（2GB）で補う
  instance_type = "t4g.micro"

  # 置き場所：外から入れない場所。パブリック IP はつけない
  subnet_id                   = aws_subnet.app.id
  vpc_security_group_ids      = [aws_security_group.ec2.id]
  associate_public_ip_address = false

  iam_instance_profile = aws_iam_instance_profile.ec2.name

  # 力（CPU クレジット）を使い切ったら、ゆっくり動く。追加のお金はかからない
  credit_specification {
    cpu_credits = "standard"
  }

  # EC2 の中から自分の情報を聞くときは、合言葉（トークン）を必須にする（IMDSv2）
  metadata_options {
    http_tokens   = "required"
    http_endpoint = "enabled"
  }

  # ルートディスク：gp3・暗号化あり。部品（vendor・node_modules）と swap が入るよう 16GB
  root_block_device {
    volume_type = "gp3"
    volume_size = 16
    encrypted   = true
  }

  # 準備の手順（user_data.sh.tftpl）。RDS の住所と金庫の場所（秘密ではない。パスワードそのものは書かない）を埋めて渡す
  user_data = templatefile("${path.module}/user_data.sh.tftpl", {
    repository_url = var.repository_url
    db_host        = aws_db_instance.main.address
    db_port        = aws_db_instance.main.port
    db_name        = aws_db_instance.main.db_name
    db_secret_arn  = aws_db_instance.main.master_user_secret[0].secret_arn
  })
  # 準備の手順を書きかえたら、EC2 を作り直す（書きかえても、動いている EC2 では動かないため）
  user_data_replace_on_change = true

  # AWS が新しい OS を出すたびに「作り直し」になるのを防ぐ
  lifecycle {
    ignore_changes = [ami]
  }

  tags = {
    Name = "${var.project_name}-app"
  }

  # 準備の途中で部品を取りに行くので、出口（NAT への道案内）ができてから起こす
  depends_on = [aws_route_table_association.app]
}
