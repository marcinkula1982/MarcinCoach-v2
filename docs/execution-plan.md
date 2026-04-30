# MarcinCoach v2 - execution plan

Status dokumentu: aktywna lista wykonawcza.
Stan: 2026-04-30.

Ten plik odpowiada na pytanie: **co robimy po kolei**.

Nie zastępuje:
- `docs/user-scenarios/coverage-matrix.md` - mapa pokrycia scenariuszy,
- `docs/user-scenarios/gaps-and-next-steps.md` - analiza luk i uzasadnienie kolejności,
- `docs/status.md` - dziennik faktycznie zrealizowanych funkcjonalności,
- `docs/roadmap.md` - strategiczny kierunek produktu.

## Zasady pracy

1. Każdy task ma stabilne ID `EP-XXX`.
2. Sekcja `NOW` ma maksymalnie 5 tasków naraz.
3. Po wykonaniu taska:
   - oznacz go jako wykonany albo przenieś do `DONE`,
   - dopisz wpis w `docs/status.md`,
   - jeśli task zmienia status scenariusza, zaktualizuj scenariusz i `coverage-matrix.md`,
   - zapisz walidację: test, smoke, build albo jawnie "nie uruchomiono".
4. Nie zaczynać integracji przyszłościowych przed zamknięciem P0 dla MVP.
5. RODO jest P0 dla publicznego launchu, ale nie musi blokować zamkniętej bety wśród znajomych/testerów.

## Jak czytać priorytety

- `P0-MVP` - potrzebne, żeby zamknąć działającą pętlę MVP.
- `P0-PUBLIC` - potrzebne przed publicznym launchem.
- `P1` - ważne zaraz po MVP albo wzmacnia UX/stabilność.
- `P2` - później, spike albo przyszła integracja.

## NOW

| Kolejność | ID | Pri | Task | Scenariusze | Definition of done | Walidacja |
|---:|---|---|---|---|---|---|
| - | - | - | Brak aktywnego taska w NOW | - | - | - |

## NEXT

Po zamknięciu aktualnego `NOW` przenieś tutaj kolejny pakiet z sekcji `LATER`.

## LATER - profil, integracje i jakość danych

| Kolejność | ID | Pri | Task | Scenariusze | Definition of done |
|---:|---|---|---|---|---|
| 16 | EP-016 | P1 | Strava webhook dla nowych aktywności | US-STRAVA-003 | Nowy trening ze Stravy pojawia się automatycznie po webhooku |
| 17 | EP-017 | P1 | Garmin MFA UI i on-demand sync | US-GARMIN-002, US-GARMIN-004 | User z MFA przechodzi connect; po kliknięciu "Sprawdź nowe treningi" działa sync |
| 18 | EP-018 | P1 | Upload GPX/FIT w UI | US-IMPORT-002 | Front eksponuje GPX/FIT, backendowy parser jest użyty, błędy są czytelne |
| 19 | EP-019 | P1 | Korekta klasyfikacji aktywności | US-IMPORT-006 | User może poprawić sport/subtype po imporcie, plan liczy wpływ po korekcie |
| 20 | EP-020 | P1 | Sanity checks i dzisiaj vs historia | US-IMPORT-008, US-IMPORT-011 | Pipeline rozróżnia trening dzisiejszy/historyczny i łapie oczywiste błędy tempa |
| 21 | EP-021 | P1 | Realny fixture `.fit` i decyzja raw FIT/GPX | US-IMPORT-002 | Jest test na prawdziwym FIT i zapisana decyzja storage raw |

## LATER - public launch, analiza i polerka

