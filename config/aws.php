<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AWS Credentials
    |--------------------------------------------------------------------------
    */
    'credentials' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AWS Region
    |--------------------------------------------------------------------------
    */
    'region' => env('AWS_REGION', 'us-east-1'),

    /*
    |--------------------------------------------------------------------------
    | MediaConvert Configuration
    |--------------------------------------------------------------------------
    */
    'mediaconvert' => [
        'endpoint' => env('MEDIACONVERT_ENDPOINT'),
        'role_arn' => env('MEDIACONVERT_ROLE_ARN'),
        'queue_arn' => env('MEDIACONVERT_QUEUE_ARN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | S3 Configuration
    |--------------------------------------------------------------------------
    */
    's3' => [
        'input_bucket' => env('S3_INPUT_BUCKET'),
        'output_bucket' => env('S3_OUTPUT_BUCKET'),
        'output_prefix' => env('S3_OUTPUT_PREFIX', 'transcoded/'),
    ],
];
