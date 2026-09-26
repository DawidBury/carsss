output "public_ip" {
  value = aws_eip.app.public_ip
}

output "dns_record" {
  value = "${var.api_domain} A ${aws_eip.app.public_ip}"
}

output "instance_id" {
  value = aws_instance.app.id
}

output "backup_bucket" {
  value = aws_s3_bucket.backups.bucket
}

output "worker_log_group" {
  value = aws_cloudwatch_log_group.worker.name
}
