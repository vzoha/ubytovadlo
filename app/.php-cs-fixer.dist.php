<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude('var')
    ->notPath([
        'config/bundles.php',
        'config/reference.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@PHP82Migration' => true,
        'yoda_style' => false,
        'increment_style' => ['style' => 'post'],
        'concat_space' => ['spacing' => 'one'],
        'header_comment' => [
            'header' => "This file is part of Ubytovadlo.\n\nSPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2\nSPDX-FileCopyrightText: 2026 Vojtěch Žoha",
            'location' => 'after_open',
            'comment_type' => 'comment',
            'separate' => 'both',
        ],
    ])
    ->setFinder($finder)
;
