# MarcinCoach v2 - raport zbiorczy statusu, technologii i integracji

Data raportu: 2026-04-26  
Status dokumentu: aktualne zrodlo prawdy dla AI i dalszych prac

Ten raport konsoliduje rozproszone dokumenty z `docs/`, raporty etapowe `marcincoach_status_po_*.md` oraz plan `m3_m4_beyond_current_scope.plan.md`. Starsze dokumenty pozostaja w repo jako historia decyzji, ale przy rozbieznosci pierwszenstwo ma ten raport oraz aktualny kod.

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
| D1 - backend PHP operacyjny | WYKONANE | lokalnie i na IQHost `php artisan route:list --path=api` pokazuje 42 trasy; produkcyjne migracje maja status `Ran`; lokalny suite: `259 passed, 1237 assertions` | D1 nie jest juz tylko lokalny. Core PHP API jest wdrozone i pokryte testami. |
| Onboarding MVP | WYKONANE | `src/components/Onboarding.tsx`, `src/api/profile.ts`, testy `AuthAndProfileTest`; UI skip test przeszedl | Aktualny model: data-first + minimalne pytania + skip. |
| M1 - profil uzytkownika beyond minimum | WYKONANE | `ProfileQualityScoreService`, `UserProfileService`, migracje profilu, testy profilu | Profil ma typed JSON sections, projekcje `primaryRace`, `maxSessionMin`, `hasCurrentPain`, `hasHrSensor`, score jakosci. |
| M2 - quality data beyond minimum | WYKONANE | `TcxParsingService`, `WorkoutSummaryBuilder`, `TrainingSignalsService`, testy TCX i workouts parity | TCX wzbogacony o sport, HR, pace, intensity buckets; FIT/GPX jeszcze poza zakresem. |
| M3 - weekly planning enhancement | WYKONANE | `WeeklyPlanService`, `BlockPeriodizationService`, `PlanMemoryService`, `ContractFreezeTest`, `PlanningParityTest` | Plan ma `blockContext`, role tygodnia, struktury sesji i zapis `training_weeks`. |
| M4 - deeper adaptation / alerting | WYKONANE | `TrainingAdjustmentsService`, `TrainingAlertsV1Service`, testy unit/feature alertow i adjustmentow | Adaptacje maja typy, confidence i reguly trendowe; publiczny kontrakt ukrywa pola debugowe. |
| M3/M4 hardening UX | CZESCIOWO | backend gotowy, ale UX nie pokazuje jeszcze pelnego trace decyzji | Do dopracowania: ekspozycja blokow, alertow, uzasadnien i scenariusze manual smoke. |
| M2 deeper data | PLANOWANE | brak FIT/GPX/cadence/power/elevation/pace-zones w aktualnym MVP | Kolejny pakiet po hardeningu M3/M4. |
| M5 - integracje sportowe produkcyjne | CZESCIOWO | trasy Strava/Garmin sa w Laravel; oficjalne Polar/Suunto jeszcze nie | Strava/Garmin maja kontrakty i testy; potrzebny realny credential/config smoke. |
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
| Integracje | Strava OAuth, Garmin connector, sync logs | `IntegrationsController`, `StravaOAuthService`, `GarminConnectorService`, `integration_accounts`, `integration_sync_runs` |
| Testy | PHPUnit feature/unit dla core, kontraktow, AI, integracji | `backend-php/tests` |

## Sposoby integracji z aplikacjami sportowymi

