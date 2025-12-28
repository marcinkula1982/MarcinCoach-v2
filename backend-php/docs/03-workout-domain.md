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

