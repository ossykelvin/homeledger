<?php

declare(strict_types=1);

final class Recurrence
{
    private const FREQUENCIES = ['daily', 'weekly', 'monthly', 'yearly'];

    public static function nextDate(string $date, string $frequency, int $interval = 1): string
    {
        if (!in_array($frequency, self::FREQUENCIES, true)) {
            throw new InvalidArgumentException('Unsupported recurrence frequency.');
        }

        $interval = max(1, $interval);
        $current = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$current || $current->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid recurrence date.');
        }

        return match ($frequency) {
            'daily' => $current->modify("+{$interval} days")->format('Y-m-d'),
            'weekly' => $current->modify("+{$interval} weeks")->format('Y-m-d'),
            'monthly' => self::addMonthsClamped($current, $interval)->format('Y-m-d'),
            'yearly' => self::addYearsClamped($current, $interval)->format('Y-m-d'),
        };
    }

    private static function addMonthsClamped(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $monthIndex = ((int) $date->format('Y') * 12) + ((int) $date->format('n') - 1) + $months;
        $year = intdiv($monthIndex, 12);
        $month = ($monthIndex % 12) + 1;
        $day = min((int) $date->format('j'), self::daysInMonth($year, $month));

        return $date->setDate($year, $month, $day);
    }

    private static function addYearsClamped(DateTimeImmutable $date, int $years): DateTimeImmutable
    {
        $year = (int) $date->format('Y') + $years;
        $month = (int) $date->format('n');
        $day = min((int) $date->format('j'), self::daysInMonth($year, $month));

        return $date->setDate($year, $month, $day);
    }

    private static function daysInMonth(int $year, int $month): int
    {
        $firstDay = DateTimeImmutable::createFromFormat('!Y-n-j', "{$year}-{$month}-1");
        if (!$firstDay) {
            throw new RuntimeException('Unable to calculate the recurrence date.');
        }

        return (int) $firstDay->modify('last day of this month')->format('j');
    }
}
