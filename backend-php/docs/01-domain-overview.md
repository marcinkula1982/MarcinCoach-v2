# Domain Architecture Overview

## Purpose
This document provides an overview of the MarcinCoach domain architecture for the Laravel PHP backend.

## Domain Boundaries

The MarcinCoach application is organized into four main domain areas:

1. **User Domain** - User identity, authentication, and profile management
2. **Workout Domain** - Completed training sessions and workout data
3. **Training Domain** - Training plans, planned sessions, and plan compliance
4. **Signals Domain** - Training signals, metrics, and evaluations

## Domain Model Relationships

```
User (Aggregate Root)
├── UserProfile (Entity)
├── Workout[] (Reference)
├── TrainingFeedbackV2[] (Reference)
└── PlanSnapshot[] (Reference)

Workout (Aggregate Root)
├── WorkoutRawTcx (Entity)
└── TrainingFeedbackV2 (Reference)

PlanSnapshot (Aggregate Root)
└── PlanSnapshotDay[] (Value Objects)

TrainingSignals (Domain Service Output)
- Computed from Workout[] data
- No persistent entity
```

## Core Principles

1. **Aggregate Roots**: User, Workout, PlanSnapshot
2. **Value Objects**: PlanSnapshotDay, Metrics, WorkoutSummary
3. **Domain Services**: TrainingSignalsCalculator, PlanComplianceEvaluator
4. **Repository Pattern**: Separation of domain logic from data access

## Next Steps

See individual domain design documents:
- [User Domain](02-user-domain.md)
- [Workout Domain](03-workout-domain.md)
- [Training Domain](04-training-domain.md)
- [Signals Domain](05-signals-domain.md)

