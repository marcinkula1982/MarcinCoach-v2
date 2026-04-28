# 01 — Onboarding i wybór źródła danych

Plik obejmuje: rejestrację/login, wizard onboardingu (Źródło → Pytania), skip onboardingu, opcję "powiadom nas o brakującej aplikacji".

Realny stan UI 28.04.2026: wizard ma 2 fazy (Źródło → Pytania). Nie ma w wizardzie kroku "przegląd zaimportowanych treningów". Nie ma w wizardzie propozycji stref HR. Po submit/skip user idzie do dashboardu.

---

## US-ONBOARD-001 — Rejestracja nowego użytkownika

**Typ:** happy path
**Persona:** P-NOVICE, P-GARMIN, P-MULTI, P-RETURN, P-COROS (każdy nowy user)
**Status:** partial
**Priorytet:** P0

### Stan wejściowy
Brak konta w systemie. Użytkownik trafia na `https://coach.host89998.iqhs.pl`.

### Preconditions
- Frontend działa.
- Backend `/api/auth/register` jest dostępny (potwierdzone w `routes/api.php`).
- Frontend NIE pokazuje dziś przycisku rejestracji — tylko login.

### Kroki użytkownika
1. Wchodzi na stronę.
2. Widzi formularz logowania.
3. Próbuje znaleźć "Załóż konto" — i tu jest dziura UX, bo nie ma takiego przycisku.

### Oczekiwane zachowanie UI
- Powinien być link/przycisk "Załóż konto" obok formularza logowania.
- Po kliknięciu: formularz rejestracji (email, hasło, potwierdzenie hasła).
- Po submit: konto utworzone, automatyczny login, redirect do onboardingu.

### Oczekiwane API
- `POST /api/auth/register` — body: `{ email, password }` → tworzy użytkownika i sesję.
- Zwraca `x-session-token` i `x-username` (lub w body).

### Oczekiwane zmiany danych
- Wpis w `users`.
- Pusty `user_profiles` z domyślnymi wartościami.
- Aktywna sesja w cache.

### Kryteria akceptacji (P0 — szczegółowe)
- Formularz rejestracji jest dostępny z ekranu logowania.
- Walidacja email po stronie frontu (format) i backendu (unikalność).
- Hasło: min. 8 znaków, walidacja po obu stronach.
- Po sukcesie: response zawiera valid session token, frontend zapisuje go i ustawia user state.
- Onboardingu wizard pojawia się natychmiast po rejestracji (`onboardingCompleted === false && workouts.length === 0`).
- Próba rejestracji istniejącego email zwraca błąd 409 z czytelnym komunikatem ("Konto już istnieje, zaloguj się").

### Testy / smoke
- Manual smoke produkcyjny: zarejestruj nowe konto, zweryfikuj że trafia do wizard.
- Test e2e: register → onboarding → dashboard.
- Test backend: duplikat email zwraca 409.

### Uwagi produktowe
Brak rejestracji w UI to dziś ukryty blocker MVP — nikt nowy nie założy sobie konta sam. Frontend musi to wystawić zanim ogłosimy launch.

---

## US-ONBOARD-002 — Login istniejącego użytkownika

**Typ:** happy path
**Persona:** P-GARMIN (returning user)
**Status:** implemented
**Priorytet:** P0

### Stan wejściowy
Użytkownik ma konto i wykonał wcześniej onboarding lub go pominął.

### Preconditions
- Frontend i backend działają.
- Konto istnieje w bazie.

### Kroki użytkownika
1. Wchodzi na stronę.
2. Wpisuje email i hasło.
3. Klika "Zaloguj".

### Oczekiwane zachowanie UI
- Po kliknięciu loading state.
- Po sukcesie: redirect do dashboardu (jeśli onboardingCompleted) lub do wizard (jeśli nie).
- Po błędzie: czytelny komunikat ("Niepoprawny email lub hasło").

### Oczekiwane API
- `POST /api/auth/login` → zwraca session token.

