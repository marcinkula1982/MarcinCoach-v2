# 03 — Analiza danych i profil użytkownika

Plik obejmuje: analiza zaimportowanych treningów, AnalyticsSummary, OnboardingSummaryCard, profil użytkownika (dane z importu + ankiety), propozycja stref HR/pace, confidence score, low-data fallback.

Realny stan 28.04.2026:
- Backend ma `AnalyticsSummary` z byDay, byWeek, zones, longRunKm, avgPaceSecPerKm.
- Backend ma `ProfileQualityScoreService`.
- Backend ma sygnały: load, intensity, longRun, consistency, flags.
- UI ma `AnalyticsSummary` i `OnboardingSummaryCard`.
- Propozycja stref HR w onboardingu: **missing** (backend ma `hrZones` w profilu, UI nie pokazuje propozycji wyliczonej z danych).

---

## US-ANALYSIS-001 — Wyświetlenie analizy po pierwszym imporcie (≥6 treningów)

**Typ:** happy path
**Persona:** P-GARMIN (po Garmin sync), P-MULTI (po multi-upload)
**Status:** partial (komponenty istnieją, kompleksowość TBD)
**Priorytet:** P0

### Stan wejściowy
User zaimportował 6+ treningów (manual upload lub Garmin sync).

### Preconditions
- Workouts w bazie ≥ 6.
- Sygnały przeliczone.

### Kroki użytkownika
1. Trafia na dashboard po imporcie.
2. Widzi `AnalyticsSummary`, `OnboardingSummaryCard`, `WeeklyPlanSection`.

### Oczekiwane zachowanie UI
- `AnalyticsSummary` pokazuje:
  - Sumaryczny dystans i czas (ostatnie 28 dni).
  - Liczba sesji.
  - Średnie tempo.
  - Najdłuższy bieg.
  - Rozkład intensywności (zones Z1–Z5 w sekundach lub %).
  - Trend tygodniowy (byWeek).
- `OnboardingSummaryCard` pokazuje:
  - Liczba zaimportowanych treningów.
  - Okres analizy (np. "ostatnie 30 dni").
  - Confidence label (np. "Średni poziom pewności").

### Oczekiwane API
- `GET /api/training-signals?days=28` — sygnały.
- `GET /api/workouts?from=...&to=...` — lista treningów.
- `GET /api/me/profile` — profil z `quality.score`.

### Kryteria akceptacji (P0)
- Wszystkie wartości obliczone deterministycznie z workoutów.
- Brak danych w polu (np. brak HR) wyświetlany jako "—" lub "Brak danych".
- Confidence: niski (<6 treningów), średni (6-15), wysoki (15+, w tym ≥1 long, ≥1 quality).

### Testy / smoke
- Test backend: 6 fixture workoutów → analytics summary z poprawnymi liczbami.
- Test e2e: import → dashboard → wszystkie liczby widoczne.

### Uwagi produktowe
Z notatek projektowych: "12-20 plików = realna analiza, 25+ = pełny profil". Confidence boundaries powinny to odzwierciedlać.

---

## US-ANALYSIS-002 — Niski confidence przy <6 treningach

**Typ:** edge case
**Persona:** P-NOVICE, P-RETURN (powrót po przerwie)
**Status:** partial
**Priorytet:** P0

### Stan wejściowy
User ma 0–5 treningów (po skipie wizardu lub po pierwszym uploadzie).

### Preconditions
- `workouts.count < 6`.

### Oczekiwane zachowanie UI
- `OnboardingSummaryCard` pokazuje banner "Niski poziom pewności — uzupełnimy po pierwszych treningach".
- `AnalyticsSummary` pokazuje dane co jest, z labelem "Pierwsze obserwacje".
- `WeeklyPlanSection` pokazuje **bardzo ostrożny plan**: 2-3 easy biegi, krótkie, bez akcentów.
- `AiPlanSection` (jeśli widoczny) ma label "Niski poziom pewności".

