# ADR 0002: Zakres cutoveru — PHP core, Node.js dla AI i integracji (Phase 1)

## Status
Accepted

## Context

Projekt MarcinCoach v2 migruje backend z Node.js (NestJS) na PHP (Laravel).
Backend PHP osiągnął stan `cutover ready` po domknięciu pakietów M1–M4.

Przed podjęciem decyzji o przełączeniu ruchu ujawniono krytyczny błąd w definicji zakresu cutoveru:
checklista cutoveru (`docs/php-only-cutover-checklist.md`) w fazie T+30m wymagała weryfikacji endpointów:

- `/ai/insights`
- `/ai/plan`
- `/integrations/strava/*`
- `/integrations/garmin/*`

które **nie istnieją w backendzie PHP**.

W backendzie PHP są zaimplementowane:
- auth / session
- profil użytkownika
- import i zarządzanie workoutami (TCX)
- training signals
- training context
- training adjustments
- weekly plan
- plan compliance / alerts
- training feedback

W backendzie Node.js pozostają:
- AI plan (`/ai/plan`)
- AI insights (`/ai/insights`)
- Training Feedback V2 AI (`/training-feedback-v2/ai/answer`)
- Integracja Strava (OAuth, sync)
- Integracja Garmin (connect, sync, status)

Migracja warstwy AI i integracji do PHP to odrębny, złożony pakiet (M6 / M5 wg harmonogramu),
niewymagany dla uruchomienia produkcyjnego coacha backendowego.

## Decision

**Cutover Phase 1 obejmuje wyłącznie PHP core.**

Zakres przełączenia:
- auth, session, me, profil użytkownika → PHP
- workouty (import, upload, lista, szczegóły, meta, delete) → PHP
- training signals → PHP
- training context → PHP
- training adjustments → PHP
- weekly plan → PHP
- plan compliance, alerts, training feedback → PHP

Poza zakresem cutoveru Phase 1 (pozostają na Node.js):
- `/ai/plan` — AI plan generation
- `/ai/insights` — AI insights
- `/training-feedback-v2/ai/*` — AI-powered feedback Q&A
- `/integrations/strava/*` — Strava OAuth i sync
- `/integrations/garmin/*` — Garmin connect i sync

Node.js pozostaje uruchomiony i dostępny dla tych endpointów do czasu domknięcia
osobnego pakietu migracji AI (M6) i integracji (M5).

Frontend w Phase 1 może wywoływać PHP dla core i Node.js dla AI/integracji.
Nie jest wymagany adapter BFF w Phase 1 — rozdzielenie punktów docelowych jest akceptowalne.

## Consequences

### Pozytywne
- Cutover Phase 1 jest wykonywalny bez migracji AI i integracji.
- Checklist cutoveru można zweryfikować — wszystkie wymagane endpointy istnieją w PHP.
- Zmniejsza ryzyko regresji funkcjonalnej po przełączeniu.
- Pozwala uruchomić PHP coach w produkcji i weryfikować jakość decyzji na realnym ruchu.

### Negatywne / Ryzyka
- Dual-backend model w Phase 1: frontend zależy od dwóch backendów jednocześnie.
- Koszt synchronizacji zmian wspólnych danych (user profile, workout history) między Node a PHP
  rośnie wraz z czasem trwania Phase 1.
- Jeśli migracja AI (M6) nie nastąpi w rozsądnym czasie, dual-backend stanie się stałym stanem.

### Guardrail
Decyzja o domknięciu Node.js i przejściu na PHP-only powinna być podjęta najpóźniej
po domknięciu M5 (integracje) i M6 (AI), nie później niż 6 miesięcy po cutoverze Phase 1.

## Zmiana w dokumentach operacyjnych

Konsekwencją tej decyzji jest:
- usunięcie `/ai/insights`, `/ai/plan`, `/integrations/strava/*`, `/integrations/garmin/*`
  z fazy T+30m checklisty cutoveru,
- dodanie noty że te endpointy pozostają na Node.js w Phase 1,
- brak zmiany w rollback triggery (dotyczą PHP core).

Data: 2026-04-21
Decydent: Marcin Kula — Project Owner
