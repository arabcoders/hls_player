<?php

declare(strict_types=1);

return function (): array {
    return [
        'functions' => [
            'assets' => [
                'call' => function (string $asset) {
                    if (empty($asset)) {
                        return '';
                    }

                    if (($file = assetUrl($asset))) {
                        return $file;
                    }

                    throw new RuntimeException(sprintf('Unable to find asset. \'%s\'.', $asset));
                },
            ],
        ],
        'tests' => [
        ],
        'filters' => [
        ],
        'globals' => [
            'app' => [
                'is_dev' => 'dev' === env('VP_ENV', 'prod'),
                'is_prod' => 'prod' === env('VP_ENV', 'prod'),
                'year' => gmdate('Y'),
            ],
        ],
    ];
};
