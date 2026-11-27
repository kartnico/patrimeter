<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Game\ChallengeCard;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Game\Challenge;
use Patrimeter\Domain\Game\ChallengeFamily;
use Patrimeter\Domain\Game\ChallengeResult;
use Patrimeter\Domain\Game\FirstStep;
use Patrimeter\Domain\Game\SaverLevel;
use Patrimeter\Domain\Game\SaverStage;
use Patrimeter\Domain\Goal\GoalScope;
use Patrimeter\Domain\Goal\GoalStatus;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\WealthDistribution;
use Patrimeter\Domain\WorldWealthDistribution;
use Patrimeter\Http\HistoryChart;
use Patrimeter\Security\AuthJournal;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Interface in French (source language) and in English: every text of the interface has its English translation.
 * The texts found in the templates and the code (trans filter, t(), constraints) are checked by debug:translation;
 * the labels built at run time (levels, account types, budget groups, log, periods, sources) are checked one by one.
 */
final class TranslationCompletenessTest extends KernelTestCase
{
    public function testEveryExtractedMessageHasAnEnglishTranslation(): void
    {
        $application = new Application(self::bootKernel());
        foreach (['messages', 'validators'] as $domain) {
            $tester = new CommandTester($application->find('debug:translation'));
            $tester->execute(['locale' => 'en', '--only-missing' => true, '--domain' => $domain]);

            self::assertSame(0, $tester->getStatusCode(), "Traductions anglaises manquantes ($domain) :\n" . $tester->getDisplay());
        }
    }

    /**
     * "(condition ? 'A' : 'B')|trans" escapes the extraction: neither A nor B would be checked. Each text carries its
     * own trans filter ("condition ? 'A'|trans : 'B'|trans").
     */
    public function testTemplatesNeverTranslateAComputedString(): void
    {
        $finder = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($finder as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $content = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression("/'\\)\\|trans/", $content, $file->getPathname());
        }
    }

    /**
     * A constraint message built before being translated (sprintf, concatenation) escapes the extraction and the
     * translator: it would stay in French. The values go through Symfony's parameters ({{ limit }}…).
     */
    public function testConstraintMessagesAreNeverComputed(): void
    {
        $finder = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($finder as $file) {
            self::assertInstanceOf(\SplFileInfo::class, $file);
            $content = (string) file_get_contents($file->getPathname());
            self::assertDoesNotMatchRegularExpression('/[mM]essage:\s*(?:sprintf\(|[\'"][^\'"]*[\'"]\s*\.)/', $content, $file->getPathname());
        }
    }

    public function testRuntimeLabelsHaveAnEnglishTranslation(): void
    {
        $catalogue = $this->catalogue();
        foreach (Level::cases() as $level) {
            self::assertTrue($catalogue->defines($level->label(), 'levels'), $level->label());
            self::assertTrue($catalogue->defines($level->shortLabel(), 'levels'), $level->shortLabel());
        }
        /** @var list<string> $types */
        $types = self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT label FROM account_types');
        self::assertCount(38, $types);
        foreach ($types as $type) {
            self::assertTrue($catalogue->defines($type, 'account_types'), $type);
        }
        $labels = [
            ...array_map(static fn(BudgetGroup $group): string => $group->label(), BudgetGroup::cases()),
            ...array_map(static fn(GoalStatus $status): string => $status->label(), GoalStatus::cases()),
            ...array_map(static fn(GoalScope $scope): string => $scope->label(), GoalScope::cases()),
            ...array_column(HistoryChart::PERIODS, 0),
            ...array_values(HistoryChart::MODES),
            ...self::journalLabels(),
            WealthDistribution::SOURCE, WealthDistribution::REFERENCE,
            WorldWealthDistribution::SOURCE, WorldWealthDistribution::REFERENCE,
            ...self::gameLabels(),
        ];
        foreach ($labels as $label) {
            self::assertTrue($catalogue->defines($label), $label);
        }
    }

    public function testPlaceholdersAndPluralsMatchTheFrenchSource(): void
    {
        foreach (['messages', 'validators', 'levels', 'account_types'] as $domain) {
            foreach ($this->catalogue()->all($domain) as $french => $english) {
                $french = (string) $french;
                self::assertIsString($english);
                preg_match_all('/%[a-z_]+%|\{\{ [a-z_]+ \}\}/', $french, $expected);
                preg_match_all('/%[a-z_]+%|\{\{ [a-z_]+ \}\}/', $english, $actual);
                self::assertEqualsCanonicalizing(array_unique($expected[0]), array_unique($actual[0]), "Paramètres de « $french »");
                self::assertSame(substr_count($french, '|'), substr_count($english, '|'), "Pluriel de « $french »");
            }
        }
    }

    private function catalogue(): MessageCatalogueInterface
    {
        return self::getContainer()->get(TranslatorInterface::class)->getCatalogue('en');
    }

    /** @return list<string> texts of the challenges, their families, the stages, the levels and the first steps */
    private static function gameLabels(): array
    {
        $labels = [];
        foreach (Challenge::cases() as $challenge) {
            array_push($labels, $challenge->title(), $challenge->description(), $challenge->progressLabel(), $challenge->thresholdLabel(), $challenge->stepLabel(), $challenge->missingLabel());
            $unavailable = $challenge->unavailableLabel();
            if ($unavailable !== null) {
                $labels[] = $unavailable;
            }
        }
        foreach (SaverStage::cases() as $stage) {
            array_push($labels, $stage->label(), $stage->requirement());
            $measure = $stage->measureLabel();
            if ($measure !== null) {
                $labels[] = $measure;
            }
        }
        $firstSteps = new ChallengeResult(Challenge::FirstSteps, 0, [1, 2, 3, 4, 5]);
        foreach (range(0, 4) as $stars) {
            $labels[] = (string) (new ChallengeCard($firstSteps, $stars))->nextStarLabel();
        }

        return [
            ...$labels,
            ...array_map(static fn(ChallengeFamily $family): string => $family->label(), ChallengeFamily::cases()),
            ...array_map(static fn(FirstStep $step): string => $step->label(), FirstStep::cases()),
            ...array_column(SaverLevel::ladder(), 'name'),
        ];
    }

    /** @return list<string> labels of the sign-in log (AuthJournal::LABELS, private) */
    private static function journalLabels(): array
    {
        $labels = (new \ReflectionClassConstant(AuthJournal::class, 'LABELS'))->getValue();
        self::assertIsArray($labels);

        return array_values(array_filter($labels, is_string(...)));
    }
}
