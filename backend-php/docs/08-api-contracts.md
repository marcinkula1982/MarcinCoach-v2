# API Contracts Design

## Overview
This document defines API contracts (endpoints, request/response formats, validation rules) for the MarcinCoach backend.

## Base URL
All API endpoints are prefixed with `/api`.

## Authentication
Authentication uses Laravel Sanctum tokens. Include token in `Authorization` header:
```
Authorization: Bearer {token}
```

## Common Response Formats

### Success Response
```json
{
  "data": { ... }
}
```

### Error Response
```json
{
  "error": {
    "message": "Error message",
    "code": "ERROR_CODE"
  }
}
```

### Validation Error Response
```json
{
  "errors": {
    "field_name": ["Validation message"]
  }
}
```

## User Endpoints

### GET /api/users/me
Get current authenticated user information.

**Authentication**: Required

**Response**:
```json
{
  "data": {
    "id": 1,
    "externalId": "user123",
    "createdAt": "2025-12-01T00:00:00Z"
  }
}
```

### GET /api/users/me/profile
Get current user's profile.

**Authentication**: Required

**Response**:
```json
{
  "data": {
    "userId": 1,
    "preferredRunDays": [1, 3, 5],
    "preferredSurface": "ROAD",
    "goals": ["marathon", "endurance"],
    "constraints": {
      "timezone": "Europe/Warsaw",
      "runningDays": ["mon", "wed", "fri"],
      "surfaces": { "preferTrail": false },
      "hrZones": { ... }
    }
  }
}
```

### PUT /api/users/me/profile
Update current user's profile.

**Authentication**: Required

**Request Body**:
```json
{
  "preferredRunDays": [1, 3, 5],
  "preferredSurface": "TRAIL",
  "goals": ["5k", "10k"],
  "constraints": { ... }
}
```

**Response**: Updated profile (same as GET)

## Workout Endpoints

### POST /api/workouts
Create a new workout from TCX data.

**Authentication**: Required

**Request Body**:
```json
{
  "tcxRaw": "<?xml version=\"1.0\"?>...",
  "action": "save",
  "kind": "training",
  "summary": {
    "fileName": "workout.tcx",
    "startTimeIso": "2025-12-01T08:00:00Z",
    "original": {
      "durationSec": 3600,
      "distanceM": 10000,
      "avgPaceSecPerKm": 360,
      "avgHr": 150,
      "maxHr": 170,
      "count": 1200
    },
    "trimmed": { ... },
    "intensity": {
      "z1Sec": 600,
      "z2Sec": 2400,
      "z3Sec": 600,
      "z4Sec": 0,
      "z5Sec": 0,
      "totalSec": 3600
    },
    "totalPoints": 1200,
    "selectedPoints": 1000
  },
  "raceMeta": {
    "name": "Marathon",
    "distance": "42.2 km",
    "priority": "A"
  }
}
```

**Validation Rules**:
- `tcxRaw`: required, string, non-empty
- `action`: required, enum: ['save', 'preview-only']
- `kind`: required, enum: ['training', 'race']
- `summary`: required, object
- `raceMeta`: optional, object (required if kind='race')

**Response**:
```json
{
  "data": {
    "id": 1,
    "userId": 1,
    "action": "save",
    "kind": "training",
    "summary": { ... },
    "createdAt": "2025-12-01T10:00:00Z",
    "updatedAt": "2025-12-01T10:00:00Z"
  }
}
```

### GET /api/workouts
List workouts for authenticated user.

**Authentication**: Required

**Query Parameters**:
- `limit: int?` - Number of results (default: 50, max: 100)
- `offset: int?` - Pagination offset (default: 0)
- `from: string?` - ISO date (filter from)
- `to: string?` - ISO date (filter to)

**Response**:
```json
{
  "data": [
    {
      "id": 1,
      "userId": 1,
      "action": "save",
      "kind": "training",
      "summary": { ... },
      "createdAt": "2025-12-01T10:00:00Z"
    }
  ],
  "meta": {
    "total": 100,
    "limit": 50,
    "offset": 0
  }
}
```

### GET /api/workouts/{id}
Get workout by ID.

**Authentication**: Required

**Query Parameters**:
- `includeRaw: bool` - Include raw TCX data (default: false)

**Response**: Workout object (same structure as POST response)

### DELETE /api/workouts/{id}
Delete workout.

**Authentication**: Required

**Response**: 204 No Content

## Training Signals Endpoints

### GET /api/training-signals
Get training signals for authenticated user.

**Authentication**: Required

**Query Parameters**:
- `days: int?` - Number of days to analyze (default: 28, min: 1, max: 365)
- `from: string?` - ISO date (overrides days)
- `to: string?` - ISO date (overrides days)

