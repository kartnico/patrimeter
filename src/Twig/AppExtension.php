<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Patrimeter\Application\Calendar;
use Patrimeter\Application\Form\HouseholdForm;
use Patrimeter\Domain\AccountValue;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetOverview;
use Patrimeter\Domain\Budget\PrecautionCoverage;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\NetWorthSnapshot;
use Patrimeter\Domain\OwnershipShares;
use Patrimeter\Http\LocalePreference;
use Patrimeter\Http\ThemePreference;
use Patrimeter\Infrastructure\Persistence\WebAuthnCredentialRepository;
use Patrimeter\Security\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Formats in the interface language (amounts, dates, months, percentages: French or English) and HTML components of
 * the templates.
 */
final class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly PyramidSvg $pyramid,
        private readonly BudgetSankeySvg $sankey,
        private readonly PrecautionGaugeSvg $gauge,
        private readonly QrCodeSvg $qrCode,
        private readonly Calendar $calendar,
        private readonly CurrentUser $currentUser,
        private readonly WebAuthnCredentialRepository $credentials,
        private readonly TranslatorInterface $translator,
    ) {}

    #[\Override]
    public function getFilters(): array
    {
        return [
            new TwigFilter('money', fn(int $cents, bool $signed = false): string => Money::format($cents, $signed, $this->locale())),
            new TwigFilter('money_rounded', fn(int $cents): string => Money::formatRounded($cents, $this->locale())),
            new TwigFilter('money_input', fn(int $cents): string => Money::toInput($cents, $this->locale())),
            new TwigFilter('percent', fn(?float $value, int $decimals = 1): string => Money::formatPercent($value, $decimals, $this->locale())),
            new TwigFilter('sign_class', self::signClass(...)),
            new TwigFilter('date_fr', fn(?string $date): string => self::dateFr($date, $this->locale())),
            new TwigFilter('datetime_fr', fn(string $timestamp): string => $this->datetimeFr($timestamp, $this->calendar->timezone())),
            new TwigFilter('month_label', fn(Month $month): string => $month->label($this->locale())),
            new TwigFilter('month_of', fn(Month $month): string => $month->ofLabel($this->locale())),
            new TwigFilter('month_short', fn(Month $month): string => $month->shortLabel($this->locale())),
            new TwigFilter('months_count', fn(float|int $months): string => $this->gauge->months((float) $months)),
        ];
    }

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('levels', Level::ordered(...)),
            new TwigFunction('household_child_rows', HouseholdForm::childRows(...)),
            new TwigFunction('value_badge', $this->valueBadge(...), ['is_safe' => ['html']]),
            new TwigFunction('pyramid', fn(NetWorthSnapshot $snapshot, bool $amounts = true): string => $this->pyramid->render($snapshot, $amounts), ['is_safe' => ['html']]),
            new TwigFunction('budget_sankey', fn(BudgetOverview $overview): string => $this->sankey->render($overview), ['is_safe' => ['html']]),
            new TwigFunction('precaution_gauge', fn(PrecautionCoverage $coverage): string => $this->gauge->render($coverage), ['is_safe' => ['html']]),
            new TwigFunction('budget_groups', static fn(): array => BudgetGroup::cases()),
            new TwigFunction('month_picker', fn(Month $selected, Month $max, ?Month $first, string $base, ?Month $today = null): MonthPicker => new MonthPicker($selected, $max, $first, $base, $this->locale(), $today)),
            new TwigFunction('theme_preference', ThemePreference::current(...)),
            new TwigFunction('locales', static fn(): array => LocalePreference::LOCALES),
            new TwigFunction('month_names', $this->monthNames(...)),
            new TwigFunction('current_month_value', fn(): string => $this->calendar->currentMonth()->toString()),
            // Current month (Calendar), shown in the middle of the top bar.
            new TwigFunction('current_month', fn(): Month => $this->calendar->currentMonth()),
            new TwigFunction('qr_code', $this->qrCode->render(...), ['is_safe' => ['html']]),
            // Account ready: signed in, second factor passed and TOTP set up (full navigation).
            // Second factor pending: offer the security key only if the account has one.
            new TwigFunction('has_security_key', fn(): bool => ($user = $this->currentUser->pendingSecondFactor()) !== null && $this->credentials->all($user->id) !== []),
            new TwigFunction('share_percent', fn(int $share): string => OwnershipShares::formatPercent($share, $this->locale())),
            // Share with its sign, as written in the language: « 50 % », "50%".
            new TwigFunction('share_percent_label', fn(int $share): string => OwnershipShares::formatPercent($share, $this->locale()) . ($this->locale() === 'en' ? '' : "\u{00A0}") . '%'),
            new TwigFunction('account_ready', fn(): bool => $this->currentUser->authenticated()?->isReady() ?? false),
        ];
    }

    /** CSS class according to the sign of an amount. */
    public static function signClass(int $cents): string
    {
        return $cents < 0 ? 'negative' : ($cents > 0 ? 'positive' : '');
    }

    /**
     * Names of the twelve months in the interface language, for app.js's calendars.
     *
     * @return array{short: list<string>, long: list<string>}
     */
    public function monthNames(): array
    {
        $short = [];
        $long = [];
        for ($m = 1; $m <= 12; ++$m) {
            $month = Month::fromString(sprintf('2000-%02d', $m));
            $short[] = explode(' ', $month->shortLabel($this->locale()))[0];
            $long[] = explode(' ', $month->label($this->locale()))[0];
        }

        return ['short' => $short, 'long' => $long];
    }

    /** Interface language: "fr" or "en". */
    private function locale(): string
    {
        return $this->translator->getLocale() === LocalePreference::ENGLISH ? LocalePreference::ENGLISH : LocalePreference::FRENCH;
    }

    /** UTC timestamp of the database (YYYY-MM-DDTHH:MM:SSZ) in local time: "15/11/2026 à 12:00", "15 Nov 2026, 12:00". */
    public function datetimeFr(string $timestamp, \DateTimeZone $timezone): string
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i:s\\Z', $timestamp, new \DateTimeZone('UTC'));

        if ($date === false) {
            return '';
        }
        $local = $date->setTimezone($timezone);

        return $this->locale() === LocalePreference::ENGLISH
            ? self::dateFr($local->format('Y-m-d'), LocalePreference::ENGLISH) . ', ' . $local->format('H:i')
            : $local->format('d/m/Y') . ' à ' . $local->format('H:i');
    }

    /** YYYY-MM-DD date in the DD/MM/YYYY format, or "15 Nov 2026" in English. */
    public static function dateFr(?string $date, string $locale = LocalePreference::FRENCH): string
    {
        if ($date === null || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $date, $m) !== 1) {
            return '';
        }
        if ($locale === LocalePreference::ENGLISH) {
            return ltrim($m[3], '0') . ' ' . Month::fromString($m[1] . '-' . $m[2])->shortLabel($locale);
        }

        return $m[3] . '/' . $m[2] . '/' . $m[1];
    }

    /** Badge telling whether the month's value was entered or carried over from an earlier month. */
    public function valueBadge(?AccountValue $value): string
    {
        if ($value === null) {
            return '<span class="badge badge-empty">' . self::escape($this->translator->trans('aucun solde')) . '</span>';
        }
        if ($value->carried) {
            return '<span class="badge badge-carried" title="' . self::escape($this->translator->trans('Dernier solde connu, reporté')) . '">'
                . self::escape($this->translator->trans('reporté · %month%', ['%month%' => $value->sourceMonth->shortLabel($this->locale())]))
                . '</span>';
        }

        return '<span class="badge badge-entered">' . self::escape($this->translator->trans('saisi')) . '</span>';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
