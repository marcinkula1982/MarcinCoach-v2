<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserProfile;
use App\Services\ProfileQualityScoreService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileQualityScoreService $qualityScoreService,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        $userId = $this->authUserId($request);
        $profile = UserProfile::query()->firstOrCreate(
            ['user_id' => $userId],
            [
                'preferred_run_days' => null,
                'preferred_surface' => null,
                'goals' => null,
                'constraints' => null,
                'races_json' => null,
                'availability_json' => null,
                'health_json' => null,
                'equipment_json' => null,
                'onboarding_completed' => false,
            ]
        );

        return response()->json($this->buildResponse($profile));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Backward-compatible fields
            'preferredRunDays' => ['sometimes', 'nullable', 'string'],
            'preferredSurface' => ['sometimes', 'nullable', 'string'],
            'goals' => ['sometimes', 'nullable', 'string'],
            'constraints' => ['sometimes', 'nullable', 'string'],

            // M1 minimum typed onboarding sections
            'races' => ['sometimes', 'nullable', 'array'],
            'races.*.name' => ['nullable', 'string', 'max:160'],
            'races.*.date' => ['required_with:races', 'date', 'before:+5 years'],
            'races.*.distanceKm' => ['required_with:races', 'numeric', 'min:0.1', 'max:200'],
            'races.*.priority' => ['nullable', 'in:A,B,C'],
            'races.*.targetTime' => ['nullable', 'string', 'max:16'],

            'availability' => ['sometimes', 'nullable', 'array'],
            'availability.runningDays' => ['sometimes', 'array'],
            'availability.runningDays.*' => ['in:mon,tue,wed,thu,fri,sat,sun'],
            'availability.maxSessionMin' => ['sometimes', 'integer', 'min:15', 'max:300'],

            'health' => ['sometimes', 'nullable', 'array'],
            'health.injuryHistory' => ['sometimes', 'array'],
            'health.currentPain' => ['sometimes', 'boolean'],

            'equipment' => ['sometimes', 'nullable', 'array'],
            'equipment.watch' => ['sometimes', 'boolean'],
            'equipment.hrSensor' => ['sometimes', 'boolean'],

            // HR zones: cross-field validation done after base validate
            'hrZones' => ['sometimes', 'array'],
            'hrZones.z1.min' => ['nullable', 'integer', 'min:0', 'max:260'],
            'hrZones.z1.max' => ['nullable', 'integer', 'min:0', 'max:260'],
            'hrZones.z2.min' => ['nullable', 'integer', 'min:0', 'max:260'],
            'hrZones.z2.max' => ['nullable', 'integer', 'min:0', 'max:260'],
            'hrZones.z3.min' => ['nullable', 'integer', 'min:0', 'max:260'],
            'hrZones.z3.max' => ['nullable', 'integer', 'min:0', 'max:260'],
            'hrZones.z4.min' => ['nullable', 'integer', 'min:0', 'max:260'],
            'hrZones.z4.max' => ['nullable', 'integer', 'min:0', 'max:260'],
            'hrZones.z5.min' => ['nullable', 'integer', 'min:0', 'max:260'],
            'hrZones.z5.max' => ['nullable', 'integer', 'min:0', 'max:260'],

            'paceZones' => ['sometimes', 'nullable', 'array'],
            'paceZones.status' => ['nullable', 'in:known,derived,estimated,missing'],
            'paceZones.z1SecPerKm' => ['nullable', 'numeric', 'min:60', 'max:1200'],
            'paceZones.z2SecPerKm' => ['nullable', 'numeric', 'min:60', 'max:1200'],
            'paceZones.z3SecPerKm' => ['nullable', 'numeric', 'min:60', 'max:1200'],
            'paceZones.z4SecPerKm' => ['nullable', 'numeric', 'min:60', 'max:1200'],
            'paceZones.z5SecPerKm' => ['nullable', 'numeric', 'min:60', 'max:1200'],
        ]);

        // Cross-field HR zones validation
        if (isset($validated['hrZones']) && is_array($validated['hrZones'])) {
            $this->validateHrZonesCrossField($validated['hrZones']);
        }

        $userId = $this->authUserId($request);
        $profile = UserProfile::query()->firstOrCreate(['user_id' => $userId]);

        if (array_key_exists('preferredRunDays', $validated)) {
            $profile->preferred_run_days = $validated['preferredRunDays'];
        }
        if (array_key_exists('preferredSurface', $validated)) {
            $profile->preferred_surface = $validated['preferredSurface'];
        }
        if (array_key_exists('goals', $validated)) {
            $profile->goals = $validated['goals'];
        }
        if (array_key_exists('constraints', $validated)) {
            $profile->constraints = $validated['constraints'];
        }
        if (array_key_exists('paceZones', $validated)) {
            $profile->constraints = json_encode($this->mergeConstraints($profile->constraints, [
                'paceZones' => $this->normalizePaceZones($validated['paceZones']),
            ]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (array_key_exists('races', $validated)) {
            $profile->races_json = $validated['races'];
        }
        if (array_key_exists('availability', $validated)) {
            $profile->availability_json = $validated['availability'];
        }
        if (array_key_exists('health', $validated)) {
            $profile->health_json = $validated['health'];
        }
        if (array_key_exists('equipment', $validated)) {
            $profile->equipment_json = $validated['equipment'];
        }

        if (isset($validated['hrZones']) && is_array($validated['hrZones'])) {
            $this->applyHrZonesFromPayload($profile, $validated['hrZones']);
        }

        // Derive preferred_run_days from availability.runningDays (canonical direction)
        if (isset($validated['availability']['runningDays']) && is_array($validated['availability']['runningDays'])) {
            $dayMap = array_flip(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']);
            // map to 1-based ISO numbers
            $isoMap = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
            $isoNums = array_values(array_filter(array_map(
                fn ($d) => $isoMap[$d] ?? null,
                $validated['availability']['runningDays']
            )));
            $profile->preferred_run_days = json_encode($isoNums);
        }

        // Project JSON sections → typed columns
        $this->applyProjections($profile);

        // Compute quality score
        $scoreData = $this->qualityScoreService->scoreWithBreakdown($this->profileToScoreArray($profile));
        $profile->profile_quality_score = $scoreData['score'];

        $profile->onboarding_completed = $this->computeOnboardingCompleted($profile);
        $profile->save();

        return response()->json($this->buildResponse($profile, $scoreData));
    }

    /**
     * @param array<string,mixed> $hrZones
     */
    private function validateHrZonesCrossField(array $hrZones): void
    {
        $errors = [];
        $zoneOrder = ['z1', 'z2', 'z3', 'z4', 'z5'];
        $zonePairs = [];

        foreach ($zoneOrder as $zone) {
            $min = isset($hrZones[$zone]['min']) && is_numeric($hrZones[$zone]['min'])
                ? (int) $hrZones[$zone]['min']
                : null;
            $max = isset($hrZones[$zone]['max']) && is_numeric($hrZones[$zone]['max'])
                ? (int) $hrZones[$zone]['max']
                : null;

            $zonePairs[$zone] = ['min' => $min, 'max' => $max];

            if ($min !== null && $max !== null && $min >= $max) {
                $errors["hrZones.{$zone}"] = ["hrZones.{$zone}.min must be less than hrZones.{$zone}.max"];
            }
        }

        // Cross-zone monotonic check
        for ($i = 0; $i < 4; $i++) {
            $current = $zoneOrder[$i];
            $next = $zoneOrder[$i + 1];
            $currentMax = $zonePairs[$current]['max'];
            $nextMin = $zonePairs[$next]['min'];

            if ($currentMax !== null && $nextMin !== null && $currentMax > $nextMin) {
                $errors["hrZones.{$next}"] = ["hrZones.{$next}.min must be greater than or equal to hrZones.{$current}.max ({$currentMax})"];
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param array<string,mixed> $hrZones
     */
    private function applyHrZonesFromPayload(UserProfile $profile, array $hrZones): void
    {
        foreach (['z1', 'z2', 'z3', 'z4', 'z5'] as $zone) {
            if (!isset($hrZones[$zone]) || !is_array($hrZones[$zone])) {
                continue;
            }

            if (array_key_exists('min', $hrZones[$zone])) {
                $profile->{"hr_{$zone}_min"} = $hrZones[$zone]['min'];
            }
            if (array_key_exists('max', $hrZones[$zone])) {
                $profile->{"hr_{$zone}_max"} = $hrZones[$zone]['max'];
            }
        }
    }

    private function applyProjections(UserProfile $profile): void
    {
        // primary_race_* from races_json: nearest future race, priority A > B > C
        $races = is_array($profile->races_json) ? $profile->races_json : [];
        $primaryRace = $this->selectPrimaryRace($races);
        if ($primaryRace !== null) {
            $profile->primary_race_date = Carbon::parse((string) $primaryRace['date'])->toDateString();
            $profile->primary_race_distance_km = (float) $primaryRace['distanceKm'];
            $profile->primary_race_priority = $primaryRace['priority'] ?? null;
        } else {
            $profile->primary_race_date = null;
            $profile->primary_race_distance_km = null;
            $profile->primary_race_priority = null;
        }

        // max_session_min
        $avail = is_array($profile->availability_json) ? $profile->availability_json : [];
        $maxSession = isset($avail['maxSessionMin']) && is_numeric($avail['maxSessionMin'])
            ? (int) $avail['maxSessionMin']
            : null;
        $profile->max_session_min = $maxSession;

        // has_current_pain
        $health = is_array($profile->health_json) ? $profile->health_json : [];
        $profile->has_current_pain = array_key_exists('currentPain', $health) && $health['currentPain'] === true;

        // has_hr_sensor
        $equipment = is_array($profile->equipment_json) ? $profile->equipment_json : [];
        $profile->has_hr_sensor = array_key_exists('hrSensor', $equipment) && $equipment['hrSensor'] === true;
    }

    /**
     * @param array<int,array<string,mixed>> $races
     * @return array<string,mixed>|null
     */
    private function selectPrimaryRace(array $races): ?array
    {
        $today = Carbon::today();
        $priorityRank = ['A' => 1, 'B' => 2, 'C' => 3];
        $candidates = [];

        foreach ($races as $race) {
            if (!is_array($race) || !isset($race['date'])) {
                continue;
            }
            try {
                $dt = Carbon::parse((string) $race['date']);
            } catch (\Throwable) {
                continue;
            }
            if ($dt->lessThan($today)) {
                continue;
            }
            $candidates[] = [
                'race' => $race,
                'dt' => $dt,
                'rank' => $priorityRank[$race['priority'] ?? ''] ?? 99,
            ];
        }

        if (empty($candidates)) {
            return null;
        }

        usort($candidates, function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $a['rank'] <=> $b['rank'];
            }
            return $a['dt']->getTimestamp() <=> $b['dt']->getTimestamp();
        });

        return $candidates[0]['race'];
    }

    private function computeOnboardingCompleted(UserProfile $profile): bool
    {
        $hasGoals = !is_null($profile->goals) || (is_array($profile->races_json) && count($profile->races_json) > 0);
        $hasAvailability = is_array($profile->availability_json) && count($profile->availability_json) > 0;
        $hasHealth = is_array($profile->health_json) && count($profile->health_json) > 0;
        $hasEquipment = is_array($profile->equipment_json) && count($profile->equipment_json) > 0;

        return $hasGoals && $hasAvailability && $hasHealth && $hasEquipment;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeConstraints(?string $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $patch
     * @return array<string,mixed>
     */
    private function mergeConstraints(?string $raw, array $patch): array
    {
        return array_replace_recursive($this->decodeConstraints($raw), $patch);
    }

    /**
     * @param array<string,mixed>|null $raw
     * @return array<string,mixed>
     */
    private function normalizePaceZones(?array $raw): array
    {
        $raw ??= [];
        return [
            'status' => (string) ($raw['status'] ?? 'estimated'),
            'z1SecPerKm' => isset($raw['z1SecPerKm']) && is_numeric($raw['z1SecPerKm']) ? (float) $raw['z1SecPerKm'] : null,
            'z2SecPerKm' => isset($raw['z2SecPerKm']) && is_numeric($raw['z2SecPerKm']) ? (float) $raw['z2SecPerKm'] : null,
            'z3SecPerKm' => isset($raw['z3SecPerKm']) && is_numeric($raw['z3SecPerKm']) ? (float) $raw['z3SecPerKm'] : null,
            'z4SecPerKm' => isset($raw['z4SecPerKm']) && is_numeric($raw['z4SecPerKm']) ? (float) $raw['z4SecPerKm'] : null,
            'z5SecPerKm' => isset($raw['z5SecPerKm']) && is_numeric($raw['z5SecPerKm']) ? (float) $raw['z5SecPerKm'] : null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function profileToScoreArray(UserProfile $profile): array
    {
        return [
            'preferred_run_days' => $profile->preferred_run_days,
            'preferred_surface' => $profile->preferred_surface,
            'races_json' => $profile->races_json,
            'availability_json' => $profile->availability_json,
            'health_json' => $profile->health_json,
            'equipment_json' => $profile->equipment_json,
            'hr_z1_min' => $profile->hr_z1_min, 'hr_z1_max' => $profile->hr_z1_max,
            'hr_z2_min' => $profile->hr_z2_min, 'hr_z2_max' => $profile->hr_z2_max,
            'hr_z3_min' => $profile->hr_z3_min, 'hr_z3_max' => $profile->hr_z3_max,
            'hr_z4_min' => $profile->hr_z4_min, 'hr_z4_max' => $profile->hr_z4_max,
            'hr_z5_min' => $profile->hr_z5_min, 'hr_z5_max' => $profile->hr_z5_max,
            'max_session_min' => $profile->max_session_min,
            'primary_race_date' => $profile->primary_race_date,
        ];
    }

    /**
     * @param array{score:int,breakdown:array<string,array<string,mixed>>}|null $scoreData
     * @return array<string,mixed>
     */
    private function buildResponse(UserProfile $profile, ?array $scoreData = null): array
    {
        if ($scoreData === null) {
            $scoreData = $this->qualityScoreService->scoreWithBreakdown($this->profileToScoreArray($profile));
        }

        $primaryRace = null;
        if ($profile->primary_race_date !== null) {
            $selectedRace = $this->selectPrimaryRace(is_array($profile->races_json) ? $profile->races_json : []);
            $primaryRace = [
                'name' => is_array($selectedRace) ? ($selectedRace['name'] ?? null) : null,
                'date' => $profile->primary_race_date->toDateString(),
                'distanceKm' => $profile->primary_race_distance_km !== null ? (float) $profile->primary_race_distance_km : null,
                'priority' => $profile->primary_race_priority,
                'targetTime' => is_array($selectedRace) ? ($selectedRace['targetTime'] ?? null) : null,
            ];
        }

        $constraints = $this->decodeConstraints($profile->constraints);

        return [
            'id' => $profile->id,
            'userId' => $profile->user_id,
            'preferredRunDays' => $profile->preferred_run_days,
            'preferredSurface' => $profile->preferred_surface,
            'goals' => $profile->goals,
            'constraints' => $profile->constraints,
            'races' => $profile->races_json,
            'availability' => $profile->availability_json,
            'health' => $profile->health_json,
            'equipment' => $profile->equipment_json,
            'onboardingCompleted' => (bool) ($profile->onboarding_completed ?? false),
            'hrZones' => [
                'z1' => ['min' => $profile->hr_z1_min, 'max' => $profile->hr_z1_max],
                'z2' => ['min' => $profile->hr_z2_min, 'max' => $profile->hr_z2_max],
                'z3' => ['min' => $profile->hr_z3_min, 'max' => $profile->hr_z3_max],
                'z4' => ['min' => $profile->hr_z4_min, 'max' => $profile->hr_z4_max],
                'z5' => ['min' => $profile->hr_z5_min, 'max' => $profile->hr_z5_max],
            ],
            // M1 beyond minimum additions (additive — no existing keys changed)
            'paceZones' => $constraints['paceZones'] ?? null,
            'primaryRace' => $primaryRace,
            'quality' => [
                'score' => $scoreData['score'],
                'breakdown' => $scoreData['breakdown'],
            ],
            'createdAt' => $profile->created_at?->toISOString(),
            'updatedAt' => $profile->updated_at?->toISOString(),
        ];
    }
}
