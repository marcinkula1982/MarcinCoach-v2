# Domain Services Design

## Overview
Domain services contain business logic that doesn't naturally fit within entity methods. They operate on multiple aggregates or perform complex calculations.

## TrainingSignalsCalculator

### Purpose
Computes training signals from workout data for a given time period.

### Interface
```php
interface TrainingSignalsCalculatorInterface
{
    /**
     * Calculate training signals for user in date range
     */
    public function calculate(
        int $userId,
        string $fromIso,
        string $toIso
    ): TrainingSignals;

    /**
     * Calculate training signals from provided workouts
     */
    public function calculateFromWorkouts(
        array $workouts,
        string $fromIso,
        string $toIso
    ): TrainingSignals;
}
```

### Dependencies
- `WorkoutRepositoryInterface` - To fetch workouts

### Responsibilities
1. Fetch or accept workouts for the period
2. Calculate volume metrics (distance, duration, sessions)
3. Calculate intensity distribution (HR zones)
4. Identify long run
5. Calculate training load (weekly and rolling 4-week)
6. Calculate consistency metrics
7. Evaluate risk flags

### Business Rules
- Returns default/empty values if no workouts exist
- Period defaults to last N days if not specified
- Long run threshold: > 15km or > 90 minutes
- Fatigue flag: high rolling load + high intensity ratio
- Injury risk flag: sudden load increase + consistency drop

## PlanComplianceEvaluator

### Purpose
Evaluates compliance between planned training sessions and actual workouts.

### Interface
```php
interface PlanComplianceEvaluatorInterface
{
    /**
     * Evaluate compliance for a planned session vs actual workout
     */
    public function evaluate(
        PlannedSession $planned,
        ?Workout $actual
    ): PlanCompliance;
}
```

### Dependencies
None (pure domain logic)

### Responsibilities
1. Compare planned duration vs actual duration
2. Compare planned distance vs actual distance
3. Compare planned intensity vs actual intensity
4. Check session type match
5. Determine compliance status (OK, MINOR_DEVIATION, MAJOR_DEVIATION)

### Business Rules
- **Duration Compliance**:
  - < 70% = MAJOR_DEVIATION (undershoot)
  - 70-85% = MINOR_DEVIATION (undershoot)
  - 85-115% = OK
  - 115-130% = MINOR_DEVIATION (overshoot)
  - > 130% = MAJOR_DEVIATION (overshoot)

- **Intensity Compliance**:
  - Hard → Easy = MAJOR_DEVIATION
  - Moderate → Easy = MINOR_DEVIATION
  - Easy → Hard = MINOR_DEVIATION

- **Session Type Compliance**:
  - Planned interval/tempo executed as easy = MAJOR_DEVIATION

- **Skip Compliance**:
  - Planned session not executed = MAJOR_DEVIATION

- **Status Determination**:
  - Any MAJOR flag → MAJOR_DEVIATION
  - Any MINOR flag (no MAJOR) → MINOR_DEVIATION
  - Otherwise → OK

## TrainingAdjustmentsCalculator

### Purpose
Generates training adjustments based on signals, compliance, and user profile.

### Interface
```php
interface TrainingAdjustmentsCalculatorInterface
{
    /**
     * Calculate training adjustments for user
     */
    public function calculate(
        int $userId,
        int $windowDays
    ): TrainingAdjustments;
}
```

### Dependencies
- `TrainingSignalsCalculatorInterface`
- `PlanSnapshotRepositoryInterface`
- `WorkoutRepositoryInterface`
- `UserProfileRepositoryInterface`

### Responsibilities
1. Fetch training signals
2. Fetch recent plan compliance history
3. Analyze user profile constraints
4. Generate adjustment recommendations
5. Assign severity levels

### Business Rules
- Adjustments based on signals flags (fatigue, injury risk)
- Adjustments based on plan compliance history
- Respects user profile constraints
- Severity based on signal strength and compliance patterns

## TrainingContextBuilder

### Purpose
Builds training context for AI plan generation.

### Interface
```php
interface TrainingContextBuilderInterface
{
    /**
     * Build training context for user
     */
    public function build(
        int $userId,
        int $windowDays
    ): TrainingContext;
}
```

### Dependencies
- `TrainingSignalsCalculatorInterface`
- `UserProfileRepositoryInterface`

### Responsibilities
1. Calculate training signals
2. Fetch user profile constraints
3. Combine into TrainingContext
4. Add metadata (generatedAtIso, windowDays)

## MetricsCalculator

### Purpose
Computes metrics from TCX trackpoint data.

### Interface
```php
interface MetricsCalculatorInterface
{
    /**
     * Calculate metrics from trackpoints
     */
    public function calculate(array $trackpoints): Metrics;
}
```

### Responsibilities
1. Calculate duration from time range
2. Calculate distance from distance values
3. Calculate average pace
4. Calculate heart rate statistics (avg, max)
5. Count trackpoints

### Business Rules
- Handles missing/null values gracefully
- Pace calculated as duration / distance
- HR statistics only if HR data available

## TcxParser

### Purpose
Parses TCX XML into structured trackpoint data.

### Interface
```php
interface TcxParserInterface
{
    /**
     * Parse TCX XML string
     */
    public function parse(string $xml): ParsedTcx;
}
```

### Output
```php
class ParsedTcx
{
    public array $trackpoints; // Trackpoint[]
    public ?string $startTimeIso;
}

class Trackpoint
{
    public string $time; // ISO timestamp
    public ?float $distanceMeters;
    public ?float $altitudeMeters;
    public ?int $heartRateBpm;
}
```

