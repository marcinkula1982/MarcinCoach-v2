# 04 — Plan i pętla feedbacku

Plik obejmuje: rolling plan 14 dni, generowanie i odświeżanie planu, feedback po treningu (deterministyczny), korekta planu po treningu, trening zgodny z planem, trening zmodyfikowany, trening spontaniczny, cross-training, race profile w planie.

Realny stan 30.04.2026:
- Frontend pokazuje 14 dni przez `fetchRollingPlan(14)`.
- Brak ręcznej edycji / przesuwania treningów.
- Backend i frontend mają manual check-in dla wykonania/zmiany/skipa bez pliku (`POST /api/workouts/manual-check-in`) przy sesjach rolling planu.
- "Refresh" pozwala wygenerować nowy plan, opcjonalnie z modal cross-training.
- Backend feedback: `praise, deviations, conclusions, planImpact, confidence, metrics` — deterministyczny.
- `planImpact` opisuje, **nie** mutuje planu natychmiast — wpływa po regeneracji.
- Auto-refresh planu po imporcie/check-inie: po EP-009 frontend podbija token planu po uploadzie TCX, Garmin sync i manual check-inie, a `WeeklyPlanSection` pobiera ponownie `GET /api/rolling-plan?days=14`.
- EP-010 dodał lokalny API smoke pełnej pętli manualnej bez pliku: health -> register/login -> profile -> rolling plan -> manual check-in -> feedback -> rolling plan. Produkcyjny/browser smoke nadal nieuruchomiony.
- Trening dziś vs historyczny: missing (spec, nie wdrożone).

---

## US-PLAN-001 — Wygenerowanie pierwszego rolling planu 14 dni

**Typ:** happy path
**Persona:** każda po onboardingu
**Status:** implemented
**Priorytet:** P0

### Stan wejściowy
User właśnie skończył onboarding, ma ≥6 treningów lub manual answers.

### Preconditions
- Profil zapisany (lub minimal manual answers).
- Sygnały policzone.

### Kroki użytkownika
1. Wraca z onboardingu na dashboard.
2. Widzi `WeeklyPlanSection` z planem 14 dni.

### Oczekiwane zachowanie UI
- Plan ma 14 dni (od dziś, nie od poniedziałku).
- Każdy dzień: typ (easy / long / quality / rest / cross-training), planned duration, planned intensity, ewentualnie struktura (dla quality).
- Dni rest mają wyraźną etykietę.
- Long run wyróżniony kolorem/ikonką.
- Quality session pokazuje structure (np. "3×10 min Z3 z 2 min przerwy").

### Oczekiwane API
- `GET /api/rolling-plan?days=14` — zwraca plan.
- Backend wewnętrznie: `WeeklyPlanService` + `BlockPeriodizationService` + `TrainingAdjustmentsService`.

### Oczekiwane zmiany danych
- Snapshot zapisany w `plan_snapshots` z `block_type`, `week_role`, `key_capability_focus`.
- Wpis w `training_weeks` dla bieżącego tygodnia.

### Kryteria akceptacji (P0)
- Response zawiera `sessions[]` z 14 dniami.
- Każda sesja ma: `dateKey` (YYYY-MM-DD), `type` (enum), `plannedDurationMin`, `plannedIntensity`.
- Plan respektuje `availability.runningDays` z profilu.
- Plan respektuje `maxSessionMin` z profilu.
- Plan ma quality density guard (max 1 quality, brak quality dzień przed/po long).
- Plan ma `appliedAdjustmentsCodes` — lista kodów które zostały zastosowane.
- `blockContext` w response zawiera: `block_type`, `week_role`, `load_direction`, `key_capability_focus`.

### Testy / smoke
- Test backend: cold start (0 treningów) → plan minimalny (`ColdStartTest.php`).
- Test backend: 6 treningów → plan z bardziej zróżnicowanymi sesjami.
- Test e2e: dashboard → plan widoczny.
- Test contract: `/api/rolling-plan` shape (`ContractFreezeTest.php`).

### Uwagi produktowe
14 dni to **rolling window**, nie tygodniowy plan. Każdego dnia okno przesuwa się o 1 dzień (jeśli regeneracja).

---

## US-PLAN-002 — Plan na dzień dzisiejszy widoczny natychmiast

