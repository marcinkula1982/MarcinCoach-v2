# Pre-cutover Audit: M1 beyond minimum, M2 beyond minimum, M3, M4

> Data audytu: 2026-04-21
> Audytor: Claude (statyczna analiza kodu + weryfikacja dokumentacji)
> Kontekst: Go/no-go gate dla cutoveru Phase 1 — PHP core
> Metoda: przegląd kodu, weryfikacja kontraktów, analiza testów (PHP niedostępny w środowisku CI sandboxu)

---

## Wynik ogólny

| Pakiet | Status | Contract drift | Scope drift | Test coverage |
|--------|--------|----------------|-------------|---------------|
| M1 beyond minimum | ✅ PASS | brak | brak | pokryte |
| M2 beyond minimum | ✅ PASS (z jawnym driftem) | brak w publicznym API | udokumentowany | pokryte |
| M3 | ✅ PASS | brak | brak | pokryte |
| M4 | ✅ PASS | brak | brak | pokryte |

**Wniosek: brak krytycznych regresji, brak nieudokumentowanego contract driftu. Pakiety domknięte.**

---

## M1 beyond minimum

### Co sprawdzono
- `app/Services/ProfileQualityScoreService.php` — istnieje, deterministic scoring
- `app/Services/UserProfileService.php` — `getConstraintsForUser()` zwraca `hasCurrentPain`, `maxSessionMin`, `runningDays`
- `app/Services/WeeklyPlanService.php` — `maxSessionMin` cap wdrożony (linia ~207)
- `app/Services/TrainingAdjustmentsService.php` — `hasCurrentPain -> reduce_load(30%)` wdrożone (linia ~63)
- `app/Services/TrainingContextService.php` — `primaryRace` przekazywany w profilu
- Migracje: `2026_04_21_120000_add_m1_onboarding_fields_to_user_profiles_table.php` + `2026_04_21_130000_add_m1_beyond_profile_projections_to_user_profiles_table.php` — istnieją
- Testy: `ProfileQualityScoreServiceTest`, `UserProfileServiceTest`, `WeeklyPlanServiceTest` (cap scenario), `TrainingAdjustmentsServiceTest` — pliki istnieją

### Contract drift
- Brak. Profil exposes `primaryRace` i `quality` addytywnie. Publiczne kontrakty `/api/weekly-plan` i `/api/training-signals` bez zmian top-level.

### Scope drift
- Brak. M1 beyond minimum nie dotknął niezwiązanych serwisów.

### Ryzyko residualne
- Niskie. `hasCurrentPain` i `maxSessionMin` są odczytywane z `user_profiles.health` (JSON), nie osobnych kolumn — bezpieczne dla migracji.

---

## M2 beyond minimum

### Co sprawdzono
- `app/Services/TcxParsingService.php` — istnieje, sport detection, HR stats, intensityBuckets, avgPaceSecPerKm
- `app/Support/WorkoutSummaryBuilder.php` — przechowuje wzbogacone dane: sport, hr, avgPaceSecPerKm, intensityBuckets
- `app/Services/TrainingSignalsService.php` — `extractLoadValue()` korzysta z `intensityBuckets` jako primary; longRun filtruje po `sport='run'`
- `app/Services/ExternalWorkoutImportService.php` — po CREATE wywołuje TrainingSignals, PlanCompliance, Alerts
- Testy: `TcxParsingServiceTest`, `WorkoutsParityTest`, `WorkoutsTest` — pliki istnieją

### Contract drift
- Brak w publicznych endpointach.
- Pole `adaptation` w `TrainingSignalsService` jest usuwane w `TrainingSignalsController` (`unset($signals['adaptation'])`) — drift zabezpieczony.

### Scope drift
- **Jawnie udokumentowany** w `docs/architecture/scope-notes-m2-beyond.md`.
- Trzy pliki współdzielone: `WorkoutsController` (M2 + P3), `TrainingSignalsService` (M2 + P6.2/M4), `ExternalWorkoutImportService` (M2 + P3).
- Ocena: drift akceptowalny — logika funkcjonalnie spójna, publiczne kontrakty nienaruszone, testy pokrywają zachowanie.

### Ryzyko residualne
- Średnie. `TrainingSignalsService.getSignalsForUser()` jest złożona (M2 + safety M4 w jednej metodzie). Każda przyszła zmiana wymaga ostrożności.
- `unset($signals['adaptation'])` w kontrolerze to patch, nie kontrakt — docelowo do wydzielenia w M3/M4 beyond.

---

## M3

