# MarcinCoach v2 - status projektu

Data raportu: 2026-04-26  
Status dokumentu: aktywne zrodlo prawdy dla wykonanego zakresu, technologii i walidacji.

Ten dokument konsoliduje aktualny stan projektu po migracji na Laravel/PHP. Starsze dokumenty z `docs/` oraz dawne raporty z root repo zostaly przeniesione do `docs/archive/` i nie sa aktualna instrukcja pracy.

## Gdzie dopisywac zrealizowane funkcjonalnosci

Nowe ukonczone funkcjonalnosci dopisujemy tutaj, w `docs/status.md`.

Zasada:
- po zrealizowaniu funkcji dodaj wpis w sekcji `Dziennik zrealizowanych funkcjonalnosci`,
- jesli funkcja zamyka kamien milowy, zaktualizuj tez tabele `Zweryfikowane kamienie milowe`,
- planowane prace trzymamy w `docs/roadmap.md`,
- status integracji sportowych trzymamy w `docs/integrations.md`,
- plikow w `docs/archive/` nie aktualizujemy jako zrodla prawdy.

## Dziennik zrealizowanych funkcjonalnosci

| Data | Obszar | Funkcjonalnosc | Walidacja / dowod |
|---|---|---|---|
| 2026-04-26 | Dokumentacja | Uporzadkowano aktywne dokumenty `docs`: status, roadmap, deploy frontu, integracje | Pozostale pliki przeniesione do `docs/archive/` i oznaczone jako historyczne. |
| 2026-04-26 | Repo cleanup | Usunieto legacy backend Node/Nest z aktywnego repo oraz parity Node-vs-PHP | `git ls-files` dla legacy backendu, `dist` i starych skryptow parity zwraca pusto. |
| 2026-04-26 | Frontend | Usunieto stara widoczna tozsamosc toolkitowa i stare prefiksy kluczy sesji z aktywnego kodu | Aktywny grep dla dawnych nazw produktu i dawnych kluczy sesji zwraca pusto. |
| 2026-04-26 | Backend integracje | Naprawiono `GarminConnectorService.php` i dopuszczono connect Garmina bez body, gdy credentials sa po stronie connectora/env | `php artisan test --filter=IntegrationsParityTest` -> 3 passed; pelny suite -> 260 passed. |

## Decyzja o starej ankiecie

Stara 11-sekcyjna ankieta: **USUNIETA / NIE UZYWAC**.

Powod usuniecia:
- zostala zastapiona onboardingiem data-first,
- mogla wprowadzac AI w blad jako aktywny kierunek produktu,
- wymagane informacje maja wynikac najpierw z danych treningowych, a dopiero potem z krotkich pytan uzupelniajacych.

Aktualny onboarding:
- wybor zrodla danych: Strava, Garmin, pliki TCX, brak danych,
- zrodla przyszle widoczne jako kierunek: Polar, Suunto,
- minimalne pytania: cel tekstowy, data startu, bol/kontuzja, liczba dni treningowych, dni niedostepne,
- sciezka reczna tylko dla uzytkownika bez danych,
- przycisk `Pomin` zapisuje minimalny profil i oznacza onboarding jako zakonczony.

## Zweryfikowane kamienie milowe

