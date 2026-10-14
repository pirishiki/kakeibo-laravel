# 作ったあとに画面に見せる値（terraform output で、もう一度見られる）

output "app_url" {
  description = "アプリのアドレス（https）。ブラウザで開く。デプロイ用のスクリプトにも渡す。作るたびに変わる"
  value       = "https://${aws_cloudfront_distribution.app.domain_name}"
}

output "ec2_instance_id" {
  description = "EC2 の名前。Session Manager で入るときに使う（aws ssm start-session --target <これ>）"
  value       = aws_instance.app.id
}

output "deploy_command" {
  description = "Session Manager で EC2 に入ってから動かす、デプロイのコマンド"
  value       = "sudo bash /var/www/kakeibo/scripts/deploy.sh https://${aws_cloudfront_distribution.app.domain_name}"
}

output "db_endpoint" {
  description = "RDS の住所（EC2 の中からだけつながる）。パスワードは金庫にあり、ここには出さない"
  value       = aws_db_instance.main.address
}