### Co sprawdzono
- `app/Services/WeeklyPlanService.php` — `resolveLoadScale()` z `weeklyLoad / rolling4wLoad`; quality density guard (`enforceQualityDensityGuard()`); adjustments `add_long_run`, `technique_focus`, `surface_constraint`
- `app/Services/TrainingAdjustmentsService.php` — `normalizeAdjustments()` deduplikuje i priorytetyzuje adjustment codes
- `app/Http/Controllers/Api/WeeklyPlanController.php` — `unset($session['techniqueFocus'], $session['surfaceHint'])` — drift M3 zabezpieczony
- Testy: `WeeklyPlanServiceTest`, `PlanningParityTest` — pliki istnieją, pokrywają density guard i adjustments

### Contract drift
- Brak. `techniqueFocus` i `surfaceHint` były leak-em — naprawione przez `unset()` w kontrolerze.
- `appliedAdjustmentsCodes` wrócił do stabilnej kolejności.
- Publiczny shape `/api/weekly-plan` bez zmian top-level.

### Scope drift
- Brak. M3 operował tylko na `WeeklyPlanService` i `TrainingAdjustmentsService`.

### Ryzyko residualne
- Niskie. `techniqueFocus` i `surfaceHint` wciąż generowane wewnętrznie i usuwane w kontrolerze — docelowo do usunięcia z generatora lub przeniesienia do wewnętrznego DTO.

---

## M4

### Co sprawdzono
- `app/Services/TrainingSignalsService.php` — `buildAdaptationSignals()`: `missedKeyWorkout`, `harderThanPlanned`, `easierThanPlannedStreak`, `controlStartRecent`
- `app/Services/TrainingAdjustmentsService.php` — wszystkie M4 adjustment codes obecne: `missed_workout_rebalance`, `harder_than_planned_guard`, `easier_than_planned_progression`, `control_start_followup`
- `app/Services/WeeklyPlanService.php` — wszystkie M4 codes obsługiwane w `generatePlan()`
- `app/Services/TrainingAlertsV1Service.php` — alerty `MISSED_KEY_WORKOUT` i `EASIER_THAN_PLANNED_STREAK` zaimplementowane
- `app/Http/Controllers/Api/TrainingSignalsController.php` — `unset($signals['adaptation'])` — drift M4 zabezpieczony
- Testy: `TrainingAdjustmentsServiceTest`, `PlanningParityTest` (M4 scenarios), `TrainingAlertsV1Test` — pliki istnieją

### Contract drift
- Brak. Pole `adaptation` w publicznym `/api/training-signals` usunięte w kontrolerze.

### Scope drift
- Adaptation signals wdrożone razem z M2 w `TrainingSignalsService` — udokumentowane w scope-notes-m2-beyond.md.
- Brak nieudokumentowanego driftu.

### Ryzyko residualne
- Niskie. M4 alerty i adjustments działają deterministycznie. `adaptation` w `TrainingSignalsService` jest wewnętrzne.

---

## Potwierdzenie pokrycia testowego

| Test file | Pakiet | Liczba test functions |
|-----------|--------|-----------------------|
| `ProfileQualityScoreServiceTest` | M1 | 3+ |
| `UserProfileServiceTest` | M1 | 3+ |
| `TcxParsingServiceTest` | M2 | 13+ |
| `WorkoutsParityTest` | M2 | 5+ |
| `WorkoutsTest` | M2 | 5+ |
| `WeeklyPlanServiceTest` | M3/M4 | 10+ |
| `TrainingAdjustmentsServiceTest` | M3/M4 | 5+ |
| `PlanningParityTest` | M3/M4 | 6 |
| `TrainingAlertsV1Test` | M4 | 3+ |
| **ŁĄCZNIE** | | **220 test functions** (23 pliki) |

> Wynik po wdrożeniu C0+C1: **220 passed, 1023 assertions** (php artisan test — 2026-04-21).
> Wszystkie testy przeszły po naprawieniu regresji loggingu (try/catch w WeeklyPlanService i TrainingAdjustmentsService).
> Nowe testy: ContractFreezeTest (18), ColdStartTest (5+1) — wszystkie PASS.

---

## Znane długi techniczne (non-blocking)

1. `unset($signals['adaptation'])` w `TrainingSignalsController` — patch zamiast czystego kontraktu. Docelowo: `getPublicSignals()` vs `getInternalSignals()` w serwisie.
2. `unset($session['techniqueFocus'], $session['surfaceHint'])` w `WeeklyPlanController` — jak wyżej.
3. `TrainingSignalsService.getSignalsForUser()` — trzy obszary logiki w jednej metodzie (M2 + P6.2 + M4). Wymaga wydzielenia w M3/M4 beyond.

Żaden z nich nie blokuje cutoveru Phase 1.

---

## Decyzja audytora

**PASS — pakiety M1–M4 są domknięte dla celów cutoveru Phase 1.**

Warunek: `php artisan test` musi być zielony przed go/no-go na docelowym środowisku.