**Response**:
```json
{
  "data": {
    "period": {
      "from": "2025-12-01T00:00:00Z",
      "to": "2025-12-28T23:59:59Z"
    },
    "volume": {
      "distanceKm": 120.5,
      "durationMin": 720,
      "sessions": 12
    },
    "intensity": {
      "z1Sec": 10800,
      "z2Sec": 25200,
      "z3Sec": 7200,
      "z4Sec": 0,
      "z5Sec": 0,
      "totalSec": 43200
    },
    "longRun": {
      "exists": true,
      "distanceKm": 21.1,
      "durationMin": 120,
      "workoutId": 5,
      "workoutDt": "2025-12-15"
    },
    "load": {
      "weeklyLoad": 45.2,
      "rolling4wLoad": 180.5
    },
    "consistency": {
      "sessionsPerWeek": 3.0,
      "streakWeeks": 4
    },
    "flags": {
      "injuryRisk": false,
      "fatigue": true
    }
  }
}
```

## Training Context Endpoints

### GET /api/training-context
Get training context for AI plan generation.

**Authentication**: Required

**Query Parameters**:
- `days: int?` - Number of days for signals (default: 28)

**Response**:
```json
{
  "data": {
    "generatedAtIso": "2025-12-28T17:00:00Z",
    "windowDays": 28,
    "signals": { ... },
    "profile": {
      "timezone": "Europe/Warsaw",
      "runningDays": ["mon", "wed", "fri"],
      "surfaces": { "preferTrail": false },
      "hrZones": { ... }
    }
  }
}
```

## Plan Snapshot Endpoints

### GET /api/plan-snapshots
List plan snapshots for authenticated user.

**Authentication**: Required

**Response**:
```json
{
  "data": [
    {
      "id": 1,
      "userId": 1,
      "windowStartIso": "2025-12-01T00:00:00Z",
      "windowEndIso": "2025-12-28T23:59:59Z",
      "createdAt": "2025-12-01T10:00:00Z"
    }
  ]
}
```

### GET /api/plan-snapshots/{id}
Get plan snapshot by ID (includes deserialized plan data).

**Authentication**: Required

**Response**:
```json
{
  "data": {
    "id": 1,
    "userId": 1,
    "windowStartIso": "2025-12-01T00:00:00Z",
    "windowEndIso": "2025-12-28T23:59:59Z",
    "plan": {
      "windowStartIso": "2025-12-01T00:00:00Z",
      "windowEndIso": "2025-12-28T23:59:59Z",
      "days": [
        {
          "dateKey": "2025-12-01",
          "type": "easy",
          "plannedDurationMin": 30,
          "plannedDistanceKm": 5.0,
          "plannedIntensity": "easy"
        }
      ]
    },
    "createdAt": "2025-12-01T10:00:00Z"
  }
}
```

### POST /api/plan-snapshots
Create new plan snapshot (typically generated by AI).

**Authentication**: Required

**Request Body**:
```json
{
  "plan": {
    "windowStartIso": "2025-12-01T00:00:00Z",
    "windowEndIso": "2025-12-28T23:59:59Z",
    "days": [ ... ]
  }
}
```

**Response**: Created plan snapshot (same as GET by ID)

## Training Adjustments Endpoints

### GET /api/training-adjustments
Get training adjustments for authenticated user.

**Authentication**: Required

**Query Parameters**:
- `days: int?` - Analysis window (default: 28)

**Response**:
```json
{
  "data": {
    "generatedAtIso": "2025-12-28T17:00:00Z",
    "windowDays": 28,
    "adjustments": [
      {
        "code": "reduce_load",
        "severity": "medium",
        "rationale": "High fatigue detected",
        "evidence": [
          { "key": "fatigueFlag", "value": true },
          { "key": "rolling4wLoad", "value": 180.5 }
        ],
        "params": { "reductionPercent": 10 }
      }
    ]
  }
}
```

## Error Codes

- `UNAUTHORIZED` - Authentication required
- `FORBIDDEN` - Insufficient permissions
- `NOT_FOUND` - Resource not found
- `VALIDATION_ERROR` - Request validation failed
- `DUPLICATE_WORKOUT` - Workout already exists (dedupe key conflict)
- `INTERNAL_ERROR` - Server error

## Status Codes

- `200 OK` - Success
- `201 Created` - Resource created
- `204 No Content` - Success (no body)
- `400 Bad Request` - Validation error
- `401 Unauthorized` - Authentication required
- `403 Forbidden` - Insufficient permissions
- `404 Not Found` - Resource not found
- `500 Internal Server Error` - Server error

