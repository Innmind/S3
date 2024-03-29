<?php
declare(strict_types = 1);

require 'vendor/autoload.php';

use Innmind\BlackBox\{
    Application,
    Runner\Load,
    Runner\CodeCoverage,
};

Application::new($argv)
    ->disableMemoryLimit()
    ->scenariiPerProof(1)
    ->when(
        \getenv('ENABLE_COVERAGE') !== false,
        static fn(Application $app) => $app->codeCoverage(
            CodeCoverage::of(
                __DIR__.'/src/',
                __DIR__.'/proofs/',
            )
                ->dumpTo('coverage.clover')
                ->enableWhen(true),
        ),
    )
    ->tryToProve(Load::everythingIn(__DIR__.'/proofs/'))
    ->exit();
