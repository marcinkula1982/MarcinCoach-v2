# 08 — Manual check-in bez integracji i bez plików

Plik obejmuje użytkownika, który nie podłącza Garmin/Strava/Polar/Suunto/Coros i po treningach nie wrzuca TCX/GPX/FIT. Aplikacja musi dalej działać jako coach: planuje ostrożnie, pyta po treningu "czy zrobiłeś / jak poszło", zbiera minimalne dane subiektywne i na tej podstawie aktualizuje feedback oraz kolejny plan.

Realny stan 30.04.2026:
- Onboarding ma ścieżkę "Brak danych / wpiszę ręcznie".
- Backend potrafi zapisać `workoutMeta` (`planCompliance`, `rpe`, `fatigueFlag`, `note`) dla istniejącego workoutu.
- Backend ma `manual_check_ins` oraz `POST /api/workouts/manual-check-in`: `done`/`modified` zapisują syntetyczny workout `MANUAL_CHECK_IN` bez `tcxRaw`, a `skipped` zamyka dzień bez treningu 0 km.
- Check-in jest idempotentny per dzień albo per dzień + `plannedSessionId`.
- Frontend ma przy sesjach rolling planu przyciski "Wykonane", "Zmienione" i "Nie zrobiłem" oraz modal check-inu bez pliku.
- Feedback bez danych telemetrycznych działa backendowo i nie wymyśla HR/tempa, gdy ich nie ma; flow bez pliku ma UI po EP-008, auto-refresh planu po EP-009 i lokalny API smoke po EP-010. Produkcyjny/browser smoke nadal nieuruchomiony.

Ten obszar jest **core MVP**. Bez niego "brak integracji" oznacza tylko startowy plan, a nie zamkniętą pętlę coachingu.

---

## US-MANUAL-001 — Plan startowy bez integracji i bez plików

**Typ:** happy path / low-data
**Persona:** P-NOVICE, P-RETURN
**Status:** partial
**Priorytet:** P0

### Stan wejściowy
Nowy użytkownik nie ma zegarka, nie ma historii treningów i nie chce importować plików.

### Kroki użytkownika
1. Rejestruje się lub loguje.
2. W onboardingu wybiera "Brak danych / wpiszę ręcznie".
3. Uzupełnia minimum: cel, liczba dni biegania, ograniczenia, ból/kontuzja, ostatnia aktywność.
4. Kończy onboarding.

### Oczekiwane zachowanie UI
- Dashboard pokazuje 14-dniowy rolling plan.
- Plan ma wyraźny label niskiej pewności, np. "Niski poziom pewności — doprecyzujemy po kilku check-inach".
- UI nie blokuje użytkownika komunikatem "podłącz integrację".
- CTA integracji/uploadu może być widoczne, ale jako opcja, nie wymóg.

### Oczekiwane API
- `PUT /api/me/profile` zapisuje odpowiedzi manualne.
- `GET /api/rolling-plan?days=14` zwraca plan startowy.

### Kryteria akceptacji (P0)
- User bez workoutów widzi plan, nie pusty dashboard.
- Plan respektuje dni treningowe i ograniczenia z profilu.
- Confidence jest oznaczony jako niski.
- User widzi następny krok po treningu: check-in manualny.

### Testy / smoke
- E2E: nowe konto → brak danych → onboarding manualny → dashboard → plan widoczny.
- Backend: profil bez workoutów → rolling plan bez 500.
- Lokalny API smoke po EP-010: register/login → profil manual-source → rolling plan bez pliku → check-in → feedback → rolling plan.

---

## US-MANUAL-002 — Oznaczenie dzisiejszego treningu jako wykonanego

**Typ:** happy path
**Persona:** P-NOVICE, P-RETURN
**Status:** implemented
**Priorytet:** P0

### Stan wejściowy
User ma na dziś zaplanowany trening, ale nie ma pliku ani integracji.

### Kroki użytkownika
1. Otwiera dashboard lub widok planu.
2. Przy dzisiejszej sesji klika "Wykonane".
3. Wypełnia krótki check-in.
4. Zapisuje.

### Oczekiwane zachowanie UI
- Check-in jest dostępny bez uploadu pliku.
- Minimalny formularz ma: wykonano/nie wykonano, czas trwania, RPE 1-10, samopoczucie, ból/kontuzja, opcjonalna notatka.
- Dystans jest opcjonalny, bo beginner może go nie znać.
- Jeśli user nie poda dystansu, feedback nie mówi o tempie.