### Kryteria akceptacji (P0)
- Login z poprawnymi danymi: 200 OK, sesja aktywna.
- Login z błędnymi danymi: 401, brak sesji.
- Login z nieistniejącym email: 401 (taki sam komunikat jak złe hasło — dla bezpieczeństwa).
- Sesja TTL: 30 dni (potwierdzone z notatek projektu).
- Po loginie frontend potrafi pobrać `/api/me`, `/api/me/profile`, `/api/workouts`, `/api/rolling-plan?days=14`.

### Testy / smoke
- Test e2e: login → dashboard.
- Manual smoke produkcyjny: czy login działa na live prod.

---

## US-ONBOARD-003 — Wizard onboardingu: faza 1 — wybór źródła danych

**Typ:** happy path
**Persona:** P-NOVICE, P-GARMIN, P-MULTI, P-RETURN, P-COROS
**Status:** implemented (z brakami w opcjach)
**Priorytet:** P0

### Stan wejściowy
Nowy user po rejestracji/loginie. `onboardingCompleted === false && workouts.length === 0`.

### Preconditions
- User zalogowany.
- Wizard jest wymuszany (nie da się ominąć dashboardem przed wyborem).

### Kroki użytkownika
1. Widzi ekran "Skąd chcesz zaimportować dane treningowe?".
2. Widzi listę opcji: Garmin, Strava, Upload pliku TCX, Brak danych / wpiszę ręcznie.
3. Wybiera opcję.

### Oczekiwane zachowanie UI
- Każda opcja ma czytelną ikonę i krótki opis.
- Aktywne (klikalne) są tylko opcje z działającą integracją (dziś: Garmin, Strava partial, Upload TCX, Brak danych).
- Polar i Suunto: widoczne ale wyszarzone z labelem "wkrótce" lub w sekcji "Inne".
- Coros: jak Polar/Suunto.
- Pod listą: link/przycisk **"Brakuje Twojej aplikacji? Powiadom nas"**.

### Oczekiwane API
- Brak na tym kroku — wybór jest stanem frontu.

### Kryteria akceptacji (P0)
- 4 aktywne opcje są widoczne i klikalne: Garmin, Strava, Upload, Brak danych.
- Wybór przekierowuje do odpowiedniego sub-flow:
  - Garmin → US-GARMIN-001
  - Strava → US-STRAVA-001
  - Upload → US-IMPORT-001 (multi-upload TCX)
  - Brak danych → faza 2 wizardu (manual minimum)

### Testy / smoke
- Manual smoke każdej z 4 ścieżek.
- Test e2e dla 1 ścieżki happy path.

### Uwagi produktowe
Polar i Suunto powinny być widoczne ale nieklikalne — nie udajemy że mamy. Coros można dorzucić obok nich z notką "fallback: import pliku FIT/TCX".

---

## US-ONBOARD-004 — Wizard onboardingu: faza 2 — pytania uzupełniające

**Typ:** happy path
**Persona:** P-NOVICE (manual flow), P-GARMIN (po sukcesie sync), inne po wyborze źródła
**Status:** partial
**Priorytet:** P0

### Stan wejściowy
User zakończył fazę 1 (wybrał źródło i ewentualnie zaimportował dane lub wybrał manual).

### Preconditions
- Sesja aktywna.
- Faza 1 zakończona.

### Kroki użytkownika
1. Widzi formularz z pytaniami.
2. Wypełnia: cel (tekstowy), data startu (jeśli ma), liczba dni biegania w tygodniu, dni niedostępne, ból tak/nie + opis.
3. Manual-only path: dodatkowo ostatni bieg, biegi w 2 tyg., najdłuższy bieg, czy przebiegnie 30 min ciągłego biegu.
4. Klika "Zakończ" lub "Pomiń".

### Oczekiwane zachowanie UI
- Pola są zwalidowane lokalnie (data niepusta jeśli wybrana, dni 1–7).
- "Pomiń" widoczny w prawym górnym rogu lub na dole.
- Po submit: spinner, potem redirect do dashboardu.

