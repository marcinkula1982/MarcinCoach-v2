# Coverage matrix — co pokrywamy i czym

Stan: 30.04.2026.
Cel: jednym spojrzeniem widać dla każdego scenariusza, czy frontend, backend, test automatyczny i smoke produkcyjny są na miejscu, oraz jaki jest finalny status.

## Jak czytać

Kolumny:
- **ID** — identyfikator scenariusza (link do pliku).
- **Nazwa** — krótki opis.
- **Pri** — priorytet (P0 / P1 / P2).
- **FE** — frontend: czy UI obsługuje ten scenariusz.
- **BE** — backend: czy logika i endpointy są.
- **Test** — czy istnieje test automatyczny (unit/integration/e2e).
- **Smoke** — czy wykonano ostatnio smoke produkcyjny.
- **Status** — finalny status: implemented / partial / missing / unknown.

Wartości w kolumnach FE / BE / Test / Smoke:
- ✓ — jest, działa.
- ~ — jest częściowo lub jest ale nie zweryfikowane na produkcji.
- ✗ — brak.
- ? — nie sprawdzono / nie wiadomo.
- — — nie dotyczy.

## 01 — Onboarding i wybór źródła danych

| ID | Nazwa | Pri | FE | BE | Test | Smoke | Status |
|---|---|---|---|---|---|---|---|
| US-ONBOARD-001 | Rejestracja nowego użytkownika | P0 | ~ | ✓ | ~ | ✗ | partial |
| US-ONBOARD-002 | Login istniejącego użytkownika | P0 | ✓ | ✓ | ~ | ~ | implemented |
| US-ONBOARD-003 | Wizard faza 1 — wybór źródła | P0 | ✓ | — | ? | ~ | implemented |
| US-ONBOARD-004 | Wizard faza 2 — pytania | P0 | ~ | ✓ | ~ | ~ | partial |
| US-ONBOARD-005 | Skip onboardingu | P0 | ✓ | ✓ | ~ | ~ | implemented |
| US-ONBOARD-006 | Powiadom o brakującej aplikacji | P1 | ✗ | ✗ | ✗ | ✗ | missing |
| US-ONBOARD-007 | Powrót do onboardingu z Dashboardu/Profilu | P0 | ✓ | ~ | ✗ | ✗ | partial |
| US-ONBOARD-008 | Onboarding na mobile | P1 | ? | — | ✗ | ✗ | unknown |
| US-ONBOARD-009 | Multi-session login | P1 | ~ | ✓ | ? | ✗ | implemented |
| US-ONBOARD-010 | Manual onboarding bez danych | P0 | ~ | ✓ | ~ | ✗ | partial |

## 02 — Import plików i jakość danych

| ID | Nazwa | Pri | FE | BE | Test | Smoke | Status |
|---|---|---|---|---|---|---|---|
| US-IMPORT-001 | Multi-upload TCX w onboardingu | P0 | ✓ | ✓ | ~ | ~ | implemented |
| US-IMPORT-002 | Upload GPX i FIT z UI | P1 | ✗ | ✓ | ~ | ✗ | partial |
| US-IMPORT-003 | Single upload w dashboardzie | P0 | ✓ | ✓ | ~ | ~ | implemented |
| US-IMPORT-004 | Upload duplikatu | P0 | ✓ | ✓ | ✓ | ✗ | implemented |
| US-IMPORT-005 | Upload pliku z innego sportu | P0 | ~ | ~ | ~ | ✗ | partial |
| US-IMPORT-006 | Korekta klasyfikacji aktywności | P1 | ✗ | ~ | ✗ | ✗ | missing |
| US-IMPORT-007 | Upload z malformed XML | P0 | ~ | ✓ | ✓ | ✗ | implemented |
| US-IMPORT-008 | Sanity check: zbyt wysokie tempo | P1 | ✗ | ✗ | ✗ | ✗ | missing |
| US-IMPORT-009 | Plik bez dystansu | P1 | ? | ? | ✗ | ✗ | unknown |
| US-IMPORT-010 | Plik bez HR | P0 | ~ | ✓ | ✓ | ✗ | implemented |
| US-IMPORT-011 | Trening z dzisiaj vs historyczny | P1 | ✗ | ✗ | ✗ | ✗ | missing |
| US-IMPORT-012 | Upload bardzo dużego pliku | P1 | ? | ? | ✗ | ✗ | unknown |
| US-IMPORT-013 | Upload przerwany przez timeout | P1 | ? | ? | ✗ | ✗ | unknown |
| US-IMPORT-014 | Multi-upload mieszany | P0 | ~ | ✓ | ~ | ✗ | partial |
| US-IMPORT-015 | Upload ZIP | P2 | ✗ | ✗ | ✗ | ✗ | missing |
| US-IMPORT-016 | Obsługa błędów technicznych (zbiorczy) | P1 | ~ | ~ | ~ | ✗ | partial |