### API
- `POST /api/workouts/manual-check-in`
- Body minimum:
  - `plannedSessionDate`
  - `status: done` (`completed` jest akceptowane jako alias)
  - `durationMin`
  - `distanceKm?`
  - `rpe?`
  - `mood?`
  - `painFlag?`
  - `note?`

### Oczekiwane zmiany danych
- Powstaje workout `source=MANUAL_CHECK_IN` bez raw file.
- Workout jest powiązany z rekordem `manual_check_ins` i planowaną sesją z danego dnia.
- Przeliczają się signals/compliance/alerts w wersji low-data.

### Kryteria akceptacji (P0)
- Zapis działa bez `tcxRaw`.
- User nie musi wybierać pliku.
- Dzisiejsza sesja zmienia stan na wykonaną.
- Po zapisie można wygenerować feedback.

### Testy / smoke
- E2E: plan dziś → Wykonane → zapis → workout na liście.
- Backend: manual check-in bez dystansu zapisuje się poprawnie.
- Lokalny API smoke po EP-010: `done` check-in bez pliku tworzy workout `MANUAL_CHECK_IN`, bez rekordu `workout_raw_tcx`.

---

## US-MANUAL-003 — Check-in z częściowymi danymi: czas, dystans, RPE, samopoczucie

**Typ:** happy path / partial data
**Persona:** P-NOVICE, P-RETURN
**Status:** implemented
**Priorytet:** P0

### Stan wejściowy
User zrobił trening, ale pamięta tylko część danych.

### Warianty danych
- Tylko "zrobione" + RPE.
- Czas + RPE.
- Czas + dystans + RPE.
- Czas + dystans + RPE + ból/notatka.

### Oczekiwane zachowanie UI
- Formularz pozwala zapisać każdy z wariantów.
- Pola niewypełnione nie są zastępowane fałszywymi zerami.
- UI komunikuje, że im więcej danych, tym lepszy feedback, ale nie zawstydza użytkownika.

### Oczekiwane API
- Manual check-in akceptuje brak dystansu i brak RPE, ale wymaga przynajmniej statusu wykonania.
- Backendowy kontrakt po EP-007: `POST /api/workouts/manual-check-in`, statusy `done`, `modified`, `skipped`, idempotencja per dzień/sesja.
- `PATCH /api/workouts/{id}/meta` może być użyty tylko dla istniejącego workoutu; nie zastępuje tworzenia manualnego workoutu.

### Kryteria akceptacji (P0)
- Brak dystansu nie powoduje crashu analizy.
- Brak HR nie powoduje sugestii stref HR.
- RPE wpływa na fatigue / plan impact.
- Ból/kontuzja trafia do ostrzeżeń i korekt planu.

### Testy / smoke
- Backend: 4 warianty body, każdy zapisuje workout.
- E2E: zapis tylko czasu i RPE → feedback bez pace/HR.
- Lokalny API smoke po EP-010: czas + RPE bez dystansu przechodzi przez zapis, listę workoutów i feedback.

---

## US-MANUAL-004 — Trening wykonany inaczej niż plan

**Typ:** edge case
**Persona:** P-NOVICE, P-RETURN, P-GARMIN bez uploadu po treningu
**Status:** implemented
**Priorytet:** P0

### Stan wejściowy
Plan: easy 45 min. User zrobił 25 min albo 70 min.

### Kroki użytkownika
1. Klika "Wykonane".
2. Wpisuje rzeczywisty czas.
3. Zaznacza lub system sugeruje `modified`.
4. Dodaje opcjonalny powód.

### Oczekiwane zachowanie UI
- UI nie wymaga ręcznego rozumienia `planned/modified/unplanned`.
- System może zasugerować "zmodyfikowany", ale user może poprawić.
- Feedback odnosi się do różnicy czasu/intensywności, nie do HR/pace.

### Oczekiwane API
- Manual check-in zapisuje `planCompliance: modified`.
- `durationMin` jest porównywane z planowaną sesją.

### Kryteria akceptacji (P0)
- Krótszy trening nie znika jako "done OK".
- Dłuższy/mocniejszy trening wpływa na ostrożność kolejnych dni.
- User może dodać powód, np. brak czasu, zmęczenie, ból.

### Testy / smoke
- Backend: planned 45, actual 25 → deviation.
- Backend: planned 45, actual 70 + RPE 8 → fatigue warning.

---

## US-MANUAL-005 — Pominięcie zaplanowanego treningu

