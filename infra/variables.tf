# 設計図の中で使う変数（あとから値を変えられる名前）。秘密の値はない（DB のパスワードは RDS が作る。全体構成設計書 4 章）

variable "project_name" {
  description = "作るものの名前の頭につける文字と、名札（Project）の値"
  type        = string
  default     = "kakeibo"
}

variable "repository_url" {
  description = "EC2 がアプリのコードを取りに行く GitHub のリポジトリ（公開なので鍵はいらない）"
  type        = string
  default     = "https://github.com/pirishiki/kakeibo-laravel.git"
}
