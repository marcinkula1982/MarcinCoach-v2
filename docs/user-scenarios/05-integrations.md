# 05 — Integracje

Plik obejmuje: Garmin (sync historii, auto-sync, send workout, MFA), Strava (OAuth, sync, webhook), Polar (planowane), Suunto (planowane), Coros (missing, fallback FIT/TCX), Garmin Event Dashboard (spike), race profile (manual, import).

Realny stan 28.04.2026:
- Garmin: history sync **partial-implemented** (smoke 26.04 dla 30 dni / 9 aktywności), auto-sync **missing**, send workout **implemented** (smoke 26.04, workout id 1548384239).
- Garmin MFA: connector ścieżka istnieje (`GARMIN_MFA_CODE`), UI nie ma pola — **partial / risky**.
- Strava: OAuth/callback/sync — backend lokalnie tak, produkcja **unknown / needs credentials**. Mapowanie pól partial (id, start, elapsed, distance, sport/type, HR avg/max).
- Polar: **missing**.
- Suunto: **missing**.
- Coros: **missing** (P2, future), fallback FIT/TCX import.
- Garmin Event Dashboard: **missing / spike**.

---

## Persony i scenariusze per integracja

Sekcje:
- [Garmin](#garmin)
- [Strava](#strava)
- [Polar](#polar)
- [Suunto](#suunto)
- [Coros](#coros-missing-future)
- [Race profile](#race-profile)

---

# Garmin

## US-GARMIN-001 — Połączenie konta Garmin w onboardingu

**Typ:** happy path
**Persona:** P-GARMIN
**Status:** implemented (z ryzykami)
**Priorytet:** P0

### Stan wejściowy
User w fazie 1 onboardingu klika "Garmin".

### Preconditions
- Connector `GARMIN_CONNECTOR_BASE_URL` skonfigurowany.
- User ma konto Garmin Connect.

### Kroki użytkownika
1. Klika Garmin.
2. Widzi formularz: email, hasło Garmin.
3. Submituje.
4. Connector loguje się przez `python-garminconnect`.
5. (Jeśli MFA aktywne) — **flow się dziś rozjedzie**, brak pola MFA w UI.

### Oczekiwane zachowanie UI (happy path bez MFA)
- Spinner "Łączymy z Garmin Connect...".
- Po sukcesie: "Połączono. Importujemy treningi z ostatnich 30 dni..."
- Po imporcie: licznik "Zaimportowano X treningów".
- Możliwe przejście do fazy 2.

### Oczekiwane API
- `POST /api/integrations/garmin/connect` — body: `{ email, password }`.
- Backend → `GarminConnectorService` → connector → Garmin Connect.
- Po sukcesie: `POST /api/integrations/garmin/sync?days=30`.

### Oczekiwane zmiany danych
- `integration_accounts`: nowy wpis dla user_id + provider=garmin.
- `integration_sync_runs`: log synchronizacji.
- `workouts`: nowe wpisy z `source = GARMIN`, `source_activity_id` z Garmina.

### Kryteria akceptacji (P0)
- Connect kończy się sukcesem dla konta bez MFA.
- 30 dni historii zaimportowane (potwierdzone smoke 26.04: 9 aktywności).
- Duplikaty (jeśli user wgrał wcześniej te same pliki ręcznie) są obsługiwane przez `dedupe_key`.

### Testy / smoke
- Manual smoke produkcyjny: dedykowane konto testowe Garmin, full flow.
- Test backend: `IntegrationsParityTest.php` (jeśli pokrywa Garmin).

### Uwagi produktowe
**Ryzyka jawne:**
- Garmin nie ma oficjalnego API dla zwykłych użytkowników. `python-garminconnect` to nieoficjalna ścieżka.
- MFA jest blokerem dla części użytkowników (US-GARMIN-002).
- Garmin może zmienić ToS lub zablokować connector.

---

## US-GARMIN-002 — Konto Garmin z włączonym MFA

**Typ:** edge case / error
**Persona:** P-GARMIN (security-conscious)
**Status:** partial (backend tak, UI nie)
**Priorytet:** P1

### Stan wejściowy
User ma MFA na koncie Garmin Connect.

### Kroki użytkownika
1. Klika Garmin, podaje email/hasło.
2. Connector próbuje login, dostaje wymóg MFA.
3. UI dziś nie ma pola MFA — flow się wywala.

### Oczekiwane zachowanie po wdrożeniu UI
- UI prosi o MFA code (6 cyfr): "Wpisz kod z aplikacji autoryzującej".
- User wpisuje, submituje.
- Connector finalizuje login.

### Oczekiwane API
- `POST /api/integrations/garmin/connect` z `{ email, password, mfaCode? }`.
- Jeśli backend zwraca status `mfa_required`, UI pokazuje pole MFA.
- Drugi request: `POST /api/integrations/garmin/connect` z `{ email, password, mfaCode }`.

### Kryteria akceptacji (P1)
- MFA flow działa end-to-end.
- Komunikat błędu jest jasny jeśli kod jest zły.
- Token sesji Garmin jest persisted (nie wymagać MFA przy każdym sync).

### Testy / smoke
- Manual smoke z kontem MFA-enabled.
- Test backend: mock MFA flow.

### Uwagi produktowe
**To jest realny blocker** dla wielu użytkowników. Bez UI dla MFA, część osób z Garminem odbije się na pierwszym kroku.

---

## US-GARMIN-003 — Sync historycznych aktywności (30 dni)

**Typ:** happy path
**Persona:** P-GARMIN
**Status:** implemented (smoke 26.04, 9 aktywności)
**Priorytet:** P0

### Stan wejściowy
User właśnie połączył Garmin.

### Kroki użytkownika
1. Backend automatycznie wywołuje sync (lub user klika "Importuj historię").

### Oczekiwane zachowanie UI
- Progress: "Importujemy treningi 1/9..."
- Lub silent sync z pojawieniem się treningów po zakończeniu.

### Oczekiwane API
- `POST /api/integrations/garmin/sync?days=30`.
- Backend pobiera listę aktywności z Garmina.
- Per aktywność: pobiera detale (TCX) i zapisuje jako `workout` z `source = GARMIN`.

### Oczekiwane zmiany danych
- N nowych workoutów z `source = GARMIN, source_activity_id = ...`.
- `integration_sync_runs` log z `imported: N, skipped: 0, errors: 0`.

### Kryteria akceptacji (P0)
- 30 dni historii zaimportowane.
- Sport detection działa (run, trail_running, treadmill_running → `sport = run`).
- Cross-training też zaimportowany (bike, swim, walk → odpowiedni `sport`).
- Duplikaty pomijane.

### Testy / smoke
- Manual smoke (potwierdzony 26.04): 30 dni → 9 aktywności.
- Test backend: `IntegrationsParityTest.php`.

### Uwagi produktowe
30 dni to MVP. Później rozszerzyć do 60-90 dni (zgodnie z `integrations.md`).

---

## US-GARMIN-004 — Auto-sync nowych aktywności

**Typ:** happy path (oczekiwany)
**Persona:** P-GARMIN
**Status:** missing
**Priorytet:** P1

### Stan wejściowy
User ma połączony Garmin. Robi nowy trening, kończy się synchronizacja Garmin → Garmin Connect.

### Oczekiwane zachowanie po wdrożeniu
- (a) Backend ma webhook od Garmina (oficjalna ścieżka, niedostępna w nieoficjalnym connector).
- (b) Backend co 15 min lub co 1h pollinguje connector po nowe aktywności.
- (c) Sync triggered przez user (np. "Sprawdź nowe treningi").

### Decyzja architektoniczna
Webhook nieosiągalny przez nieoficjalny connector → realna opcja to **(b) polling** lub **(c) on-demand**.

Sugestia: implementować **(c)** w MVP (przycisk "Sprawdź nowe treningi" na dashboardzie) + **(b)** jako backend cron 1-2 razy/dzień dla aktywnych użytkowników.

### Oczekiwane API
- `POST /api/integrations/garmin/sync?days=7` — pobiera ostatnie 7 dni.
- Backend pomija duplikaty.

### Kryteria akceptacji (P1)
- Po kliknięciu "Sprawdź nowe treningi" w UI, nowe aktywności pojawiają się w ciągu 30s.
- Cron (jeśli wdrożony) działa rzetelnie i nie zacina backendu.

### Testy / smoke
- Test backend: scheduled sync.

### Uwagi produktowe
**Ryzyko rate limit:** Garmin może blokować zbyt częste poll. Trzymać limit 1-2× dziennie per user na cron, plus on-demand kiedy user otwiera dashboard.

---

## US-GARMIN-005 — Wysyłka zaplanowanego treningu do Garmin Connect

**Typ:** happy path
**Persona:** P-MULTI (planuje na zegarku)
**Status:** implemented (smoke 26.04, workout 1548384239)
**Priorytet:** P1

### Stan wejściowy
User ma plan z planowanym quality session na jutro. Chce żeby zegarek pokazał strukturę.

### Kroki użytkownika
1. W planie tygodnia klika "Wyślij do Garmin" obok jutrzejszej sesji.

### Oczekiwane zachowanie UI
- Spinner "Wysyłamy do Garmin Connect...".
- Po sukcesie: "Wysłano. Trening zaplanowany w Twoim kalendarzu Garmin na jutro."

### Oczekiwane API
- `POST /api/integrations/garmin/workouts/send` z body `{ planSessionId: ..., date: ... }`.
- Backend tworzy strukturę Garmin workout i wysyła przez connector.

### Kryteria akceptacji (P1)
- Workout pojawia się w Garmin Connect kalendarzu.
- User na zegarku widzi strukturę przy starcie treningu.
- Status: scheduled.

### Testy / smoke
- Manual smoke (potwierdzony 26.04: workoutId 1548384239 zaplanowany na 2026-04-26).

### Uwagi produktowe
**Już działa.** UI musi tylko wystawić przycisk. To jest miły bonus dla power-userów.

---

## US-GARMIN-006 — Błąd connectora (offline/timeout)

**Typ:** error
**Persona:** P-GARMIN
**Status:** partial
**Priorytet:** P0

### Stan wejściowy
Connector jest offline lub Garmin API zwraca 5xx.

### Oczekiwane zachowanie
- Backend zwraca 503 z kodem `GARMIN_CONNECTOR_UNAVAILABLE`.
- UI: "Garmin Connect chwilowo niedostępny. Spróbuj za chwilę lub wgraj plik TCX ręcznie."
- Fallback: link do US-IMPORT-001.

### Kryteria akceptacji (P0)
- Backend ma retry z exponential backoff (3× max).
- UI nie wisi w pętli.
- User widzi czytelny komunikat.

### Testy / smoke
- Test backend: connector mock zwracający 503.

---

## US-GARMIN-007 — Odłączenie konta Garmin

**Typ:** happy path
**Persona:** P-GARMIN (rezygnuje)
**Status:** unknown
**Priorytet:** P1

### Stan wejściowy
User chce odłączyć Garmin (np. przed usunięciem konta MarcinCoach).

### Kroki użytkownika
1. Idzie do ustawień / zakładki integracji.
2. Klika "Odłącz Garmin".
3. Potwierdza.

### Oczekiwane zachowanie
- Backend usuwa `integration_accounts` dla user+provider=garmin.
- Token Garmin (jeśli persisted) jest unieważniany.
- Workouty już zaimportowane **pozostają** w bazie (decyzja produktowa, alternatywa: usuń też workouty).

### Oczekiwane API
- `DELETE /api/integrations/garmin`.

### Kryteria akceptacji (P1)
- Po odłączeniu user nie widzi opcji sync.
- Może ponownie połączyć w każdej chwili.
- Decyzja co z workoutami jest jawna w UI ("Twoje treningi pozostaną w aplikacji").

### Testy / smoke
- Test e2e: connect → disconnect → reconnect.

### Uwagi produktowe
**Wymóg RODO:** user musi mieć kontrolę nad integracją. Patrz US-PRIVACY-002.

---

# Strava

## US-STRAVA-001 — Połączenie konta Strava (OAuth)

**Typ:** happy path
**Persona:** P-MULTI
**Status:** unknown (backend lokalnie tak, produkcja needs credentials/smoke)
**Priorytet:** P0

### Stan wejściowy
User w fazie 1 onboardingu klika "Strava".

### Preconditions
- Strava credentials produkcyjne ustawione w `.env` (do potwierdzenia).
- Backend ma `StravaOAuthService`.

### Kroki użytkownika
1. Klika Strava.
2. Backend redirects do `https://www.strava.com/oauth/authorize?...`.
3. User na Stravie autoryzuje aplikację.
4. Strava redirects do callback `https://api.coach.host89998.iqhs.pl/api/integrations/strava/callback?code=...`.
5. Backend wymienia code na access_token.
6. Backend pobiera historię.

### Oczekiwane API
- `POST /api/integrations/strava/connect` → URL do Strava OAuth.
- `GET /api/integrations/strava/callback?code=...` → exchange + sync.

### Oczekiwane zmiany danych
- `integration_accounts`: nowy wpis dla strava.
- `workouts`: nowe wpisy z `source = STRAVA`.

### Kryteria akceptacji (P0)
- OAuth flow kończy się sukcesem na produkcji.
- Pierwsza synchronizacja pobiera 30 dni.
- Token zapisany dla późniejszego użycia.
- Refresh token obsługiwany (Strava tokens wygasają po 6h).

### Testy / smoke
- Manual smoke produkcyjny z realnym kontem Strava — **TODO przed launchem**.
- Test backend: mock OAuth flow.

### Uwagi produktowe
**Status produkcyjny: unknown.** Zgodnie z `integrations.md` wymaga produkcyjnych credentials i smoke. Bez tego Strava jest na liście ale nie wiadomo czy działa end-to-end.

---

## US-STRAVA-002 — Sync historii Strava

**Typ:** happy path
**Persona:** P-MULTI
**Status:** unknown (produkcja needs credentials/smoke)
**Priorytet:** P0

### Stan wejściowy
User właśnie połączył Stravę.

### Oczekiwane zachowanie
- Backend pobiera 30 dni aktywności przez Strava API.
- Per aktywność: pobiera detale i zapisuje.

### Oczekiwane API
- Strava API: `GET /athlete/activities?after=...&per_page=200`.
- Per aktywność: `GET /activities/{id}`.

### Mapowanie pól
- `start_date_local` → `summary.startTimeIso`
- `elapsed_time` → `summary.original.durationSec`
- `distance` → `summary.original.distanceM`
- `sport_type` / `type` → `sport` (Run, TrailRun, VirtualRun → run; Ride → bike; etc.)
- `average_heartrate` → `hr.avgBpm`
- `max_heartrate` → `hr.maxBpm`

### Kryteria akceptacji (P0)
- Mapping kompletny dla pól które backend dziś używa.
- Brak crashów na nietypowych aktywnościach.
- Duplikaty pomijane.

### Testy / smoke
- Test backend: mock Strava API → import 5 aktywności.
- Manual smoke produkcyjny z realnym kontem Strava — TODO przed publicznym launchem.

### Uwagi produktowe
**Mapping partial:** strava nie zawsze daje wszystkie pola. HR opcjonalne (jak ze sportu indoor). Trackpoints (streams) trzeba pobierać osobnym requestem (`/activities/{id}/streams`). Bez streams brakuje danych do `intensityBuckets`.

**Decyzja:** czy w MVP pobierać streams (więcej API calls) czy zadowolić się summary?

---

## US-STRAVA-003 — Strava webhook dla nowych aktywności

**Typ:** happy path (oczekiwany)
**Persona:** P-MULTI
**Status:** missing
**Priorytet:** P1

### Stan wejściowy
User ma połączoną Stravę. Strava ma oficjalny webhook ("Strava Subscriptions").

### Oczekiwane zachowanie po wdrożeniu
- Backend rejestruje subscription u Stravy podczas connect.
- Strava POST do `/api/integrations/strava/webhook` po nowej aktywności.
- Backend pobiera detale i zapisuje workout.

### Oczekiwane API
- Backend wystawia `POST /api/integrations/strava/webhook` (publiczny, walidacja przez secret).
- Strava API: `POST /push_subscriptions`.

### Kryteria akceptacji (P1)
- Webhook działa.
- Nowy trening pojawia się w MarcinCoach < 60s po zakończeniu sync ze Stravy.

### Testy / smoke
- Manual smoke z realnym kontem.

### Uwagi produktowe
Strava webhook to **oficjalna i stabilna** ścieżka. To powinno być pierwszym wyborem dla auto-sync zanim zacznie się polling Garmina.

---

## US-STRAVA-004 — Brak zgody na zakres / scope issues

**Typ:** error
**Persona:** P-MULTI
**Status:** unknown
**Priorytet:** P1

### Stan wejściowy
User na Stravie odrzuca część scope (np. nie zgadza się na "private activities").

### Oczekiwane zachowanie
- Backend pracuje z dostępnymi danymi.
- Komunikat: "Niektóre treningi mogą nie być widoczne, jeśli są oznaczone jako prywatne".

### Kryteria akceptacji (P1)
- Brak crashów.
- User wie co stracił.

### Testy / smoke
- Manual smoke z minimal scope.

---

## US-STRAVA-005 — Refresh access_token

**Typ:** happy path (transparent)
**Persona:** P-MULTI
**Status:** partial (backend ma logikę, do potwierdzenia)
**Priorytet:** P0

### Stan wejściowy
Strava token wygasł (po 6h).

### Oczekiwane zachowanie
- Backend automatycznie używa `refresh_token` do uzyskania nowego.
- User nic nie zauważa.

### Oczekiwane API
- Strava: `POST /oauth/token` z `grant_type=refresh_token`.

### Kryteria akceptacji (P0)
- Refresh działa transparentnie.
- Jeśli refresh_token też wygasł — UI pokazuje "Połącz ponownie ze Stravą".

### Testy / smoke
- Test backend: expired token + refresh.

---

# Polar

## US-POLAR-001 — Przycisk Polar w onboardingu (placeholder)

**Typ:** happy path (placeholder)
**Persona:** użytkownik Polar
**Status:** missing
**Priorytet:** P2

### Stan wejściowy
User ma Polar, w onboardingu klika "Polar".

### Oczekiwane zachowanie
- UI pokazuje: "Integracja Polar jest w przygotowaniu. W międzyczasie możesz wgrywać pliki TCX/GPX z Polar Flow."
- Link do US-IMPORT-001.
- Opcjonalnie: "Powiadomimy Cię gdy będzie gotowe" (zapis do `integration_requests`).

### Kryteria akceptacji (P2)
- Polar widoczny ale nie udaje że działa.

### Uwagi produktowe
Polar AccessLink API jest oficjalne i stabilne. Implementacja: po Strava, przed Suunto. Wymaga rejestracji aplikacji u Polar i credentials.

---

## US-POLAR-002 — Pełna integracja Polar (przyszłość)

**Typ:** happy path
**Persona:** użytkownik Polar
**Status:** missing
**Priorytet:** P2

### Spec analogiczny do Strava
- OAuth flow.
- Sync historii.
- Webhook dla nowych aktywności.
- Mapowanie pól.

### Linki referencyjne
- Polar AccessLink: https://www.polar.com/accesslink-api/

---

# Suunto

## US-SUUNTO-001 — Przycisk Suunto w onboardingu (placeholder)

**Typ:** happy path (placeholder)
**Persona:** użytkownik Suunto
**Status:** missing
**Priorytet:** P2

### Analogiczny do US-POLAR-001
- UI pokazuje placeholder + fallback FIT/TCX import.
- "Powiadomimy" przez `integration_requests`.

### Uwagi produktowe
Suunto API Zone wymaga formalności partnerskich (zgłoszenie aplikacji). Implementacja po Polar.

### Linki
- Suunto API Zone: https://apizone.suunto.com/

---

# Coros (missing, future)

## US-COROS-001 — Coros nie ma integracji, ale user może wgrywać pliki

**Typ:** happy path
**Persona:** P-COROS
**Status:** missing (Coros API), partial (FIT/TCX upload działa)
**Priorytet:** P2

### Stan wejściowy
User ma Coros Pace 4. W onboardingu nie widzi "Coros" jako opcji integracji (lub widzi z labelem "wkrótce").

### Kroki użytkownika (fallback)
1. Eksportuje plik FIT lub TCX z aplikacji Coros (Coros App → Profile → Export).
2. W MarcinCoach klika "Upload pliku".
3. Wgrywa.

### Oczekiwane zachowanie UI
- W liście integracji Coros widoczny jako "wkrótce".
- Pod listą banner: "Masz Coros / Polar / Suunto / inny? Wgraj pliki FIT lub TCX → US-IMPORT-002".
- Form "Powiadom nas" (US-ONBOARD-006) wskazuje brak Coros API.

### Kryteria akceptacji (P2)
- User Coros nie czuje się wykluczony — ma działającą ścieżkę przez upload.
- W roadmapie jest jasna pozycja "Coros API integration".

### Uwagi produktowe
**Notatka strategiczna:** Coros + Wahoo ogłosili partnership 24.04.2026 z two-way API. Może być droga w przyszłości, ale dziś **brak public API dla Coros** dla third-party developerów (oprócz formularza partnerskiego).

Decyzja na MVP: nie próbować reverse-engineerować Coros. Zostać przy fallbacku FIT/TCX. W roadmapie: P2, "po stabilizacji Polar/Suunto".

---

## US-COROS-002 — Pełna integracja Coros (przyszłość, P2)

**Typ:** happy path
**Persona:** P-COROS
**Status:** missing
**Priorytet:** P2

### Spec — kiedy będzie możliwe
- Wymaga aplikacji partnerskiej u Coros.
- Wymaga oficjalnego API access.

### Spec analogiczny do Strava (kiedyś w przyszłości)
- OAuth flow.
- Sync historii.
- Webhook lub polling.
- Mapowanie pól.

### Uwagi produktowe
Można złożyć aplikację partnerską już teraz, żeby trzymać miejsce w kolejce. Sam dev to po Polar/Suunto.

---

# Race profile

## US-RACE-001 — Ręczne dodanie startu w profilu

**Typ:** happy path
**Persona:** P-MULTI
**Status:** partial (backend ma, UI ograniczone)
**Priorytet:** P0

### Stan wejściowy
User chce dodać start: marathon Wrocław 15.10.2026, cel 3:30, priorytet A.

### Kroki użytkownika
1. Idzie do zakładki Profil → Starty (TODO: zakładka missing).
2. Klika "Dodaj start".
3. Wypełnia: nazwa, data, dystans (z listy: 5/10/21.1/42.2/inny), cel czasowy (HH:MM:SS), priorytet (A/B/C).

### Oczekiwane zachowanie UI (po wdrożeniu)
- Formularz z walidacją.
- Po submit: lista startów aktualizowana.
- W planie pojawia się info "X tygodni do startu Y".

### Oczekiwane API
- Aktualny model backendu: `PUT /api/me/profile` z pełną/zmienioną tablicą `races`.
- Dedykowane endpointy `POST/PUT/DELETE /api/me/profile/races...` nie istnieją dziś; można je dodać później, jeśli UI race management tego wymaga.

### Oczekiwane zmiany danych
- Wpis w `races` (lub w `user_profiles.races` JSON, w zależności od schema).
- Plan przeliczony jeśli race jest A i wpływa na block.

### Kryteria akceptacji (P0)
- User może dodać minimum 1 start A.
- Backend respektuje race w `BlockPeriodizationService`.
- Plan pokazuje fazy: build → peak → taper.

### Testy / smoke
- Test backend: dodaj race A → block_type respektuje weeksUntilRace.

### Uwagi produktowe
**Onboarding tworzy race uproszczony** (data + dystans z celu + priority A, bez nazwy/targetTime). Pełny formularz to TODO.

Roadmap punkt 4: "Races w profilu — upewnić się że frontend pozwala dodać start ręcznie".

---

## US-RACE-002 — Edycja / zmiana celu

**Typ:** edge case
**Persona:** P-MULTI
**Status:** missing (UI)
**Priorytet:** P1

### Stan wejściowy
User ma race A za 12 tyg, decyduje że to półmaraton zamiast maratonu.

### Kroki użytkownika
1. W zakładce Starty edytuje race.
2. Zmienia dystans z 42.2 na 21.1.
3. Submit.
4. Plan się przelicza.

### Patrz US-PLAN-014 dla skutków planu.

---

## US-RACE-003 — Usunięcie startu

**Typ:** edge case
**Persona:** P-RETURN (kontuzja, nie wystartuje)
**Status:** missing (UI)
**Priorytet:** P1

### Oczekiwane zachowanie
- Race usunięty z bazy.
- Plan przelicza się — bez race A → block_type = base lub maintain.
- Komunikat: "Plan został zaktualizowany — wracamy do trybu utrzymania formy."

---

# Garmin Event Dashboard (spike)

## US-GARMIN-EVENT-001 — Import eventu z Garmin Event Dashboard

**Typ:** happy path (przyszłościowy spike)
**Persona:** P-MULTI
**Status:** missing / spike
**Priorytet:** P2

### Stan wejściowy
User na Garmin Connect ma w "Moich wydarzeniach" dodany maraton.

### Hipotetyczne kroki
1. W zakładce Starty klika "Importuj z Garmin Event".
2. UI pokazuje listę eventów z Garmin Connect.
3. User wybiera, klika "Importuj jako start A/B/C".

### Oczekiwane API (spike)
- Garmin Connect ma `https://connect.garmin.com/app/event-dashboard` — niewiadome czy connector to wspiera.

### Kryteria akceptacji (P2)
- Spike daje jasną odpowiedź: stabilne / kruche / niedostępne.
- Jeśli stabilne — implementacja P2 (po Strava webhook).
- Jeśli kruche/niedostępne — fallback to ręczne dodanie (US-RACE-001).

### Testy / smoke
- Spike: research + 2-3h testów na koncie testowym.

### Uwagi produktowe
**Nie blokuje MVP.** Roadmap punkt 5 to wymienia jako spike. Wartość dla power-userów (mają już eventy w Garminie). Nie powtarzać tego dla Strava (Strava nie ma eventów w tym sensie).

---

## US-INTEGRATION-001 — Globalny widok integracji w ustawieniach

**Typ:** happy path
**Persona:** każda
**Status:** missing
**Priorytet:** P1

### Stan wejściowy
User chce zobaczyć "co mam podłączone".

### Kroki użytkownika
1. Idzie do Ustawienia → Integracje (TODO: zakładka).
2. Widzi listę:
   - Garmin: ✓ Połączono. Ostatnia synchronizacja: 2 godziny temu. [Sync] [Odłącz]
   - Strava: ✗ Nie połączono. [Połącz]
   - Polar: ⏳ Wkrótce. [Powiadom mnie]
   - Suunto: ⏳ Wkrótce. [Powiadom mnie]
   - Coros: ⏳ Brak API. [Wgraj pliki]

### Kryteria akceptacji (P1)
- User widzi co działa, co nie, kiedy ostatni sync.
- Może zarządzać każdą integracją z jednego miejsca.

### Testy / smoke
- Test e2e: lista integracji renderuje się poprawnie dla różnych stanów.

### Uwagi produktowe
**Centralna kontrola** to też element RODO (US-PRIVACY-002).