### Oczekiwane API
- `PUT /api/me/profile` (lub odpowiednik) z danymi formularza.
- Zapis idzie w tle, frontend optymistycznie przekierowuje do dashboardu.

### Oczekiwane zmiany danych
- `user_profiles`: zaktualizowane pola, w tym `onboarding_completed = true` (jeśli submit) lub bez tej flagi (jeśli skip).
- Tworzony rekord `race` jeśli user podał datę startu (uproszczony: data + dystans z celu + priority A).

### Kryteria akceptacji (P0)
- Wszystkie pola dziś obecne w UI są zapisywane do backendu.
- "Pomiń" działa optymistycznie — user trafia do dashboardu nawet jeśli zapis trwa.
- Cel tekstowy jest zachowywany (np. "10 km w 50 minut").
- Liczba dni biegania jest liczbą 1–7.
- Dni niedostępne to lista (np. ['mon', 'wed']) lub pusta.
- Ból: pole bool + opcjonalny opis tekstowy.

### Testy / smoke
- Test e2e: submit → profil zapisany → dashboard.
- Test backend: `PUT /api/me/profile` z pełnym body i z minimum.
- Test backend: pole `has_current_pain` ma realny wpływ na adjustments (już pokryte w `TrainingAdjustmentsServiceTest`).

### Uwagi produktowe
**Brakuje w UI (backend już ma):** races full structure (name, targetTime, priority A/B/C), maxSessionMin, hrZones, paceZones, preferredSurface, crossTrainingPromptPreference. To są P1 — dodać po MVP launch lub równolegle do zakładki Profil.

**Nie ma w ogóle:** wiek, waga, wzrost, płeć, HR spoczynkowe, HR max, sen, stres. To są P2 — backendowo można je policzyć z danych treningowych, więc UI nie jest blockerem.

---

## US-ONBOARD-005 — Pominięcie onboardingu (skip)

**Typ:** edge case
**Persona:** P-GARMIN (impatient)
**Status:** implemented
**Priorytet:** P0

### Stan wejściowy
User w wizardzie (faza 1 lub 2). Klika "Pomiń".

### Preconditions
- User zalogowany.
- Wizard otwarty.

### Kroki użytkownika
1. Klika "Pomiń" w prawym górnym rogu (lub odpowiedniku).

### Oczekiwane zachowanie UI
- Frontend optymistycznie przekierowuje do dashboardu.
- Dashboard pokazuje:
  - `OnboardingSummaryCard` z empty state lub "Uzupełnij profil".
  - `AnalyticsSummary` z empty state ("Brak danych — zaimportuj treningi").
  - `GarminSection` z opcją połączenia.
  - `WeeklyPlanSection` z empty state lub bardzo ostrożnym planem startowym.
  - `AiPlanSection` z low-data warning.
  - File picker.
  - Pusta lista treningów.

### Oczekiwane API
- `PUT /api/me/profile` z minimalnym body (zapis idzie w tle).
- Equivalent flagi `onboarding_completed = false`, ale `workouts.length === 0` może wracać przy każdym kolejnym otwarciu.

### Kryteria akceptacji (P0)
- Po skipie user widzi dashboard, nie wizard.
- Wszystkie komponenty dashboardu nie crashują na pustych danych — pokazują empty state.
- User może w każdej chwili wrócić do onboardingu (przyszłościowo: zakładka Profil; dziś: missing).

### Testy / smoke
- Manual smoke: skip → dashboard → wszystkie komponenty wyrenderowane.
- Regression test: każdy komponent dashboardu z `data = []`.

### Uwagi produktowe
Dziś po skipie wizard pojawi się ponownie tylko jeśli `workouts.length === 0`. Po pierwszym imporcie/uploadzie wizard zniknie nawet bez wypełnienia profilu. To jest **akceptowalny kompromis MVP**, ale po launchu zakładka Profil pozwoli wrócić do uzupełnienia.

---

## US-ONBOARD-006 — Powiadomienie o brakującej aplikacji

