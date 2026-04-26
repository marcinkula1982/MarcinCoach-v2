# MarcinCoach — status po M2 beyond minimum i M4

## Cel tego pliku
Krótki status projektu po domknięciu pakietów:
- **M2 beyond minimum** (quality data for coach)
- **M4** (deeper adaptation / alerting)

Dokument ma zatrzymać aktualny stan projektu po przejściu od fazy cutover readiness do dalszego rozwoju backendu trenerskiego.

## Status ogólny
- **Cutover ready: tak**
- **Production switched: jeszcze nie**
- **Tryb migracji: PHP-only, od zera**
- **P1–P6.2: domknięte**
- **M1 minimum: domknięte**
- **M2 minimum: domknięte**
- **M2 beyond minimum: zaakceptowane**
- **M3 enhancement: domknięte**
- **M4 enhancement: domknięte**

---

## Co było już domknięte wcześniej
Przed tym etapem projekt miał domknięte:
- P1 — decyzja migracyjna
- P2 — summary shape / analytics visibility
- P3 — source + dedupe
- P4 — auth/session hardening
- P5 — operacyjny cutover pack
- P6.1 — TrainingSignals contract freeze
- P6.2 — minimum safety engine
- M1 minimum — typed onboarding/profile
- M2 minimum — parser/analityka minimum before cutover
- M3 — weekly planning enhancement

Efekt:
- projekt osiągnął stan **cutover ready** dla PHP-only od zera
- weekly planning został podniesiony ponad poziom minimum

---

## M2 beyond minimum — quality data for coach
**Status: ZAAKCEPTOWANE**

### Cel
Podnieść jakość danych treningowych w PHP do poziomu, który realnie poprawia:
- planowanie
- adaptację
- alerting

bez wdrażania pełnej parity z Node.

### Co zostało wdrożone
1. **Nowy bogatszy parser TCX**
   - `TcxParsingService`
   - sport detection: `run | bike | swim | other`
   - HR stats: `avgBpm`, `maxBpm`
   - `avgPaceSecPerKm`
   - `intensityBuckets`
   - relaksacja `DistanceMeters` (0, gdy brak; `TotalTimeSeconds` nadal wymagane)

2. **Wzbogacone summary workoutu**
   - `WorkoutSummaryBuilder` potrafi zapisać:
     - `sport`
     - `hr`
     - `avgPaceSecPerKm`
     - `intensity`
     - `intensityBuckets`
   - zachowana kompatybilność wsteczna

3. **Lepszy input do signals**
   - `TrainingSignalsService`:
     - load z `intensityBuckets` jako primary
     - fallback na numeric intensity
     - fallback awaryjny na `durationSec / 60`
   - `windowEnd = now()->utc()`
   - `longRun` filtruje po `sport='run'` z fallbackiem dla starych danych

4. **Lepsze analytics summary**
   - `byDay`
   - `byWeek`
   - `zones`
   - `longRunKm`
   - `avgPaceSecPerKm`

5. **External imports lepiej podpięte do coacha**
   - po CREATE z zewnętrznego importu uruchamiane są:
     - `TrainingSignalsService`
     - `PlanComplianceService`
     - `TrainingAlertsV1Service`

6. **Testy**
   - nowy `TcxParsingServiceTest`
   - rozszerzone testy feature workouts/parity
   - testy pod `Carbon::setTestNow()` dla wrażliwych okien czasu

### Zakresowy drift i jego korekta
Pakiet M2 beyond minimum był funkcjonalnie dobry, ale zakresowo nie był idealnie czysty.

#### Co wyszło poza czyste M2
- elementy `source/dedupe`
- elementy adaptation/safety
- współdzielenie odpowiedzialności w:
  - `WorkoutsController`
  - `TrainingSignalsService`
  - `ExternalWorkoutImportService`

#### Co z tym zrobiono
- nie cofano wdrożenia
- nie robiono dużego refaktoru
- dodano dokument:
  - `docs/architecture/scope-notes-m2-beyond.md`

### Efekt
- jakość danych wejściowych do coacha wzrosła realnie
- wdrożenie zostało zaakceptowane
- drift zakresowy został jawnie udokumentowany

---

## M4 — deeper adaptation / alerting
**Status: DOMKNIĘTE**

### Cel
Podnieść jakość reagowania systemu na odchylenia od planu bez przebudowy całego silnika trenerskiego.

### Co zostało wdrożone
1. **Missed workout jako sygnał adaptacji**
2. **Mocniejsza reakcja na harder-than-planned**
3. **Kontrolowana reakcja na easier-than-planned**
4. **Obsługa startu kontrolnego jako scenariusza adaptacji**
5. **Minimalne alerty sytuacyjne**
   - `MISSED_KEY_WORKOUT`
   - odpowiednik `EASIER_THAN_PLANNED_STREAK`
6. **Nowe adjustment codes zastosowane w WeeklyPlanService**
7. **Testy unit + feature dla compliance / adjustments / alerts / planning effect**

### Drift kontraktu po wdrożeniu M4
Po pierwszym wdrożeniu pojawił się drift:
- nowe top-level pole `adaptation` w `/api/training-signals`

### Co zostało poprawione
- `adaptation` zostało usunięte z publicznego response
- logika M4 została zachowana wewnętrznie
- kontrakt `/api/training-signals` wrócił do zamrożonego kształtu
- test kontraktu został uzupełniony

### Efekt
- M4 zostało zachowane
- publiczne API nie zostało rozszerzone bez versioningu

---

## Co jest domknięte po tym etapie
Projekt ma teraz:
- gotowość do przełączenia (`cutover ready`)
- minimalny onboarding backendowy
- sensowną jakość danych wejściowych z TCX
- lepsze analytics summary
- bardziej wiarygodne signals
- minimalny safety engine
- tygodniowy plan lepszy niż minimum
- głębszą adaptację po odchyleniach
- alerting sytuacyjny
- udokumentowane miejsca driftu zakresowego

---

## Co nadal NIE jest domknięte
To nie są już blockery cutoveru, tylko dalszy rozwój produktu.

### 1. M1 beyond minimum
- pełny onboarding UX
- wizard
- scoring jakości danych
- bogatszy model profilu

### 2. M2 beyond current scope
- FIT / GPX
- lepsze czyszczenie artefaktów
- głębsza parity parsera z Node
- moving time / pause detection
- cadence / power / elevation / route
- pace-zones per user

### 3. M3 beyond current scope
- pamięć poprzednich tygodni planu
- bogatsza periodyzacja
- bardziej granularne constraints

### 4. M4 beyond current scope
- bogatsze alerty
- głębsza adaptacja wielotygodniowa
- bardziej zaawansowany scoring ryzyka

### 5. Wykonanie właściwego cutoveru
- checklista
- rollback window
- przełączenie ruchu
- obserwacja po przełączeniu
- staged decommission Node

---

## Czego ten plik NIE oznacza
Ten plik **nie oznacza**, że MarcinCoach jest kompletny jako produkt.
Oznacza tylko, że:
- projekt jest gotowy do przełączenia na PHP-only
- oraz że backend trenerski został dalej rozwinięty ponad poziom minimum

---

## Najbliższy sensowny krok
Masz teraz 3 realne kierunki:
1. **wykonać właściwy cutover**
2. **wejść w M1 beyond minimum**
3. **wejść w kolejny pakiet rozwojowy danych lub coachingu**