### Kryteria akceptacji (P0)
- Aplikacja **nie udaje pewności**.
- Plan nie zawiera quality session ani long run > 30 min do czasu zebrania danych.
- User widzi jasno: "Po X treningach zaproponujemy bogatszy plan".

### Testy / smoke
- Test backend: 3 fixtures → plan ma tylko easy bieg.
- Test e2e: po pierwszym uploadzie label confidence widoczny.

### Uwagi produktowe
**Kluczowe dla zaufania użytkownika.** Aplikacja, która rzuca quality intervals po 2 importach, traci wiarygodność.

---

## US-ANALYSIS-003 — Brak danych (0 treningów po skipie)

**Typ:** edge case
**Persona:** P-NOVICE
**Status:** partial
**Priorytet:** P0

### Stan wejściowy
User pominął onboarding i nie wgrał żadnego treningu.

### Preconditions
- `workouts.count === 0`.

### Oczekiwane zachowanie UI
- `AnalyticsSummary` pokazuje empty state: "Wgraj pierwsze treningi, żeby zobaczyć analizę".
- `WeeklyPlanSection` pokazuje plan startowy bazujący tylko na manual onboarding answers (jeśli były) lub generic plan dla początkującego.
- `OnboardingSummaryCard` zachęca do uzupełnienia profilu.

### Kryteria akceptacji (P0)
- Żaden komponent nie crashuje na `data = []`.
- Empty states są user-friendly, nie pokazują błędów.
- Plan jest minimalny i bezpieczny.

### Testy / smoke
- Manual smoke: świeże konto, skip, dashboard renderuje się bez błędów.
- Test e2e: każdy komponent z `workouts = []`.

### Uwagi produktowe
Cold start jest pokryty `ColdStartTest.php` na backendzie. Frontend może być słabiej pokryty.

---

## US-ANALYSIS-004 — Propozycja stref HR po wystarczających danych

**Typ:** happy path (przyszłościowy)
**Persona:** P-GARMIN, P-MULTI
**Status:** missing (UI), partial (backend ma `hrZones` w profilu)
**Priorytet:** P1

### Stan wejściowy
User ma ≥10 treningów z danymi HR w ostatnich 30 dniach.

### Preconditions
- Backend ma logikę liczenia stref z realnych danych (nie z tabel wiekowych).

### Oczekiwane zachowanie UI
- W onboardingu (po imporcie) lub w zakładce Profil:
- Banner "Wyliczyliśmy Twoje strefy tętna z ostatnich treningów":
  - Z1: 100–130 bpm
  - Z2: 130–150 bpm
  - Z3: 150–165 bpm
  - Z4: 165–175 bpm
  - Z5: 175+ bpm
- User akceptuje, edytuje, lub odrzuca.

### Oczekiwane API
- `GET /api/me/profile/proposed-hr-zones` (nowy endpoint).
- `PUT /api/me/profile` z `hrZones`.

### Kryteria akceptacji (P1)
- Strefy są wyliczone z realnych danych (max HR z ostatnich treningów, percentyle dla rozkładu).
- User może edytować lub zaakceptować.
- Edytowane strefy wpływają na intensity calculation kolejnych workoutów.

### Testy / smoke
- Test backend: 15 fixtures z różnym HR → propozycja stref.

### Uwagi produktowe
Spec mówi: "nie może opierać stref na samym wieku lub wzorach z tabel". Realne implementacja to TODO. Bez tego strefy są albo statyczne (220-wiek), albo brak. Wpływa na precyzję klasyfikacji intensywności i feedbacku.

---

## US-ANALYSIS-005 — Propozycja stref pace per user

**Typ:** happy path (przyszłościowy)
**Persona:** P-MULTI
**Status:** missing
**Priorytet:** P1

### Stan wejściowy
User ma ≥15 treningów, w tym min. 1 quality session i 1 long run.

### Oczekiwane zachowanie UI
- W zakładce Profil banner "Twoje strefy tempa":
  - Easy: 5:30–6:00 /km
  - Marathon: 4:50 /km
  - Threshold: 4:30 /km
  - VO2max: 4:00 /km

