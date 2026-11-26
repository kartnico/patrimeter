<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Game;

/**
 * Saver style, declared by the user (optional, changeable at any time). It only changes the order of the challenges
 * shown; it says nothing about what to buy nor about the risk to take.
 */
enum SaverStyle: string
{
    case Ant = 'ant';
    case Project = 'project';
    case Home = 'home';
    case Horizon = 'horizon';

    public function label(): string
    {
        return match ($this) {
            self::Ant => 'Fourmi',
            self::Project => 'Projet',
            self::Home => 'Foyer',
            self::Horizon => 'Horizon',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Ant => 'J\'épargne un peu chaque mois, sans y penser.',
            self::Project => 'J\'épargne pour un projet daté : voyage, apport, études.',
            self::Home => 'Ma priorité : le logement et son crédit.',
            self::Horizon => 'Je prépare le long terme : retraite, indépendance.',
        };
    }

    /**
     * Challenges in the order of this style ("Premiers pas" apart: always first while it is not complete).
     *
     * @return list<Challenge>
     */
    public function order(): array
    {
        return match ($this) {
            self::Ant => [Challenge::Streak, Challenge::UpToDate, Challenge::Budget, Challenge::SavingsRate, Challenge::Precaution, Challenge::Goal, Challenge::NoConsumerCredit, Challenge::Security],
            self::Project => [Challenge::Goal, Challenge::SavingsRate, Challenge::Budget, Challenge::Streak, Challenge::Precaution, Challenge::UpToDate, Challenge::NoConsumerCredit, Challenge::Security],
            self::Home => [Challenge::NoConsumerCredit, Challenge::Precaution, Challenge::Budget, Challenge::Streak, Challenge::Goal, Challenge::SavingsRate, Challenge::UpToDate, Challenge::Security],
            self::Horizon => [Challenge::UpToDate, Challenge::Streak, Challenge::SavingsRate, Challenge::Goal, Challenge::Precaution, Challenge::Budget, Challenge::NoConsumerCredit, Challenge::Security],
        };
    }

    /**
     * Order without a declared style.
     *
     * @return list<Challenge>
     */
    public static function defaultOrder(): array
    {
        return [Challenge::Streak, Challenge::Precaution, Challenge::Goal, Challenge::Budget, Challenge::SavingsRate, Challenge::UpToDate, Challenge::NoConsumerCredit, Challenge::Security];
    }
}