## 03 — Analiza i profil

| ID | Nazwa | Pri | FE | BE | Test | Smoke | Status |
|---|---|---|---|---|---|---|---|
| US-ANALYSIS-001 | Analiza po imporcie ≥6 treningów | P0 | ~ | ✓ | ~ | ~ | partial |
| US-ANALYSIS-002 | Niski confidence przy <6 treningach | P0 | ~ | ✓ | ✓ | ✗ | partial |
| US-ANALYSIS-003 | Brak danych po skipie | P0 | ~ | ✓ | ✓ | ~ | partial |
| US-ANALYSIS-004 | Propozycja stref HR | P1 | ✗ | ~ | ✗ | ✗ | missing |
| US-ANALYSIS-005 | Propozycja stref pace | P1 | ✗ | ✗ | ✗ | ✗ | missing |
| US-ANALYSIS-006 | Wyświetlenie historii treningów | P0 | ✓ | ✓ | ~ | ~ | implemented |
| US-ANALYSIS-007 | Trend formy / progres | P1 | ✗ | ~ | ✗ | ✗ | missing |
| US-ANALYSIS-008 | Profile Quality Score widoczny | P1 | ✓ | ✓ | ✓ | ✗ | implemented |

## 04 — Plan i feedback

| ID | Nazwa | Pri | FE | BE | Test | Smoke | Status |
|---|---|---|---|---|---|---|---|
| US-PLAN-001 | Pierwszy rolling plan 14 dni | P0 | ✓ | ✓ | ✓ | ~ | implemented |
| US-PLAN-002 | Plan na dzień dzisiejszy widoczny | P0 | ~ | ✓ | ~ | ✗ | partial |
| US-PLAN-003 | Refresh planu manualny | P0 | ✓ | ✓ | ~ | ~ | implemented |
| US-PLAN-004 | Auto-refresh planu po imporcie/check-inie | P1 | ✓ | ✓ | ~ | ✗ | implemented |
| US-PLAN-005 | Generowanie feedbacku po treningu | P0 | ✓ | ✓ | ✓ | ✗ | implemented |
| US-PLAN-006 | Trening zgodny z planem | P0 | ✓ | ✓ | ~ | ✗ | partial |
| US-PLAN-007 | Trening krótszy niż planowany | P0 | ✓ | ✓ | ~ | ✗ | partial |
| US-PLAN-008 | Trening dłuższy/mocniejszy | P0 | ✓ | ✓ | ~ | ✗ | partial |
| US-PLAN-009 | Trening spontaniczny (bez planu) | P0 | ✓ | ✓ | ~ | ✗ | partial |
| US-PLAN-010 | Pominięty kluczowy trening | P0 | ✗ | ✓ | ~ | ✗ | partial |
| US-PLAN-011 | Cross-training planowany | P1 | ~ | ~ | ~ | ✗ | partial |
| US-PLAN-012 | Cross-training spontaniczny | P1 | ~ | ✓ | ~ | ✗ | partial |
| US-PLAN-013 | Race profile w planie (taper, peak) | P1 | ✗ | ✓ | ~ | ✗ | partial |
| US-PLAN-014 | Zmiana celu w trakcie cyklu | P1 | ✗ | ✓ | ~ | ✗ | partial |
| US-PLAN-015 | Powrót po przerwie / chorobie | P0 | ✗ | ✓ | ~ | ✗ | partial |
| US-PLAN-016 | Zgłoszenie bólu w trakcie cyklu | P0 | ~ | ✓ | ✓ | ✗ | partial |
| US-PLAN-017 | Brak treningu kilka dni (drift) | P1 | ✗ | ~ | ✗ | ✗ | partial |
| US-PLAN-018 | Pełna pętla po pierwszym treningu | P0 | ~ | ✓ | ✓ | ~ local API 30.04 | partial |

