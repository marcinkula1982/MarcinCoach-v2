# MarcinCoach Domain Architecture Documentation

This directory contains the domain architecture design documentation for the MarcinCoach Laravel PHP backend.

## Documentation Structure

1. **[Domain Overview](01-domain-overview.md)** - High-level overview of domain boundaries and relationships
2. **[User Domain](02-user-domain.md)** - User identity, authentication, and profile design
3. **[Workout Domain](03-workout-domain.md)** - Workout entities and value objects design
4. **[Training Domain](04-training-domain.md)** - Training plans, compliance, and adjustments design
5. **[Signals Domain](05-signals-domain.md)** - Training signals and metrics computation design
6. **[Repositories](06-repositories.md)** - Repository interface contracts
7. **[Domain Services](07-domain-services.md)** - Domain service interfaces and responsibilities
8. **[API Contracts](08-api-contracts.md)** - API endpoint specifications, request/response formats
9. **[Database Schema](09-database-schema.md)** - Database table definitions and migration strategy

## Design Principles

1. **Clean Architecture**: Separation of domain logic from infrastructure
2. **Aggregate Roots**: User, Workout, PlanSnapshot
3. **Value Objects**: Immutable objects for domain concepts (Metrics, WorkoutSummary, etc.)
4. **Repository Pattern**: Abstract data access behind interfaces
5. **Domain Services**: Business logic that spans multiple aggregates

## Implementation Notes

- This is a **design phase** - no code implementation
- All domain logic is designed to be testable
- JSON fields stored as TEXT in SQLite (Laravel handles serialization)
- Repository interfaces allow for easy mocking in tests
- Domain services are pure business logic where possible

## Next Steps

After reviewing and approving this design, implementation can begin:

1. Create Eloquent models for entities
2. Create value object classes
3. Implement repository interfaces using Eloquent
4. Implement domain services
5. Create API controllers and DTOs
6. Write database migrations
7. Write unit and integration tests

