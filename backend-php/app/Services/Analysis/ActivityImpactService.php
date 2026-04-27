<?php

namespace App\Services\Analysis;

class ActivityImpactService
{
    public const INTENSITY_EASY = 'easy';
    public const INTENSITY_MODERATE = 'moderate';
    public const INTENSITY_HARD = 'hard';

    /**
     * @return array<int,string>
     */
    public static function supportedSports(): array
    {
        return ['run', 'trail_run', 'treadmill', 'bike', 'swim', 'walk_hike', 'strength', 'other'];
    }

    public function normalizeSport(mixed $rawSport, array $context = []): string
    {
        $raw = strtolower(trim((string) ($rawSport ?? '')));
        $activityType = strtolower(trim((string) ($context['activityType'] ?? '')));
        $kind = strtolower(trim((string) ($context['kind'] ?? '')));
        $candidate = $raw !== '' ? $raw : ($activityType !== '' ? $activityType : $kind);
        $candidate = str_replace(['-', ' '], '_', $candidate);

        if (in_array($candidate, self::supportedSports(), true)) {
            return $candidate;
        }

        return match (true) {
            str_contains($candidate, 'trail') && str_contains($candidate, 'run') => 'trail_run',
            str_contains($candidate, 'treadmill') => 'treadmill',
            str_contains($candidate, 'run'), str_contains($candidate, 'running') => 'run',
            str_contains($candidate, 'bike'), str_contains($candidate, 'cycl'), str_contains($candidate, 'biking') => 'bike',
            str_contains($candidate, 'swim') => 'swim',
            str_contains($candidate, 'walk'), str_contains($candidate, 'hike'), str_contains($candidate, 'hiking') => 'walk_hike',
            str_contains($candidate, 'strength'), str_contains($candidate, 'gym'), str_contains($candidate, 'weight'),
                str_contains($candidate, 'silownia'), str_contains($candidate, 'sila') => 'strength',
            default => 'other',
        };
    }

    public function normalizeStrengthSubtype(mixed $rawSubtype): ?string
    {
        $raw = strtolower(trim((string) ($rawSubtype ?? '')));
        $raw = str_replace(['-', ' '], '_', $raw);
        if ($raw === '') {
            return null;
        }

        return match (true) {
            in_array($raw, ['lower_body', 'legs', 'leg', 'nogi', 'lower'], true) => 'lower_body',
            in_array($raw, ['upper_body', 'upper', 'gora', 'gorna', 'arms', 'chest', 'back'], true) => 'upper_body',
            in_array($raw, ['full_body', 'full', 'fbw', 'total_body'], true) => 'full_body',
            in_array($raw, ['core', 'abs', 'brzuch'], true) => 'core',
            in_array($raw, ['mobility', 'stretching', 'yoga', 'mobility_rehab'], true) => 'mobility',
            default => null,
        };
    }

    public function normalizeIntensity(mixed $rawIntensity, ?int $rpe = null): string
    {
        $raw = strtolower(trim((string) ($rawIntensity ?? '')));
        $raw = str_replace(['-', ' '], '_', $raw);

        if (in_array($raw, [self::INTENSITY_EASY, 'light', 'recovery', 'z1', 'z2'], true)) {
            return self::INTENSITY_EASY;
        }
        if (in_array($raw, [self::INTENSITY_HARD, 'intense', 'high', 'z4', 'z5'], true)) {
            return self::INTENSITY_HARD;
        }
        if (in_array($raw, [self::INTENSITY_MODERATE, 'medium', 'normal', 'z3'], true)) {
            return self::INTENSITY_MODERATE;
        }

        if ($rpe !== null) {
            if ($rpe <= 3) {
                return self::INTENSITY_EASY;
            }
            if ($rpe >= 7) {
                return self::INTENSITY_HARD;
            }
        }

        return self::INTENSITY_MODERATE;
    }

