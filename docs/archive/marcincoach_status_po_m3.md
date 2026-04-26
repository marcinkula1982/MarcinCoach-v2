# MarcinCoach — status po domknięciu M3

## Cel tego pliku
Krótki status projektu po domknięciu pakietu M3 (weekly planning enhancement) po wcześniejszym osiągnięciu cutover readiness dla PHP-only.

## Status ogólny
- **Cutover ready: tak**
- **Tryb migracji: PHP-only, od zera**
- **P1–P6.2: domknięte**
- **M1 minimum: domknięte**
- **M2 minimum: domknięte**
- **M3 enhancement: domknięte**
- **Production switched: jeszcze nie**

---

## Co było domknięte przed M3
Przed wejściem w M3 zostały domknięte:
- P1 — decyzja migracyjna
- P2 — summary shape / analytics visibility
- P3 — source + dedupeKey
- P4 — auth/session hardening
- P5 — operacyjny cutover pack
- P6.1 — TrainingSignals contract freeze
- P6.2 — minimum safety engine
- M1 minimum — typed onboarding/profile
- M2 minimum — parser/analityka minimum before cutover

Efekt:
- projekt osiągnął stan **cutover ready** dla wariantu PHP-only od zera

---

## M3 — weekly planning enhancement
**Status: DOMKNIĘTE**

### Cel M3
Podnieść jakość tygodniowego planowania w PHP bez przebudowy całego silnika trenerskiego i bez zmiany publicznego kontraktu `/api/weekly-plan`.

### Co zostało wdrożone
1. **Lekkie skalowanie duration**
   - WeeklyPlanService korzysta sensowniej z relacji `signals.weeklyLoad` vs `rolling4wLoad`
   - wprowadzony został bezpieczny clamp zamiast sztywnych, niezmiennych duration

2. **Quality density guard**
   - max 1 quality session na tydzień
   - brak quality dzień przed i po long run
   - poprawiony rozkład bodźców w tygodniu

3. **Realne zastosowanie adjustmentów**
   - `add_long_run`
   - `technique_focus`
   - `surface_constraint`
   - adjustmenty zaczęły wpływać na plan, a nie tylko istnieć w pipeline

4. **Deterministyczna deduplikacja i priorytetyzacja adjustmentów**
   - TrainingAdjustmentsService porządkuje konflikty i powtórki bez chaosu

5. **Testy**
   - nowy WeeklyPlanServiceTest
   - rozszerzony PlanningParityTest
   - rozszerzony TrainingAdjustmentsServiceTest

---

## Drift kontraktu po M3 i jego korekta
Po pierwszym wdrożeniu M3 pojawił się observable drift kontraktu:
- nowe pola w `sessions[*]`:
  - `techniqueFocus`
  - `surfaceHint`
- zmieniona kolejność `appliedAdjustmentsCodes`

### Co zostało poprawione
- `techniqueFocus` i `surfaceHint` zostały usunięte z **publicznego response** `/api/weekly-plan`
- logika M3 nadal korzysta z nich wewnętrznie
- `appliedAdjustmentsCodes` wróciło do stabilnej kolejności zgodnej z insertion order
- top-level shape `/api/weekly-plan` pozostał bez zmian

### Efekt
- M3 zostało zachowane
- kontrakt API został ponownie ustabilizowany

---

## Co jest domknięte po M3
Po domknięciu M3 projekt ma:
- gotowość do przełączenia (`cutover ready`)
- minimalny onboarding backendowy
- minimalną analitykę i sygnały przed cutoverem
- minimalny safety engine
- uszczelnione auth/session
- operacyjny pakiet cutoverowy
- sensowniejszy, bardziej deterministyczny weekly plan

---

## Co nadal NIE jest domknięte
To nie są już blockery cutoveru, tylko dalszy rozwój produktu.

### 1. M4 — deeper adaptation / alerting
Nadal można rozwinąć:
- lepszą adaptację planu po odchyleniach
- bardziej granularne reguły reagowania
- bogatsze alerty treningowe

### 2. M2 beyond minimum
Po cutoverze można rozwijać:
- FIT/GPX
- głębszą parity parsera z Node
- lepsze czyszczenie artefaktów
- bogatszą analitykę

### 3. M1 beyond minimum
Po cutoverze można rozwinąć:
- pełny onboarding UX
- wizard
- scoring jakości danych
- bogatszy model profilu

### 4. M3 beyond current enhancement
Po cutoverze można rozwijać:
- bardziej zaawansowaną periodyzację
- pamięć poprzednich tygodni planu
- bogatsze constraints i personalizację planowania

---

## Czego ten plik NIE oznacza
Ten plik **nie oznacza**, że produkt jest kompletny.
Oznacza tylko, że:
- projekt jest gotowy do przełączenia na PHP-only
- oraz że pakiet M3 został wdrożony i ustabilizowany kontraktowo

---

## Najbliższy sensowny krok
Masz teraz dwa sensowne kierunki:
1. **wykonać właściwy cutover**
2. **wejść w M4 deeper adaptation / alerting jako kolejny pakiet rozwojowy**
