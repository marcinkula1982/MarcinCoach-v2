# Repository Interfaces Design

## Overview
Repository interfaces define data access contracts for each aggregate root. Implementations will use Eloquent ORM but are abstracted behind interfaces for testability.

## UserRepositoryInterface

```php
interface UserRepositoryInterface
{
    /**
     * Find user by external ID (for authentication)
     */
    public function findByExternalId(string $externalId): ?User;

    /**
     * Find user by internal ID
     */
    public function findById(int $id): ?User;

    /**
     * Save user (create or update)
     */
    public function save(User $user): User;

    /**
     * Delete user (cascades to related entities)
     */
    public function delete(User $user): void;
}
```

## UserProfileRepositoryInterface

```php
interface UserProfileRepositoryInterface
{
    /**
     * Find profile by user ID
     */
    public function findByUserId(int $userId): ?UserProfile;

    /**
     * Save profile (create or update)
     */
    public function save(UserProfile $profile): UserProfile;

    /**
     * Delete profile
     */
    public function delete(UserProfile $profile): void;
}
```

## WorkoutRepositoryInterface

```php
interface WorkoutRepositoryInterface
{
    /**
     * Find workout by ID
     */
    public function findById(int $id): ?Workout;

    /**
     * Find workout by ID, ensuring it belongs to user
     */
    public function findByIdForUser(int $id, int $userId): ?Workout;

    /**
     * Find all workouts for user (paginated)
     */
    public function findByUserId(int $userId, ?int $limit = null, ?int $offset = null): array;

    /**
     * Find workouts in date range for user
     */
    public function findByUserIdAndDateRange(int $userId, string $fromIso, string $toIso): array;

    /**
     * Find workout by deduplication key
     */
    public function findByDedupeKey(int $userId, string $dedupeKey): ?Workout;

    /**
     * Save workout (create or update)
     */
    public function save(Workout $workout): Workout;

    /**
     * Delete workout (cascades to raw TCX)
     */
    public function delete(Workout $workout): void;
}
```

## WorkoutRawTcxRepositoryInterface

```php
interface WorkoutRawTcxRepositoryInterface
{
    /**
     * Find raw TCX data by workout ID
     */
    public function findByWorkoutId(int $workoutId): ?WorkoutRawTcx;

    /**
     * Save raw TCX data
     */
    public function save(WorkoutRawTcx $raw): WorkoutRawTcx;

    /**
     * Delete raw TCX data
     */
    public function delete(WorkoutRawTcx $raw): void;
}
```

## PlanSnapshotRepositoryInterface

```php
interface PlanSnapshotRepositoryInterface
{
    /**
     * Find plan snapshot by ID
     */
    public function findById(int $id): ?PlanSnapshot;

    /**
     * Find all plan snapshots for user
     */
    public function findByUserId(int $userId): array;

    /**
     * Find plan snapshot that covers the specified date range
     */
    public function findByUserIdAndWindow(int $userId, string $fromIso, string $toIso): ?PlanSnapshot;

    /**
     * Find latest plan snapshot for user
     */
    public function findLatestByUserId(int $userId): ?PlanSnapshot;

    /**
     * Save plan snapshot (create or update)
     */
    public function save(PlanSnapshot $snapshot): PlanSnapshot;

    /**
     * Delete plan snapshot
     */
    public function delete(PlanSnapshot $snapshot): void;
}
```

## Implementation Notes

1. **Eloquent Models**: Each repository will be implemented using Eloquent models
2. **JSON Fields**: Fields stored as JSON strings (due to SQLite limitations) should be automatically serialized/deserialized
3. **Cascading Deletes**: Handled by database foreign key constraints
4. **Transactions**: Complex operations should use database transactions
5. **Query Optimization**: Use eager loading for relationships when needed

## Testing Strategy

Repositories can be easily mocked using interfaces:
- Unit tests can use mock repositories
- Integration tests use real Eloquent implementations
- Database tests use SQLite in-memory database