**Typ:** edge case
**Persona:** P-COROS, hipotetyczny user Polar/Suunto/inny
**Status:** missing
**Priorytet:** P1

### Stan wejściowy
User w fazie 1 wizardu, jego sprzęt nie jest na liście.

### Preconditions
- User zalogowany.
- Wizard otwarty.

### Kroki użytkownika
1. Widzi listę integracji.
2. Pod listą widzi link/przycisk "Brakuje Twojej aplikacji? Powiadom nas".
3. Klika.
4. Wypełnia formularz: nazwa aplikacji/sprzętu, opcjonalnie email kontaktowy, opcjonalnie krótki komentarz.
5. Submituje.
6. Widzi potwierdzenie "Dziękujemy, dodaliśmy Twoją sugestię do listy".

### Oczekiwane zachowanie UI
- Modal lub osobna strona z prostym formularzem.
- Po submit: toast/komunikat "Dzięki, zapisaliśmy".
- Modal zamyka się i wraca do wizardu.

### Oczekiwane API
- `POST /api/integration-requests` (nowy endpoint, missing).
- Body: `{ appName: string, contactEmail?: string, comment?: string }`.

### Oczekiwane zmiany danych
- Nowa tabela `integration_requests`: `id, user_id, app_name, contact_email, comment, created_at`.
- Wpis tworzony przy submit.

### Kryteria akceptacji (P1)
- Formularz dostępny z fazy 1 wizardu.
- Submit zapisuje request w bazie.
- User widzi potwierdzenie.
- Walidacja: `appName` wymagany (min 2 znaki), email jeśli podany — poprawny format.
- Pojedynczy user nie może zaspamować — rate limit 5 requestów/dzień (P2).

### Testy / smoke
- Test backend: POST → nowy wpis w `integration_requests`.
- Test e2e: full flow z UI.

### Uwagi produktowe
**To jest też kanał feedbacku produktowego.** Warto dodać kolumnę `status` (new / triaged / planned / done / rejected), żeby admin mógł odpowiadać. Ale to już P2.

Fallback dla użytkownika Coros/Polar/Suunto bez integracji: powiedzieć w komunikacie potwierdzenia "W międzyczasie możesz wgrywać pliki FIT/TCX/GPX z aplikacji swojego sprzętu" + link do US-IMPORT-001.

---

## US-ONBOARD-007 — Powrót do onboardingu z zakładki Profil

**Typ:** happy path (przyszłościowy)
**Persona:** P-NOVICE (po skipie)
**Status:** missing
**Priorytet:** P1

### Stan wejściowy
User wcześniej pominął onboarding lub go zrobił częściowo. Chce uzupełnić.

### Preconditions
- User zalogowany.
- Zakładka Profil istnieje (dziś: missing).

### Kroki użytkownika
1. Klika "Profil" w nawigacji tabelarycznej (dziś: missing).
2. Widzi swoje obecne dane.
3. Klika "Uzupełnij" lub "Edytuj".
4. Otwiera się wizard lub formularz edycji z prefill obecnych wartości.
5. Edytuje, submituje.

### Oczekiwane zachowanie UI
- Formularz prefilluje obecne wartości.
- Submit aktualizuje, nie nadpisuje pustymi wartościami.
- Po submit: powrót do widoku Profil z toastem "Zaktualizowano".

### Oczekiwane API
- `GET /api/me/profile` — pobranie obecnych wartości.
- `PUT /api/me/profile` — partial update (PATCH semantics).

### Kryteria akceptacji (P1)
- User może edytować pojedyncze pola bez utraty pozostałych.
- Submit z pustym polem nie kasuje istniejącej wartości (chyba że user explicit clear).
- Walidacja jak w fazie 2 wizardu.

### Testy / smoke
- Test e2e: edit profile → reload → wartości przetrwały.

### Uwagi produktowe
Wymaga dwóch rzeczy: (1) nawigacja tabelaryczna w UI, (2) dedykowany widok Profil. Bez tego user nie ma jak wrócić do uzupełnienia po skipie.

---