| Kolejność | ID | Pri | Task | Scenariusze | Definition of done |
|---:|---|---|---|---|---|
| 22 | EP-022 | P0-PUBLIC | Dokumenty prawne: polityka prywatności i regulamin | US-PRIVACY-001 | Dokumenty są dostępne online i wersjonowane |
| 23 | EP-023 | P0-PUBLIC | Zgody przy rejestracji + audit log | US-PRIVACY-001, US-PRIVACY-007 | Rejestracja wymaga zgód, backend zapisuje wersję i timestamp |
| 24 | EP-024 | P0-PUBLIC | Disclaimer medyczny w UI | US-PRIVACY-005, US-PLAN-016 | Miejsca z bólem/kontuzją jasno mówią, że aplikacja nie zastępuje porady medycznej |
| 25 | EP-025 | P0-PUBLIC | Export danych | US-PRIVACY-003 | User może pobrać swoje dane w sensownym formacie |
| 26 | EP-026 | P0-PUBLIC | Usunięcie konta i danych | US-PRIVACY-004 | User może usunąć konto; retencja i backup policy są jawne |
| 27 | EP-027 | P1 | Strefy HR/pace z danych | US-ANALYSIS-004, US-ANALYSIS-005 | User z wystarczającą historią widzi propozycje stref i może je zaakceptować |
| 28 | EP-028 | P1 | Trend formy, powrót po przerwie i drift UX | US-ANALYSIS-007, US-PLAN-010, US-PLAN-015, US-PLAN-017 | UI empatycznie pokazuje progres, przerwę, pominięcie kluczowego treningu i korektę planu |
| 29 | EP-029 | P1 | Mobile audit i empty states | US-ONBOARD-008, US-IMPORT-016 | App jest używalna na 360px, bez poziomego scrolla i crashy na pustych danych |
| 30 | EP-030 | P1 | Error boundary i monitoring błędów frontu | US-IMPORT-016 | Błędy UI nie kończą się białym ekranem; jest logowanie błędów |

## FUTURE

| Kolejność | ID | Pri | Task | Scenariusze | Definition of done |
|---:|---|---|---|---|---|
| 31 | EP-031 | P2 | Polar AccessLink integration | US-POLAR-002 | OAuth/sync/webhook przez oficjalne API |
| 32 | EP-032 | P2 | Suunto API Zone (oficjalna docelowa integracja) | US-SUUNTO-001 | Integracja po akceptacji partner programu; tymczasowy Sports Tracker bridge nie zastepuje oficjalnego API |
| 33 | EP-033 | P2 | Coros partner/API access | US-COROS-002 | Tylko oficjalny partner/API access; do tego czasu FIT/TCX/GPX fallback |
| 34 | EP-034 | P2 | Garmin Event Dashboard spike | US-GARMIN-EVENT-001 | Decyzja: stabilne / kruche / niedostępne |
| 35 | EP-035 | P2 | Upload ZIP | US-IMPORT-015 | Power-user import wielu plików naraz |

## DONE - baseline przed tym planem

| Data | ID | Obszar | Co jest domknięte | Dowód |
|---|---|---|---|---|
| 2026-04-26 | DONE-001 | Produkcja | Front i backend odpowiadają na IQHost | `https://coach.host89998.iqhs.pl`, `/api/health` |
| 2026-04-26 | DONE-002 | Auth | Login, logout i sesja działają bazowo | `docs/status.md`, testy backendu |
| 2026-04-27 | DONE-003 | Onboarding MVP | 2-fazowy onboarding data-first ze skipem | `docs/status.md`, manual smoke skip |
| 2026-04-27 | DONE-004 | Upload | TCX działa, GPX/FIT mają backendowy parser MVP | `docs/status.md`, testy TCX/GPX |
| 2026-04-26 | DONE-005 | Garmin | Sync 30 dni i wysyłka workoutu do Garmin po live smoke | `workoutId=1548384239` |
| 2026-04-27 | DONE-006 | Plan | Rolling plan 14 dni działa backendowo i renderuje się w UI | `docs/status.md`, backend suite |
| 2026-04-27 | DONE-007 | Feedback backend | Endpointy `GET/POST /api/workouts/{id}/feedback` istnieją i są deterministyczne | `docs/status.md`, backend tests |
| 2026-04-27 | DONE-008 | Cross-training | Backend liczy wpływ aktywności niebiegowych i cross-training fatigue | `docs/status.md`, focused suite |

## DONE - taski z execution plan