| Zrodlo | Status dla MarcinCoach | Sposob integracji | Dane / zakres | Ryzyka i uwagi | Zrodlo |
|---|---|---|---|---|---|
| Upload plikow | Obowiazkowy fallback | import plikow FIT/TCX/GPX; obecnie TCX jest wdrozony, FIT/GPX planowane | treningi, czas, dystans, HR, tempo, docelowo cadence/power/elevation | niezalezny od API dostawcow; wymaga parserow i cleaning rules | lokalny kontrakt `POST /api/workouts/upload` i `POST /api/workouts/import` |
| Strava | Na start / czesciowo wdrozone | oficjalne OAuth2, token exchange, refresh token, sync aktywnosci | zakresy `activity:read`, `activity:read_all`; aktywnosci uzytkownika | wymagane pilnowanie scope, prywatnosci i zasad Stravy | https://developers.strava.com/docs/authentication/ |
| Garmin | Na start jako wysokie ryzyko / czesciowo wdrozone | oficjalnie Garmin Connect Developer Program / Activity API dla approved business developers; MVP moze uzywac zewnetrznego connectora-adaptera | Activity API daje dostep do FIT, GPX, TCX i activity details | oficjalny dostep wymaga akceptacji; nieoficjalny connector moze pekac przy zmianach Garmin/MFA | https://developer.garmin.com/gc-developer-program/activity-api/ |
| Polar | Kolejny etap | oficjalne AccessLink API v3, OAuth2, register user, pull notifications/webhooki | exercises, FIT, TCX, GPX, daily activity, sleep, HR | wymaga rejestracji klienta i obslugi rate limitow oraz webhook signature | https://www.polar.com/accesslink-api/ |
| Suunto | Kolejny etap po akceptacji | oficjalne Suunto API Zone / partner program | transfer danych treningowych z Suunto App do aplikacji | wymagana akceptacja partner program; nie blokuje MVP | https://apizone.suunto.com/apis |
| Apple Watch / Apple Health | Nie jako backend cloud API w MVP | HealthKit przez aplikacje iOS/watchOS z lokalna zgoda uzytkownika; alternatywnie eksport plikow lub sync przez Strava | dane HealthKit i workouty na urzadzeniu Apple | brak prostego backendowego logowania do Apple Health; osobny produkt iOS | https://developer.apple.com/documentation/healthkit |
| Wahoo | Opcjonalny pozniejszy kandydat | Wahoo Cloud API, OAuth2, upload/download workout data | dane uzytkownika i treningi w chmurze Wahoo | mniej pilne niz Strava/Garmin/Polar/Suunto | https://developers.wahooligan.com/ |

## Rozbieznosci dokumentacji vs aktualny kod

| Temat | Starszy opis w docs | Aktualny stan |
|---|---|---|
| AI poza PHP | ADR 0002 i czesc checklist mowia, ze AI zostaje na Node w Phase 1 | Laravel ma `/api/ai/plan`, `/api/ai/insights`, `/api/training-feedback-v2/ai/answer`, testy `AiPlanTest`, `AiInsightsTest`. |
| Integracje poza PHP | ADR 0002 zakladal Strava/Garmin poza PHP | Laravel ma `/api/integrations/strava/*`, `/api/integrations/garmin/*` oraz `IntegrationsParityTest`. |
| Node jako glowna sciezka UI | `technical-project-status-report.md` opisuje Node jako aktywna sciezke UI | Aktualny front produkcyjny wskazuje `https://api.coach.host89998.iqhs.pl/api`, a PHP core ma pelne endpointy. |
| `Production switched: jeszcze nie` | Raporty etapowe z root opisują stan przed produkcyjnym wdrozeniem | 2026-04-26 front i API na IQHost odpowiadaja 200; migracje produkcyjne profilu i core sa wykonane. |
| Poprzedni formularz onboardingowy | Plan aktualny wskazywal dawny formularz jako referencje | Formularz zostal usuniety i nie jest aktywnym zrodlem. |

## Mapa dokumentow

| Plik | Klasyfikacja | Jak uzywac |
|---|---|---|
| `docs/raport-zbiorczy-status-technologia-2026-04-26.md` | aktualne zrodlo prawdy | Pierwszy dokument do czytania przez AI i implementatora. |
| `docs/plan_aktualny_2026_04_26.md` | aktualne, ale pomocnicze | Plan roboczy; przy rozbieznosci sprawdzic ten raport i kod. |
| `docs/deploy/frontend-iqhost-deploy.txt` | aktualne runbook/deploy | Obowiazujacy workflow frontu na IQHost. |
| `docs/deploy/frontend-subdomain.md` | runbook/historyczne | Pomocniczy runbook subdomeny i hooka. |
| `docs/adr/0001-ingest-single-source-of-truth.md` | ADR aktualny | Zasada jednego punktu prawdy ingestu. |
| `docs/adr/0002-cutover-scope-php-core-node-ai.md` | ADR czesciowo nieaktualny | Historycznie wazny, ale AI/integracje sa juz czesciowo w PHP. |
| `docs/adr/0003-m3-m4-contract-closure-pre-d0.md` | ADR aktualny | Guardrail kontraktu M3/M4. |
| `docs/architecture/scope-notes-m2-beyond.md` | aktualne/historyczne | Wyjasnia zaakceptowany drift M2. |
| `docs/audit/m1-m4-pre-cutover-audit.md` | historyczne, nadal wartosciowe | Audyt pre-cutover; liczby testow sa starsze. |
| `docs/laravel-mvp-implementation-backlog.md` | backlog historyczny, zrealizowany | Uzywac jako dowod wymagan D1, nie jako lista rzeczy brakujacych. |
| `docs/node-php-migration-gap-audit.md` | czesciowo nieaktualne | Wiele luk zostalo domknietych; traktowac jako historyczny gap audit. |
| `docs/node-to-php-migration-map.md` | historyczne/reference | Referencja migracji z Node i modelu obciazenia. |
| `docs/technical-project-status-report.md` | czesciowo nieaktualne | Starszy obraz dual-backend; nie traktowac jako aktualny status produkcji. |
| `docs/php-only-cutover-checklist.md` | runbook czesciowo historyczny | Przydatny do smoke, ale zakres AI/integracji wymaga aktualizacji. |
| `docs/operations/cutover-roles-and-owners.md` | runbook | Role i odpowiedzialnosci. |
| `docs/operations/node-decommission-plan.md` | runbook przyszly | Uzyc dopiero po decyzji o decommission Node. |
| `docs/operations/php-cutover-monitoring.md` | runbook | Monitoring po cutover/smoke. |
| `docs/runbooks/php-only-rollback-runbook.md` | runbook | Procedura rollback. |
| `docs/integracje_zrodla.txt` | pomocnicze, czesciowo dubluje | Skrot wnioskow o integracjach. |
| `docs/marcincoach-integracje-zrodla-treningow.txt` | aktualne produktowo | Pelniejsze decyzje o integracjach; uzupelnione tym raportem o stan kodu. |

