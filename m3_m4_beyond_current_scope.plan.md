# MarcinCoach v2 — M3/M4 beyond current scope
## Plan pakietu implementacyjnego

Data audytu: 2026-04-21

---

## 1. Stan obecny — co faktycznie jest w kodzie

### WeeklyPlanService
Serwis generuje plan tygodnia na podstawie: `runningDays`, `fatigue` flag, `weeklyLoad`, `rolling4wLoad`, `sessionsCount`.

**Co działa poprawnie:**
- Podstawowy dobór sesji: easy / long / quality / rest
- Ochrona przed quality zbyt blisko long run (`enforceQualityDensityGuard`)
- Stosowanie adjustmentów (reduce_load, recovery_focus, itd.)
- Cap `maxSessionMin` z profilu użytkownika
- `loadScale` jako współczynnik skalowania objętości

**Co jest zbyt płytkie lub brakuje:**
- `loadScale` ma tylko 3 stany (0.90 / 1.00 / 1.10) — bez gradacji progresji
- Brak jakiejkolwiek wiedzy o bloku / fazie / cyklu treningowym
- Plan jest generowany wyłącznie reaktywnie na ostatnie 28 dni — bez pamięci co było planowane w poprzednich tygodniach
- Typy sesji: tylko `easy`, `long`, `quality` (Z3) — brak `threshold`, `intervals`, `fartlek`, `tempo`, `recovery`
- Jakość ma hint `Z3` — bez struktury (np. 3×10min Z4, 5×3min Z5, bieg przelotowy 45min Z3)
- Brak pól: `block_type`, `block_goal`, `load_direction`, `key_capability_focus`, `week_role_in_block`
- Brak ochrony konkretnych zdolności (np. „chroń long run kosztem quality")

---

### TrainingAdjustmentsService
Serwis generuje adjustmenty reaktywnie na bieżące sygnały.

**Co działa poprawnie:**
- Kompletna lista kluczowych reguł: fatigue, injuryRisk, currentPain, longRunMissing, harderThanPlanned, easierStreak, controlStart, overloadRisk, hrInstability, economyDrop
- Deduplikacja i merge adjustmentów tego samego kodu
- Severity ranking przy merge

**Co jest zbyt płytkie lub brakuje:**
- Adaptacje są binarne: „zmniejsz objętość" albo „zamień quality na easy" — brak zmiany struktury bodźca
- Brak decyzji: „zachowaj jakość, skróć easy" / „zamień interwały na fartlek" / „obniż gęstość, nie objętość"
- Brak pamięci: ten sam sygnał (np. harderThanPlanned) może produkować ten sam adjustment tydzień po tygodniu bez uczenia się trendu
- Brak poziomów: `adaptationType` = `volume` / `intensity` / `density` / `structure` — teraz wszystko idzie w jedno
- Brak confidence: serwis nie oznacza „ta decyzja opiera się na 1 sygnale vs 4 sygnałach zbieżnych"
- Brak śladu decyzyjnego: nie wiadomo „dlaczego ten adjustment, na podstawie czego"

---

### TrainingAlertsV1Service
Serwis generuje alerty per-workout do tabeli `training_alerts_v1`.

**Co działa poprawnie:**
- 7 typów alertów: LOAD_SPIKE, MISSED_KEY_WORKOUT, EASIER_THAN_PLANNED_STREAK, PLAN_MISSING, DURATION_MAJOR_OVERSHOOT, DURATION_MAJOR_UNDERSHOOT, EASY_BECAME_Z5, HR_DATA_MISSING
- UPSERT z cleanup nieaktywnych alertów

**Co jest zbyt płytkie lub brakuje:**
- Brak rodzin alertów (safety / compliance / trend / data_quality)
- Brak `confidence` — każdy alert ma taką samą pewność niezależnie od jakości danych
- Brak `explanation_code` — nie wiadomo co pokazać użytkownikowi
- Alerty są per-workout, nie per-week ani per-block — brak alertów trendowych
- Brakujące typy alertów:
  - `UNDER_RECOVERY_TREND` — rosnące zmęczenie przez 2+ tygodnie
  - `EXECUTION_DRIFT` — systematyczny rozjazd plan vs wykonanie
  - `STALE_MISSED_CAPABILITY` — cel treningowy niewykonywany przez N tygodni
  - `WEAK_DATA_CONFIDENCE` — za mało danych do pewnej rekomendacji
  - `EXCESSIVE_DENSITY_TREND` — za gęsto akcenty przez kilka tygodni z rzędu
  - `POST_RACE_RETURN_RISK` — powrót po starcie bez odpoczynku
  - `PAIN_WITH_LOAD_CONFLICT` — ból zgłoszony przez użytkownika + zaplanowany akcent
  - `BLOCK_GOAL_NOT_MET` — cel bloku nie osiągnięty przed przejściem do kolejnego

---

### TrainingSignalsService
Oblicza sygnały dla okna 28 dni.

**Co działa poprawnie:**
- weeklyLoad, rolling4wLoad (TRIMP-like z bucket weightingiem)
- longRun detection
- adaptation signals: missedKeyWorkout, harderThanPlanned, easierStreak, controlStartRecent
- loadSpike flag (7d vs poprzedni 7d)
- returnAfterBreak i postRaceWeek na podstawie gap/kind

**Co brakuje:**
- Brak week-by-week breakdown (ostatnie 4-8 tygodni osobno)
- Brak trendu obciążenia (rosnący / stabilny / malejący)
- Brak intensityRatio trendów (ile % intensywności przez ostatnie tygodnie)
- Brak danych do zasilenia `BlockPeriodizationService`

---

### PlanSnapshotService
Zapisuje snapshot planu jako JSON.

**Co brakuje:**
- Snapshot nie zawiera metadanych bloku (block_type, block_goal, week_role)
- Brak osobnej tabeli `training_weeks` — tydzień nie ma swojego rekordu z rolą/celem
- Brak tabeli `planned_workouts` — planowane sesje są tylko w JSON snapshotu, nie jako osobne rekordy
- Brak tabeli `deviation_events` — odchylenia są rekonstruowane na żywo, nie są przechowywane

---

## 2. Brakujące elementy — pełna lista

### 2.1 Brakujące serwisy PHP
| Serwis | Co robi |
|---|---|
| `BlockPeriodizationService` | Określa aktualny blok, cel bloku, rolę tygodnia, fazę cyklu |
| `PlanMemoryService` | Zarządza pamięcią planistyczną (ostatnie 4-8 tygodni) |
| `AdaptiveDecisionService` | Głębsza adaptacja: typ korekty, chroniony bodziec, struktura |
| `AlertClassificationService` | Trendowe alerty wielotygodniowe, rodziny, confidence |

### 2.2 Brakujące tabele DB
| Tabela | Po co |
|---|---|
| `training_plans` | Plan / blok z metadanymi: faza, cel, czas trwania |
| `training_weeks` | Tydzień w planie: rola (build/peak/recovery/taper), focus, cel |
| `planned_workouts` | Planowana sesja: typ, zdolność docelowa, priority, protected |
| `deviation_events` | Historia odchyleń z klasyfikacją: typ, skala, kontekst |

### 2.3 Brakujące pola w istniejących tabelach
| Tabela | Brakujące pola |
|---|---|
| `plan_snapshots` | `block_type`, `block_goal`, `week_role`, `load_direction`, `key_capability_focus` |
| `training_alerts_v1` | `family`, `confidence`, `explanation_code`, `week_id` |

---

## 3. Pakiet implementacyjny — must-have

### Etap A: DB migrations (fundament)

**A1. Rozszerz `plan_snapshots`** o metadane bloku:
```sql
ALTER TABLE plan_snapshots ADD COLUMN block_type VARCHAR(32) NULL;
ALTER TABLE plan_snapshots ADD COLUMN block_goal VARCHAR(128) NULL;
ALTER TABLE plan_snapshots ADD COLUMN week_role VARCHAR(32) NULL;
ALTER TABLE plan_snapshots ADD COLUMN load_direction VARCHAR(16) NULL;
ALTER TABLE plan_snapshots ADD COLUMN key_capability_focus VARCHAR(64) NULL;
```
Dozwolone wartości:
- `block_type`: `base`, `build`, `peak`, `taper`, `recovery`, `return`
- `week_role`: `build`, `peak`, `recovery`, `taper`, `test`
- `load_direction`: `increase`, `maintain`, `decrease`
- `key_capability_focus`: `aerobic_base`, `threshold`, `vo2max`, `long_run`, `economy`

**A2. Utwórz `training_weeks`**:
```sql
CREATE TABLE training_weeks (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    user_id BIGINT NOT NULL,
    week_start_date DATE NOT NULL,
    week_end_date DATE NOT NULL,
    block_type VARCHAR(32) NULL,
    week_role VARCHAR(32) NULL,
    block_goal VARCHAR(128) NULL,
    key_capability_focus VARCHAR(64) NULL,
    load_direction VARCHAR(16) NULL,
    planned_total_min INT NULL,
    actual_total_min INT NULL,
    planned_quality_count INT NULL,
    actual_quality_count INT NULL,
    goal_met TINYINT(1) NULL,
    decision_log JSON NULL,
    created_at TIMESTAMP NOT NULL,
    updated_at TIMESTAMP NOT NULL,
    UNIQUE KEY uq_user_week (user_id, week_start_date)
);
```

**A3. Rozszerz `training_alerts_v1`** o klasyfikację:
```sql
ALTER TABLE training_alerts_v1 ADD COLUMN family VARCHAR(32) NULL;
ALTER TABLE training_alerts_v1 ADD COLUMN confidence VARCHAR(16) NULL DEFAULT 'medium';
ALTER TABLE training_alerts_v1 ADD COLUMN explanation_code VARCHAR(64) NULL;
ALTER TABLE training_alerts_v1 ADD COLUMN week_id BIGINT NULL;
```
Dozwolone wartości:
- `family`: `safety`, `compliance`, `trend`, `data_quality`
- `confidence`: `low`, `medium`, `high`

---

### Etap B: BlockPeriodizationService (nowy serwis)

Plik: `app/Services/BlockPeriodizationService.php`

**Wejście:** profil użytkownika (`UserProfile`), sygnały (`TrainingSignals`), historia tygodni (`training_weeks`)

**Wyjście:**
```php
[
    'block_type' => 'build',           // aktualny typ bloku
    'block_goal' => 'Rozbudowa bazy tlenowej przed blokiem progowym',
    'week_role' => 'build',            // rola bieżącego tygodnia w bloku
    'load_direction' => 'increase',    // kierunek obciążenia na ten tydzień
    'key_capability_focus' => 'aerobic_base',  // chroniona zdolność
    'weeks_in_block' => 3,             // ile tygodni trwa bieżący blok
    'weeks_until_race' => 14,          // ile tygodni do startu (z profilu)
    'rationale' => '...',
]
```

**Logika decyzyjna (kolejność):**

1. Jeśli `weeksUntilRace <= 2` → `block_type = taper`, `week_role = taper`
2. Jeśli `weeksUntilRace <= 6` → `block_type = peak`, `week_role = peak`
3. Jeśli `returnAfterBreak` lub `injuryRisk` → `block_type = return`, `week_role = recovery`
4. Jeśli `postRaceWeek` → `block_type = recovery`, `week_role = recovery`
5. Jeśli `rolling4wLoad` rośnie przez ostatnie 3 tygodnie → `block_type = build`
6. Domyślnie → `block_type = base`

**Rola tygodnia w bloku:**
- Co 4. tydzień → `week_role = recovery` (niezależnie od bloku, chyba że peak/taper)
- Inaczej → `week_role = build` (base/build) lub `peak` (peak)

**`load_direction`:**
- `recovery` week → `decrease`
- poprzedni tydzień wykonany `< 85%` → `maintain`
- poprzedni tydzień wykonany `>= 85%` i nie recovery → `increase`

---

### Etap C: PlanMemoryService (nowy serwis)

Plik: `app/Services/PlanMemoryService.php`

**Co przechowuje (tabela `training_weeks`):**
- Po każdym wygenerowaniu planu tygodnia: upsert rekordu dla danego tygodnia z polami planistycznymi
- Po każdym imporcie/analizie workoutu: aktualizacja `actual_total_min`, `actual_quality_count`

**Metody:**
```php
public function upsertWeekFromPlan(int $userId, array $planOutput, array $blockContext): void
// zapisuje/aktualizuje training_weeks dla tygodnia z danych planu i kontekstu bloku

public function updateWeekActuals(int $userId, string $weekStartDate): void
// przelicza actual_total_min, actual_quality_count na podstawie workoutów w tym tygodniu

public function getRecentWeeks(int $userId, int $count = 6): array
// zwraca ostatnie N tygodni z training_weeks (posortowane malejąco)

public function getWeekGoalMet(int $userId, string $weekStartDate): ?bool
// czy cel tygodnia został osiągnięty (planned vs actual >= 80%)
```

**Integracja z WeeklyPlanController:**
Po wygenerowaniu planu → `PlanMemoryService::upsertWeekFromPlan()` → `PlanSnapshotService::saveForUser()`

---

### Etap D: Rozszerzenie WeeklyPlanService

**D1. Przyjmij `blockContext` jako dodatkowy parametr:**
```php
public function generatePlan(array $context, ?array $adjustments = null, ?array $blockContext = null): array
```

**D2. Wzbogać typy sesji o strukturę:**

Obecne: `easy`, `long`, `quality` (Z3)

Nowe, gdy `block_type = build` i `key_capability_focus = threshold`:
- `quality` → type: `threshold`, hint: `Z3`, structure: `3×10min Z3 z 2min przerwy`

Gdy `block_type = peak` i `key_capability_focus = vo2max`:
- `quality` → type: `intervals`, hint: `Z4-Z5`, structure: `5×3min Z4/Z5 z 3min truchtu`

Gdy `key_capability_focus = economy`:
- `quality` → type: `fartlek`, hint: `Z3-Z4`, structure: `8×1min zmiennie Z3/Z4`

**D3. Dodaj pola bloku do outputu:**
```php
'blockContext' => [
    'block_type' => 'build',
    'week_role' => 'build',
    'block_goal' => '...',
    'load_direction' => 'increase',
    'key_capability_focus' => 'aerobic_base',
],
```

**D4. Rozbuduj `loadScale` — więcej gradacji:**
- `week_role = recovery` → scale 0.70
- `week_role = taper` → scale 0.60
- `load_direction = increase` + ratio OK → scale 1.10
- `load_direction = maintain` → scale 1.00
- `load_direction = decrease` → scale 0.85
- loadSpike niezależnie → cap do 0.85

---

### Etap E: Rozszerzenie TrainingAdjustmentsService

**E1. Dodaj `adaptationType` do każdego adjustmentu:**
```php
'adaptationType' => 'volume'    // volume | intensity | density | structure
```

**E2. Dodaj nowe adjustmenty strukturalne:**

```php
// Zachowaj jakość, skróć easy
'code' => 'protect_quality_shorten_easy'
// Gdy: block_type=peak, harderThanPlanned=false, missedKeyWorkout=false

// Zamień interwały na fartlek tlenowy
'code' => 'swap_intervals_to_fartlek'
// Gdy: fatigue=true + key_capability_focus=vo2max

// Obniż gęstość akcentów, nie objętość
'code' => 'reduce_intensity_density'
// Gdy: easierStreak=0 ale too_many_quality_sessions w ostatnich 2 tygodniach

// Chroń long run kosztem quality
'code' => 'protect_long_run'
// Gdy: injuryRisk=true + week_role != taper
```

**E3. Dodaj `confidence` i `decisionBasis`:**
```php
'confidence' => 'high',        // low | medium | high
'decisionBasis' => [           // skąd pochodzi ta decyzja
    'signals' => ['fatigue', 'harderThanPlanned'],
    'weekHistory' => 'last_2_weeks_over_planned',
    'blockContext' => 'peak_week',
]
```

**E4. Dodaj pamięć wielotygodniową (przez PlanMemoryService):**

Wstrzyknij `PlanMemoryService` do konstruktora. Pobierz ostatnie 4 tygodnie.

Nowe reguły oparte na historii:
- Jeśli 3 z 4 ostatnich tygodni `actual < 80%` planned → `code: persistent_underexecution_check` (confidence: high)
- Jeśli ostatnie 2 tygodnie `actual_quality_count = 0` → `code: quality_session_missing_trend` (confidence: medium)
- Jeśli 4 tygodnie z rzędu `load_direction = increase` → `code: force_recovery_week` (confidence: high)

---

### Etap F: Rozszerzenie TrainingAlertsV1Service

**F1. Dodaj rodzinę, confidence, explanation_code do każdego alertu:**
```php
[
    'code' => 'LOAD_SPIKE',
    'severity' => 'WARNING',
    'family' => 'safety',
    'confidence' => 'high',
    'explanation_code' => 'load_spike_7d',
    'payload_json' => '...',
]
```

**F2. Dodaj nowe alerty trendowe (wielotygodniowe, przez PlanMemoryService):**

```php
// UNDER_RECOVERY_TREND
// Warunek: 2+ ostatnie tygodnie load_direction=decrease + actual_quality_count < planned
// Family: trend, Severity: WARNING, Confidence: medium

// EXECUTION_DRIFT
// Warunek: ostatnie 3 tygodnie actual/planned ratio < 0.75
// Family: compliance, Severity: WARNING, Confidence: high

// STALE_MISSED_CAPABILITY
// Warunek: key_capability_focus nie było wykonane przez 3+ tygodnie
// Family: trend, Severity: INFO, Confidence: medium

// EXCESSIVE_DENSITY_TREND
// Warunek: actual_quality_count >= 3 przez 2+ tygodnie z rzędu
// Family: safety, Severity: WARNING, Confidence: medium

// BLOCK_GOAL_NOT_MET
// Warunek: blok kończy się (week_role zmienia się na recovery/taper) + goal_met = false przez > 60% tygodni bloku
// Family: compliance, Severity: INFO, Confidence: low

// PAIN_WITH_LOAD_CONFLICT
// Warunek: profil.health.hasCurrentPain = true + bieżący tydzień ma quality session
// Family: safety, Severity: CRITICAL, Confidence: high
```

**F3. Przenieś alerty trendowe do oddzielnej metody:**
```php
public function upsertForWorkout(int $workoutId): void   // istniejąca, bez zmian w sygnaturze
public function upsertWeeklyAlerts(int $userId, string $weekStartDate): void  // NOWA
```

---

## 4. Kolejność wdrożenia

```
A1 → A2 → A3          (migracje DB — fundament, kolejno)
     ↓
B  (BlockPeriodizationService — pure logic, brak zależności DB poza profilem)
     ↓
C  (PlanMemoryService — wymaga A2 training_weeks)
     ↓
D  (WeeklyPlanService rozszerzenie — wymaga B i C)
     ↓
E  (TrainingAdjustmentsService rozszerzenie — wymaga C)
     ↓
F  (TrainingAlertsV1Service rozszerzenie — wymaga A3 i C)
```

---

## 5. Pliki do zmiany lub stworzenia

| Plik | Akcja |
|---|---|
| `database/migrations/..._add_block_fields_to_plan_snapshots.php` | NOWY |
| `database/migrations/..._create_training_weeks_table.php` | NOWY |
| `database/migrations/..._add_classification_to_training_alerts_v1.php` | NOWY |
| `app/Services/BlockPeriodizationService.php` | NOWY |
| `app/Services/PlanMemoryService.php` | NOWY |
| `app/Services/WeeklyPlanService.php` | ZMIANA |
| `app/Services/TrainingAdjustmentsService.php` | ZMIANA |
| `app/Services/TrainingAlertsV1Service.php` | ZMIANA |
| `app/Services/TrainingContextService.php` | ZMIANA (wstrzyknięcie nowych serwisów) |
| `app/Http/Controllers/Api/WeeklyPlanController.php` | ZMIANA (wywołanie PlanMemoryService) |
| `tests/Unit/BlockPeriodizationServiceTest.php` | NOWY |
| `tests/Unit/PlanMemoryServiceTest.php` | NOWY |
| `tests/Unit/WeeklyPlanServiceTest.php` | ZMIANA (nowe przypadki) |
| `tests/Unit/TrainingAdjustmentsServiceTest.php` | ZMIANA (nowe kody i confidence) |
| `tests/Unit/TrainingAlertsV1ServiceTest.php` | ZMIANA (nowe typy alertów) |

---

## 6. Ryzyka regresji

| Ryzyko | Obszar | Mitygacja |
|---|---|---|
| `blockContext = null` łamie WeeklyPlanService | D | Parametr opcjonalny z fallbackiem do logiki obecnej |
| PlanMemoryService nie ma danych dla nowych użytkowników | C/E | Wszystkie metody obsługują puste wyniki gracefully |
| Nowe kolumny w `plan_snapshots` mogą nie być wypełniane przez stare ścieżki | A1 | Kolumny nullable; stare snapshoty po prostu nie mają wartości |
| `training_weeks` nie istnieje przy alarmach trendowych | F | upsertWeeklyAlerts sprawdza istnienie rekordu przed odczytem |
| Nowe typy sesji (intervals, threshold) łamią frontend | D | Frontend musi obsługiwać nieznane `type` jako fallback do 'quality' |
| Duplikacja logiki miss-detection między TrainingSignalsService a PlanMemoryService | E/C | PlanMemoryService staje się jedynym źródłem prawdy dla historii tygodni; TrainingSignalsService zostaje dla okna 28d |

---

## 7. Poza zakresem tego pakietu

- AI escalation layer (M6)
- Frontend / dashboard (M7)
- Panel admina (M8)
- Import FIT / GPX (M2 deeper)
- Garmin / Strava live sync (M5)
- Predykcje ML
- Wielosport

---

## 8. Definition of Done

Pakiet uznajemy za zamknięty gdy:

- [ ] Plan tygodnia zawiera `blockContext` (block_type, week_role, load_direction, key_capability_focus)
- [ ] `training_weeks` jest zapisywany po każdym wygenerowaniu planu
- [ ] `WeeklyPlanService` generuje różne struktury quality session zależnie od `key_capability_focus`
- [ ] `TrainingAdjustmentsService` ma `adaptationType` i `confidence` przy każdym adjustmencie
- [ ] Adaptacja korzysta z historii ostatnich 4 tygodni (przez `PlanMemoryService`)
- [ ] Alerty mają `family`, `confidence`, `explanation_code`
- [ ] Zaimplementowane alerty trendowe: UNDER_RECOVERY_TREND, EXECUTION_DRIFT, STALE_MISSED_CAPABILITY, EXCESSIVE_DENSITY_TREND, PAIN_WITH_LOAD_CONFLICT
- [ ] Testy jednostkowe pokrywają: BlockPeriodizationService (min. 8 przypadków), PlanMemoryService (min. 5), nowe adjustmenty (min. 6), nowe alerty (min. 5)
- [ ] Żaden istniejący test nie jest zepsuty
- [ ] Stare ścieżki (bez blockContext) działają bez regresji
