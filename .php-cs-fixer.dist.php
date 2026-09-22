<?php

// The application is one entrypoint and its tests; there is no src/ here,
// because everything it does lives in the packages it requires.
$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/tests')
    ->append([__DIR__ . '/bot.php']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP82Migration' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'declare_strict_types' => false,
    ])
    ->setFinder($finder);
