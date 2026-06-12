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
    ])
    ->setFinder($finder)
;
