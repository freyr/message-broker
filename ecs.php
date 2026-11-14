<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\Basic\SingleLineEmptyBodyFixer;
use PhpCsFixer\Fixer\ClassNotation\ClassAttributesSeparationFixer;
use PhpCsFixer\Fixer\ControlStructure\YodaStyleFixer;
use PhpCsFixer\Fixer\Import\GlobalNamespaceImportFixer;
use PhpCsFixer\Fixer\Operator\ConcatSpaceFixer;
use PhpCsFixer\Fixer\Operator\NotOperatorWithSuccessorSpaceFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocAlignFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocLineSpanFixer;
use Symplify\CodingStandard\Fixer\Spacing\SpaceAfterCommaHereNowDocFixer;
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
            // Moves the comma after a closing heredoc marker onto its own line,
            // which defeats PHP 7.3+ flexible heredoc.
            SpaceAfterCommaHereNowDocFixer::class,
        ]
    )
    ->withPreparedSets(psr12: true, common: true)
    ->withPhpCsFixerSets(symfony: true)
    ->withConfiguredRule(YodaStyleFixer::class, [
        'equal' => false,
        'identical' => false,
        'less_and_greater' => false,
    ])
    // Keep the Symfony style the code base follows; the `common` set would
    // otherwise impose spaced concatenation and blank lines between every
    // constant and property.
    ->withConfiguredRule(ConcatSpaceFixer::class, [
        'spacing' => 'none',
    ])
    ->withConfiguredRule(ClassAttributesSeparationFixer::class, [
        'elements' => [
            'method' => 'one',
        ],
    ])
    ->withRules([SingleLineEmptyBodyFixer::class])
    ->withRootFiles();
