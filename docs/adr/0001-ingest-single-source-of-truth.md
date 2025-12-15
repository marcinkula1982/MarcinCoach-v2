\# ADR 0001: Ingest jako pojedynczy punkt prawdy (Single Source of Truth)



\## Status

Accepted



\## Context

Projekt potrzebuje jednego, deterministycznego sposobu zapisu historii treningów, bez duplikatów i bez rozjazdów między różnymi ścieżkami importu (upload/import, Garmin/Strava, ręczne dodawanie). Wcześniej logika deduplikacji i format danych rozjeżdżały się między endpointami, co powodowało chaos i brak pewności „ile treningów mamy naprawdę”.



\## Decision

Ustalamy, że jedynym punktem prawdy dla zapisu treningu jest `POST /workouts/import`.

Wszystkie inne wejścia (np. `POST /workouts/upload`) są tylko adapterami, które zamieniają wejście (np. TCX) na `ImportWorkoutDto` i wywołują tę samą logikę (`importWorkout()` w serwisie).

Deduplikacja jest deterministyczna i oparta o `(source + sourceActivityId)`; gdy `sourceActivityId` brak, stosujemy stabilny fallback na podstawie `(startTimeIso + duration + distance)` po normalizacji.



\## Consequences

\- Logika deduplikacji nie może istnieć w wielu miejscach.

\- „Strefy/intensity” w imporcie są liczone jako metryka techniczna (np. z tempa), ale personalizacja (HR zones) to osobny etap i nie może wpływać na ingest.

\- Zmiana tej decyzji wymaga nowego ADR (łamie fundament historii danych).