## 05 — Integracje

### Garmin

| ID | Nazwa | Pri | FE | BE | Test | Smoke | Status |
|---|---|---|---|---|---|---|---|
| US-GARMIN-001 | Połączenie konta Garmin | P0 | ✓ | ✓ | ~ | ✓ (26.04) | implemented |
| US-GARMIN-002 | Konto z MFA | P1 | ✗ | ~ | ✗ | ✗ | partial |
| US-GARMIN-003 | Sync historii (30 dni) | P0 | ✓ | ✓ | ~ | ✓ (26.04) | implemented |
| US-GARMIN-004 | Auto-sync nowych aktywności | P1 | ✗ | ✗ | ✗ | ✗ | missing |
| US-GARMIN-005 | Wysyłka workoutu do Garmin | P1 | ~ | ✓ | ~ | ✓ (26.04) | implemented |
| US-GARMIN-006 | Błąd connectora (offline) | P0 | ~ | ~ | ✗ | ✗ | partial |
| US-GARMIN-007 | Odłączenie konta Garmin | P1 | ✓ | ✓ | ✓ | ✗ | implemented |

### Strava

| ID | Nazwa | Pri | FE | BE | Test | Smoke | Status |
|---|---|---|---|---|---|---|---|
| US-STRAVA-001 | Połączenie konta (OAuth, prod needs credentials) | P0 | ~ | ~ | ~ | ✗ | unknown |
| US-STRAVA-002 | Sync historii Strava (prod needs smoke) | P0 | ~ | ~ | ~ | ✗ | unknown |
| US-STRAVA-003 | Webhook dla nowych aktywności | P1 | ✗ | ✗ | ✗ | ✗ | missing |
| US-STRAVA-004 | Brak zgody na zakres / scope | P1 | ? | ? | ✗ | ✗ | unknown |
| US-STRAVA-005 | Refresh access_token | P0 | — | ~ | ✗ | ✗ | partial |

### Polar / Suunto / Coros

| ID | Nazwa | Pri | FE | BE | Test | Smoke | Status |
|---|---|---|---|---|---|---|---|
| US-POLAR-001 | Placeholder Polar w onboardingu | P2 | ✗ | — | ✗ | — | missing |
| US-POLAR-002 | Pełna integracja Polar | P2 | ✗ | ✗ | ✗ | ✗ | missing |
| US-SUUNTO-001 | Placeholder Suunto w onboardingu | P2 | ✗ | — | ✗ | — | missing |
| US-SUUNTO-002 | Tymczasowy Suunto Sports Tracker test bridge | P1 | ✗ | ✓ | ✓ | ✗ | partial |
| US-COROS-001 | Coros bez integracji, fallback FIT/TCX | P2 | ✗ | ~ | ✗ | — | missing |
| US-COROS-002 | Pełna integracja Coros (przyszłość) | P2 | ✗ | ✗ | ✗ | ✗ | missing |

### Race profile / Garmin Event

| ID | Nazwa | Pri | FE | BE | Test | Smoke | Status |
|---|---|---|---|---|---|---|---|
| US-RACE-001 | Ręczne dodanie startu | P0 | ✓ | ✓ | ~ | ✗ | implemented |
| US-RACE-002 | Edycja startu / zmiana celu | P1 | ✓ | ✓ | ~ | ✗ | implemented |
| US-RACE-003 | Usunięcie startu | P1 | ✓ | ✓ | ~ | ✗ | implemented |
| US-GARMIN-EVENT-001 | Import eventu z Garmin Event Dashboard | P2 | ✗ | ✗ | ✗ | ✗ | missing |
| US-INTEGRATION-001 | Globalny widok integracji | P1 | ✓ | ✓ | ✓ | ✗ | implemented |

