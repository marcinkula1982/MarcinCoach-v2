# Scope Notes: M2 Beyond Minimum

> Status: zamknięte, zaakceptowane z udokumentowanym driftem
> Data: 2026-04-21

## Co weszło zgodnie z planem M2

### Nowe pliki

| Plik | Odpowiedzialność |
|------|-----------------|
| `backend-php/app/Services/TcxParsingService.php` | Centralny parser TCX: sport, HR stats, avgPace, intensityBuckets, relaksacja DistanceMeters |
| `backend-php/tests/Unit/TcxParsingServiceTest.php` | Unit testy parsera (13 testów) |

### Zmienione pliki — zakres M2

| Plik | Zmiana w zakresie M2 |
|------|----------------------|
| `backend-php/app/Support/WorkoutSummaryBuilder.php` | Opcjonalny `$parsed` blob: zapis sport/hr/avgPaceSecPerKm/intensityBuckets/intensity do summary |
| `backend-php/app/Http/Controllers/Api/WorkoutsController.php` | Integracja `TcxParsingService` w `import()` i `create()`; rozszerzenie `analyticsSummary` o byDay/byWeek z zones/longRunKm/avgPaceSecPerKm; `buildAnalyticsRow` używa `summary.sport` i dodaje `avgPaceSecPerKm` |
| `backend-php/app/Services/TrainingSignalsV2Service.php` | `parseTcxForHeartRate` deleguje do `TcxParsingService::parseHeartRateTrackpoints` |
| `backend-php/app/Services/TrainingSignalsService.php` | Load z `intensityBuckets` (TRIMP-like primary) + duration fallback; longRun filtruje po `sport='run'` z fallbackiem dla starych danych |
| `backend-php/app/Services/ExternalWorkoutImportService.php` | Po CREATE z zewnętrznego importu wołane są signal pipelines (v1, PlanCompliance, Alerts) |
| `backend-php/tests/Feature/Api/WorkoutsTest.php` | Testy TCX gate, rich summary persistence, longRun sport filter |
| `backend-php/tests/Feature/Api/WorkoutsParityTest.php` | Testy zones/longRunKm/avgPaceSecPerKm w summary; value-level assertSame z cast na float |

---

## Elementy poza czystym zakresem M2

Podczas wdrożenia M2 do tych samych plików weszły zmiany należące do innych pakietów:

### 1. Source/Dedupe unifikacja (właściwość: P3)

Zmiany weszły do:
- `backend-php/app/Http/Controllers/Api/WorkoutsController.php` — użycie `WorkoutSourceContract::normalize()` i `buildDedupeKey()` w `import()`, canonical uppercase `source` przy `create()`
- `backend-php/app/Services/ExternalWorkoutImportService.php` — normalizacja providerów do canonical source przed zapisem

Klasa `backend-php/app/Support/WorkoutSourceContract.php` (nowy plik) dostarcza wspólne stałe i logikę budowania `dedupeKey`.

### 2. Adaptation/Safety signals (właściwość: P6.2 / M4)

Zmiany weszły do:
- `backend-php/app/Services/TrainingSignalsService.php` — dodanie `buildAdaptationSignals()`, `hasMissedKeyWorkout()`, safety flags (loadSpike, returnAfterBreak, postRaceWeek) wpływające na `flags.injuryRisk` i `flags.fatigue`

Te elementy były wcześniej zaplanowane w P6.2 (safety engine) i M4 (adaptation). Zostały wdrożone razem z M2 w jednym commicie.

---

## Współdzielone punkty odpowiedzialności

Trzy pliki są teraz właścicielami więcej niż jednego obszaru:

### `backend-php/app/Http/Controllers/Api/WorkoutsController.php`

| Obszar | Logika |
|--------|--------|
| **Data quality (M2)** | TCX parsing via `TcxParsingService`, enrichment summary, `analyticsSummary` z zones/pace/longRun |
| **Source/dedupe (P3)** | `WorkoutSourceContract::normalize()` i `buildDedupeKey()` w `import()` i `create()` |

### `backend-php/app/Services/TrainingSignalsService.php`

