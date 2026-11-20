<?php

return [
    'path' => app_path('DataPatches'),
    'namespace' => 'App\\DataPatches',
    's3_disk' => null,
    // Set disk, bucket and actual SDK endpoint only after verifying conditional PUTs there.
    's3_upload_verified_target' => null,
];
