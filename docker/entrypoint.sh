#!/bin/sh
set -e

REGION="${AWS_REGION:-eu-west-1}"
ENV_FILE="/tmp/.env_secrets"

# Clean up any previous secrets file
rm -f "$ENV_FILE"
touch "$ENV_FILE"

# Helper: fetch a secret and write its keys to env file
fetch_and_export() {
    local secret_arn="$1"
    local label="$2"

    echo "Fetching ${label} from Secrets Manager..." >&2
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

    echo "$secret_json" | php -r '
        $json = file_get_contents("php://stdin");
        $config = json_decode($json, true);
        if ($config === null) {
            fwrite(STDERR, "ERROR: Invalid JSON in " . $argv[1] . "\n");
            exit(1);
        }
        foreach ($config as $key => $value) {
            $value = addcslashes($value, "\"\\$`");
            echo "export " . $key . "=\"" . $value . "\"\n";
        }
    ' "$label" >> "$ENV_FILE"

    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to parse ${label}" >&2
        exit 1
    fi

    echo "${label} loaded" >&2
}

# Helper: fetch specific keys from a secret
fetch_db_credentials() {
    local secret_arn="$1"

    echo "Fetching database credentials from Secrets Manager..." >&2
    local secret_json
    secret_json=$(aws secretsmanager get-secret-value \
        --secret-id "${secret_arn}" \
        --query 'SecretString' \
        --output text \
        --region "${REGION}")

    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to fetch database credentials" >&2
        exit 1
    fi

    echo "$secret_json" | php -r '
        $config = json_decode(file_get_contents("php://stdin"), true);
        if (isset($config["username"])) {
            echo "export DB_USERNAME=\"" . addcslashes($config["username"], "\"\\$`") . "\"\n";
        }
        if (isset($config["password"])) {
            echo "export DB_PASSWORD=\"" . addcslashes($config["password"], "\"\\$`") . "\"\n";
        }
    ' >> "$ENV_FILE"

    echo "Database credentials loaded" >&2
}

# Helper: fetch Redis auth token
fetch_redis_credentials() {
    local secret_arn="$1"

    echo "Fetching Redis credentials from Secrets Manager..." >&2
    local secret_json
    secret_json=$(aws secretsmanager get-secret-value \
        --secret-id "${secret_arn}" \
        --query 'SecretString' \
        --output text \
        --region "${REGION}")

    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to fetch Redis credentials" >&2
        exit 1
    fi

    echo "$secret_json" | php -r '
        $config = json_decode(file_get_contents("php://stdin"), true);
        if (isset($config["authToken"])) {
            echo "export REDIS_PASSWORD=\"" . addcslashes($config["authToken"], "\"\\$`") . "\"\n";
        }
    ' >> "$ENV_FILE"

    echo "Redis credentials loaded" >&2
}

# 1. Fetch app config secret
if [ -n "${AWS_SECRET_ARN}" ]; then
    fetch_and_export "${AWS_SECRET_ARN}" "app configuration"
fi

# 2. Fetch DB credentials from RDS-managed secret
if [ -n "${AWS_DB_SECRET_ARN}" ]; then
    fetch_db_credentials "${AWS_DB_SECRET_ARN}"
fi

# 3. Fetch Redis AUTH token
if [ -n "${AWS_REDIS_SECRET_ARN}" ]; then
    fetch_redis_credentials "${AWS_REDIS_SECRET_ARN}"
fi

# Source all exported variables
if [ -f "$ENV_FILE" ]; then
    . "$ENV_FILE"
    rm -f "$ENV_FILE"
fi

echo "All configuration loaded successfully" >&2

# Execute the main command
exec "$@"