**Typ:** happy path
**Persona:** każda
**Status:** partial (zakładam, do potwierdzenia)
**Priorytet:** P0

### Stan wejściowy
User loguje się rano, chce wiedzieć co dziś.

### Kroki użytkownika
1. Otwiera dashboard.
2. Widzi "Dzisiaj: easy 45 min" lub "Dzisiaj: rest day".

### Oczekiwane zachowanie UI
- Dzień dzisiejszy jest wyróżniony.
- Pokazuje: typ, czas, ewentualnie struktura.
- Jeśli quality: "Dzisiaj: 3×10 min Z3 (10 min rozgrzewki + 10 min cooldown)".
- Jeśli rest: "Dzisiaj: dzień regeneracji".

### Kryteria akceptacji (P0)
- Dzień dzisiejszy zawsze widoczny pierwszy (lub wyróżniony).
- Komunikat jest lakoniczny i konkretny.
- User ma 1 click do "wgraj wykonany trening" (jeśli zrobił).

### Testy / smoke
- Test e2e: plan z dzisiejszym dniem → wyróżniony.

---

## US-PLAN-003 — Refresh planu manualny

**Typ:** happy path
**Persona:** P-MULTI
**Status:** implemented
**Priorytet:** P0

### Stan wejściowy
User ma plan i chce go odświeżyć (np. po imporcie treningu, po zmianie profilu).

### Kroki użytkownika
1. Klika "Odśwież plan" w `WeeklyPlanSection`.

### Oczekiwane zachowanie UI
- Spinner/loading.
- (Opcjonalnie) modal cross-training z pytaniem "Czy w ciągu ostatnich 7 dni miałeś aktywności inne niż bieg?".
- Po odpowiedzi (jeśli pytane): plan regeneruje się.
- Pokazuje nowy plan, ewentualnie z notką "Plan zaktualizowany: 2 dni przesunięte".

### Oczekiwane API
- `POST /api/rolling-plan` (lub `GET /api/rolling-plan` z param `force=true`).

### Kryteria akceptacji (P0)
- Refresh kończy się w < 3s.
- Nowy snapshot w `plan_snapshots`.
- Stary snapshot zachowany (audit).

### Testy / smoke
- Test e2e: refresh → nowy plan.

### Uwagi produktowe
Modal cross-training to dziś (zgodnie z Twoimi notami). To wymaga jasności:
- czy modal pojawia się zawsze, czy tylko gdy backend nie ma danych o cross-training?
- czy user musi odpowiadać przy każdym refresh?

Sugestia: modal tylko gdy w ostatnich 7 dniach jest gap > 2 dni bez treningu, **lub** gdy user ma ustawienie "zapytaj zawsze".

---

## US-PLAN-004 — Auto-refresh planu po imporcie/check-inie

**Typ:** happy path (oczekiwany)
**Persona:** każda po imporcie
**Status:** implemented
**Priorytet:** P1

### Stan wejściowy
User wgrywa nowy trening (TCX upload lub Garmin sync) albo zapisuje manual check-in bez pliku.

### Oczekiwane zachowanie po wdrożeniu
- Frontend po zapisie treningu/check-inu podbija token odświeżenia planu.
- `WeeklyPlanSection` automatycznie pobiera świeży rolling plan bez klikania "Refresh".
- Wersja MVP nie wymaga websocketów ani snapshot notification; wystarcza lokalny refresh po mutacji danych treningowych.

### Oczekiwane API
- `POST /api/workouts/upload` zapisuje workout.
- `POST /api/workouts/manual-check-in` zapisuje check-in.
- Po sukcesie frontend wywołuje `GET /api/rolling-plan?days=14` przez `WeeklyPlanSection`.

### Kryteria akceptacji (P1)
- Po uploadzie albo manual check-inie plan odświeża się automatycznie (< 5s opóźnienia).
- User nie musi klikać refresh.
- Manualny refresh pozostaje dostępny jako fallback.

### Testy / smoke
- Build frontendu: `npm run build` -> OK po EP-009.
- E2E do wykonania w EP-010: upload/check-in → feedback → plan zmienia się bez kliknięcia refresh.

### Uwagi produktowe
Roadmap nie wymienia tego eksplicytnie, ale to istotna luka UX. Po EP-009 bazowy frontendowy refresh jest domknięty; pozostała walidacja manual/E2E.