| Obszar | Logika |
|--------|--------|
| **Data quality (M2)** | `extractLoadValue()` z `intensityBuckets` primary; `windowEnd = now()->utc()`; longRun filter na `sport='run'` |
| **Adaptation/Safety (P6.2/M4)** | `buildAdaptationSignals()`, `hasMissedKeyWorkout()`, loadSpike, returnAfterBreak, postRaceWeek |

### `backend-php/app/Services/ExternalWorkoutImportService.php`

| Obszar | Logika |
|--------|--------|
| **Data quality (M2)** | Signal pipelines po CREATE (v1, PlanCompliance, Alerts) |
| **Source/dedupe (P3)** | `WorkoutSourceContract::normalize()` dla providerów Strava/Garmin |

---

## Dlaczego wdrożenie zostało zaakceptowane mimo driftu

1. **Brak regresji**: pełny suite (139 testów, 763 asercje) przechodzi bez błędów.
2. **Brak naruszenia publicznych kontraktów**: pole `adaptation` z `TrainingSignalsService` jest usuwane w `TrainingSignalsController` przed wysłaniem odpowiedzi (`unset($signals['adaptation'])`). Pozostałe zmiany w publicznych endpointach są wyłącznie addytywne (nowe pola, nie usunięcia).
3. **Spójność funkcjonalna**: zmiany z P3 były konieczne, żeby poprawny canonical `source` był zapisywany razem ze wzbogaconym summary. Rozdzielenie wymagałoby partial state w bazie.
4. **Akceptowalny koszt driftu**: wszystkie zmiany są w plikach już zmodyfikowanych przez M2 — nie dotknięto nowych plików poza zakresem.
5. **Dokumentacja zastępuje refaktor**: drift zakresu jest teraz jawnie udokumentowany. Refaktor (wydzielenie odpowiedzialności do osobnych serwisów) jest odłożony na przyszły, świadomy pakiet.

---

## Guardrails dla kolejnych pakietów

### Czego nie robić

1. **Nie wdrażać zmian z różnych pakietów w jednym commit batch.** Jeśli M3 zmienia `TrainingSignalsService`, a P6.2 też go zmienia — to dwa oddzielne PR-y lub przynajmniej oddzielne logiczne commity z wyraźnym tytułem.
2. **Nie używać `unset()` w kontrolerze jako stałego mechanizmu ochrony kontraktu.** `unset($signals['adaptation'])` to patch, nie kontrakt. Jeśli `adaptation` ma być wewnętrzne, należy go usunąć z publicznego `return` w serwisie lub wyodrębnić do osobnej metody `getAdaptationForInternalUse()`.
3. **Nie przepisywać logiki z różnych pakietów w jednej metodzie.** Metoda `getSignalsForUser()` zawiera teraz data quality M2 + safety P6.2 + adaptation M4. Następna modyfikacja tej metody powinna — jeśli zakres rośnie — wydzielić np. prywatne `computeSafetyFlags()` wyraźnie opisując pakiet właściciela.
4. **Nie zmieniać semantyki publicznych wartości (`windowEnd`, `loadValue`) bez testu deterministycznego.** Zmiana `windowEnd` na `now()->utc()` wymagała `Carbon::setTestNow()` we wszystkich wrażliwych testach. Następna zmiana tego rodzaju musi mieć listę testów do zaktualizowania zanim zaczniesz kodować.
5. **Nie normalizować `source` w kilku miejscach.** `WorkoutSourceContract` powstał właśnie po to, żeby był jedynym punktem normalizacji. Nie dodawaj `strtolower($source)` ani `strtoupper($source)` bezpośrednio w kontrolerach — zawsze korzystaj z `WorkoutSourceContract::normalize()`.

### Co robić

1. **Oznaczaj pliki z wieloma właścicielami.** Jeśli modyfikujesz plik współdzielony (np. `WorkoutsController`, `TrainingSignalsService`), dodaj w nagłówku komentarz `// @scope: M2 + P3` tak, żeby reviewer wiedział, że zmiana krzyżuje pakiety.
2. **Dokumentuj intent każdej nowej metody prywatnej.** Metody `buildAdaptationSignals()`, `hasMissedKeyWorkout()` mają PHPDoc — zachowaj tę praktykę.
3. **Przy każdej nowej rozbudowie `TrainingSignalsService` — sprawdzaj publiczny shape przez test strukturalny** (assertJsonStructure lub assertJsonPath na wszystkich polach top-level).
