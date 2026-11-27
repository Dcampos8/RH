<?php
declare(strict_types=1);

function calculate_late_minutes(DateTimeImmutable $firstPunch, string $date, ?string $scheduledStart, int $graceMinutes = 10): ?int
{
    $scheduledStart = trim((string)$scheduledStart);
    if ($scheduledStart === '') return null;

    $graceEnd = (new DateTimeImmutable($date . ' ' . $scheduledStart))->modify('+' . $graceMinutes . ' minutes');
    $secondsAfterGrace = $firstPunch->getTimestamp() - $graceEnd->getTimestamp();
    return $secondsAfterGrace < 60 ? 0 : intdiv($secondsAfterGrace, 60);
}

function format_attendance_duration(?int $minutes): string
{
    if ($minutes === null) return '—';
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}

/**
 * Classifies chronological device marks around a scheduled shift.
 * Short pairs well before shift end are meal out/return, not checkout.
 * Post-shift marks are retained as overtime marks instead of replacing the regular exit.
 */
function summarize_attendance_punches(array $punches): array
{
    $count = count($punches);
    $exitIndex = null;
    $mealOutIndex = null;
    $mealBackIndex = null;
    $overtimeEntryIndex = null;
    $overtimeExitIndex = null;
    $overtimeMinutes = null;
    $labels = [];

    if ($count === 0) {
        return [
            'exit_index' => null, 'meal_out_index' => null, 'meal_back_index' => null,
            'meal_minutes' => null, 'overtime_entry_index' => null,
            'overtime_exit_index' => null, 'overtime_minutes' => null, 'labels' => [],
        ];
    }

    $date = (new DateTimeImmutable((string)$punches[0]['punch_time']))->format('Y-m-d');
    $startText = trim((string)($punches[0]['hora_inicio'] ?? ''));
    $endText = trim((string)($punches[0]['hora_fin'] ?? ''));
    $expectedEnd = null;
    if ($endText !== '') {
        $expectedEnd = new DateTimeImmutable($date . ' ' . $endText);
        if ($startText !== '' && $endText <= $startText) $expectedEnd = $expectedEnd->modify('+1 day');
    }
    $times = array_map(static fn(array $punch): DateTimeImmutable => new DateTimeImmutable((string)$punch['punch_time']), $punches);

    if ($count > 1 && $expectedEnd !== null) {
        $afterShift = [];
        $beforeShift = [];
        foreach ($times as $index => $time) {
            if ($index === 0) continue;
            if ($time > $expectedEnd) $afterShift[] = $index;
            else $beforeShift[] = $index;
        }

        // Identify a plausible meal pair before the shift ends, even if the shift's final
        // checkout has not happened yet. A three-mark day can therefore be entry + meal pair.
        $mealCandidates = array_values(array_filter($beforeShift, static fn(int $index): bool => $times[$index] < $expectedEnd->modify('-90 minutes')));
        if (count($mealCandidates) >= 2) {
            $candidateOut = $mealCandidates[0];
            $candidateBack = $mealCandidates[1];
            $gap = $times[$candidateBack]->getTimestamp() - $times[$candidateOut]->getTimestamp();
            if ($gap >= 0 && $gap <= 3 * 3600) {
                $mealOutIndex = $candidateOut;
                $mealBackIndex = $candidateBack;
            }
        } elseif (count($mealCandidates) === 1 && $count === 2) {
            // One early mark well before shift end is more likely a meal departure than checkout.
            $mealOutIndex = $mealCandidates[0];
        }

        $nearEnd = array_values(array_filter($beforeShift, static fn(int $index): bool =>
            $times[$index] >= $expectedEnd->modify('-90 minutes')
            && $index !== $mealOutIndex && $index !== $mealBackIndex
        ));
        if (count($afterShift) >= 2) {
            // Preserve a regular exit near the scheduled end. Any later pair can then
            // represent an extra-work entry/exit without replacing that regular exit.
            $remainingAfterShift = $afterShift;
            if ($nearEnd) {
                $exitIndex = $nearEnd[count($nearEnd) - 1];
            }
            if (count($remainingAfterShift) >= 2) {
                $overtimeEntryIndex = $remainingAfterShift[0];
                $overtimeExitIndex = $remainingAfterShift[count($remainingAfterShift) - 1];
                $overtimeMinutes = max(0, (int)floor(($times[$overtimeExitIndex]->getTimestamp() - $times[$overtimeEntryIndex]->getTimestamp()) / 60));
            }
        } elseif (count($afterShift) === 1) {
            $markIndex = $afterShift[0];
            $exitIndex = $markIndex;
            $overtimeExitIndex = $markIndex;
            $overtimeMinutes = max(0, (int)floor(($times[$markIndex]->getTimestamp() - $expectedEnd->getTimestamp()) / 60));
        } else {
            if ($nearEnd) $exitIndex = $nearEnd[count($nearEnd) - 1];
        }
    } elseif ($count > 1) {
        // Without a configured shift there is no reliable meal/overtime boundary.
        $exitIndex = $count - 1;
    }

    foreach ($punches as $index => $_punch) {
        if ($index === 0) $labels[$index] = 'Entrada';
        elseif ($index === $mealOutIndex) $labels[$index] = 'Salida a comida';
        elseif ($index === $mealBackIndex) $labels[$index] = 'Regreso de comida';
        elseif ($index === $overtimeEntryIndex) $labels[$index] = 'Entrada extra';
        elseif ($index === $overtimeExitIndex) $labels[$index] = 'Salida extra';
        elseif ($index === $exitIndex) $labels[$index] = 'Salida';
        else $labels[$index] = 'Checada adicional';
    }

    $mealOut = $mealOutIndex !== null ? $times[$mealOutIndex] : null;
    $mealBack = $mealBackIndex !== null ? $times[$mealBackIndex] : null;
    $mealMinutes = $mealOut && $mealBack
        ? max(0, (int)floor(($mealBack->getTimestamp() - $mealOut->getTimestamp()) / 60))
        : null;

    return [
        'exit_index' => $exitIndex,
        'meal_out_index' => $mealOutIndex,
        'meal_back_index' => $mealBackIndex,
        'meal_minutes' => $mealMinutes,
        'overtime_entry_index' => $overtimeEntryIndex,
        'overtime_exit_index' => $overtimeExitIndex,
        'overtime_minutes' => $overtimeMinutes,
        'labels' => $labels,
    ];
}
