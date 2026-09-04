<?php

return [
    'connection' => env('EMISSION_CANDIDATE_INGEST_CONNECTION', 'central'),
    'queue' => env('EMISSION_CANDIDATE_INGEST_QUEUE', 'emission-candidate-ingest'),

    'tables' => [
        'packages' => 'emission_candidate_packages',
        'members' => 'emission_candidate_package_members',
    ],

    'supported_manifest_schema_versions' => ['1.0.0'],

    'storage_profiles' => [
        'atlas.candidate_ingress' => [
            'disk' => env('EMISSION_CANDIDATE_INGEST_DISK', 'candidate_ingress'),
            'max_artifact_size_bytes' => (int) env('EMISSION_CANDIDATE_MAX_ARTIFACT_SIZE_BYTES', 536870912),
            'max_uncompressed_size_bytes' => (int) env('EMISSION_CANDIDATE_MAX_UNCOMPRESSED_SIZE_BYTES', 2147483648),
            'max_line_size_bytes' => (int) env('EMISSION_CANDIDATE_MAX_LINE_SIZE_BYTES', 8388608),
            'allowed_media_types' => [
                'application/vnd.netzeroatlas.candidate-package+zip',
            ],
        ],
    ],
];
