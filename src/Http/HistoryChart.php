<?php

declare(strict_types=1);

namespace Patrimeter\Http;

use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\NetWorthSnapshot;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * History of the net worth at the bottom of the wealth page: period and view chosen in the query
 * (periode, vue), monthly series up to the month shown, and data for Chart.js (history.js).
 */
final class HistoryChart
{
    /**
     * Periods offered: query value => [label (translated in the template), number of months (null = all)].
     *
     * @var array<int|string, array{string, ?int}>
     */
    public const array PERIODS = [
        '12' => ['12 mois', 12],
        '24' => ['2 ans', 24],
        '60' => ['5 ans', 60],
        'tout' => ['Tout', null],
    ];

    /** @var array<string, string> */
    public const array MODES = [
        'total' => 'Net total',
        'niveaux' => 'Par niveau',
    ];

    private const string DEFAULT_PERIOD = '12';
    private const string DEFAULT_MODE = 'total';

    /** JSON inserted in the page: no character can close the <script> element. */
    private const int JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public function __construct(private readonly TranslatorInterface $translator) {}

    /** Period of the query, or the default one (12 months). */
    public static function period(Request $request): string
    {
        $period = $request->query->getString('periode', self::DEFAULT_PERIOD);

        return isset(self::PERIODS[$period]) ? $period : self::DEFAULT_PERIOD;
    }

    /** View of the query, or the default one (net total). */
    public static function mode(Request $request): string
    {
        $mode = $request->query->getString('vue', self::DEFAULT_MODE);

        return isset(self::MODES[$mode]) ? $mode : self::DEFAULT_MODE;
    }

    /**
     * Template variables: period, mode, periods, modes, series (up to $to) and chart_json.
     *
     * @return array{period: string, mode: string, periods: array<int|string, array{string, ?int}>, modes: array<string, string>, series: list<NetWorthSnapshot>, chart_json: string}
     */
    public function view(Request $request, NetWorthCalculator $calculator, Month $to): array
    {
        $period = self::period($request);
        $mode = self::mode($request);
        $first = $calculator->firstMonth();
        $series = [];
        if ($first !== null && $first->compare($to) <= 0) {
            $months = self::months($period);
            $from = $months === null ? $first : $to->addMonths(-($months - 1));
            if ($from->compare($first) < 0) {
                $from = $first;
            }
            $series = $calculator->series($from, $to);
        }

        return [
            'period' => $period,
            'mode' => $mode,
            'periods' => self::PERIODS,
            'modes' => self::MODES,
            'series' => $series,
            'chart_json' => json_encode($this->chartData($series, $mode), self::JSON_FLAGS),
        ];
    }

    /** Number of months of a period (null: all); numeric keys of PERIODS are integers in PHP. */
    private static function months(string $period): ?int
    {
        foreach (self::PERIODS as $key => [, $months]) {
            if ((string) $key === $period) {
                return $months;
            }
        }

        return null;
    }

    /**
     * Data for Chart.js (amounts in cents, converted in the browser).
     *
     * @param list<NetWorthSnapshot> $series
     *
     * @return array<string, mixed>
     */
    private function chartData(array $series, string $mode): array
    {
        $locale = $this->translator->getLocale();
        $levels = [];
        foreach (Level::ordered() as $level) {
            $levels[] = [
                'code' => $level->value,
                'label' => $this->translator->trans($level->label(), [], 'levels'),
                'data' => array_map(static fn(NetWorthSnapshot $s): int => $s->level($level)->net(), $series),
            ];
        }

        return [
            'mode' => $mode,
            'locale' => $locale,
            'total_label' => $this->translator->trans('Patrimoine net'),
            'labels' => array_map(static fn(NetWorthSnapshot $s): string => $s->month->shortLabel($locale), $series),
            'months' => array_map(static fn(NetWorthSnapshot $s): string => $s->month->label($locale), $series),
            'total' => array_map(static fn(NetWorthSnapshot $s): int => $s->net(), $series),
            'levels' => $levels,
        ];
    }
}
