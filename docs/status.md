# MarcinCoach v2 - status projektu

Data raportu: 2026-04-30
Status dokumentu: aktywne zrodlo prawdy dla wykonanego zakresu, technologii i walidacji.

Ten dokument konsoliduje aktualny stan projektu po migracji na Laravel/PHP. Starsze dokumenty z `docs/` oraz dawne raporty z root repo zostaly przeniesione do `docs/archive/` i nie sa aktualna instrukcja pracy.

## Gdzie dopisywac zrealizowane funkcjonalnosci

Nowe ukonczone funkcjonalnosci dopisujemy tutaj, w `docs/status.md`.

Zasada:
- po zrealizowaniu funkcji dodaj wpis w sekcji `Dziennik zrealizowanych funkcjonalnosci`,
- jesli funkcja zamyka kamien milowy, zaktualizuj tez tabele `Zweryfikowane kamienie milowe`,
- strategiczne planowane prace trzymamy w `docs/roadmap.md`,
- operacyjna kolejnosc prac jest w `docs/execution-plan.md`,
- status integracji sportowych trzymamy w `docs/integrations.md`,
- plikow w `docs/archive/` nie aktualizujemy jako zrodla prawdy.

## Dziennik zrealizowanych funkcjonalnosci

| Data | Obszar | Funkcjonalnosc | Walidacja / dowod |
|---|---|---|---|
| 2026-04-26 | Dokumentacja | Uporzadkowano aktywne dokumenty `docs`: status, roadmap, deploy frontu, integracje | Pozostale pliki przeniesione do `docs/archive/` i oznaczone jako historyczne. |
| 2026-04-26 | Repo cleanup | Usunieto legacy backend Node/Nest z aktywnego repo oraz parity Node-vs-PHP | `git ls-files` dla legacy backendu, `dist` i starych skryptow parity zwraca pusto. |
| 2026-04-26 | Frontend | Usunieto stara widoczna tozsamosc toolkitowa i stare prefiksy kluczy sesji z aktywnego kodu | Aktywny grep dla dawnych nazw produktu i dawnych kluczy sesji zwraca pusto. |
| 2026-04-26 | Backend integracje | Naprawiono `GarminConnectorService.php` i dopuszczono connect Garmina bez body, gdy credentials sa po stronie connectora/env | `php artisan test --filter=IntegrationsParityTest` -> 3 passed; pelny suite -> 260 passed. |
| 2026-04-26 | Garmin workout export | Dodano wysylke zaplanowanych treningow z weekly plan do Garmin Connect i przycisk `Wyslij do urzadzenia` w UI | Live smoke IQHost: `POST /v1/garmin/workouts` -> `scheduled`; test `MarcinCoach TEST 2026-04-26`, `workoutId=1548384239`, potwierdzone na koncie Garmin. |
| 2026-04-27 | Provider-neutral analytics F4 | Dodano `GET /api/me/training-analysis` z cache per user/window/version i snapshotami w `training_analysis_snapshots` | `php artisan test tests\Feature\Api\TrainingAnalysisEndpointTest.php` -> 2 passed; `php artisan test` -> 291 passed. |
| 2026-04-27 | Provider-neutral analytics F5 | Dodano fact-based `GET /api/me/onboarding-summary` oraz panel podsumowania po onboardingu oparty na `training-analysis` | `php artisan test tests\Feature\Api\OnboardingSummaryEndpointTest.php tests\Feature\Api\TrainingAnalysisEndpointTest.php` -> 4 passed; `php artisan test` -> 293 passed; `npm run build` -> OK. |
| 2026-04-27 | Provider-neutral analytics F6 | Przepieto `TrainingContextService` i weekly plan na `UserTrainingAnalysis`; stary `TrainingSignalsService` zostal jako wypelniacz pol kompatybilnosci/adaptacji M4 | Focused analytics/plan/contract suite -> 69 passed; `php artisan test` -> 296 passed, 1426 assertions. |
| 2026-04-27 | Provider-neutral analytics F7 | Przepieto alert `LOAD_SPIKE` i feedback-v2 load risk na `UserTrainingAnalysis`; `/api/training-signals` oznaczone jako kompatybilnosc legacy | Focused alerts/feedback/plan suite -> 60 passed; `php artisan test` -> 297 passed, 1434 assertions. |
| 2026-04-27 | MVP 14-dniowy coach | Dodano backendowy `GET /api/rolling-plan?days=14`, oparty o dwa tygodnie planu, feedback signals, adjustments i plan memory | `php artisan test tests\Feature\Api\PlanningParityTest.php --filter=rolling_plan` -> 1 passed; pelny suite -> 301 passed. |
| 2026-04-27 | Feedback po treningu | Podpieto `GET /api/workouts/{id}/feedback` i `POST /api/workouts/{id}/feedback/generate`; feedback jest deterministyczny i zwraca praise, deviations, conclusions oraz planImpact | `php artisan test tests\Feature\Api\WorkoutsTest.php --filter=workout_feedback` -> 1 passed; pelny suite -> 301 passed. |
| 2026-04-27 | M2 deeper workout data | Rozszerzono import/summary o TCX/GPX/FIT parsing path oraz pola: moving/elapsed time, cadence, power, elevation, paceZones, dataAvailability; GPX i TCX maja testy regresyjne | `php artisan test tests\Feature\Api\WorkoutsTest.php --filter=gpx_upload` -> 1 passed; `php artisan test tests\Unit\TcxParsingServiceTest.php --filter=deeper_trackpoint_metrics` -> 1 passed; pelny suite -> 301 passed. |
| 2026-04-27 | Profil startow i tempa | Profil przyjmuje `races[].name`, `races[].targetTime`, `races[].priority` oraz `paceZones`; `UserProfileService` wystawia `primaryRace` z nazwa i targetTime | `php artisan test tests\Feature\Api\AuthAndProfileTest.php tests\Unit\UserProfileServiceTest.php` w pelnym suite -> OK. |
| 2026-04-27 | Cross-training w rolling planie | Dodano deterministyczny model aktywnosci niebiegowych: normalizacja sport/subtype, `activityImpact`, osobne `runningLoad`/`crossTrainingFatigue`/`overallFatigue`, `POST /api/rolling-plan`, guardy kolizji i modal frontendowy przed odswiezeniem planu | `php artisan test` -> 310 passed, 1529 assertions; `npm run build` -> OK; focused cross-training suite -> 47 passed. |
| 2026-04-27 | Szczegoly treningu w planie | Kazda sesja biegowa w weekplan/rolling plan dostaje `blocks`: rozgrzewka, czesc glowna i schlodzenie/mobilizacja; frontend ma rozwijany podglad szczegolow treningu | `php artisan test` -> 312 passed, 1568 assertions; focused planner/contract suite -> 48 passed; `npm run build` -> OK. |
| 2026-04-27 | Onboarding UX fix | Przycisk Pomin dziala optimistycznie: `onCompleted()` wywolany natychmiast, PUT `/me/profile` idzie w tle; blad API nie blokuje przejscia. Onboarding wyswietlany tylko nowym userom (`onboardingCompleted===false && workouts.length===0`); istniejacy user z treningami omija onboarding automatycznie. | Weryfikacja reczna: klikniecie Pomin przenosi do dashboardu; user z treningami nie widzi onboardingu po zalogowaniu. |
| 2026-04-29 | Zarzadzanie projektem | Dodano `docs/execution-plan.md` jako operacyjna liste `NOW/NEXT/LATER/FUTURE/DONE` i powiazano ja z zasada aktualizacji w `AGENTS.md` | Zmiana dokumentacyjna; testow kodu nie uruchamiano. |
| 2026-04-29 | EP-001 app shell | Dodano bazowa nawigacje zakladkowa w froncie: Dashboard / Plan / Historia / Profil / Ustawienia. Obecny dashboard zostal zachowany, a Plan, Historia, Profil i Ustawienia maja osobne widoki startowe. | `npm run build` -> OK; manual smoke w przegladarce nieuruchomiony. |
| 2026-04-29 | EP-002 rejestracja UI | Dodano przelacznik Logowanie/Zaloz konto, formularz rejestracji, frontendowa walidacje hasla i redirect nowego usera do onboardingu po sukcesie. Front korzysta z istniejacego kontraktu `POST /api/auth/register` (`username`, `password`). | `npm run build` -> OK; `php artisan test tests\Feature\Api\AuthAndProfileTest.php` -> 24 passed; manual smoke register -> onboarding nieuruchomiony. |
| 2026-04-29 | EP-003 resumable onboarding | Dodano CTA powrotu do onboardingu na Dashboardzie i w Profilu dla profilu wymagajacego uzupelnienia lub oznaczonego jako skipped. Onboarding dostaje `initialProfile` i prefilluje zapisane pola profilu. | `npm run build` -> OK; manual e2e register -> skip -> CTA -> submit nieuruchomiony. |
| 2026-04-29 | EP-004 session expired | Ujednolicono obsluge wygaslej sesji we frontendowym axios interceptorze: kazde 401/403 z aktywna sesja czysci token, wylogowuje usera i pokazuje komunikat "Sesja wygasla. Zaloguj sie ponownie." przy formularzu logowania. | `npm run build` -> OK; manual 401/upload smoke nieuruchomiony. |
| 2026-04-29 | EP-005 reset hasla | Dodano flow resetu hasla: `POST /api/auth/forgot-password`, `POST /api/auth/reset-password`, token wazny 1h w `password_reset_tokens`, mail `PasswordResetMail`, link na frontend oraz UI "Nie pamietam hasla" / "Zmien haslo". Rejestracja frontendowa zbiera teraz email do resetu, a backend zachowuje kompatybilnosc z dawnym `username/password`. | `php artisan test tests\Feature\Api\AuthAndProfileTest.php` -> 28 passed, 106 assertions; `npm run build` -> OK; SMTP produkcyjny/manual smoke nieuruchomiony. |
| 2026-04-29 | EP-006 UX feedbacku po treningu | Dodano panel feedbacku po treningu w dashboardzie: po zapisie/imporcie frontend generuje `POST /api/workouts/{id}/feedback/generate`, pokazuje praise, deviations, conclusions, planImpact, confidence i metryki oraz pozwala ponownie odczytac zapisany feedback przez `GET /api/workouts/{id}/feedback`. Backendowa generacja zachowuje idempotentny rekord feedbacku. | `php artisan test tests\Feature\Api\WorkoutsTest.php --filter=workout_feedback` -> 1 passed, 32 assertions; `php artisan test tests\Feature\Api\TrainingFeedbackV2Test.php` -> 2 passed, 22 assertions; `npm run build` -> OK; manual smoke import -> feedback nieuruchomiony. |
| 2026-04-29 | EP-007 manual check-in API | Dodano backendowy model `manual_check_ins` i endpoint `POST /api/workouts/manual-check-in`. Statusy `done`/`modified` tworza lub aktualizuja syntetyczny workout `MANUAL_CHECK_IN` bez `tcxRaw`, `skipped` zamyka dzien bez treningu 0 km, a idempotencja dziala per dzien albo dzien+plannedSessionId. Check-in zapisuje RPE, bol, notatke, powod skipa i modyfikacje planu; low-data feedback nie wymysla HR/tempa gdy ich nie ma. | `php artisan test tests\Feature\Api\ManualCheckInTest.php tests\Unit\ManualCheckInServiceTest.php` -> 6 passed, 57 assertions; `php artisan test` -> 324 passed, 1671 assertions; deploy na IQHost nieuruchomiony. |
| 2026-04-29 | EP-008 manual check-in UI | Dodano frontendowy check-in bez pliku przy sesjach rolling planu: przyciski `Wykonane`, `Zmienione`, `Nie zrobilem`, modal z czasem, dystansem opcjonalnym, RPE, samopoczuciem, bolem i notatka oraz zapis do `POST /api/workouts/manual-check-in`. Po zapisie `done/modified` aplikacja odswieza liste treningow i generuje feedback dla syntetycznego workoutu. | `npm run build` -> OK; `php artisan test tests\Feature\Api\ManualCheckInTest.php tests\Unit\ManualCheckInServiceTest.php` -> 6 passed, 57 assertions; manual smoke w przegladarce bez pliku nieuruchomiony; deploy na IQHost nieuruchomiony. |
| 2026-04-29 | EP-009 auto-refresh rolling planu | Dodano frontendowy token odswiezania planu: po uploadzie TCX, duplikacie uploadu, Garmin sync oraz manual check-inie `WeeklyPlanSection` ponownie pobiera `GET /api/rolling-plan?days=14`, zeby user zobaczyl aktualny rolling plan bez klikania Refresh. | `npm run build` -> OK; manual smoke import/check-in -> plan nieuruchomiony; deploy na IQHost nieuruchomiony. |
| 2026-04-30 | EP-010 smoke MVP API/manual check-in | Dodano lokalny smoke API pelnej petli MVP w `tests\Feature\Api\MvpSmokeTest.php`: health -> register -> login -> `/me` -> profile -> rolling plan -> manual check-in `done` bez pliku -> workout -> feedback generate/get -> rolling plan. Smoke potwierdza tez brak `workout_raw_tcx` dla syntetycznego check-inu i low-data feedback bez tempa. | `php artisan test tests\Feature\Api\MvpSmokeTest.php` -> 1 passed, 51 assertions; `php artisan test` -> 325 passed, 1722 assertions; `npm run build` -> OK; smoke produkcyjny/browser nieuruchomiony; deploy na IQHost nieuruchomiony. |
| 2026-04-30 | EP-011 Profil edycja | Zastąpiono placeholder zakładki Profil realnym formularzem edycji: goals, dni treningowe, maxSessionMin, surface, health (currentPain + disclaimer medyczny), equipment. Partial update — pola brak w requescie nie kasują istniejących danych. | `npm run build` -> OK; `php artisan test` -> 331 passed, 1776 assertions; manual smoke nieuruchomiony. |
| 2026-04-30 | EP-012 Races CRUD | Dodano `RacesManager` w `ProfileEditSection.tsx`: dodaj/edytuj/usuń start z nazwą, datą, dystansem (preset + custom), priorytetem A/B/C i targetTime; zapis przez `PUT /api/me/profile` pełną tablicą races. | `npm run build` -> OK; backend testy profilu (AuthAndProfileTest) niezmienione i przeszły. |
| 2026-04-30 | EP-013 Profile Quality Score | Dodano `ProfileQualityScore` w `ProfileEditSection.tsx`: score X/100, pasek koloru (zielony/żółty/czerwony), lista kategorii do uzupełnienia z czytelnym opisem i hintem. | `npm run build` -> OK; `ProfileQualityScoreService` niezmieniony, testy 331 passed. |
| 2026-04-30 | EP-014 Globalny widok integracji | Dodano `GET /api/integrations/status` i `DELETE /api/integrations/{provider}` w backendzie; `IntegrationsSettingsSection.tsx` pokazuje Garmin/Strava/Polar/Suunto/Coros z real statusem, datą ostatniego sync i przyciskami sync/connect/disconnect; fallback info dla Polar/Suunto/Coros (wkrótce); GarminSection pozostaje na dashboardzie. | `php artisan test tests\Feature\Api\IntegrationsParityTest.php` -> 7 passed, 51 assertions; `php artisan test` -> 331 passed, 1776 assertions; `npm run build` -> OK. |
| 2026-04-30 | Suunto Sports Tracker test bridge | Dodano tymczasowy, nieoficjalny importer Suunto przez Sports Tracker browser session token: `POST /api/integrations/suunto/sports-tracker/sync`, `source=SUUNTO`, log `suunto_sports_tracker`, deduplikacja po `workoutKey`; token jest transient i nie jest zapisywany w bazie. Endpoint domyslnie wymaga `SUUNTO_SPORTS_TRACKER_ENABLED=true`. | `php artisan test tests\Feature\Api\SuuntoSportsTrackerIntegrationTest.php` -> 2 passed, 31 assertions; `php artisan test tests\Feature\Api\IntegrationsParityTest.php tests\Feature\Api\SuuntoSportsTrackerIntegrationTest.php` -> 5 passed, 59 assertions; `php artisan test` -> 327 passed, 1753 assertions; deploy na IQHost nieuruchomiony. |

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
| MVP 14-dniowy coach | CZESCIOWO | `RollingPlanController`, `/api/workouts/{id}/feedback`, `POST /api/workouts/manual-check-in`, `WeeklyPlanSection`, `MvpSmokeTest`, focused tests i pelny backend suite | Rolling plan 14 dni jest glownym widokiem frontendu, obsluguje planowane cross-trainingi, pokazuje bloki treningu, ma UX feedbacku po treningu, UI/API manual check-in bez pliku, auto-refresh planu po imporcie/check-inie oraz lokalny API smoke pelnej petli bez pliku; brakuje jeszcze produkcyjnego/browser smoke. |
| M3/M4 hardening UX | CZESCIOWO | backend gotowy, ale UX nie pokazuje jeszcze pelnego trace decyzji | Do dopracowania: ekspozycja blokow, alertow, uzasadnien i scenariusze manual smoke. |
| M2 deeper data | CZESCIOWO | `TcxParsingService`, `GpxParsingService`, `FitParsingService`, `WorkoutSummaryBuilder`, `WorkoutFactsDto`; testy TCX/GPX przechodza | TCX/GPX maja backendowe parsowanie i summary; FIT parser jest w kodzie, ale nadal potrzebuje testu na realnym pliku `.fit` i decyzji o przechowywaniu raw FIT/GPX. |
| M5 - integracje sportowe produkcyjne | CZESCIOWO | trasy Strava/Garmin/Suunto test bridge sa w Laravel; lokalne testy integracji przechodza; Garmin live smoke potwierdzil sync aktywnosci, download TCX i upload zaplanowanego workoutu; oficjalne Polar/Suunto/Coros jeszcze nie | Strava ma sciezke OAuth. Garmin connector dziala w trybie `live` na `python-garminconnect`; to nie jest oficjalny Garmin Activity API. Suunto ma kontrolowany most testowy przez Sports Tracker session token, domyslnie wylaczony i bez trwalego zapisu tokena. Coros zostaje fallbackiem plikowym do czasu oficjalnego partner/API access. |
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
| Treningi | `workouts`, `manual_check_ins`, `workout_raw_tcx`, `workout_import_events` | `WorkoutsController`, `ManualCheckInController`, `ManualCheckInService`, `ExternalWorkoutImportService` |
| Sygnały | Training signals v1/v2, rolling load, intensity buckets, safety flags | `TrainingSignalsService`, `TrainingSignalsV2Service` |
| Plan | weekly plan + rolling 14 dni, cross-training guards, block context, memory tygodniowa | `WeeklyPlanService`, `RollingPlanController`, `BlockPeriodizationService`, `PlanMemoryService` |
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
| Upload plikow | Obowiazkowy fallback | import plikow TCX/GPX/FIT; TCX i GPX sa backendowo testowane, FIT ma parser MVP bez testu na realnym fixture | treningi, moving/elapsed time, dystans, HR, tempo, cadence, power, elevation, pace zones, dataAvailability | raw TCX jest zapisywany w `workout_raw_tcx`; raw FIT/GPX na razie tylko parsowane do `workouts.summary`, bez osobnej tabeli raw plikow | lokalny kontrakt `POST /api/workouts/upload` i `POST /api/workouts/import` |
| Strava | Na start / czesciowo wdrozone | oficjalne OAuth2, token exchange, refresh token, sync aktywnosci | zakresy `activity:read`, `activity:read_all`; aktywnosci uzytkownika | wymagane pilnowanie scope, prywatnosci i zasad Stravy | https://developers.strava.com/docs/authentication/ |
| Garmin | Na start jako wysokie ryzyko / live smoke wykonany | **Kod aktualnie uzywa zewnetrznego connectora** `GARMIN_CONNECTOR_BASE_URL` / `GARMIN_CONNECTOR_API_KEY`, opartego na `python-garminconnect==0.3.3`, z trybem `stub/live`; oficjalna alternatywa to Garmin Connect Developer Program / Activity API dla approved business developers | Connector zwraca znormalizowane aktywnosci do importu, ma endpoint download FIT/TCX/GPX/KML/CSV oraz endpoint uploadu zaplanowanych workoutow; smoke IQHost pobral 9 aktywnosci z ostatnich 30 dni, TCX jednej aktywnosci i zaplanowal testowy workout `1548384239` w kalendarzu Garmin | To nadal nieoficjalna sciezka z ryzykiem auth/MFA/rate limit/regulaminu; funkcja "wyslij do urzadzenia" jest wdrozona jako MVP. | https://developer.garmin.com/gc-developer-program/activity-api/ |
| Polar | Kolejny etap | oficjalne AccessLink API v3, OAuth2, register user, pull notifications/webhooki | exercises, FIT, TCX, GPX, daily activity, sleep, HR | wymaga rejestracji klienta i obslugi rate limitow oraz webhook signature | https://www.polar.com/accesslink-api/ |
| Suunto | Testowo jako nieoficjalny most, docelowo po akceptacji | tymczasowo Sports Tracker session token -> export FIT/GPX; docelowo oficjalne Suunto API Zone / partner program | treningi z Suunto App/Sports Tracker w FIT/GPX, importowane jako `source=SUUNTO` | nieoficjalny most jest kruchy i domyslnie wylaczony; token nie jest zapisywany; oficjalne API wymaga partner access | https://apizone.suunto.com/ |
| Coros | Przyszly etap / fallback plikowy teraz | brak natywnej integracji w MVP; docelowo tylko oficjalny partner/API access | eksport FIT/TCX/GPX i reczny upload do MarcinCoach | nie reverse-engineerowac; dodac do backlogu integracji i formularza "Powiadom nas" | brak stabilnego publicznego linku API w docs |
| Apple Watch / Apple Health | Nie jako backend cloud API w MVP | HealthKit przez aplikacje iOS/watchOS z lokalna zgoda uzytkownika; alternatywnie eksport plikow lub sync przez Strava | dane HealthKit i workouty na urzadzeniu Apple | brak prostego backendowego logowania do Apple Health; osobny produkt iOS | https://developer.apple.com/documentation/healthkit |
| Wahoo | Opcjonalny pozniejszy kandydat | Wahoo Cloud API, OAuth2, upload/download workout data | dane uzytkownika i treningi w chmurze Wahoo | mniej pilne niz Strava/Garmin/Polar/Suunto/Coros | https://developers.wahooligan.com/ |

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
| `docs/execution-plan.md` | aktywna lista wykonawcza | Operacyjna kolejnosc prac `NOW/NEXT/LATER/FUTURE/DONE`; po tasku aktualizowac plan, status i macierz/scenariusze jesli dotyczy. |
| `docs/deploy/frontend-iqhost-deploy.txt` | aktualne runbook/deploy | Obowiazujacy workflow frontu na IQHost. |
| `docs/integrations.md` | aktywny status integracji | Strava, Garmin, Polar, Suunto, Coros, Apple Health i fallback plikow. |

