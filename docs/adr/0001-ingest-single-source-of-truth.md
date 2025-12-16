# ADR 0001: Ingest jako pojedynczy punkt prawdy (Single Source of Truth)

## Status
Accepted

## Context
System MarcinCoach ingestuje treningi z różnych źródeł:
- ręczny upload plików (TCX/FIT),
- importy zewnętrzne (Garmin, Strava – w przyszłości),
- potencjalne integracje API.

Wcześniej logika zapisu, deduplikacji i walidacji treningów była rozproszona
między różne endpointy (`/upload`, `/import`) i warstwy aplikacji, co prowadziło
do niespójności danych, trudności w utrzymaniu oraz ryzyka duplikatów.

Kluczowym wymaganiem projektu jest **jeden, niepodważalny zapis historii treningów**,
na którym będą budowane:
- analityka,
- TrainingSignals,
- planowanie treningów,
- warstwa AI.

## Decision
Ustalamy, że **jedynym punktem prawdy dla zapisu treningu jest endpoint:**

