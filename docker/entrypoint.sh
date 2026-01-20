#!/bin/sh
set -e

# Fetch secrets from AWS Secrets Manager if SECRET_ARN is provided
if [ -n "${SECRET_ARN}" ]; then
    echo "Fetching configuration from Secrets Manager..."

    # Fetch the secret value
    SECRET_JSON=$(aws secretsmanager get-secret-value \
        --secret-id "${SECRET_ARN}" \
        --query 'SecretString' \
        --output text \
        --region "${AWS_REGION:-us-east-1}")

    if [ $? -ne 0 ]; then
        echo "ERROR: Failed to fetch secret from Secrets Manager"
        exit 1
    fi

    # Parse JSON and export each key as environment variable
    # Using PHP since it's available in the container
    eval $(php -r '
        $json = file_get_contents("php://stdin");
        $config = json_decode($json, true);
        if ($config === null) {
            fwrite(STDERR, "ERROR: Invalid JSON in secret\n");
            exit(1);
        }
        foreach ($config as $key => $value) {
            // Escape single quotes in values
            $value = str_replace("'\''", "'\''\\'\'''\''", $value);
            echo "export " . $key . "='\''" . $value . "'\''\n";
        }
    ' <<< "$SECRET_JSON")

    echo "Configuration loaded successfully"
fi

# Execute the main command
exec "$@"