Wszystkie pozostale dokumenty `.md` / `.txt` z poprzedniego katalogu `docs/` oraz dawne raporty z root repo sa w `docs/archive/` i maja status historyczny.

## Aktualna kolejnosc dalszych prac

Operacyjna kolejnosc dalszych prac jest w `docs/execution-plan.md`.

Ten plik (`docs/status.md`) zostaje dziennikiem wykonanych funkcjonalnosci i walidacji. Nie utrzymujemy tutaj drugiej listy TODO, zeby nie rozjechala sie z execution planem.

## Walidacja wykonana dla raportu

| Check | Wynik |
|---|---|
| Lokalny backend test suite | `php artisan test` -> `327 passed, 1753 assertions` |
| EP-010 lokalny API smoke MVP | `php artisan test tests\Feature\Api\MvpSmokeTest.php` -> `1 passed, 51 assertions`; flow: health -> register/login -> profile -> rolling plan -> manual check-in bez pliku -> feedback -> rolling plan |
| Suunto Sports Tracker test bridge | `php artisan test tests\Feature\Api\SuuntoSportsTrackerIntegrationTest.php` -> `2 passed, 31 assertions`; `php artisan test tests\Feature\Api\IntegrationsParityTest.php tests\Feature\Api\SuuntoSportsTrackerIntegrationTest.php` -> `5 passed, 59 assertions`; `php artisan test` -> `327 passed, 1753 assertions` |
| Focused MVP 14 dni / feedback / deeper data / cross-training | `php artisan test tests\Unit\Analysis\ActivityImpactServiceTest.php tests\Unit\Analysis\WorkoutFactsAggregatorTest.php tests\Unit\Analysis\WorkoutFactsExtractorTest.php tests\Unit\TcxParsingServiceTest.php` -> 34 passed; `php artisan test tests\Feature\Api\PlanningParityTest.php` -> 13 passed |
| Focused weekplan blocks | `php artisan test tests\Unit\WeeklyPlanServiceTest.php tests\Feature\Api\PlanningParityTest.php tests\Feature\Api\ContractFreezeTest.php` -> 48 passed |
| Garmin connector stub smoke | FastAPI `TestClient` -> `connect/start`, `sync`, `status` HTTP 200 |
| Garmin connector live smoke IQHost | `connect/start` -> HTTP 200; `sync` ostatnich 30 dni -> 9 aktywnosci; `status` -> `connected=true`; download TCX jednej aktywnosci -> HTTP 200, 464313 B; `POST /v1/garmin/workouts` -> `scheduled`, `workoutId=1548384239`, kalendarz `2026-04-26`; `GARMIN_MFA_CODE` nie jest trzymany po logowaniu |
| Frontend build | `npm run build` -> OK |
| Lokalna lista tras API | `php artisan route:list --path=api` -> Showing [54] routes |
| Produkcyjna lista tras API | Historycznie: SSH IQHost `php artisan route:list --path=api` -> 42 trasy; do ponowienia przy kolejnym smoke produkcyjnym. |
| Produkcyjne migracje | SSH IQHost `php artisan migrate:status` -> wszystkie wymagane migracje `Ran` |
| Front produkcyjny | `https://coach.host89998.iqhs.pl` -> HTTP 200 |
| API produkcyjne | `https://api.coach.host89998.iqhs.pl/api/health` -> HTTP 200 |
