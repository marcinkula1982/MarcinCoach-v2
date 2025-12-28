# Database Schema Design

## Overview
This document defines the database schema for MarcinCoach using Laravel migrations. The database uses SQLite for development and can be migrated to PostgreSQL/MySQL for production.

## Schema Notes

1. **JSON Fields**: Due to SQLite limitations, JSON fields are stored as TEXT columns. Eloquent will handle serialization/deserialization automatically.

2. **Indexes**: Strategic indexes are defined for query performance.

3. **Cascading Deletes**: Foreign keys use `onDelete('cascade')` where appropriate.

4. **Timestamps**: All tables include `created_at` and `updated_at` timestamps unless specified otherwise.

## Tables

### users
Primary user table (aggregate root).

```php
Schema::create('users', function (Blueprint $table) {
    $table->id();
    $table->string('external_id')->unique();
    $table->timestamps();
    
    $table->index('external_id');
});
```

**Fields**:
- `id`: bigint, primary key, auto increment
- `external_id`: string, unique, indexed
- `created_at`: timestamp
- `updated_at`: timestamp

### user_profiles
User preferences and constraints.

```php
Schema::create('user_profiles', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->unique()->constrained()->onDelete('cascade');
    $table->text('preferred_run_days')->nullable(); // JSON array
    $table->string('preferred_surface')->nullable();
    $table->text('goals')->nullable(); // JSON array
    $table->text('constraints')->nullable(); // JSON object
    $table->timestamps();
});
```

**Fields**:
- `id`: bigint, primary key
- `user_id`: bigint, foreign key to users, unique
- `preferred_run_days`: text (JSON), nullable
- `preferred_surface`: string, nullable
- `goals`: text (JSON), nullable
- `constraints`: text (JSON), nullable
- `created_at`: timestamp
- `updated_at`: timestamp

### workouts
Workout table (aggregate root).

```php
Schema::create('workouts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->string('action'); // 'save', 'preview-only'
    $table->string('kind'); // 'training', 'race'
    $table->text('summary'); // JSON WorkoutSummary
    $table->text('race_meta')->nullable(); // JSON RaceMeta
    $table->text('workout_meta')->nullable(); // JSON object
    $table->string('source')->default('MANUAL_UPLOAD');
    $table->string('source_activity_id')->nullable();
    $table->string('source_user_id')->nullable();
    $table->string('dedupe_key');
    $table->timestamps();
    
    $table->unique(['user_id', 'dedupe_key'], 'workouts_user_dedupe_unique');
    $table->index('user_id');
    $table->index(['user_id', 'created_at']);
    $table->index(['user_id', 'source', 'source_activity_id']);
});
```

**Fields**:
- `id`: bigint, primary key
- `user_id`: bigint, foreign key to users
- `action`: string
- `kind`: string
- `summary`: text (JSON WorkoutSummary), required
- `race_meta`: text (JSON RaceMeta), nullable
- `workout_meta`: text (JSON), nullable
- `source`: string, default 'MANUAL_UPLOAD'
- `source_activity_id`: string, nullable
- `source_user_id`: string, nullable
- `dedupe_key`: string
- `created_at`: timestamp
- `updated_at`: timestamp

**Indexes**:
- Unique: `(user_id, dedupe_key)`
- Index: `user_id`
- Index: `(user_id, created_at)`
- Index: `(user_id, source, source_activity_id)`

### workout_raw_tcx
Raw TCX XML data (stored separately for performance).

```php
Schema::create('workout_raw_tcx', function (Blueprint $table) {
    $table->id();
    $table->foreignId('workout_id')->unique()->constrained()->onDelete('cascade');
    $table->text('xml');
    $table->timestamp('created_at');
    
    $table->index('workout_id');
});
```

**Fields**:
- `id`: bigint, primary key
- `workout_id`: bigint, foreign key to workouts, unique
- `xml`: text (TCX XML content)
- `created_at`: timestamp

### plan_snapshots
Training plan snapshots (aggregate root).

```php
Schema::create('plan_snapshots', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->text('snapshot_json'); // JSON PlanSnapshot
    $table->string('window_start_iso'); // ISO timestamp
    $table->string('window_end_iso'); // ISO timestamp
    $table->timestamp('created_at');
    
    $table->index(['user_id', 'window_start_iso']);
    $table->index('user_id');
});
```

**Fields**:
- `id`: bigint, primary key
- `user_id`: bigint, foreign key to users
- `snapshot_json`: text (JSON PlanSnapshot), required
- `window_start_iso`: string (ISO timestamp)
- `window_end_iso`: string (ISO timestamp)
- `created_at`: timestamp

**Indexes**:
- Index: `(user_id, window_start_iso)` - For querying by window
- Index: `user_id` - For latest plan fallback

### training_feedback_v2
Training feedback (optional, can be added later).

```php
Schema::create('training_feedback_v2', function (Blueprint $table) {
    $table->id();
    $table->foreignId('workout_id')->unique()->constrained()->onDelete('cascade');
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->text('feedback'); // JSON feedback data
    $table->timestamps();
    
    $table->index('user_id');
    $table->index('workout_id');
});
```

**Fields**:
- `id`: bigint, primary key
- `workout_id`: bigint, foreign key to workouts, unique
- `user_id`: bigint, foreign key to users
- `feedback`: text (JSON), required
- `created_at`: timestamp
- `updated_at`: timestamp

## Migration Strategy

1. **Initial Migration**: Create base tables (users, user_profiles, workouts, workout_raw_tcx)
2. **Plan Snapshots Migration**: Add plan_snapshots table
3. **Feedback Migration**: Add training_feedback_v2 table (if needed)
4. **Future Migrations**: Add indexes or columns as needed

## Eloquent Model Mappings

- `User` → `users` table
- `UserProfile` → `user_profiles` table
- `Workout` → `workouts` table
- `WorkoutRawTcx` → `workout_raw_tcx` table
- `PlanSnapshot` → `plan_snapshots` table

## JSON Field Handling

Laravel Eloquent will automatically serialize/deserialize JSON fields using casts:

```php
protected $casts = [
    'summary' => 'array',
    'race_meta' => 'array',
    'workout_meta' => 'array',
    'preferred_run_days' => 'array',
    'goals' => 'array',
    'constraints' => 'array',
    'snapshot_json' => 'array',
    'feedback' => 'array',
];
```

## Relationships

- `User` hasMany `Workout`
- `User` hasOne `UserProfile`
- `User` hasMany `PlanSnapshot`
- `Workout` belongsTo `User`
- `Workout` hasOne `WorkoutRawTcx`
- `WorkoutRawTcx` belongsTo `Workout`
- `PlanSnapshot` belongsTo `User`

