terraform {
  required_version = ">= 1.5"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.80.0"
    }
  }

  # Uncomment and configure for remote state
  # backend "s3" {
  #   bucket = "zencoder-terraform-state-517241097221"
  #   key    = "zencoder-translator/terraform.tfstate"
  #   region = "eu-west-1"
  # }
}

provider "aws" {
  region  = var.aws_region
  profile = "PrimarySite-cms-prod"

  default_tags {
    tags = {
      Project     = var.project_name
      ManagedBy   = "terraform"
      Environment = "production"
    }
  }
}

data "aws_caller_identity" "current" {}