---

## US-PLAN-005 — Generowanie feedbacku po treningu

**Typ:** happy path
**Persona:** każda po imporcie
**Status:** implemented (backend + UX; bez produkcyjnego smoke)
**Priorytet:** P0

### Stan wejściowy
User wgrał lub zsynchronizował trening.

### Preconditions
- Workout istnieje.

### Kroki użytkownika
1. Otwiera szczegóły treningu lub wraca na dashboard.
2. Klika "Pokaż feedback" lub feedback pojawia się automatycznie.

### Zachowanie UI
- Sekcja feedback z 5 częściami:
  - **Praise:** "Trzymałeś tempo Z2 przez 80% biegu — bardzo dobrze."
  - **Deviations:** "Tempo było szybsze o 15% niż planowane easy."
  - **Conclusions:** "Trening był efektywny ale nieco bardziej intensywny niż zakładano."
  - **PlanImpact:** "Jutro lepiej zrobić easy lub rest, żeby zregenerować się przed czwartkową jakością."
  - **Confidence:** "Średni poziom pewności (na podstawie 12 treningów ostatnich 30 dni)."
- Metrics: avgHr, maxHr, avgPace, distance, intensity zones.

### Oczekiwane API
- `POST /api/workouts/{id}/feedback/generate` — generuje (raz, idempotent).
- `GET /api/workouts/{id}/feedback` — pobiera istniejący.

### Kryteria akceptacji (P0)
- Feedback jest deterministyczny (te same dane = ten sam feedback).
- 5 sekcji obecnych w response.
- `planImpact` jest opisem, nie mutuje planu natychmiast.
- `confidence` zależy od jakości danych (HR yes/no, liczba treningów, plan istnieje yes/no).

### Testy / smoke
- Test backend: feedback dla 3 typów treningu (easy / quality / long) — różne praise/conclusions.
- Test backend: idempotentność (`POST /generate` 2× → same wynik).
- Test e2e: import → feedback widoczny.

### Uwagi produktowe
Domknięte w EP-006: backend ma endpointy, frontend pokazuje feedback po zapisie oraz pozwala ponownie odczytać zapisany wynik.

---

## US-PLAN-006 — Trening wykonany zgodnie z planem (compliance OK)

**Typ:** happy path
**Persona:** P-GARMIN
**Status:** partial (backend liczy, UI pokazuje feedback; brakuje wariantowego smoke/E2E)
**Priorytet:** P0

### Stan wejściowy
User miał plan: easy 45 min Z2. Wykonał: 47 min, avg pace zgodny z Z2.

### Oczekiwane zachowanie
- Backend rozpoznaje compliance OK.
- Feedback: praise dominuje, deviations puste lub minimalne.
- planImpact: "Jutro zgodnie z planem easy."
- Plan kolejnych dni: bez korekty.

### Kryteria akceptacji (P0)
- Compliance status = OK.
- Plan kolejnych dni nie zmienia się.

### Testy / smoke
- Test backend: planned 45 min easy, actual 47 min easy → status OK.

---

## US-PLAN-007 — Trening krótszy niż planowany

**Typ:** edge case
**Persona:** P-NOVICE (uciekł czas)
**Status:** partial
**Priorytet:** P0

### Stan wejściowy
Plan: easy 60 min. Wykonał: 30 min easy.

### Oczekiwane zachowanie
- Compliance: MINOR lub MAJOR_DEVIATION (zależy od progu).
- Feedback: deviations: "Trening był o 50% krótszy niż planowany."
- planImpact: "Możemy dodać 15 min do jutrzejszego biegu jeśli czujesz się dobrze."
- Sygnał `easierThanPlanned` może być wygenerowany.

### Kryteria akceptacji (P0)
- Status compliance widoczny.
- Plan pozostaje rozsądny (nie wciska 90 min biegu jutro żeby "nadgonić").

### Testy / smoke
- Test backend: planned 60, actual 30 → MAJOR undershoot.

---

## US-PLAN-008 — Trening dłuższy/mocniejszy niż planowany

**Typ:** edge case
**Persona:** P-MULTI ("dziś dobrze szło")
**Status:** partial
**Priorytet:** P0

### Stan wejściowy
Plan: easy 45 min Z2. Wykonał: 60 min Z3.

