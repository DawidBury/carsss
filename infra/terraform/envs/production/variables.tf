variable "aws_account_id" {
  type = string

  validation {
    condition     = can(regex("^[0-9]{12}$", var.aws_account_id))
    error_message = "aws_account_id must be the 12-digit id of the account this stack belongs to."
  }
}

variable "aws_region" {
  type    = string
  default = "eu-central-1"
}

variable "project" {
  type    = string
  default = "cars"
}

variable "api_domain" {
  type = string
}

variable "alert_email" {
  type = string
}

variable "instance_type" {
  type    = string
  default = "t4g.small"
}

variable "root_volume_size" {
  type    = number
  default = 20
}

variable "monthly_budget_usd" {
  type    = number
  default = 20
}

variable "backup_retention_days" {
  type    = number
  default = 30
}

variable "log_retention_days" {
  type    = number
  default = 7
}

variable "ssh_public_key_path" {
  type    = string
  default = "~/.ssh/id_ed25519.pub"
}