## 06 — Auth, sesja, smoke

| ID | Nazwa | Pri | FE | BE | Test | Smoke | Status |
|---|---|---|---|---|---|---|---|
| US-AUTH-001 | Login z poprawnymi danymi | P0 | ✓ | ✓ | ~ | ~ | implemented |
| US-AUTH-002 | Login z niepoprawnymi danymi | P0 | ✓ | ✓ | ~ | ✗ | implemented |
| US-AUTH-003 | Logout | P0 | ✓ | ✓ | ~ | ✗ | implemented |
| US-AUTH-004 | Sesja wygasa po 30 dniach | P0 | ~ | ✓ | ~ | ✗ | implemented |
| US-AUTH-005 | Ręczne usunięcie tokena | P1 | ~ | — | ✗ | ✗ | partial |
| US-AUTH-006 | 401 podczas autoload (silent fail) | P1 | ✓ | ✓ | ✗ | ✗ | partial |
| US-AUTH-007 | Sesja wygasła w trakcie uploadu | P1 | ~ | ✓ | ✗ | ✗ | partial |
| US-AUTH-008 | Login na 2 urządzeniach | P1 | ~ | ✓ | ~ | ✗ | implemented |
| US-AUTH-009 | "Zapomniałem hasła" / reset | P0 | ✓ | ✓ | ✓ | ✗ | partial |
| US-AUTH-010 | Zmiana hasła z profilu | P1 | ✗ | ✗ | ✗ | ✗ | missing |
| US-AUTH-011 | Smoke: register/login/profile | P0 | — | — | ✓ | ~ local API 30.04 | partial |
| US-AUTH-012 | Smoke: pełny flow MVP | P0 | — | — | ✓ | ~ local API 30.04 | partial |
| US-AUTH-013 | Frontend deploy nie psuje produkcji | P0 | — | — | ✗ | ~ | partial |
| US-AUTH-014 | CORS / cross-origin | P0 | — | ✓ | ✗ | ~ | implemented |
| US-AUTH-015 | Backend healthcheck | P0 | — | ✓ | ✗ | ✓ (28.04) | implemented |

## 07 — Prywatność i RODO

| ID | Nazwa | Pri | FE | BE | Test | Smoke | Status |
|---|---|---|---|---|---|---|---|
| US-PRIVACY-001 | Zgody przy rejestracji | P0 | ✗ | ✗ | ✗ | ✗ | missing |
| US-PRIVACY-002 | Odłączenie integracji (revoke) | P0 | ✓ | ✓ | ✓ | ✗ | partial |
| US-PRIVACY-003 | Export danych | P0 | ✗ | ✗ | ✗ | ✗ | missing |
| US-PRIVACY-004 | Usunięcie konta i danych | P0 | ✗ | ✗ | ✗ | ✗ | missing |
| US-PRIVACY-005 | Granica info treningowa/medyczna | P0 | ✗ | — | ✗ | ✗ | missing |
| US-PRIVACY-006 | Dane zdrowotne jako wrażliwe | P0 | ? | ? | ✗ | ✗ | unknown |
| US-PRIVACY-007 | Audit log zgód | P0 | — | ✗ | ✗ | ✗ | missing |
| US-PRIVACY-008 | Sprostowanie / edycja danych | P1 | ✓ | ✓ | ~ | ✗ | implemented |
| US-PRIVACY-009 | Cookies i tracking | P1 | ? | ? | ✗ | ✗ | unknown |
| US-PRIVACY-010 | Komunikacja po breachu | P1 | — | — | ✗ | — | missing |

## 08 — Manual check-in