**Typ:** happy path / missed session
**Persona:** P-NOVICE, P-RETURN
**Status:** partial
**Priorytet:** P0

### Stan wejściowy
User nie zrobił zaplanowanego treningu.

### Kroki użytkownika
1. Przy dzisiejszej sesji klika "Nie zrobiłem".
2. Wybiera opcjonalny powód: brak czasu, zmęczenie, ból, choroba, inne.
3. Zapisuje.

### Oczekiwane zachowanie UI
- User nie musi tworzyć fałszywego workoutu o czasie 0.
- Komunikat jest neutralny i wspierający.
- Jeśli powodem jest ból/choroba, plan powinien stać się ostrożniejszy.

### API
- `POST /api/workouts/manual-check-in`
- Body: `status: skipped`, `plannedSessionDate`, `reason?`, `painFlag?`, `note?`

### Kryteria akceptacji (P0)
- Pominięcie jest osobnym stanem, nie treningiem 0 km. Backendowo po EP-007 `skipped` zapisuje `manual_check_ins.workout_id = null`.
- Plan na kolejne dni może przesunąć lub uprościć kluczową jednostkę.
- Pominięty long run/quality generuje inny plan impact niż pominięty easy.
- Po zapisie check-inu frontend odświeża `GET /api/rolling-plan?days=14`.

### Testy / smoke
- E2E: dzisiejsza sesja → Nie zrobiłem → plan jutro bez crashu.
- Backend: skipped long run → alert/plan impact.

---

## US-MANUAL-006 — Feedback bez telemetryki

**Typ:** happy path / low-data feedback
**Persona:** P-NOVICE, P-RETURN
**Status:** implemented
**Priorytet:** P0

### Stan wejściowy
User zapisał manual check-in bez HR, tempa i trackpointów.

### Oczekiwane zachowanie feedbacku
- Feedback korzysta tylko z tego, co wiadomo: plan dnia, wykonano/nie wykonano, czas, dystans opcjonalny, RPE, ból, notatka.
- Feedback nie wymyśla tempa, stref HR ani obciążenia z trackpointów.
- `confidence` jest niższy niż przy Garmin/TCX.
- `planImpact` mówi ostrożnie, co może się zmienić w kolejnych dniach.
- Backend po EP-007 zwraca `confidence=low` dla manual check-inu i ukrywa pace/HR, gdy nie ma dystansu/telemetrii.

### Oczekiwane sekcje
- `praise` — np. konsekwencja / powrót do rytmu.
- `deviations` — różnica względem planu, jeśli są dane.
- `conclusions` — interpretacja RPE/bólu.
- `planImpact` — wpływ na jutro/następne 3 dni.
- `confidence` — jawnie low/manual.

### Kryteria akceptacji (P0)
- Brak HR/pace nie powoduje pustego feedbacku.
- Feedback nie zawiera fałszywie precyzyjnych stwierdzeń.
- Pain flag ma pierwszeństwo nad motywacyjnym tonem.

### Testy / smoke
- Backend: manual workout bez dystansu → feedback bez pace/HR.
- E2E: check-in → feedback widoczny → plan odświeżony.
- Lokalny API smoke po EP-010: feedback dla manual check-inu ma `confidence=low` i `summary.avgPaceSecPerKm=null`, a po nim `GET /api/rolling-plan?days=14` widzi co najmniej 1 workout.

---

## US-MANUAL-007 — Długoterminowy manual mode

**Typ:** regression / product fallback
**Persona:** P-NOVICE, P-RETURN
**Status:** missing
**Priorytet:** P1

### Stan wejściowy
User przez 2 tygodnie nie podłącza integracji i nie wrzuca plików, ale regularnie robi check-iny.

### Oczekiwane zachowanie
- System dalej generuje rolling plan.
- Profile Quality Score rośnie wolniej niż przy danych z zegarka, ale nie stoi w miejscu.
- UI może zachęcać do integracji/uploadu, ale nie blokuje.
- Plan bazuje na trendach manualnych: regularność, wykonanie, RPE, ból, czas trwania.

### Kryteria akceptacji (P1)
- Po 14 dniach manual check-inów dashboard nie wraca do pustych stanów.
- Plan nie zwiększa obciążenia agresywnie bez danych telemetrycznych.
- User widzi jasny komunikat: "Plan jest oparty na Twoich check-inach".

### Testy / smoke
- Seed 14 dni manual check-inów → rolling plan → confidence manual/medium-low.
- E2E regresja: brak plików przez cały flow.
