# ECS Task Execution Role
resource "aws_iam_role" "ecs_execution" {
  name = "${var.project_name}-ecs-execution-role"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Action = "sts:AssumeRole"
      Effect = "Allow"
      Principal = {
        Service = "ecs-tasks.amazonaws.com"
      }
    }]
  })
}

resource "aws_iam_role_policy_attachment" "ecs_execution_managed" {
  role       = aws_iam_role.ecs_execution.name
  policy_arn = "arn:aws:iam::aws:policy/service-role/AmazonECSTaskExecutionRolePolicy"
}

resource "aws_iam_role_policy" "ecs_execution_extras" {
  name = "${var.project_name}-execution-extras"
  role = aws_iam_role.ecs_execution.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = "secretsmanager:GetSecretValue"
        Resource = [
          aws_secretsmanager_secret.config.arn,
          try(aws_rds_cluster.main.master_user_secret[0].secret_arn, aws_secretsmanager_secret.config.arn),
          aws_secretsmanager_secret.redis_auth.arn
        ]
      },
      {
        Effect = "Allow"
        Action = "kms:Decrypt"
        Resource = aws_kms_key.db_secret.arn
      },
      {
        Effect = "Allow"
        Action = [
          "logs:CreateLogStream",
          "logs:PutLogEvents"
        ]
        Resource = "${aws_cloudwatch_log_group.ecs.arn}:*"
      }
    ]
  })
}

# ECS Task Role
resource "aws_iam_role" "ecs_task" {
  name = "${var.project_name}-ecs-task-role"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Action = "sts:AssumeRole"
      Effect = "Allow"
      Principal = {
        Service = "ecs-tasks.amazonaws.com"
      }
    }]
  })
}

resource "aws_iam_role_policy" "ecs_task_policy" {
  name = "${var.project_name}-task-policy"
  role = aws_iam_role.ecs_task.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Effect = "Allow"
        Action = [
          "mediaconvert:CreateJob",
          "mediaconvert:GetJob",
          "mediaconvert:CancelJob",
          "mediaconvert:ListJobs",
          "mediaconvert:DescribeEndpoints"
        ]
        Resource = "*"
      },
      {
        Effect = "Allow"
        Action = [
          "s3:GetObject",
          "s3:PutObject",
          "s3:DeleteObject",
          "s3:HeadObject",
          "s3:ListBucket",
          "s3:GetBucketLocation"
        ]
        Resource = [
          "arn:aws:s3:::primarysite-prod-sorted",
          "arn:aws:s3:::primarysite-prod-sorted/*"
        ]
      },
      {
        Effect = "Allow"
        Action = "secretsmanager:GetSecretValue"
        Resource = [
          aws_secretsmanager_secret.config.arn,
          try(aws_rds_cluster.main.master_user_secret[0].secret_arn, aws_secretsmanager_secret.config.arn),
          aws_secretsmanager_secret.redis_auth.arn
        ]
      },
      {
        Effect = "Allow"
        Action = "kms:Decrypt"
        Resource = aws_kms_key.db_secret.arn
      },
      {
        Effect   = "Allow"
        Action   = "iam:PassRole"
        Resource = aws_iam_role.mediaconvert.arn
      }
    ]
  })
}

resource "aws_iam_role_policy" "ecs_task_ssm" {
  name = "${var.project_name}-ssm-exec"
  role = aws_iam_role.ecs_task.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect = "Allow"
      Action = [
        "ssmmessages:CreateControlChannel",
        "ssmmessages:CreateDataChannel",
        "ssmmessages:OpenControlChannel",
        "ssmmessages:OpenDataChannel"
      ]
      Resource = "*"
    }]
  })
}

# MediaConvert Role
resource "aws_iam_role" "mediaconvert" {
  name = "${var.project_name}-mediaconvert-role"

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Action = "sts:AssumeRole"
      Effect = "Allow"
      Principal = {
        Service = "mediaconvert.amazonaws.com"
      }
    }]
  })
}

resource "aws_iam_role_policy" "mediaconvert_s3" {
  name = "${var.project_name}-mediaconvert-s3-policy"
  role = aws_iam_role.mediaconvert.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect = "Allow"
      Action = [
        "s3:GetObject",
        "s3:PutObject",
        "s3:ListBucket",
        "s3:GetBucketLocation"
      ]
      Resource = [
        "arn:aws:s3:::primarysite-prod-sorted",
        "arn:aws:s3:::primarysite-prod-sorted/*"
      ]
    }]
  })
}