| ID | Nazwa | Pri | FE | BE | Test | Smoke | Status |
|---|---|---|---|---|---|---|---|
| US-MANUAL-001 | Plan startowy bez integracji i bez plików | P0 | ~ | ~ | ~ | ~ local API 30.04 | partial |
| US-MANUAL-002 | Oznaczenie dzisiejszego treningu jako wykonanego | P0 | ✓ | ✓ | ✓ | ~ local API 30.04 | implemented |
| US-MANUAL-003 | Check-in z częściowymi danymi | P0 | ✓ | ✓ | ✓ | ~ local API 30.04 | implemented |
| US-MANUAL-004 | Trening wykonany inaczej niż plan | P0 | ✓ | ✓ | ✓ | ✗ | implemented |
| US-MANUAL-005 | Pominięcie zaplanowanego treningu | P0 | ✓ | ✓ | ✓ | ✗ | partial |
| US-MANUAL-006 | Feedback bez telemetryki | P0 | ✓ | ✓ | ✓ | ~ local API 30.04 | implemented |
| US-MANUAL-007 | Długoterminowy manual mode | P1 | ✗ | ~ | ✗ | ✗ | missing |

## Podsumowanie liczbowe

### Według priorytetu
- **P0:** 60 scenariuszy
- **P1:** 40 scenariuszy
- **P2:** 7 scenariuszy
- **Razem:** 107 scenariuszy

### Według statusu
- **implemented:** 28 (~26%)
- **partial:** 44 (~41%)
- **missing:** 24 (~23%)
- **unknown:** 11 (~10%)

### P0 alone
- **P0 implemented:** 24
- **P0 partial:** 27
- **P0 missing:** 5
- **P0 unknown:** 4

### Krytyczne luki P0 missing
1. US-PRIVACY-001 — zgody przy rejestracji
2. US-PRIVACY-003 — export danych
3. US-PRIVACY-004 — usunięcie konta
4. US-PRIVACY-005 — granica medyczna w UI
5. US-PRIVACY-007 — audit log zgód

### Krytyczne luki P0 partial (do dokończenia)
1. US-ONBOARD-001 — rejestracja w UI (bazowy frontend gotowy, email do resetu dodany; do domknięcia pełna walidacja/smoke)
2. US-AUTH-009 — reset hasła (API, mail i UI są; brak SMTP produkcyjnego/manual smoke oraz revoke starych tokenów sesji)
3. US-ONBOARD-007 — powrót do onboardingu po skipie/przerwaniu (CTA i prefill są, brak e2e smoke i pełnego flow edycji)
4. US-ONBOARD-010 — manual onboarding bez danych (działa częściowo, wymaga spięcia z check-in)
5. US-PLAN-015 — UX powrotu po przerwie
6. US-PLAN-018 — pełna pętla pierwszy trening (lokalny API smoke bez pliku jest po EP-010; produkcyjny/browser E2E nadal potrzebny)
7. US-RACE-001 — pełny formularz race w UI
8. US-AUTH-011/012 — lokalny API smoke jest po EP-010; automatyczny smoke produkcyjny/cron nadal potrzebny
9. US-MANUAL-005 — pominięcie zaplanowanego treningu ma UI/API bez tworzenia treningu 0 km oraz auto-refresh planu; plan impact i produkcyjny/browser E2E smoke nadal wymagają walidacji

### Krytyczne luki P0 unknown (do potwierdzenia smoke/testem)
1. US-STRAVA-001/002 — produkcyjne credentials + smoke z realnym kontem
2. US-PRIVACY-002 — revoke integracji u providera
3. US-PRIVACY-006 — klasyfikacja i obsługa danych zdrowotnych jako wrażliwych

## Aktualizacja matrycy

Po każdej zmianie statusu w pliku scenariusza (01-08), zaktualizuj odpowiedni wiersz tutaj. Zasada: **tabele i scenariusze nie mogą się rozjechać**.

Tabele mogą wyglądać przytłaczająco — ich celem nie jest decyzja "co robić", tylko **mapowanie**: kto co już zaimplementował i czego brakuje. Decyzje "co robić następne" są w `gaps-and-next-steps.md`.
