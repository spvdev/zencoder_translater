#!/bin/sh
# Load secrets from AWS Secrets Manager and run a command
# Usage: load-secrets-and-run.sh <command> [args...]

if [ -n "${AWS_SECRET_ARN}" ]; then
    SECRET_JSON=$(aws secretsmanager get-secret-value \
        --secret-id "${AWS_SECRET_ARN}" \
        --query 'SecretString' \
        --output text \
        --region "${AWS_REGION:-us-east-1}")

    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to fetch secret"
        exit 1
    fi

    # Use PHP to parse JSON and write a sourceable env file
    echo "$SECRET_JSON" | php -r '
        $config = json_decode(file_get_contents("php://stdin"), true);
        foreach ($config as $key => $value) {
            $value = addcslashes($value, "\"\\");
            echo "export " . $key . "=\"" . $value . "\"\n";
        }
    ' > /tmp/.env_secrets

    . /tmp/.env_secrets
    rm -f /tmp/.env_secrets
fi

exec "$@"
