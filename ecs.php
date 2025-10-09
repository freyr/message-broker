<?php

declare(strict_types=1);

use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths(['src', 'tests'])
    ->withSkip(['/var', '/vendor', '/docker', '/tests/Application/var'])
    ->withPreparedSets(psr12: true, common: true, symplify: true)
    ->withPhpCsFixerSets(symfony: true)
    ->withRootFiles();
