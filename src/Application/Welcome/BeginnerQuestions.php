<?php

declare(strict_types=1);

namespace Patrimeter\Application\Welcome;

use Patrimeter\Application\Game\Game;
use Patrimeter\Domain\AmountRange;
use Patrimeter\Domain\Game\SaverStyle;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\UserId;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Simple questions for beginners (welcome page): one question per account type, in everyday words, answered by "no",
 * by a range of amounts or by an exact amount; then, optionally, the saver style. The answers create the first
 * accounts through StarterAccounts (balances flagged "to refine"); the style only orders the challenges. A question
 * describes what the user may already hold, never what they should open.
 */
final class BeginnerQuestions
{
    /** Answer for an exact amount (typed in the field of the question). */
    public const string EXACT = 'exact';

    /**
     * Questions in order: account type code => [question, why it is asked (the level of the pyramid), name of the
     * account created, home scale].
     */
    private const array QUESTIONS = [
        'compte_courant' => [
            'Avez-vous un compte courant ? Environ combien dessus aujourd\'hui ?',
            'Le compte courant compte dans l\'épargne de précaution : l\'argent disponible tout de suite.',
            'Compte courant',
            false,
        ],
        'livret_a' => [
            'Avez-vous de l\'argent de côté, disponible tout de suite (Livret A, LDDS, LEP…) ?',
            'Les livrets forment l\'épargne de précaution : de l\'argent disponible tout de suite, sans risque de perte.',
            'Livrets',
            false,
        ],
        'fonds_euros' => [
            'Avez-vous une assurance vie en fonds euros ?',
            'Le fonds euros d\'une assurance vie a son propre niveau dans la pyramide : un capital garanti, disponible en quelques jours.',
            'Assurance vie',
            false,
        ],
        'cto' => [
            'Avez-vous des placements en bourse (actions, PEA, compte-titres, unités de compte) ?',
            'Les placements en bourse forment le niveau des actions : leur valeur monte et descend avec les marchés.',
            'Placements en bourse',
            false,
        ],
        'residence_principale' => [
            'Êtes-vous propriétaire de votre logement ? Combien vaut-il environ ?',
            'La résidence principale est un niveau à part de la pyramide. Détenue à plusieurs ? Vous indiquerez votre part ensuite, dans Biens immobiliers.',
            'Résidence principale',
            true,
        ],
        'credit_immo_rp' => [
            'Reste-t-il un crédit sur ce logement ? Environ combien à rembourser ?',
            'Le capital restant dû est déduit de la valeur du logement, au même niveau de la pyramide.',
            'Crédit immobilier',
            true,
        ],
        'credit_conso' => [
            'Avez-vous un autre crédit en cours (voiture, travaux, consommation) ? Environ combien reste-t-il à rembourser ?',
            'Un crédit à la consommation est déduit de l\'épargne de précaution.',
            'Crédit',
            false,
        ],
    ];

    public function __construct(
        private readonly StarterAccounts $starters,
        private readonly Game $game,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * The questions, in order.
     *
     * @return list<array{code: string, question: string, why: string, ranges: list<AmountRange>}>
     */
    public function questions(): array
    {
        $questions = [];
        foreach (self::QUESTIONS as $code => [$question, $why, , $home]) {
            $questions[] = ['code' => $code, 'question' => $question, 'why' => $why, 'ranges' => $home ? AmountRange::home() : AmountRange::savings()];
        }

        return $questions;
    }

    /**
     * Creates the accounts answered (all or none) and keeps the saver style chosen.
     *
     * @param array<array-key, mixed> $answers type code => "" (no), a range key or EXACT; "style" => a saver style
     * @param array<array-key, mixed> $amounts type code => exact amount typed
     *
     * @return array{created: int, errors: array<string, string>} errors by type code (nothing created then)
     */
    public function answer(UserId $owner, array $answers, array $amounts, string $locale): array
    {
        $rows = [];
        $errors = [];
        foreach (self::QUESTIONS as $code => [, , $name, $home]) {
            $answer = self::text($answers, $code);
            $exact = self::text($amounts, $code);
            $range = AmountRange::tryFromKey($answer, $home);
            if ($range !== null) {
                $amount = Money::toInput($range->cents(), $locale);
            } elseif ($answer === self::EXACT || ($answer === '' && $exact !== '')) {
                if ($exact === '') {
                    $errors[$code] = 'Indiquez le montant, ou choisissez une tranche.';
                    continue;
                }
                // Read here too, so that every wrong amount is pointed out at once.
                try {
                    Money::parse($exact, $locale);
                } catch (\InvalidArgumentException $e) {
                    $errors[$code] = $e->getMessage();
                    continue;
                }
                $amount = $exact;
            } else {
                continue;
            }
            $rows[$code] = ['name' => $this->translator->trans($name, [], 'messages', $locale), 'amount' => $amount];
        }
        if ($errors !== []) {
            return ['created' => 0, 'errors' => $errors];
        }
        $result = $this->starters->create($owner, $rows, $locale);
        // The style answered is kept, accounts or not: it only orders the challenges.
        $style = SaverStyle::tryFrom(self::text($answers, 'style'));
        if ($result['errors'] === [] && $style !== null) {
            $this->game->setStyle($owner, $style);
        }

        return $result;
    }

    /** @param array<array-key, mixed> $values */
    private static function text(array $values, string $key): string
    {
        return array_key_exists($key, $values) && is_string($values[$key]) ? trim($values[$key]) : '';
    }
}
