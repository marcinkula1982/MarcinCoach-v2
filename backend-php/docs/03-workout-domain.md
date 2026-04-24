# Workout Domain Design

## Overview
The Workout domain manages completed training sessions, workout metadata, and raw workout data (TCX files).

## Aggregate Root: Workout

### Properties
- `id: int` - Primary key
- `userId: int` - Foreign key to User
- `action: string` - Action type ('save', 'preview-only')
- `kind: string` - Workout kind ('training', 'race')
- `summary: WorkoutSummary` - Workout summary (stored as JSON)
- `raceMeta: RaceMeta?` - Race metadata (optional, stored as JSON)
- `workoutMeta: object?` - Additional workout metadata (stored as JSON)
- `source: string` - Source of workout ('MANUAL_UPLOAD', 'IMPORT', etc.)
- `sourceActivityId: string?` - External activity ID (for imports)
- `sourceUserId: string?` - External user ID (for imports)
- `dedupeKey: string` - Deduplication key (userId + source + normalized data)
- `createdAt: DateTime`
- `updatedAt: DateTime`

### Relationships
- `user: User` - Owner of the workout
- `raw: WorkoutRawTcx?` - Raw TCX data (optional)
- `feedback: TrainingFeedbackV2?` - Associated training feedback (optional)

### Business Rules
- Unique constraint on `(userId, dedupeKey)` to prevent duplicates
- Workout deletion cascades to related raw TCX data
- `summary` is required and contains computed metrics
- `tcxRaw` is stored separately (in WorkoutRawTcx) for performance

## Value Object: WorkoutSummary

```php
class WorkoutSummary
{
    public ?string $fileName;
    public ?string $startTimeIso; // ISO timestamp
    public ?Metrics $original;
    public ?Metrics $trimmed;
    public ?IntensityBuckets $intensity;
    public int $totalPoints;
    public int $selectedPoints;
}
```

## Value Object: Metrics

```php
class Metrics
{
    public int $durationSec;
    public float $distanceM;
    public ?float $avgPaceSecPerKm;
    public ?int $avgHr;
    public ?int $maxHr;
    public int $count; // Number of trackpoints
}
```

## Value Object: IntensityBuckets

Time distribution across intensity zones (Z1-Z5).

## Value Object: RaceMeta

```php
class RaceMeta
{
    public string $name;
    public string $distance; // '5 km', '10 km', '21.1 km', '42.2 km', 'Inny'
    public string $priority; // 'A', 'B', 'C'
    public ?string $customDistance;
}
```

## Entity: WorkoutRawTcx

### Properties
- `id: int` - Primary key
- `workoutId: int` - Foreign key to Workout (unique)
- `xml: string` - Raw TCX XML content
- `createdAt: DateTime`

### Business Rules
- One-to-one relationship with Workout
- Stored separately for performance (lazy loading)
- Deleted when workout is deleted

## Repository Interface: WorkoutRepository

```php
interface WorkoutRepositoryInterface
{
    public function findById(int $id): ?Workout;
    public function findByIdForUser(int $id, int $userId): ?Workout;
    public function findByUserId(int $userId, ?int $limit = null, ?int $offset = null): array;
    public function findByUserIdAndDateRange(int $userId, string $fromIso, string $toIso): array;
    public function findByDedupeKey(int $userId, string $dedupeKey): ?Workout;
    public function save(Workout $workout): Workout;
    public function delete(Workout $workout): void;
}
```

## Repository Interface: WorkoutRawTcxRepository

```php
interface WorkoutRawTcxRepositoryInterface
{
    public function findByWorkoutId(int $workoutId): ?WorkoutRawTcx;
    public function save(WorkoutRawTcx $raw): WorkoutRawTcx;
    public function delete(WorkoutRawTcx $raw): void;
}
```

## Zasada: walidacja importu — dyscyplina i dane

### 1. Filtr dyscypliny (sport detection)

Backend importuje **wyłącznie treningi biegowe**. Każdy import — manualny TCX, Garmin API, Strava API — musi sprawdzić dyscyplinę przed zapisem.

**Źródła dyscypliny:**

| Źródło | Pole | Akceptowana wartość |
|--------|------|---------------------|
| TCX | `<Activity Sport="...">` | `Running` |
| Garmin API | `activityType.typeKey` | `running`, `trail_running`, `treadmill_running`, `track_running` |
| Strava API | `sport_type` / `type` | `Run`, `TrailRun`, `VirtualRun` |

Jeśli dyscyplina nie jest biegiem → **odrzuć import, zwróć błąd z kodem `WRONG_SPORT`**.
Nie zapisuj, nie generuj sygnałów, nie informuj planu.

Przykładowe wartości do odrzucenia: `Biking`, `Cycling`, `Swimming`, `rowing`, `Ride`, `Walk` i wszystko inne poza listą akceptowanych.

### 2. Sanity check prędkości

