#!/bin/sh
# Load secrets from AWS Secrets Manager and run a command
# Usage: load-secrets-and-run.sh <command> [args...]

REGION="${AWS_REGION:-eu-west-1}"

fetch_and_write() {
    local secret_arn="$1"
    local label="$2"

    local secret_json
    secret_json=$(aws secretsmanager get-secret-value \
        --secret-id "${secret_arn}" \
        --query 'SecretString' \
        --output text \
        --region "${REGION}")

    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to fetch ${label}" >&2
        exit 1
    fi

    echo "$secret_json"
}

# 1. App config
if [ -n "${AWS_SECRET_ARN}" ]; then
    fetch_and_write "${AWS_SECRET_ARN}" "app config" | php -r '
        $config = json_decode(file_get_contents("php://stdin"), true);
        foreach ($config as $key => $value) {
            $value = addcslashes($value, "\"\\");
            echo "export " . $key . "=\"" . $value . "\"\n";
        }
    ' >> /tmp/.env_secrets
fi

# 2. DB credentials (RDS-managed secret)
if [ -n "${AWS_DB_SECRET_ARN}" ]; then
    fetch_and_write "${AWS_DB_SECRET_ARN}" "database credentials" | php -r '
        $config = json_decode(file_get_contents("php://stdin"), true);
        if (isset($config["username"])) {
            echo "export DB_USERNAME=\"" . addcslashes($config["username"], "\"\\") . "\"\n";
        }
        if (isset($config["password"])) {
            echo "export DB_PASSWORD=\"" . addcslashes($config["password"], "\"\\") . "\"\n";
        }
    ' >> /tmp/.env_secrets
fi

# 3. Redis AUTH token
if [ -n "${AWS_REDIS_SECRET_ARN}" ]; then
    fetch_and_write "${AWS_REDIS_SECRET_ARN}" "Redis credentials" | php -r '
        $config = json_decode(file_get_contents("php://stdin"), true);
        if (isset($config["authToken"])) {
            echo "export REDIS_PASSWORD=\"" . addcslashes($config["authToken"], "\"\\") . "\"\n";
        }
    ' >> /tmp/.env_secrets
fi

if [ -f /tmp/.env_secrets ]; then
    . /tmp/.env_secrets
    rm -f /tmp/.env_secrets
fi

exec "$@"