### Oczekiwane zachowanie
- Compliance: MAJOR_DEVIATION (overshoot intensity + duration).
- Feedback: deviations: "Tempo było wyraźnie wyższe niż easy.", "Trening dłuższy o 33%."
- planImpact: "Jutro lepiej zrobić easy lub rest. Możemy też skrócić jutrzejszy bieg."
- Sygnał `harderThanPlanned` generowany.
- Adjustment `harder_than_planned_guard` może być zastosowany do następnego mikrocyklu.

### Kryteria akceptacji (P0)
- System reaguje na harder-than-planned ostrożnie — nie pochwala "zrobienia więcej", tylko sygnalizuje ryzyko przeciążenia.

### Testy / smoke
- Test backend: 3 takie treningi z rzędu → adjustment "reduce_load".

### Uwagi produktowe
**Kluczowe dla bezpieczeństwa.** Aplikacja musi rozpoznawać agresywnych użytkowników i hamować, nie nakręcać.

---

## US-PLAN-009 — Trening spontaniczny (bez planu)

**Typ:** edge case
**Persona:** P-NOVICE
**Status:** partial
**Priorytet:** P0

### Stan wejściowy
Plan na dziś: rest. User mimo to zrobił bieg.

### Oczekiwane zachowanie
- Compliance: workout nie ma planned session do porównania → status `unplanned`.
- Feedback: praise: "Aktywność dodatkowa.", conclusions: "Ten trening dodaje obciążenie do tygodniowego load."
- planImpact: "Jutrzejszy plan może wymagać korekty."
- Sygnały: load wzrasta.

### Kryteria akceptacji (P0)
- Workout zapisany.
- Compliance ma status "unplanned" (lub equivalent).
- Plan kolejnych dni może się zmienić (zwłaszcza jeśli to był ciężki trening).

### Testy / smoke
- Test backend: planned rest, actual easy → status unplanned, load wzrasta.

---

## US-PLAN-010 — Pominięty kluczowy trening (long run)

**Typ:** error
**Persona:** P-MULTI (long w niedzielę pominięty)
**Status:** partial (backend ma `MISSED_KEY_WORKOUT` alert)
**Priorytet:** P0

### Stan wejściowy
Plan na niedzielę: long run 90 min. User nie zrobił.

### Oczekiwane zachowanie
- Sygnał `missedKeyWorkout` generowany.
- Alert `MISSED_KEY_WORKOUT` w `training_alerts_v1`.
- Adjustment `missed_workout_rebalance` może przesunąć long run na kolejny tydzień (jeśli to nie naruszy density).
- Feedback następnego treningu: "W ostatni tydzień ominąłeś long run. Plan przesuwa go na X."

### Kryteria akceptacji (P0)
- Alert widoczny w UI (jeśli alert center jest wdrożony) lub w komunikacie planu.
- Plan adaptuje się.

### Testy / smoke
- Test backend: missed long → alert + adjustment.

### Uwagi produktowe
UI alert center: missing. Dziś alert siedzi w bazie ale user może go nie zobaczyć.

---

## US-PLAN-011 — Cross-training planowany

**Typ:** happy path
**Persona:** P-MULTI (siłownia 1×/tydz)
**Status:** partial
**Priorytet:** P1

### Stan wejściowy
User w profilu zaznaczył preferencję siłowni 1×/tydz.

### Oczekiwane zachowanie
- Plan zawiera 1 dzień z `type = strength` lub `type = cross-training`.
- UI rozróżnia kolorem/ikonką.

### Kryteria akceptacji (P1)
- Cross-training zaplanowany według preferencji.
- Nie koliduje z quality / long.

### Testy / smoke
- Test backend: profil z `crossTrainingPromptPreference: true` → plan ma cross day.

---

## US-PLAN-012 — Cross-training spontaniczny zaimportowany

**Typ:** edge case
**Persona:** P-MULTI (rower w sobotę)
**Status:** partial (klasyfikacja działa, fatigue computed)
**Priorytet:** P1

### Stan wejściowy
User wgrywa TCX z roweru.

### Oczekiwane zachowanie
- Workout zapisany jako `sport = bike`.
- `ActivityImpactService` liczy wpływ na fatigue.
- Plan kolejnych dni może uwzględnić zmęczenie z roweru (np. obniżyć quality jutro).