    /**
     * @param array<string,mixed> $context
     * @return array{
     *   sportKind:string,
     *   sportSubtype:string|null,
     *   intensity:string,
     *   durationMin:float,
     *   runningLoadMin:float,
     *   crossTrainingFatigueMin:float,
     *   overallFatigueMin:float,
     *   collisionLevel:string,
     *   affectedSystems:list<string>,
     *   needsUserClassification:bool
     * }
     */
    public function impact(
        string $sportKind,
        ?string $sportSubtype,
        ?int $durationSec,
        ?float $elevationGainMeters = null,
        ?int $perceivedEffort = null,
        array $context = [],
    ): array {
        $sport = $this->normalizeSport($sportKind);
        $subtype = $sport === 'strength' ? $this->normalizeStrengthSubtype($sportSubtype) : $sportSubtype;
        $durationMin = $durationSec !== null && $durationSec > 0 ? round($durationSec / 60.0, 2) : 0.0;
        $intensity = $this->normalizeIntensity($context['intensity'] ?? $context['intensityHint'] ?? null, $perceivedEffort);
        $factor = $this->intensityFactor($intensity);

        $runningLoad = $this->isRunish($sport) ? $durationMin : 0.0;
        $crossLoad = 0.0;
        $collision = 'none';
        $systems = [];
        $needsUserClassification = false;

        if ($sport === 'bike') {
            $crossLoad = min($durationMin, 180.0) * 0.5 * $factor;
            $collision = ($intensity === self::INTENSITY_HARD || $durationMin > 60.0) ? 'medium' : 'low';
            $systems = ['aerobic', 'lower_body'];
        } elseif ($sport === 'swim') {
            $crossLoad = min($durationMin, 150.0) * 0.4 * $factor;
            $collision = $intensity === self::INTENSITY_HARD ? 'medium' : 'low';
            $systems = ['aerobic', 'upper_body'];
        } elseif ($sport === 'walk_hike') {
            $hikeLike = ($elevationGainMeters ?? 0.0) >= 300.0 || in_array($subtype, ['hike', 'hiking'], true);
            $crossLoad = $durationMin * ($hikeLike ? 0.45 : 0.25) * $factor;
            $collision = $durationMin > 120.0 || $hikeLike ? 'medium' : 'low';
            $systems = ['aerobic', 'lower_body'];
        } elseif ($sport === 'strength') {
            $strengthSubtype = $subtype ?? 'full_body';
            $weight = in_array($strengthSubtype, ['lower_body', 'full_body'], true)
                ? 0.8
                : (in_array($strengthSubtype, ['upper_body', 'core'], true) ? 0.35 : 0.1);
            $crossLoad = $durationMin * $weight * $factor;
            $collision = in_array($strengthSubtype, ['lower_body', 'full_body'], true)
                ? ($intensity === self::INTENSITY_HARD || $durationMin >= 45.0 ? 'high' : 'medium')
                : ($intensity === self::INTENSITY_HARD ? 'medium' : 'low');
            $systems = match ($strengthSubtype) {
                'lower_body' => ['lower_body'],
                'upper_body' => ['upper_body'],
                'core' => ['upper_body'],
                'mobility' => ['mobility'],
                default => ['lower_body', 'upper_body'],
            };
        } elseif ($sport === 'other') {
            $crossLoad = $durationMin * 0.5 * $factor;
            $collision = $durationMin > 0 ? 'medium' : 'none';
            $systems = ['aerobic'];
            $needsUserClassification = true;
        } elseif ($this->isRunish($sport)) {
            $systems = ['aerobic', 'lower_body'];
            $collision = $intensity === self::INTENSITY_HARD ? 'medium' : 'low';
        }

        $crossLoad = round(max(0.0, $crossLoad), 2);
        $runningLoad = round(max(0.0, $runningLoad), 2);

        return [
            'sportKind' => $sport,
            'sportSubtype' => $subtype,
            'intensity' => $intensity,
            'durationMin' => $durationMin,
            'runningLoadMin' => $runningLoad,
            'crossTrainingFatigueMin' => $crossLoad,
            'overallFatigueMin' => round($runningLoad + $crossLoad, 2),
            'collisionLevel' => $collision,
            'affectedSystems' => array_values(array_unique($systems)),
            'needsUserClassification' => $needsUserClassification,
        ];
    }

    public function isRunish(string $sport): bool
    {
        return in_array($sport, ['run', 'trail_run', 'treadmill'], true);
    }

    private function intensityFactor(string $intensity): float
    {
        return match ($intensity) {
            self::INTENSITY_EASY => 0.7,
            self::INTENSITY_HARD => 1.3,
            default => 1.0,
        };
    }
}
