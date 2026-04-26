# MarcinCoach — status po M1 beyond minimum, M2 beyond minimum, M3 i M4

## Cel tego pliku
Krótki status projektu po domknięciu czterech większych pakietów rozwojowych backendu trenerskiego:
- **M1 beyond minimum**
- **M2 beyond minimum**
- **M3**
- **M4**

Dokument ma zatrzymać aktualny stan projektu po wyjściu poza fazę samego cutover readiness i po wejściu w realny rozwój jakości coacha.

## Status ogólny
- **Cutover ready: tak**
- **Production switched: jeszcze nie**
- **Tryb migracji: PHP-only, od zera**
- **P1–P6.2: domknięte**
- **M1 minimum: domknięte**
- **M1 beyond minimum: domknięte**
- **M2 minimum: domknięte**
- **M2 beyond minimum: zaakceptowane**
- **M3 enhancement: domknięte**
- **M4 enhancement: domknięte**

---

## Co było domknięte wcześniej
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

Efekt:
- projekt osiągnął stan **cutover ready** dla PHP-only od zera

---

## M1 beyond minimum — lepszy model użytkownika
**Status: DOMKNIĘTE**

### Cel
Przekształcić profil użytkownika z „dane zapisane, nikt nie czyta” w spójny, typowany model z ograniczonym, realnym wpływem downstream na coacha.

### Co zostało wdrożone
1. **Migracja projekcji**
   - `primary_race_date`
   - `primary_race_distance_km`
   - `primary_race_priority`
   - `max_session_min`
   - `has_current_pain`
   - `has_hr_sensor`
   - `profile_quality_score`

2. **Model UserProfile**
   - fillable + casts dla nowych pól

3. **ProfileQualityScoreService**
   - deterministyczny scoring jakości profilu

4. **ProfileController**
   - cross-field walidacja HR zones
   - projekcja JSON → kolumny
   - kanonikalizacja `runningDays`
   - zapis `profile_quality_score`
   - addytywne pola w response:
     - `primaryRace`
     - `quality`

5. **UserProfileService**
   - rozszerzony shape addytywnie
   - priorytet `availability.runningDays` nad `preferred_run_days`

6. **Trzy twarde integracje downstream**
   - `WeeklyPlanService`: cap `durationMin` przez `maxSessionMin`
   - `TrainingAdjustmentsService`: `hasCurrentPain -> reduce_load(30%)`
   - `TrainingContextService`: `primaryRace` w kontekście, bez logiki taper

7. **Testy**
   - `ProfileQualityScoreServiceTest`
   - `UserProfileServiceTest`
   - rozszerzony `AuthAndProfileTest`
   - rozszerzony `WeeklyPlanServiceTest`
   - rozszerzony `TrainingAdjustmentsServiceTest`
   - regresja `PlanningParityTest`

### Efekt
- profil użytkownika przestał być martwy
- coach zaczął realnie czytać kontekst profilu
- zakres został utrzymany bez rozlewania na wizard/UX

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
   - relaksacja `DistanceMeters`

2. **Wzbogacone summary workoutu**
   - `WorkoutSummaryBuilder` zapisuje:
     - `sport`
     - `hr`
     - `avgPaceSecPerKm`
     - `intensity`
     - `intensityBuckets`

3. **Lepszy input do signals**
   - `TrainingSignalsService`:
     - load z `intensityBuckets` jako primary
     - fallback na numeric intensity
     - fallback na `durationSec / 60`
   - `windowEnd = now()->utc()`
   - `longRun` filtruje po `sport='run'`

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
   - testy wrażliwe na czas przeniesione na `Carbon::setTestNow()`

### Drift zakresowy
Pakiet M2 beyond minimum został zaakceptowany funkcjonalnie, ale z udokumentowanym driftem zakresowym:
- elementy `source/dedupe`
- elementy adaptation/safety
- współdzielenie odpowiedzialności w części plików runtime

### Co z tym zrobiono
- nie cofano wdrożenia
- nie robiono dużego refaktoru
- dodano dokument:
  - `docs/architecture/scope-notes-m2-beyond.md`

### Efekt
- jakość danych wejściowych do coacha wzrosła realnie
- wdrożenie zostało zaakceptowane
- drift zakresowy został jawnie udokumentowany

---

## M3 — weekly planning enhancement
**Status: DOMKNIĘTE**

### Cel
Podnieść jakość tygodniowego planowania w PHP bez przebudowy całego silnika trenerskiego i bez zmiany publicznego kontraktu `/api/weekly-plan`.

### Co zostało wdrożone
1. **Lekkie skalowanie duration**
   - WeeklyPlanService korzysta sensowniej z relacji `signals.weeklyLoad` vs `rolling4wLoad`
   - wprowadzony został bezpieczny clamp

2. **Quality density guard**
   - max 1 quality session na tydzień
   - brak quality dzień przed i po long run

3. **Realne zastosowanie adjustmentów**
   - `add_long_run`
   - `technique_focus`
   - `surface_constraint`

4. **Deterministyczna deduplikacja i priorytetyzacja adjustmentów**
   - TrainingAdjustmentsService porządkuje konflikty i powtórki

5. **Testy**
   - nowy `WeeklyPlanServiceTest`
   - rozszerzony `PlanningParityTest`
   - rozszerzony `TrainingAdjustmentsServiceTest`

### Drift kontraktu po M3 i jego korekta
Po pierwszym wdrożeniu M3 pojawił się observable drift:
- nowe pola w `sessions[*]`
- zmieniona kolejność `appliedAdjustmentsCodes`

#### Co zostało poprawione
- pola zostały usunięte z publicznego response `/api/weekly-plan`
- logika M3 nadal korzysta z nich wewnętrznie
- `appliedAdjustmentsCodes` wróciło do stabilnej kolejności
- top-level shape `/api/weekly-plan` pozostał bez zmian

### Efekt
- weekly plan stał się bardziej sensowny i deterministyczny
- kontrakt API został ponownie ustabilizowany

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
- adaptacja po odchyleniach stała się głębsza
- alerting sytuacyjny ma realną wartość
- publiczne API nie zostało rozszerzone bez versioningu

---

## Co jest domknięte po tym etapie
Projekt ma teraz:
- gotowość do przełączenia (`cutover ready`)
- minimalny onboarding backendowy + jego rozwinięcie
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

### 1. M1 beyond current scope
- pełny onboarding UX
- wizard
- scoring jakości danych rozwinięty dalej
- bogatszy model profilu
- per-day availability windows
- injury history wpływające głębiej na coaching

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
- oraz że backend trenerski został rozwinięty znacząco ponad poziom minimum

---

## Najbliższy sensowny krok
Masz teraz 3 realne kierunki:
1. **wykonać właściwy cutover**
2. **wejść w kolejny pakiet rozwojowy M1/M2/M3/M4 beyond current scope**
3. **zatrzymać stan i świadomie wybrać, co daje największy zwrot dla jakości coacha**