## Raporty etapowe spoza `docs/`

| Plik | Klasyfikacja | Wniosek skonsolidowany |
|---|---|---|
| `marcincoach_status_po_p1_p4.md` | historyczne | Wczesne domkniecie decyzji migracyjnych i hardeningu auth/session. |
| `marcincoach_status_po_p1_p5.md` | historyczne | Operacyjny cutover pack i przygotowanie PHP-only. |
| `marcincoach_status_po_c0_c1.md` | historyczne | Cold start i contract freeze jako czesc stabilizacji. |
| `marcincoach_status_po_m3.md` | historyczne | M3 weekly planning enhancement wykonany i kontraktowo ustabilizowany. |
| `marcincoach_status_po_m2_beyond_i_m4.md` | historyczne | M2 beyond i M4 wykonane; czesc brakow przeniesiona do beyond/current scope. |
| `marcincoach_status_po_m1_beyond_m2_beyond_m3_m4.md` | historyczne | M1-M4 domkniete dla cutoveru; czesc statusow produkcyjnych nieaktualna po 2026-04-26. |
| `marcincoach_status_po_m1_beyond_m2_beyond_m3_m4_next.md` | historyczne/roadmap | Rekomendacja: M3/M4 hardening przed M2 deeper data. |
| `m3_m4_beyond_current_scope.plan.md` | aktualny roadmap techniczny | Zrodlo szczegolow dla dalszego hardeningu M3/M4. |

## Aktualna kolejnosc dalszych prac

1. Smoke produkcji core flow:
   - register/login/profile,
   - import/upload treningu,
   - training signals/context/adjustments,
   - weekly plan,
   - onboarding skip i normalny zapis profilu.
2. Porzadkowanie dokumentacji:
   - ten raport jako pierwsze zrodlo prawdy,
   - stare raporty etapowe jako historia,
   - aktualizacja lub archiwizacja nieaktualnych cutover docs.
3. M3/M4 hardening UX:
   - ekspozycja `blockContext`,
   - widoczne alerty i decision trace,
   - scenariusze reczne: powrot po przerwie, load spike, taper, chroniczne niedowykonanie.
4. M2 deeper data:
   - FIT/GPX,
   - moving time,
   - cadence, power, elevation,
   - pace-zones per user.
5. M5/M6:
   - produkcyjne credentials i smoke Strava/Garmin,
   - Polar/Suunto,
   - AI provider hardening, rate limit, cache, observability.

## Walidacja wykonana dla raportu

| Check | Wynik |
|---|---|
| Lokalny backend test suite | `php artisan test` -> `259 passed, 1237 assertions` |
| Frontend build | `npm run build` -> OK |
| Lokalna lista tras API | `php artisan route:list --path=api` -> 42 trasy |
| Produkcyjna lista tras API | SSH IQHost `php artisan route:list --path=api` -> 42 trasy |
| Produkcyjne migracje | SSH IQHost `php artisan migrate:status` -> wszystkie wymagane migracje `Ran` |
| Front produkcyjny | `https://coach.host89998.iqhs.pl` -> HTTP 200 |
| API produkcyjne | `https://api.coach.host89998.iqhs.pl/api/health` -> HTTP 200 |
