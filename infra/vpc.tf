# VPC：自分専用のネットワーク（塀で囲った敷地。全体構成設計書 3 章）
#
#   VPC（10.0.0.0/16）
#   ├─ パブリック 2a（10.0.1.0/24）　… NAT ゲートウェイ（出口）。外への道は門（インターネットゲートウェイ）
#   ├─ プライベート 2a（10.0.11.0/24）… EC2。外へ出るときは NAT を通る。外からは入れない（入れるのは CloudFront の VPC オリジンだけ）
#   ├─ プライベート 2a（10.0.21.0/24）… RDS
#   └─ プライベート 2b（10.0.22.0/24）… RDS（RDS は場所が 2 つ要る決まり。動くのは 1 台）

# 通信の記録（フローログ）は取らない。記録の置き場所（CloudWatch Logs）が destroy のあとも残るおそれがあるため（全体構成設計書 6.3）
#trivy:ignore:AWS-0178
resource "aws_vpc" "main" {
  cidr_block = "10.0.0.0/16"
  # 敷地の中で、住所の代わりに名前を使えるようにする（RDS の住所は名前で渡されるため）
  enable_dns_support   = true
  enable_dns_hostnames = true

  tags = {
    Name = "${var.project_name}-vpc"
  }
}

# 敷地の門（インターネットへの出入口）。CloudFront の VPC オリジンを使うにも、これが要る
resource "aws_internet_gateway" "main" {
  vpc_id = aws_vpc.main.id

  tags = {
    Name = "${var.project_name}-igw"
  }
}

resource "aws_subnet" "public" {
  vpc_id                  = aws_vpc.main.id
  cidr_block              = "10.0.1.0/24"
  availability_zone       = "ap-southeast-2a"
  map_public_ip_on_launch = false

  tags = {
    Name = "${var.project_name}-public-a"
  }
}

resource "aws_subnet" "app" {
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.0.11.0/24"
  availability_zone = "ap-southeast-2a"

  tags = {
    Name = "${var.project_name}-app-a"
  }
}

resource "aws_subnet" "db_a" {
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.0.21.0/24"
  availability_zone = "ap-southeast-2a"

  tags = {
    Name = "${var.project_name}-db-a"
  }
}

resource "aws_subnet" "db_b" {
  vpc_id            = aws_vpc.main.id
  cidr_block        = "10.0.22.0/24"
  availability_zone = "ap-southeast-2b"

  tags = {
    Name = "${var.project_name}-db-b"
  }
}

# NAT ゲートウェイ：EC2 が部品（PHP・Composer・npm）や GitHub を取りに行き、Session Manager と話すための出口。外からは入れない。
# 置いているあいだ 1 時間ごとにお金がかかる（無料枠の対象外）。ほかと同じ設計図に書き、destroy 1 回で一緒に消す（別に止める手順は作らない）
resource "aws_eip" "nat" {
  domain = "vpc"

  tags = {
    Name = "${var.project_name}-nat-ip"
  }
}

resource "aws_nat_gateway" "main" {
  allocation_id = aws_eip.nat.id
  subnet_id     = aws_subnet.public.id

  tags = {
    Name = "${var.project_name}-nat"
  }

  depends_on = [aws_internet_gateway.main]
}

# パブリックの道案内：「外（0.0.0.0/0）へは、門を通って出る」
resource "aws_route_table" "public" {
  vpc_id = aws_vpc.main.id

  route {
    cidr_block = "0.0.0.0/0"
    gateway_id = aws_internet_gateway.main.id
  }

  tags = {
    Name = "${var.project_name}-public-rt"
  }
}

resource "aws_route_table_association" "public" {
  subnet_id      = aws_subnet.public.id
  route_table_id = aws_route_table.public.id
}

# EC2 の場所の道案内：「外へは、NAT を通って出る」。入口ではない（外から EC2 へは来られない）
resource "aws_route_table" "app" {
  vpc_id = aws_vpc.main.id

  route {
    cidr_block     = "0.0.0.0/0"
    nat_gateway_id = aws_nat_gateway.main.id
  }

  tags = {
    Name = "${var.project_name}-app-rt"
  }
}

resource "aws_route_table_association" "app" {
  subnet_id      = aws_subnet.app.id
  route_table_id = aws_route_table.app.id
}

# RDS の場所には道案内を立てない（VPC の最初の道案内のまま＝敷地の中だけ。外へは出られない）

# VPC に最初からついてくる門番（デフォルトのセキュリティグループ）は、だれも通さない設定にする
resource "aws_default_security_group" "default" {
  vpc_id = aws_vpc.main.id

  tags = {
    Name = "${var.project_name}-default-sg-locked"
  }
}
