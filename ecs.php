<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\Basic\SingleLineEmptyBodyFixer;
use PhpCsFixer\Fixer\ControlStructure\YodaStyleFixer;
use PhpCsFixer\Fixer\Import\GlobalNamespaceImportFixer;
use PhpCsFixer\Fixer\Operator\NotOperatorWithSuccessorSpaceFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocAlignFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocLineSpanFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths(['src', 'tests'])
    ->withSkip(
        [
            '/var',
            '/vendor',
            '/docker',
            '/tests/Application/var',
            GlobalNamespaceImportFixer::class,
            PhpdocAlignFixer::class,
            PhpdocLineSpanFixer::class,
            NotOperatorWithSuccessorSpaceFixer::class,
        ]
    )
    ->withPreparedSets(psr12: true, common: true, symplify: true)
    ->withPhpCsFixerSets(symfony: true)
    ->withConfiguredRule(YodaStyleFixer::class, [
        'equal' => false,
        'identical' => false,
        'less_and_greater' => false,
    ])
    ->withRules([SingleLineEmptyBodyFixer::class])
    ->withRootFiles();
