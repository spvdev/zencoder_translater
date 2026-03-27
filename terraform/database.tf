# Aurora DB Subnet Group
resource "aws_db_subnet_group" "main" {
  name       = "${var.project_name}-db-subnets"
  subnet_ids = aws_subnet.private[*].id

  tags = {
    Name = "${var.project_name}-db-subnets"
  }
}

# Aurora MySQL Cluster
resource "aws_rds_cluster" "main" {
  cluster_identifier = "${var.project_name}-aurora-cluster"
  engine             = "aurora-mysql"
  engine_version     = "8.0.mysql_aurora.3.08.0"
  database_name      = var.db_name
  master_username    = var.db_master_username
  master_password    = var.db_master_password
  port               = 3306

  db_subnet_group_name   = aws_db_subnet_group.main.name
  vpc_security_group_ids = [aws_security_group.db.id]

  storage_encrypted            = true
  iam_database_authentication_enabled = true

  backup_retention_period = var.db_backup_retention
  preferred_backup_window = "03:47-04:17"

  skip_final_snapshot = true
  deletion_protection = false

  tags = {
    Name = "${var.project_name}-aurora-cluster"
  }
}

# Aurora Writer Instance
resource "aws_rds_cluster_instance" "writer" {
  identifier         = "${var.project_name}-aurora-writer"
  cluster_identifier = aws_rds_cluster.main.id
  instance_class     = var.db_instance_class
  engine             = aws_rds_cluster.main.engine
  engine_version     = aws_rds_cluster.main.engine_version

  publicly_accessible  = false
  db_subnet_group_name = aws_db_subnet_group.main.name

  auto_minor_version_upgrade = true

  tags = {
    Name = "${var.project_name}-aurora-writer"
  }
}