| Kamien milowy | Status | Weryfikacja | Uwagi |
|---|---|---|---|
| D0 - infrastruktura i deploy | WYKONANE | `https://coach.host89998.iqhs.pl` zwraca HTTP 200; `https://api.coach.host89998.iqhs.pl/api/health` zwraca HTTP 200 | Front jest statycznym buildem w `public_html`, backend Laravel dziala na subdomenie API. |
| D1 - backend PHP operacyjny | WYKONANE | lokalnie `php artisan route:list --path=api` pokazuje 43 trasy; lokalny suite: `260 passed, 1240 assertions` | Core PHP API jest wdrozone i test-clean po naprawie Garmina. |
| Onboarding MVP | WYKONANE | `src/components/Onboarding.tsx`, `src/api/profile.ts`, testy `AuthAndProfileTest`; UI skip test przeszedl | Aktualny model: data-first + minimalne pytania + skip. |
| M1 - profil uzytkownika beyond minimum | WYKONANE | `ProfileQualityScoreService`, `UserProfileService`, migracje profilu, testy profilu | Profil ma typed JSON sections, projekcje `primaryRace`, `maxSessionMin`, `hasCurrentPain`, `hasHrSensor`, score jakosci. |
| M2 - quality data beyond minimum | WYKONANE | `TcxParsingService`, `WorkoutSummaryBuilder`, `TrainingSignalsService`, testy TCX i workouts parity | TCX wzbogacony o sport, HR, pace, intensity buckets; FIT/GPX jeszcze poza zakresem. |
| M3 - weekly planning enhancement | WYKONANE | `WeeklyPlanService`, `BlockPeriodizationService`, `PlanMemoryService`, `ContractFreezeTest`, `PlanningParityTest` | Plan ma `blockContext`, role tygodnia, struktury sesji i zapis `training_weeks`. |
| M4 - deeper adaptation / alerting | WYKONANE | `TrainingAdjustmentsService`, `TrainingAlertsV1Service`, testy unit/feature alertow i adjustmentow | Adaptacje maja typy, confidence i reguly trendowe; publiczny kontrakt ukrywa pola debugowe. |
| M3/M4 hardening UX | CZESCIOWO | backend gotowy, ale UX nie pokazuje jeszcze pelnego trace decyzji | Do dopracowania: ekspozycja blokow, alertow, uzasadnien i scenariusze manual smoke. |
| M2 deeper data | PLANOWANE | brak FIT/GPX/cadence/power/elevation/pace-zones w aktualnym MVP | Kolejny pakiet po hardeningu M3/M4. |
| M5 - integracje sportowe produkcyjne | CZESCIOWO | trasy Strava/Garmin sa w Laravel; lokalne testy integracji przechodza; oficjalne Polar/Suunto jeszcze nie | Strava ma sciezke OAuth. Garmin connector dziala w trybie `live` na `python-garminconnect`; to nie jest oficjalny Garmin Activity API. Adapter pozostaje sciezka MVP z jawnie zaakceptowanym ryzykiem nieoficjalnego logowania. |
| M6 - AI provider hardening | CZESCIOWO | `/api/ai/plan`, `/api/ai/insights`, feedback-v2 AI endpointy istnieja; env moze dzialac jako stub | Do dopracowania provider OpenAI, limity, cache i observability produkcyjne. |

## Aktualne rozwiazania technologiczne

| Obszar | Rozwiazanie | Zrodlo prawdy |
|---|---|---|
| Frontend | React + Vite + TypeScript | `src/`, `vite.config.ts`, `package.json` |
| API base URL | `VITE_API_BASE_URL`; produkcja wskazuje `https://api.coach.host89998.iqhs.pl/api` | `.env.production`, `src/api/client.ts` |
| Deploy frontu | lokalny `npm run build`, potem `deploy-front.ps1` wysyla `dist/*` przez SCP | `deploy-front.ps1`, `docs/deploy/frontend-iqhost-deploy.txt` |
| Hosting | IQHost: frontend w `public_html`, backend w `app-laravel/backend-php` | `AGENTS.md`, `docs/deploy/*` |
| Backend | Laravel/PHP, endpointy pod `/api/*` | `backend-php/routes/api.php` |
| Sesja | custom session token w cache + naglowki `x-session-token`, `x-username` | `SessionTokenService`, `AuthController`, `src/api/client.ts` |
| Profil | `user_profiles` z typed JSON i projekcjami | `ProfileController`, `UserProfile`, migracje M1 |
| Treningi | `workouts`, `workout_raw_tcx`, `workout_import_events` | `WorkoutsController`, `ExternalWorkoutImportService` |
| Sygnały | Training signals v1/v2, rolling load, intensity buckets, safety flags | `TrainingSignalsService`, `TrainingSignalsV2Service` |
| Plan | weekly plan, block context, memory tygodniowa | `WeeklyPlanService`, `BlockPeriodizationService`, `PlanMemoryService` |
| Alerty | per-workout i weekly trend alerts | `TrainingAlertsV1Service`, `training_alerts_v1` |
| AI | AI plan, AI insights, feedback-v2 AI, cache, rate limit | `AiPlanService`, `AiInsightsService`, `TrainingFeedbackV2AiService`, `AiRateLimitService` |
| Integracje | Strava OAuth, Garmin connector `stub/live`, sync logs | `IntegrationsController`, `StravaOAuthService`, `GarminConnectorService`, `integrations/garmin-connector`, `integration_accounts`, `integration_sync_runs` |
| Testy | PHPUnit feature/unit dla core, kontraktow, AI, integracji | `backend-php/tests` |

