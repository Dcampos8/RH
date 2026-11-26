<?php
declare(strict_types=1);

/** Classifies the day's punches using the scheduled shift end to identify checkout. */
function summarize_attendance_punches(array $punches): array
{
    $count = count($punches);
    $exitIndex = null;
    if ($count > 1) {
        $exitIndex = $count - 1;
        $date = (new DateTimeImmutable((string)$punches[0]['punch_time']))->format('Y-m-d');
        $endText = trim((string)($punches[0]['hora_fin'] ?? ''));
        if ($endText !== '') {
            $expectedEnd = new DateTimeImmutable($date . ' ' . $endText);
            $startText = trim((string)($punches[0]['hora_inicio'] ?? ''));
            if ($startText !== '' && $endText <= $startText) {
                $expectedEnd = $expectedEnd->modify('+1 day');
            }
            $closestDistance = PHP_INT_MAX;
            for ($index = 1; $index < $count; $index++) {
                $punchAt = new DateTimeImmutable((string)$punches[$index]['punch_time']);
                foreach ([-1, 0, 1] as $dayOffset) {
                    $target = $dayOffset === 0
                        ? $expectedEnd
                        : $expectedEnd->modify(($dayOffset > 0 ? '+' : '') . $dayOffset . ' day');
                    $distance = abs($punchAt->getTimestamp() - $target->getTimestamp());
                    if ($distance < $closestDistance) {
                        $closestDistance = $distance;
                        $exitIndex = $index;
                    }
                }
            }
        }
    }

    $mealIndexes = [];
    if ($count >= 4) {
        for ($index = 1; $index < $count && count($mealIndexes) < 2; $index++) {
            if ($index !== $exitIndex) $mealIndexes[] = $index;
        }
        if (count($mealIndexes) < 2) $mealIndexes = [];
    }

    $labels = [];
    foreach ($punches as $index => $_punch) {
        if ($index === 0) $labels[$index] = 'Entrada';
        elseif ($index === $exitIndex) $labels[$index] = 'Salida';
        elseif ($index === ($mealIndexes[0] ?? null)) $labels[$index] = 'Salida a comida';
        elseif ($index === ($mealIndexes[1] ?? null)) $labels[$index] = 'Regreso de comida';
        else $labels[$index] = $count >= 4 ? 'Checada extra' : 'Checada intermedia';
    }

    $mealOut = isset($mealIndexes[0]) ? new DateTimeImmutable((string)$punches[$mealIndexes[0]]['punch_time']) : null;
    $mealBack = isset($mealIndexes[1]) ? new DateTimeImmutable((string)$punches[$mealIndexes[1]]['punch_time']) : null;
    $mealMinutes = $mealOut && $mealBack
        ? max(0, (int)floor(($mealBack->getTimestamp() - $mealOut->getTimestamp()) / 60))
        : null;

    return [
        'exit_index' => $exitIndex,
        'meal_out_index' => $mealIndexes[0] ?? null,
        'meal_back_index' => $mealIndexes[1] ?? null,
        'meal_minutes' => $mealMinutes,
        'labels' => $labels,
    ];
}