### Kryteria akceptacji (P1)
- Cross-training nie jest "niewidzialny" dla planu.
- Nie liczy się do `weeklyDistanceKm` biegowego ale liczy się do `fatigueLoad`.

### Testy / smoke
- Test backend: rower przed jakością → adjustment `protect_quality_shorten_easy` lub `reduce_intensity_density`.

### Uwagi produktowe
Roadmap punkt 2 wymienia "korekta klasyfikacji aktywności zaimportowanych jako other" — to jest US-IMPORT-006.

---

## US-PLAN-013 — Race profile w planie (taper, peak)

**Typ:** happy path
**Persona:** P-MULTI (start za 6 tyg)
**Status:** partial (backend ma `BlockPeriodizationService`, UI raczej missing)
**Priorytet:** P1

### Stan wejściowy
User dodał race A: półmaraton, 6 tygodni.

### Oczekiwane zachowanie
- `BlockPeriodizationService` rozpoznaje `weeksUntilRace = 6` → `block_type = peak`.
- Plan ostatnich 2 tygodni → `taper`.
- UI pokazuje "Faza: peak (6 tyg do startu)".

### Kryteria akceptacji (P1)
- Block type widoczny w UI lub w `blockContext`.
- Plan respektuje fazy: build → peak → taper.

### Testy / smoke
- Test backend: race za 14 tyg → base/build → 6 tyg → peak → 2 tyg → taper.

### Uwagi produktowe
UI nie pokazuje tego dziś użytkownikowi explicite. Backend logika jest. To luka edukacyjna — user nie wie czemu plan ma "lekki tydzień".

---

## US-PLAN-014 — Zmiana celu w trakcie cyklu

**Typ:** edge case
**Persona:** P-MULTI (zmienia race date)
**Status:** missing (UI), partial (backend reaguje)
**Priorytet:** P1

### Stan wejściowy
User miał plan na maraton za 12 tyg. Decyduje że jednak będzie półmaraton za 8 tyg.

### Kroki użytkownika
1. Edytuje race w profilu.
2. Klika "Zaktualizuj plan".

### Oczekiwane zachowanie
- Plan natychmiast się przelicza dla nowego celu.
- User widzi nowy `block_type` i `key_capability_focus`.
- Komunikat: "Plan zaktualizowany — przygotowanie do półmaratonu za 8 tygodni."

### Oczekiwane API
- `PUT /api/me/profile` z nowym race.
- `POST /api/rolling-plan`.

### Kryteria akceptacji (P1)
- Plan przelicza się.
- Block context aktualny.

### Testy / smoke
- Test backend: zmiana race → nowy block type.

### Uwagi produktowe
Wymaga UI do edycji race (P1, missing).

---

## US-PLAN-015 — Powrót po przerwie / chorobie

**Typ:** edge case
**Persona:** P-RETURN
**Status:** partial (backend rozpoznaje `returnAfterBreak`)
**Priorytet:** P0

### Stan wejściowy
User nie biegał 3 tygodnie. Wraca i wgrywa pierwszy trening.

### Oczekiwane zachowanie
- Backend rozpoznaje gap > 14 dni → `returnAfterBreak: true`.
- `BlockPeriodizationService` ustawia `block_type = return`, `week_role = recovery`.
- Plan jest **konserwatywny**: 2-3 easy biegi, krótkie, bez akcentów.
- Komunikat: "Witaj z powrotem. Plan jest na razie ostrożny — wracamy stopniowo."

### Kryteria akceptacji (P0)
- Plan **nigdy** nie wskakuje od razu w peak po powrocie.
- Stopniowy wzrost objętości (max +15%/tydz).

### Testy / smoke
- Test backend: gap 21 dni → `returnAfterBreak`, plan recovery.

### Uwagi produktowe
**Kluczowe dla bezpieczeństwa.** Spec mówi: "po starcie lub chorobie system próbuje wrócić za szybko" jako ryzyko. Reguła musi być solidna.

---

## US-PLAN-016 — Zgłoszenie bólu w trakcie cyklu

**Typ:** edge case
**Persona:** P-RETURN
**Status:** partial (backend ma `hasCurrentPain`)
**Priorytet:** P0