## Backend gotowy, UX jeszcze niepelny

Te endpointy istnieja w Laravelze, ale frontend nie pokazuje jeszcze pelnego UX/decision trace. To jest swiadomy dlug M3/M4 UX, nie luka migracji:

- `/api/training-feedback`
- `/api/training-signals`
- `/api/training-context`
- `/api/training-adjustments`
- `/api/training-feedback-v2/*`
- `/api/workouts/{id}/signals`
- `/api/workouts/{id}/compliance`
- `/api/workouts/{id}/compliance-v2`
- `/api/workouts/{id}/alerts-v1`

## Sposoby integracji z aplikacjami sportowymi

| Zrodlo | Status dla MarcinCoach | Sposob integracji | Dane / zakres | Ryzyka i uwagi | Zrodlo |
|---|---|---|---|---|---|
| Upload plikow | Obowiazkowy fallback | import plikow FIT/TCX/GPX; obecnie TCX jest wdrozony, FIT/GPX planowane | treningi, czas, dystans, HR, tempo, docelowo cadence/power/elevation | niezalezny od API dostawcow; wymaga parserow i cleaning rules | lokalny kontrakt `POST /api/workouts/upload` i `POST /api/workouts/import` |
| Strava | Na start / czesciowo wdrozone | oficjalne OAuth2, token exchange, refresh token, sync aktywnosci | zakresy `activity:read`, `activity:read_all`; aktywnosci uzytkownika | wymagane pilnowanie scope, prywatnosci i zasad Stravy | https://developers.strava.com/docs/authentication/ |
| Garmin | Na start jako wysokie ryzyko / read-only live smoke wykonany | **Kod aktualnie uzywa zewnetrznego connectora** `GARMIN_CONNECTOR_BASE_URL` / `GARMIN_CONNECTOR_API_KEY`, opartego na `python-garminconnect==0.3.3`, z trybem `stub/live`; oficjalna alternatywa to Garmin Connect Developer Program / Activity API dla approved business developers | Connector zwraca znormalizowane aktywnosci do importu i ma endpoint download FIT/TCX/GPX/KML/CSV; smoke IQHost pobral 9 aktywnosci z ostatnich 30 dni i TCX jednej aktywnosci | To nadal nieoficjalna sciezka z ryzykiem auth/MFA/rate limit/regulaminu; upload treningow i Garmin Calendar sa poza obecnym zakresem. | https://developer.garmin.com/gc-developer-program/activity-api/ |
| Polar | Kolejny etap | oficjalne AccessLink API v3, OAuth2, register user, pull notifications/webhooki | exercises, FIT, TCX, GPX, daily activity, sleep, HR | wymaga rejestracji klienta i obslugi rate limitow oraz webhook signature | https://www.polar.com/accesslink-api/ |
| Suunto | Kolejny etap po akceptacji | oficjalne Suunto API Zone / partner program | transfer danych treningowych z Suunto App do aplikacji | wymagana akceptacja partner program; nie blokuje MVP | https://apizone.suunto.com/apis |
| Apple Watch / Apple Health | Nie jako backend cloud API w MVP | HealthKit przez aplikacje iOS/watchOS z lokalna zgoda uzytkownika; alternatywnie eksport plikow lub sync przez Strava | dane HealthKit i workouty na urzadzeniu Apple | brak prostego backendowego logowania do Apple Health; osobny produkt iOS | https://developer.apple.com/documentation/healthkit |
| Wahoo | Opcjonalny pozniejszy kandydat | Wahoo Cloud API, OAuth2, upload/download workout data | dane uzytkownika i treningi w chmurze Wahoo | mniej pilne niz Strava/Garmin/Polar/Suunto | https://developers.wahooligan.com/ |

## Rozbieznosci dokumentacji vs aktualny kod

