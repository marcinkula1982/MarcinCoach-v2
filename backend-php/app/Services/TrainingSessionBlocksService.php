<?php

namespace App\Services;

class TrainingSessionBlocksService
{
    private const RUN_TYPES = ['easy', 'long', 'quality', 'threshold', 'intervals', 'fartlek', 'tempo', 'strides'];

    /**
     * @param list<array<string,mixed>> $sessions
     * @return list<array<string,mixed>>
     */
    public function withBlocks(array $sessions): array
    {
        foreach ($sessions as &$session) {
            $blocks = $this->blocksForSession($session);
            if (count($blocks) > 0) {
                $session['blocks'] = $blocks;
            } else {
                unset($session['blocks']);
            }
        }
        unset($session);

        return $sessions;
    }

    /**
     * @param array<string,mixed> $session
     * @return list<array<string,mixed>>
     */
    public function blocksForSession(array $session): array
    {
        $type = (string) ($session['type'] ?? 'rest');
        $duration = max(0, (int) ($session['durationMin'] ?? 0));
        if ($duration <= 0 || ! in_array($type, self::RUN_TYPES, true)) {
            return [];
        }

        $warmup = $this->warmupDuration($type, $duration);
        $cooldown = $this->cooldownDuration($duration);
        $main = max(0, $duration - $warmup - $cooldown);

        $blocks = [];
        if ($warmup > 0) {
            $blocks[] = [
                'kind' => 'warmup',
                'title' => 'Rozgrzewka',
                'durationMin' => $warmup,
                'intensityHint' => 'Z1-Z2',
                'description' => 'Rozgrzewka ważniejsza niż trening! 5-10 min spokojnego wejścia w ruch.',
                'tips' => [
                    '2-3 min bardzo spokojnego truchtu lub marszobiegu.',
                    'Krążenia bioder, wymachy nóg, skip A/C po 20-30 m.',
                    'Przed akcentem dodaj 3-4 luźne przebieżki po 15-20 s.',
                ],
            ];
        }

        if ($main > 0) {
            $blocks[] = $this->mainBlock($session, $main);
        }

        if ($cooldown > 0) {
            $blocks[] = [
                'kind' => 'cooldown',
                'title' => 'Schłodzenie i mobilizacja',
                'durationMin' => $cooldown,
                'intensityHint' => 'very_easy',
                'description' => 'Ty też nie lubisz rozciągania? Ale Twoje mięśnie błagają o 5 min dla nich.',
                'tips' => [
                    '2-3 min marszu albo bardzo lekkiego truchtu.',
                    'Łydki, dwugłowe, pośladki i biodra: po 30-45 s na stronę.',
                ],
            ];
        }

        return $blocks;
    }

    private function warmupDuration(string $type, int $duration): int
    {
        if ($duration < 25) {
            return 5;
        }

        return in_array($type, ['quality', 'threshold', 'intervals', 'fartlek', 'tempo'], true) ? 10 : 5;
    }

    private function cooldownDuration(int $duration): int
    {
        return $duration >= 25 ? 5 : 0;
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,mixed>
     */
    private function mainBlock(array $session, int $duration): array
    {
        $type = (string) ($session['type'] ?? 'easy');
        $intensity = (string) ($session['intensityHint'] ?? 'Z2');
        $structure = isset($session['structure']) ? (string) $session['structure'] : null;

        [$title, $description, $tips] = match ($type) {
            'long' => [
                'Długie wybieganie',
                'Równo i spokojnie. Masz czuć, że możesz rozmawiać pełnymi zdaniami.',
                ['Trzymaj Z2; końcówka ma być kontrolowana, nie bohaterska.'],
            ],
            'threshold' => [
                'Próg',
                $structure ?: 'Odcinki progowe w Z3 z krótkim truchtem między nimi.',
                ['Tempo mocne, ale stabilne. Ostatni odcinek nie powinien być sprintem.'],
            ],
            'intervals' => [
                'Interwały',
                $structure ?: 'Odcinki w Z4-Z5 z pełną kontrolą przerw w truchcie.',
                ['Pilnuj jakości ruchu. Jeśli technika się sypie, kończ odcinek spokojniej.'],
            ],
            'fartlek' => [
                'Fartlek',
                $structure ?: 'Zmienna intensywność: mocniejsze odcinki przeplatane spokojnym truchtem.',
                ['Mocne fragmenty mają być żywe, ale bez zajechania.'],
            ],
            'tempo' => [
                'Tempo',
                $structure ?: 'Ciągły lub środkowy blok w Z3 wpleciony w spokojny bieg.',
                ['Rytm ma być równy. Nie zaczynaj szybciej niż plan.'],
            ],
            'quality' => [
                'Akcent',
                $structure ?: 'Kontrolowany akcent w Z3. Równo, technicznie, bez ścigania zegarka.',
                ['Najważniejsza jest powtarzalność i kontrola oddechu.'],
            ],
            'strides' => [
                'Easy + przebieżki',
                'Spokojny bieg easy, a na końcu luźne przebieżki techniczne.',
                ['Przebieżki szybkie, ale swobodne; pełny odpoczynek między powtórzeniami.'],
            ],
            default => [
                'Bieg easy',
                'Swobodny bieg w komforcie. Bez dokładania tempa, nawet jeśli noga niesie.',
                ['Trzymaj oddech i krok pod kontrolą. To ma budować, nie testować formę.'],
            ],
        };

        return [
            'kind' => 'main',
            'title' => $title,
            'durationMin' => $duration,
            'intensityHint' => $intensity,
            'description' => $description,
            'tips' => $tips,
        ];
    }
}