### Stan wejściowy
User w profilu ma `hasCurrentPain: false`. Po treningu zaznacza ból.

### Kroki użytkownika
1. Otwiera profil lub szybki formularz "Jak się czujesz".
2. Zaznacza ból + opis.

### Oczekiwane zachowanie
- Backend ustawia `hasCurrentPain: true`.
- `TrainingAdjustmentsService` generuje adjustment `reduce_load` (30%).
- Plan kolejnych dni: zmniejszona intensywność, brak quality, więcej rest.
- Alert `PAIN_WITH_LOAD_CONFLICT` jeśli zaplanowano quality.
- Komunikat: "Zgłoszenie bólu odnotowane. Plan został złagodzony. Rozważ konsultację z fizjoterapeutą jeśli ból się utrzymuje."

### Kryteria akceptacji (P0)
- Pain flag wpływa na plan **natychmiast**.
- Aplikacja **nie zastępuje porady medycznej** — komunikat to jasno mówi.

### Testy / smoke
- Test backend: pain=true → adjustment + alert.

### Uwagi produktowe
**Granica między informacją treningową a medyczną.** Spec wymaga jasnego rozdzielenia. Komunikat musi to respektować.

---

## US-PLAN-017 — Brak treningu przez kilka dni (drift)

**Typ:** edge case
**Persona:** P-NOVICE (utknął)
**Status:** partial
**Priorytet:** P1

### Stan wejściowy
Ostatni trening 5 dni temu, plan zaplanował 3 sesje w tym czasie.

### Oczekiwane zachowanie
- Backend rozpoznaje gap.
- Sygnał `executionDrift` (lub `MISSED_KEY_WORKOUT` × 3).
- Komunikat na dashboardzie: "Nie zarejestrowaliśmy treningu od 5 dni. Plan przesunięty o 5 dni. Wszystko OK?".
- Plan resetuje się od dzisiaj zamiast wymagać "nadgonienia".

### Kryteria akceptacji (P1)
- Aplikacja **nie wstydzi** użytkownika.
- Plan adaptuje się empatycznie.

### Testy / smoke
- Test backend: gap 5 dni → drift detection.

### Uwagi produktowe
Spec: "nie może traktować każdego opuszczenia treningu jako problemu". Ważne dla utrzymania użytkownika.

---

## US-PLAN-018 — Pełna pętla po pierwszym treningu

**Typ:** integration / regression
**Persona:** P-GARMIN, P-NOVICE/manual fallback
**Status:** partial (local API smoke)
**Priorytet:** P0

### Stan wejściowy
User ma plan, robi pierwszy trening i importuje go albo zapisuje manual check-in bez pliku.

### Sekwencja
1. **Plan dnia widoczny** (US-PLAN-002).
2. **User wykonuje trening** w terenie.
3. **Wraca, importuje** (TCX upload albo Garmin sync) albo zapisuje manual check-in bez pliku — US-IMPORT-003, US-GARMIN-005 lub US-MANUAL-002.
4. **Workout zapisany**, sygnały przeliczone.
5. **Feedback wygenerowany** — US-PLAN-005.
6. **User czyta feedback** (praise / deviations / conclusions / planImpact).
7. **Plan się odświeża** automatycznie po imporcie/check-inie (US-PLAN-004), z manualnym refresh jako fallbackiem (US-PLAN-003).
8. **Plan na jutro** zaktualizowany według `planImpact`.

### Kryteria akceptacji (P0)
- Cała pętla wykonalna w < 2 minuty od zakończenia treningu do zobaczenia planu jutrzejszego.
- Brak utraty danych po drodze.
- Każdy krok ma jasne UX.

### Testy / smoke
- Manual smoke produkcyjny: realny trening → realny upload → feedback → plan.
- Lokalny API smoke po EP-010: `php artisan test tests\Feature\Api\MvpSmokeTest.php` -> 1 passed, 51 assertions; wariant bez pliku przechodzi przez manual check-in, low-data feedback i ponowny rolling plan.
- Test e2e zautomatyzowany na fixtures oraz produkcyjny/browser smoke nadal do zrobienia.

### Uwagi produktowe
**To jest core MVP.** Bez tej pętli aplikacja jest tylko logiem treningów. To powinien być jeden z 3-5 scenariuszy które są pokryte testem e2e na produkcji (smoke).