Po wyliczeniu metryk sprawdź czy dane nie są fizycznie absurdalne.

**Progi dla biegacza:**

| Sygnał | Próg | Interpretacja |
|--------|------|---------------|
| Średnie tempo całego treningu | < 3:00/km (> 20 km/h) | prawdopodobnie rower lub błąd GPS |
| Maksymalna prędkość w jednym punkcie GPS | > 35 km/h | błąd GPS, nie sprint |
| Średnie tempo całego treningu | > 15:00/km (< 4 km/h) | spacer, nie bieg — ostrzeżenie |
| Dystans / czas — niespójność | czas > 0, dystans = 0 | błąd parsowania TCX |

**Kontekst:** rekord świata w maratonie to ~20.4 km/h (Kipchoge). Dla amatora średnie tempo powyżej 20 km/h przez cały trening jest fizycznie niemożliwe — to niemal na pewno rower lub błąd danych.

**Zachowanie przy przekroczeniu progu:**

- Średnie tempo < 3:00/km → odrzuć, `IMPORT_SANITY_SPEED_TOO_HIGH`
- Pojedynczy punkt GPS > 35 km/h → nie odrzucaj całego treningu, usuń/wygładź punkt, dodaj flagę `gps_anomaly` do metadanych
- Średnie tempo > 15:00/km → zapisz, ale dodaj flagę `low_pace_warning`, nie generuj planu compliance
- Dystans = 0 przy czasie > 0 → odrzuć, `IMPORT_SANITY_MISSING_DISTANCE`

### 3. Kody błędów importu

```
WRONG_SPORT               — dyscyplina inna niż bieg
IMPORT_SANITY_SPEED_TOO_HIGH   — średnie tempo < 3:00/km
IMPORT_SANITY_MISSING_DISTANCE — dystans zerowy przy niezerowym czasie
```

Ostrzeżenia (import przebiega, ale z flagą):
```
gps_anomaly       — pojedynczy punkt GPS > 35 km/h (wygładzony)
low_pace_warning  — średnie tempo > 15:00/km
```

---

## Zasada: świadomość daty przy imporcie

**Każdy import — manualny (TCX upload) i automatyczny (API Garmin/Strava) — musi porównać datę treningu z datą bieżącą (UTC) natychmiast po zapisie.**

### Po co

Backend musi wiedzieć czy zaimportowany trening to trening z **dzisiaj**, żeby:

- odnieść się do zaplanowanej sesji na ten dzień (z `PlanSnapshot`)
- wygenerować feedback w kontekście bieżącego dnia ("dzisiaj miałeś jakość, zrobiłeś easy — to dobrze / to za wolno")
- poinformować użytkownika o nadchodzących wydarzeniach jeśli jest to ważne (np. jutro długi bieg, pojutrze start)

### Logika

```
workoutDate = date(workout.summary.startTimeIso)  // strefa UTC
today       = date(now(), UTC)

if workoutDate == today:
    → tryb "feedback dziś"
    → pobierz plannedSession na dziś z PlanSnapshot
    → wygeneruj post-workout summary z odniesieniem do planu
    → sprawdź jutrzejsze i pojutrznyszne sesje — jeśli ważne (long, race, quality) → alert
else:
    → tryb "historyczny"
    → zapisz sygnały, zaktualizuj compliance, nie generuj alertów kontekstowych
```

### Implementacja

Sprawdzenie daty należy do pipeline'u importu (`ExternalWorkoutImportService` i kontroler manualnego uploadu), **nie** do serwisu feedbacku. Serwis feedbacku dostaje już flagę `isToday: bool` jako parametr.

### Strefa czasowa

Zawsze porównuj w **UTC**. Użytkownik może biegać o 23:50 czasu lokalnego — trening zapisany jako następny dzień UTC nie jest "dzisiejszym" treningiem. W przyszłości można dodać `userTimezone` do profilu i porównywać w strefie użytkownika.

---

## Domain Services

### WorkoutMetricsCalculator
Computes metrics from TCX trackpoints:
- Duration, distance, average pace
- Heart rate statistics (avg, max)
- Intensity zone distribution

### TcxParser
Parses TCX XML into structured data (Trackpoint[]).

## API Contracts

### POST /api/workouts
Create a new workout from TCX data.

**Request Body:**
```json
{
  "tcxRaw": "<?xml>...</xml>",
  "action": "save",
  "kind": "training",
  "summary": { ... },
  "raceMeta": { ... } // optional
}
```

### GET /api/workouts
List workouts for authenticated user.

**Query Parameters:**
- `limit: int?` - Number of results
- `offset: int?` - Pagination offset
- `from: string?` - ISO date (filter from)
- `to: string?` - ISO date (filter to)

### GET /api/workouts/{id}
Get workout by ID (includes raw TCX if requested).

**Query Parameters:**
- `includeRaw: bool` - Include raw TCX data

### DELETE /api/workouts/{id}
Delete workout.

