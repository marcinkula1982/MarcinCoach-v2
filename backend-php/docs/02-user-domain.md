# User Domain Design

## Overview
The User domain manages user identity, authentication, and user profile/preferences.

## Aggregate Root: User

### Properties
- `id: int` - Primary key
- `externalId: string` - Unique external identifier (for authentication integration)
- `createdAt: DateTime` - Creation timestamp

### Relationships
- `userProfile: UserProfile?` - Optional user profile (preferences, constraints)
- `workouts: Workout[]` - All workouts belonging to this user
- `feedbacks: TrainingFeedbackV2[]` - All training feedbacks
- `planSnapshots: PlanSnapshot[]` - All training plan snapshots

### Business Rules
- Each user has a unique `externalId`
- User profile is optional but can contain training preferences
- User deletion cascades to related workouts, feedbacks, and plan snapshots

## Entity: UserProfile

### Properties
- `id: int` - Primary key
- `userId: int` - Foreign key to User
- `preferredRunDays: array<int>?` - Preferred running days (ISO: Monday=1, Sunday=7)
- `preferredSurface: string?` - Preferred surface type (e.g., "ROAD", "TRAIL")
- `goals: array<string>?` - Training goals (stored as JSON)
- `constraints: object?` - User constraints (stored as JSON)
- `createdAt: DateTime`
- `updatedAt: DateTime`

### Business Rules
- One-to-one relationship with User
- All JSON fields are optional
- Constraints include timezone, HR zones, surface preferences, etc.

## Value Objects

### UserProfileConstraints
- `timezone: string` - User timezone (default: 'Europe/Warsaw')
- `runningDays: array<string>` - Preferred running days ['mon', 'tue', ...]
- `surfaces: object` - Surface preferences
- `shoes: object` - Shoe preferences
- `hrZones: object?` - Heart rate zones (optional)

## Repository Interface: UserRepository

```php
interface UserRepositoryInterface
{
    public function findByExternalId(string $externalId): ?User;
    public function findById(int $id): ?User;
    public function save(User $user): User;
    public function delete(User $user): void;
}
```

## Repository Interface: UserProfileRepository

```php
interface UserProfileRepositoryInterface
{
    public function findByUserId(int $userId): ?UserProfile;
    public function save(UserProfile $profile): UserProfile;
    public function delete(UserProfile $profile): void;
}
```

## Domain Services

None currently - User domain is primarily data storage with minimal business logic.

## API Contracts

### GET /api/users/me
Returns current authenticated user information.

### GET /api/users/{id}/profile
Returns user profile with preferences and constraints.

### PUT /api/users/{id}/profile
Updates user profile.