| Temat | Starszy opis w docs | Aktualny stan |
|---|---|---|
| AI poza PHP | ADR 0002 i czesc checklist mowia, ze AI zostaje na Node w Phase 1 | Laravel ma `/api/ai/plan`, `/api/ai/insights`, `/api/training-feedback-v2/ai/answer`, testy `AiPlanTest`, `AiInsightsTest`. |
| Integracje poza PHP | ADR 0002 zakladal Strava/Garmin poza PHP | Laravel ma `/api/integrations/strava/*`, `/api/integrations/garmin/*` oraz `IntegrationsParityTest`. |
| Garmin official vs connector | Dokumenty produktowe opisuja oficjalny Garmin Activity API jako sciezke docelowa | Aktualny kod Laravel nie laczy sie bezposrednio z Garmin Activity API; wolane sa endpointy zewnetrznego adaptera `integrations/garmin-connector` opartego na `python-garminconnect`. Connector ma tryb live i przeszedl read-only smoke na IQHost, ale nadal wymaga swiadomej akceptacji ryzyka nieoficjalnego adaptera. |
| Node jako glowna sciezka UI | archiwalny `docs/archive/technical-project-status-report.md` opisuje Node jako aktywna sciezke UI | Aktualny front produkcyjny wskazuje `https://api.coach.host89998.iqhs.pl/api`, a PHP core ma pelne endpointy. |
| `Production switched: jeszcze nie` | Raporty etapowe z root opisują stan przed produkcyjnym wdrozeniem | 2026-04-26 front i API na IQHost odpowiadaja 200; migracje produkcyjne profilu i core sa wykonane. |
| Poprzedni formularz onboardingowy | Plan aktualny wskazywal dawny formularz jako referencje | Formularz zostal usuniety i nie jest aktywnym zrodlem. |

## Aktywne dokumenty

| Plik | Klasyfikacja | Jak uzywac |
|---|---|---|
| `docs/status.md` | aktywne zrodlo prawdy | Stan wykonanych funkcji, technologii, walidacji i miejsce dopisywania kolejnych zrealizowanych funkcjonalnosci. |
| `docs/roadmap.md` | aktywny plan | Co robimy dalej. Po wykonaniu funkcji przeniesc jej status do `docs/status.md`. |
| `docs/deploy/frontend-iqhost-deploy.txt` | aktualne runbook/deploy | Obowiazujacy workflow frontu na IQHost. |
| `docs/integrations.md` | aktywny status integracji | Strava, Garmin, Polar, Suunto, Apple Health i fallback plikow. |

Wszystkie pozostale dokumenty `.md` / `.txt` z poprzedniego katalogu `docs/` oraz dawne raporty z root repo sa w `docs/archive/` i maja status historyczny.

## Aktualna kolejnosc dalszych prac

1. Smoke produkcji core flow:
   - register/login/profile,
   - import/upload treningu,
   - training signals/context/adjustments,
   - weekly plan,
   - onboarding skip i normalny zapis profilu.
2. M3/M4 hardening UX:
   - ekspozycja `blockContext`,
   - widoczne alerty i decision trace,
   - scenariusze reczne: powrot po przerwie, load spike, taper, chroniczne niedowykonanie.
3. M2 deeper data:
   - FIT/GPX,
   - moving time,
   - cadence, power, elevation,
   - pace-zones per user.
4. M5/M6:
   - produkcyjne credentials i smoke Strava,
   - Garmin: utrzymac read-only MVP connectora, dodac monitoring/rate-limit handling i dopiero pozniej rozwazyc upload planow,
   - Polar/Suunto,
   - AI provider hardening, rate limit, cache, observability.

## Walidacja wykonana dla raportu

| Check | Wynik |
|---|---|
| Lokalny backend test suite | `php artisan test` -> `260 passed, 1240 assertions` |
| Garmin connector stub smoke | FastAPI `TestClient` -> `connect/start`, `sync`, `status` HTTP 200 |
| Garmin connector live smoke IQHost | `connect/start` -> HTTP 200; `sync` ostatnich 30 dni -> 9 aktywnosci; `status` -> `connected=true`; download TCX jednej aktywnosci -> HTTP 200, 464313 B; `GARMIN_MFA_CODE` nie jest trzymany po logowaniu |
| Frontend build | `npm run build` -> OK |
| Lokalna lista tras API | `php artisan route:list --path=api` -> 43 trasy |
| Produkcyjna lista tras API | Historycznie: SSH IQHost `php artisan route:list --path=api` -> 42 trasy; do ponowienia przy kolejnym smoke produkcyjnym. |
| Produkcyjne migracje | SSH IQHost `php artisan migrate:status` -> wszystkie wymagane migracje `Ran` |
| Front produkcyjny | `https://coach.host89998.iqhs.pl` -> HTTP 200 |
| API produkcyjne | `https://api.coach.host89998.iqhs.pl/api/health` -> HTTP 200 |
