# MarcinCoach v2 - roadmap

Status dokumentu: aktywny plan dalszych prac.

Po zrealizowaniu funkcjonalnosci nie dopisuj jej tutaj jako historii. Przenies jej status do `docs/status.md`, do sekcji `Dziennik zrealizowanych funkcjonalnosci`, a w tym pliku zostaw tylko kolejne prace do wykonania.

## Najblizsza kolejnosc

0. Fundament provider-neutral analytics (priorytet):
   - zasada: importer provider-specific -> `WorkoutFacts` -> `UserTrainingAnalysis` -> AI / plan / alerty / feedback,
   - backend liczy fakty; OpenAI tylko ubiera gotowy pakiet faktow w narracje,
   - strefy HR zawsze maja jawny status: `known | derived | estimated | missing`,
   - plan tygodniowy korzysta z `UserTrainingAnalysis` + ankiety/celow/ograniczen, nie z luznego tekstu AI,
   - F1: kontrakty DTO + szkielet `UserTrainingAnalysisService` (bez logiki),
   - F2: `WorkoutFactsExtractor` z istniejacych `Workout` + raw TCX,
   - F3: realne agregaty (load 7d/28d, ACWR, regularnosc, status stref HR),
   - F4: endpoint `GET /api/me/training-analysis` + cache + snapshot w bazie,
   - F5: laurka onboardingowa na nowym kontrakcie,
   - F6: migracja `WeeklyPlanService` na nowy wsad,
   - F7: alerty i feedback-v2 na nowym kontrakcie, oznaczenie starych sciezek `@deprecated`.

1. Produkcyjny smoke po porzadkach repo:
   - register/login/profile,
   - import/upload treningu,
   - training signals/context/adjustments,
   - weekly plan,
   - onboarding skip i normalny zapis profilu,
   - Strava/Garmin happy path albo jawny blad konfiguracji.

2. M3/M4 hardening UX:
   - pokazanie `blockContext`,
   - widoczne alerty i decision trace,
   - scenariusze reczne: powrot po przerwie, load spike, taper, chroniczne niedowykonanie,
   - lepsze opisy powodow korekt planu.

3. M2 deeper data:
   - FIT/GPX,
   - moving time,
   - cadence,
   - power,
   - elevation,
   - pace-zones per user.

4. M5 integracje sportowe:
   - produkcyjne credentials i smoke Strava,
   - Garmin: monitoring connectora, rate-limit handling, MFA handling,
   - Polar AccessLink,
   - Suunto Cloud API,
   - jasny fallback przez pliki dla kazdego zrodla.

5. M6 AI hardening:
   - produkcyjny provider OpenAI,
   - limity dzienne i komunikaty UI,
   - cache,
   - observability,
   - testy kontraktowe odpowiedzi AI/stub.

## Aktywne referencje

- `docs/status.md` - wykonane funkcjonalnosci, technologie, walidacje.
- `docs/integrations.md` - integracje Garmin/Strava/Polar/Suunto.
- `docs/deploy/frontend-iqhost-deploy.txt` - deploy frontu.
- `CLAUDE.md` / `AGENTS.md` - zasady pracy AI i deployu.