## US-ONBOARD-008 — Onboarding na małym ekranie (mobile)

**Typ:** edge case (regression)
**Persona:** każda
**Status:** unknown
**Priorytet:** P1

### Stan wejściowy
User otwiera aplikację na telefonie (iOS Safari, Android Chrome).

### Preconditions
- Frontend ma responsywne style (Tailwind).

### Kroki użytkownika
1. Otwiera stronę.
2. Loguje się.
3. Przechodzi przez wizard.
4. Wgrywa plik TCX z telefonu (jeśli ma).

### Oczekiwane zachowanie UI
- Wszystkie elementy klikalne mają min. 44×44 px target.
- Formularze nie wychodzą poza viewport.
- File picker akceptuje pliki z mobilnego storage.

### Kryteria akceptacji (P1)
- Wizard wykonalny do końca na ekranie 360×640.
- Brak overflow horizontal.
- Klawiatura mobilna nie zasłania pól wejściowych.

### Testy / smoke
- Manual smoke: iPhone i Android.
- Lighthouse mobile audit.

### Uwagi produktowe
**Status unknown** — nie wiem czy to było testowane. Z punktu widzenia produktu: większość biegaczy importuje pliki z desktopa po treningu. Mobile MVP może być "zaloguj się + zobacz plan dnia", bez full uploadu.

---

## US-ONBOARD-009 — Drugi login z innego urządzenia

**Typ:** edge case
**Persona:** P-MULTI
**Status:** implemented (zakładam, do potwierdzenia)
**Priorytet:** P1

### Stan wejściowy
User zalogowany na laptopie. Loguje się dodatkowo na telefonie.

### Preconditions
- Konto istnieje, sesja aktywna na 1 urządzeniu.

### Kroki użytkownika
1. Otwiera stronę na 2. urządzeniu.
2. Loguje się.

### Oczekiwane zachowanie
- Obie sesje są aktywne równolegle (TTL 30 dni dla każdej).
- Logout na 1 urządzeniu nie wywala 2.

### Oczekiwane API
- `POST /api/auth/login` zwraca nowy token.
- Stary token na pierwszym urządzeniu nadal działa.

### Kryteria akceptacji (P1)
- Multi-session działa.
- Każdy logout revokuje tylko swój token.
- Brak limit ilości sesji per user (lub jest jasno udokumentowany).

### Testy / smoke
- Test backend: 2× login → 2 różne tokeny.
- Test backend: logout token A → token B nadal valid.

### Uwagi produktowe
Jeśli dodajemy "wyloguj wszędzie" jako feature — to osobny scenariusz P2.

---

## US-ONBOARD-010 — Pominięcie wyboru źródła i przejście od razu do pytań

**Typ:** edge case
**Persona:** P-NOVICE
**Status:** partial
**Priorytet:** P0

### Stan wejściowy
User w fazie 1 wybiera "Brak danych / wpiszę ręcznie".

### Kroki użytkownika
1. Faza 1 — klika "Brak danych".
2. Faza 2 — wypełnia rozszerzony formularz manual: cel, data, dni biegowe, ból, ostatni bieg, biegi w 2 tyg., najdłuższy bieg, czy przebiegnie 30 min.
3. Submit.

### Oczekiwane zachowanie UI
- Faza 2 ma więcej pól dla manual flow niż dla flow z importem.
- Po submit: dashboard z bardzo ostrożnym planem startowym, low-confidence label.

### Oczekiwane API
- `PUT /api/me/profile` z manual fields.
- Brak importu treningów.

### Kryteria akceptacji (P0)
- Manual fields są zapisywane.
- Plan generuje się na podstawie self-reported danych.
- Plan ma label "Niski poziom pewności — uzupełnimy po pierwszych treningach".

### Testy / smoke
- Test backend: manual onboarding → profil → plan → confidence flag low.

### Uwagi produktowe
To jest scenariusz dla P-NOVICE. Plan musi być **bardzo ostrożny**: same easy biegi, krótkie, bez akcentów, do czasu zebrania min. 6 realnych treningów.
