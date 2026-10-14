# RDS：AWS が面倒を見る MySQL（全体構成設計書 3・4 章）。外から入れない場所に置き、EC2 からの 3306 番だけを通す

# RDS を置いてよい場所。RDS の決まりで、2 つ以上の場所（2a・2b）が要る（動くのは 1 台）
resource "aws_db_subnet_group" "main" {
  name       = "${var.project_name}-db-subnets"
  subnet_ids = [aws_subnet.db_a.id, aws_subnet.db_b.id]

  tags = {
    Name = "${var.project_name}-db-subnets"
  }
}

resource "aws_security_group" "rds" {
  name        = "${var.project_name}-rds-sg"
  description = "RDS for MySQL. Ingress only from the app EC2"
  vpc_id      = aws_vpc.main.id

  tags = {
    Name = "${var.project_name}-rds-sg"
  }
}

# 入口：3306 番を「EC2 の門番の名札をつけたもの」だけ通す
resource "aws_vpc_security_group_ingress_rule" "rds_from_ec2" {
  security_group_id            = aws_security_group.rds.id
  description                  = "MySQL from app EC2"
  ip_protocol                  = "tcp"
  from_port                    = 3306
  to_port                      = 3306
  referenced_security_group_id = aws_security_group.ec2.id
}

# 動くことを確かめて数時間で destroy する使い方に合わせた設定（全体構成設計書 6.3）。わざとそうしているもの：
#   AWS-0077 バックアップを取らない　　 … 中身はテスト用のデータだけ。取ると destroy のあとも残り、消し忘れのもとになる
#   AWS-0177 削除防止をかけない　　　　 … その日のうちに destroy する
#   AWS-0176 IAM 認証を使わない　　　　 … パスワードは RDS が作り、金庫に入れる（全体構成設計書 4 章）
#   AWS-0133 Performance Insights を使わない … 数時間だけなので要らない
#trivy:ignore:AWS-0077
#trivy:ignore:AWS-0177
#trivy:ignore:AWS-0176
#trivy:ignore:AWS-0133
resource "aws_db_instance" "main" {
  identifier = "${var.project_name}-db"

  # 手元の Docker と同じ MySQL 8.4。8.4 の中の細かい版は AWS が新しくする
  engine                     = "mysql"
  engine_version             = "8.4"
  auto_minor_version_upgrade = true

  # いちばん小さい種類（メモリ 1GB）
  instance_class = "db.t4g.micro"

  # ディスク：gp3・20GB（いちばん小さい）・暗号化あり。自動で大きくしない
  storage_type      = "gp3"
  allocated_storage = 20
  storage_encrypted = true

  # 最初に作るデータベースの名前と、いちばん強い権限の利用者の名前。手元の Docker とそろえる
  db_name  = "kakeibo"
  username = "kakeibo"

  # パスワードは Terraform に書かない。RDS が作って金庫（Secrets Manager）に入れる。台帳（tfstate）にも残らない
  manage_master_user_password = true

  db_subnet_group_name   = aws_db_subnet_group.main.name
  vpc_security_group_ids = [aws_security_group.rds.id]
  publicly_accessible    = false
  multi_az               = false

  # バックアップは取らない。消すときの最後のバックアップ（スナップショット）も取らない
  backup_retention_period = 0
  skip_final_snapshot     = true
  deletion_protection     = false

  tags = {
    Name = "${var.project_name}-db"
  }
}