### Kryteria akceptacji (P1)
- Pace zones liczone z realnych danych (long run pace, quality pace, average easy pace).
- User akceptuje lub edytuje.
- Plan korzysta z tych stref do generowania quality structure.

### Testy / smoke
- Test backend: dataset realnych treningów → realistic pace zones.

### Uwagi produktowe
Roadmap punkt 3 "deeper data hardening" wspomina to: "wykorzystać `profile.paceZones` w planie i feedbacku".

---

## US-ANALYSIS-006 — Wyświetlenie historii treningów

**Typ:** happy path
**Persona:** każda po imporcie
**Status:** implemented
**Priorytet:** P0

### Stan wejściowy
User na dashboardzie, ma zaimportowane treningi.

### Kroki użytkownika
1. Widzi listę treningów (`WorkoutsList`).
2. Klika na trening.

### Oczekiwane zachowanie UI
- Lista pokazuje: data, dystans, czas, średnie tempo, sport (ikonka).
- Sortowanie: najnowsze pierwsze.
- Paginacja lub infinite scroll.
- Klik na trening: szczegóły (modal lub osobna strona).

### Oczekiwane API
- `GET /api/workouts?limit=20&offset=0` — lista.
- `GET /api/workouts/{id}` — szczegóły.

### Kryteria akceptacji (P0)
- Lista renderuje się z 50 treningami w < 1s.
- Każdy trening ma datę, dystans, czas.
- Klik otwiera szczegóły z `summary`, `intensity`, `hr`.

### Testy / smoke
- Test e2e: 50 fixtures → lista paginowana.

---

## US-ANALYSIS-007 — Trend formy / progres

**Typ:** happy path (przyszłościowy)
**Persona:** P-MULTI, P-RETURN (po 4-8 tygodniach)
**Status:** missing (frontend), partial (backend ma block context)
**Priorytet:** P1

### Stan wejściowy
User ma ≥6 tygodni danych.

### Oczekiwane zachowanie UI
- Wykres tygodniowy: kilometraż, czas, intensywność.
- Trend: rosnący / stabilny / malejący.
- Comparison: ostatnie 4 tyg vs wcześniejsze 4 tyg.

### Kryteria akceptacji (P1)
- Wykresy renderują się dla użytkownika z 6+ tygodni danych.
- User widzi czy łapie progres czy regres.

### Testy / smoke
- Test e2e: dataset 8 tygodni → wykres i trend label.

### Uwagi produktowe
Backend już ma `BlockPeriodizationService` i `PlanMemoryService`. UI to dopełnia. P1 bo użytkownicy łakną wykresów, ale plan i feedback są P0.

---

## US-ANALYSIS-008 — Profile Quality Score widoczny dla użytkownika

**Typ:** happy path
**Persona:** każda
**Status:** partial (backend liczy, UI nie pokazuje wyraźnie)
**Priorytet:** P1

### Stan wejściowy
User ma profil i jakieś treningi.

### Oczekiwane zachowanie UI
- W zakładce Profil widoczny `Profile Quality Score` (np. 65/100).
- Lista co podnosi/obniża score:
  - "Brak danych HR (-15)"
  - "Brak celu startowego (-10)"
  - "Mało treningów (<6) (-20)"
  - "Wszystkie podstawowe pola wypełnione (+30)"

### Kryteria akceptacji (P1)
- Score jest deterministyczny i powtarzalny (`ProfileQualityScoreService`).
- User wie co zrobić żeby go podnieść.
- Wyższy score = bardziej precyzyjny plan i feedback.

### Testy / smoke
- Test backend: różne profile → różne score (już pokryte w `ProfileQualityScoreServiceTest`).

### Uwagi produktowe
**To jest sposób na "edukację użytkownika"** — pokazuje że więcej danych = lepszy plan. Bez tego user nie wie czemu plan jest mało dokładny.
