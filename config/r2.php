<?php

return [

  'enabled' => filter_var(env('R2_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

  'account_id' => env('R2_ACCOUNT_ID', env('CLOUDFLARE_ACCOUNT_ID')),

  'access_key_id' => env('R2_ACCESS_KEY_ID'),

  'secret_access_key' => env('R2_SECRET_ACCESS_KEY'),

  'bucket' => env('R2_BUCKET'),

  'endpoint' => env('R2_ENDPOINT'),

  'region' => env('R2_REGION', 'auto'),

  'public_path_prefix' => env('R2_PUBLIC_PATH_PREFIX', 'f'),

  'docs' => [
    'r2' => 'https://developers.cloudflare.com/r2/',
    'api_tokens' => 'https://dash.cloudflare.com/?to=/:account/r2/overview/api-tokens',
  ],

];
