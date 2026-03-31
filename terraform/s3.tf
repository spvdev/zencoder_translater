# S3 buckets for media are managed externally (primarysite-prod-sorted)
# No Terraform-managed S3 buckets needed — MediaConvert reads/writes directly
# to the production bucket where Django uploads source files.
