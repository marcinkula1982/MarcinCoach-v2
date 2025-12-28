# Signals Domain Design

## Overview
The Signals domain computes training signals from workout data. Signals are derived values, not persistent entities.

## Domain Service Output: TrainingSignals

Training signals are computed on-demand from workout data:

```php
class TrainingSignals
{
    public Period $period; // { from: ISO, to: ISO }
    public Volume $volume;
    public Intensity $intensity;
    public LongRun $longRun;
    public Load $load;
    public Consistency $consistency;
    public Flags $flags;
}
```

## Value Objects

### Period
```php
class Period
{
    public string $from; // ISO timestamp
    public string $to; // ISO timestamp
}
```

### Volume
```php
class Volume
{
    public float $distanceKm;
    public int $durationMin;
    public int $sessions;
}
```

### Intensity
Time distribution across heart rate zones:

```php
class TrainingSignalsIntensity
{
    public int $z1Sec; // Zone 1 seconds
    public int $z2Sec; // Zone 2 seconds
    public int $z3Sec; // Zone 3 seconds
    public int $z4Sec; // Zone 4 seconds
    public int $z5Sec; // Zone 5 seconds
    public int $totalSec; // Total seconds
}
```

### LongRun
```php
class LongRun
{
    public bool $exists;
    public float $distanceKm;
    public int $durationMin;
    public ?int $workoutId;
    public ?string $workoutDt; // ISO date
}
```

### Load
```php
class Load
{
    public float $weeklyLoad; // Current week load
    public float $rolling4wLoad; // Rolling 4-week load
}
```

### Consistency
```php
class Consistency
{
    public float $sessionsPerWeek; // Average sessions per week
    public int $streakWeeks; // Consecutive weeks with sessions
}
```

### Flags
```php
class Flags
{
    public bool $injuryRisk;
    public bool $fatigue;
}
```

## Domain Service: TrainingSignalsCalculator

Computes training signals from workout data for a given time period.

### Responsibilities
1. Aggregate workouts for the specified period
2. Calculate volume metrics (distance, duration, session count)
3. Calculate intensity distribution across HR zones
4. Identify long run (if exists)
5. Calculate training load (weekly and rolling 4-week)
6. Calculate consistency metrics
7. Evaluate risk flags (injury risk, fatigue)

### Input
- `userId: int` - User ID
- `fromIso: string` - Start date (ISO)
- `toIso: string` - End date (ISO)
- `workouts: Workout[]` - Workout data (optional, can fetch internally)

### Output
- `TrainingSignals` object with all computed metrics

### Business Rules
- If no workouts exist, return empty/default values
- Period defaults to last N days if not specified
- Long run identified as longest run in period (> threshold)
- Flags computed from load, consistency, and intensity patterns

## Value Object: TrainingContext

Combines training signals with user profile for AI plan generation:

```php
class TrainingContext
{
    public string $generatedAtIso;
    public int $windowDays;
    public TrainingSignals $signals;
    public UserProfileConstraints $profile;
}
```

## Repository Dependencies

TrainingSignalsCalculator depends on:
- `WorkoutRepository` - To fetch workouts for the period

## API Contracts

### GET /api/training-signals
Get training signals for authenticated user.

**Query Parameters:**
- `days: int?` - Number of days to analyze (default: 28)
- `from: string?` - ISO date (override days)
- `to: string?` - ISO date (override days)

**Response:**
```json
{
  "period": { "from": "2025-12-01T00:00:00Z", "to": "2025-12-28T23:59:59Z" },
  "volume": { "distanceKm": 120.5, "durationMin": 720, "sessions": 12 },
  "intensity": { "z1Sec": 3600, "z2Sec": 1800, ... },
  "longRun": { "exists": true, "distanceKm": 21.1, ... },
  "load": { "weeklyLoad": 45.2, "rolling4wLoad": 180.5 },
  "consistency": { "sessionsPerWeek": 3.0, "streakWeeks": 4 },
  "flags": { "injuryRisk": false, "fatigue": true }
}
```