| Data | ID | Task | Scenariusze | Walidacja / dowód |
|---|---|---|---|---|
| 2026-04-30 | EP-036 | Tymczasowy Suunto Sports Tracker test bridge | US-SUUNTO-002 | `POST /api/integrations/suunto/sports-tracker/sync` importuje GPX/FIT z Sports Tracker przez transient `sessionToken`, zapisuje `source=SUUNTO`, loguje `suunto_sports_tracker` i nie utrwala tokena; `php artisan test tests\Feature\Api\SuuntoSportsTrackerIntegrationTest.php` -> 2 passed, 31 assertions; focused integrations suite -> 5 passed, 59 assertions; `php artisan test` -> 327 passed, 1753 assertions; deploy nieuruchomiony |
| 2026-04-29 | EP-000 | Utworzono `docs/execution-plan.md` i spięto zasadę aktualizacji tasków z `AGENTS.md` | n/d | Dokument dodany; brak testów kodu, zmiana dokumentacyjna |
| 2026-04-29 | EP-001 | App shell i zakładki bazowe: Dashboard / Plan / Historia / Profil / Ustawienia | US-ONBOARD-007, US-INTEGRATION-001, US-RACE-001 | `npm run build` -> OK; manual smoke w przeglądarce nieuruchomiony |
| 2026-04-29 | EP-002 | Rejestracja w UI + redirect do first-run onboardingu | US-ONBOARD-001 | `npm run build` -> OK; `php artisan test tests\Feature\Api\AuthAndProfileTest.php` -> 24 passed; manual smoke register -> onboarding nieuruchomiony |
| 2026-04-29 | EP-003 | Resumable onboarding: CTA "Dokończ onboarding" / "Uzupełnij dane" | US-ONBOARD-007 | `npm run build` -> OK; CTA dodane na Dashboardzie i Profilu; manual e2e register -> skip -> CTA -> submit nieuruchomiony |
| 2026-04-29 | EP-004 | Globalny handler 401/session expired | US-AUTH-006, US-AUTH-007 | `npm run build` -> OK; axios interceptor czyści sesję po 401/403 z dowolnego endpointu i pokazuje komunikat przy logowaniu; manual 401/upload smoke nieuruchomiony |
| 2026-04-29 | EP-005 | Reset hasła i mailer SMTP | US-AUTH-009 | `php artisan test tests\Feature\Api\AuthAndProfileTest.php` -> 28 passed; `npm run build` -> OK; SMTP produkcyjny/manual smoke nieuruchomiony |
| 2026-04-29 | EP-006 | UX feedbacku po treningu | US-PLAN-005, US-PLAN-006, US-PLAN-007, US-PLAN-008, US-PLAN-009 | `php artisan test tests\Feature\Api\WorkoutsTest.php --filter=workout_feedback` -> 1 passed, 32 assertions; `php artisan test tests\Feature\Api\TrainingFeedbackV2Test.php` -> 2 passed, 22 assertions; `npm run build` -> OK; manual smoke import -> feedback nieuruchomiony |
| 2026-04-29 | EP-007 | Model/API manual check-in bez pliku | US-MANUAL-002, US-MANUAL-003, US-MANUAL-005, US-MANUAL-006 | `php artisan test tests\Feature\Api\ManualCheckInTest.php tests\Unit\ManualCheckInServiceTest.php` -> 6 passed, 57 assertions; `php artisan test` -> 324 passed, 1671 assertions; deploy nieuruchomiony |
| 2026-04-29 | EP-008 | UI manual check-in: "Wykonane", "Zmienione", "Nie zrobiłem" | US-MANUAL-002, US-MANUAL-003, US-MANUAL-004, US-MANUAL-005, US-MANUAL-006 | `npm run build` -> OK; `php artisan test tests\Feature\Api\ManualCheckInTest.php tests\Unit\ManualCheckInServiceTest.php` -> 6 passed, 57 assertions; manual smoke w przeglądarce bez pliku nieuruchomiony; deploy nieuruchomiony |
| 2026-04-29 | EP-009 | Auto-refresh rolling planu po imporcie/check-inie | US-PLAN-004, US-PLAN-018 | `npm run build` -> OK; po uploadzie TCX, duplikacie uploadu, Garmin sync i manual check-inie frontend podbija token planu, a `WeeklyPlanSection` ponownie pobiera `GET /api/rolling-plan?days=14`; manual smoke import/check-in -> plan nieuruchomiony; deploy nieuruchomiony |
| 2026-04-30 | EP-010 | Smoke E2E pełnej pętli MVP ścieżką API/manual check-in bez pliku | US-AUTH-011, US-AUTH-012, US-PLAN-018 | `php artisan test tests\Feature\Api\MvpSmokeTest.php` -> 1 passed, 51 assertions; workflow: health -> register -> login -> `/me` -> profile -> rolling plan -> manual check-in `done` bez pliku -> workout -> feedback generate/get -> rolling plan; `php artisan test` -> 325 passed, 1722 assertions; `npm run build` -> OK; smoke produkcyjny/browser nieuruchomiony; deploy nieuruchomiony |
| 2026-04-30 | EP-011 | Zakładka Profil jako realny widok edycji danych | US-ONBOARD-007, US-PRIVACY-008 | `ProfileEditSection.tsx` zastępuje placeholder: edycja goals, dni treningowych, maxSessionMin, surface, health, equipment; partial update JSON merge nie kasuje istniejących kluczy, a listy typu `runningDays` są zastępowane w całości; `php artisan test tests\Feature\Api\AuthAndProfileTest.php --filter=partial_json_sections` -> 1 passed, 8 assertions; `php artisan test` -> 332 passed, 1784 assertions; `npm run build` -> OK; manual smoke profilu nieuruchomiony; deploy nieuruchomiony |
| 2026-04-30 | EP-012 | Pełny formularz startów/races w Profilu | US-RACE-001, US-RACE-002, US-RACE-003 | `RacesManager` w `ProfileEditSection.tsx`: dodaj/edytuj/usuń start z nazwą, datą, dystansem (preset + custom), priorytetem A/B/C i targetTime; zapis przez `PUT /api/me/profile` z pełną tablicą races; `php artisan test` -> 332 passed, 1784 assertions; `npm run build` -> OK; manual smoke CRUD nieuruchomiony; deploy nieuruchomiony |
| 2026-04-30 | EP-013 | Profile Quality Score widoczny i użyteczny | US-ANALYSIS-008 | `ProfileQualityScore` w `ProfileEditSection.tsx`: score/100, pasek postępu, lista brakujących elementów z czytelnym opisem co uzupełnić; dane z `quality.breakdown` backendu; `php artisan test` -> 332 passed, 1784 assertions; `npm run build` -> OK; manual smoke nieuruchomiony; deploy nieuruchomiony |
| 2026-04-30 | EP-014 | Globalny widok integracji w Ustawieniach | US-INTEGRATION-001, US-GARMIN-007, US-PRIVACY-002 | Dodano `GET /api/integrations/status` i `DELETE /api/integrations/{provider}` (usuwa `integration_accounts`); `IntegrationsSettingsSection.tsx` pokazuje Garmin/Strava/Polar/Suunto/Coros z real statusem, last sync, przyciskami sync/connect/disconnect, fallback info dla przyszłych integracji; `php artisan test tests\Feature\Api\IntegrationsParityTest.php` -> 7 passed, 51 assertions; `php artisan test` -> 332 passed, 1784 assertions; `npm run build` -> OK; UI smoke nieuruchomiony; deploy nieuruchomiony |
| 2026-04-30 | EP-015 | Strava produkcyjny smoke + credentials | US-STRAVA-001, US-STRAVA-002, US-STRAVA-005 | Status taska: done jako smoke prep. Uszczelniono produkcyjny flow Strava: browser callback bez headerów sesji, powrót frontu `?integration=strava&status=connected`, sync historii 30 dni i refresh wygasłego tokena. Dowód: `php artisan test tests\Feature\Api\IntegrationsParityTest.php` -> 9 passed, 56 assertions; `php artisan test` -> 334 passed, 1789 assertions; `npm run build` -> OK. Produkcyjny live smoke pozostaje nieuruchomiony do czasu ustawienia credentials Stravy na IQHost. |
