# MarcinCoach v2 — plan aktualny

Data: 2026-04-26

---

## Aktualizacja po starcie prac — 2026-04-26

- Backend PHP lokalnie przechodzi pełny suite: `259 passed, 1237 assertions`.
- D1 parity jest zaimplementowane lokalnie: `PATCH /workouts/{id}/meta`, `GET /training-feedback`, `GET /training-signals`.
- M3/M4 beyond jest w kodzie lokalnym w zakresie: `BlockPeriodizationService`, `PlanMemoryService`, `blockContext`, struktury sesji, `adaptationType`, `confidence`, alerty trendowe.
- Stary onboarding frontendowy został zastąpiony MVP data-first: źródło danych → minimalne pytania uzupełniające.
- Do zamknięcia produkcyjnego pozostaje smoke na IQHost i deploy frontu przez `npm run build` + `.\deploy-front.ps1`.

---

## Stan wyjściowy

### D0 — infrastruktura i deploy ✅ GOTOWE
- Frontend zbudowany i serwowany z `public_html/` na IQHost
- Backend PHP (Laravel) wdrożony na `api.coach.host89998.iqhs.pl`
- Deploy front przez `deploy-front.ps1` (SCP dist → public_html)
- Hook Git deployuje tylko backend (bez buildu frontu)

---

### D1 — backend PHP operacyjny ✅ LOKALNIE / ⏳ PROD SMOKE

PHP backend ma lokalnie domknięte kluczowe elementy parity MVP. Wymagany jest jeszcze smoke test na IQHost przed oznaczeniem D1 jako produkcyjnie zamknięte.

#### Zaimplementowane lokalnie (wg backlogu `laravel-mvp-implementation-backlog.md`):

**Workstream 1 — self-report meta**
- `PATCH /workouts/{id}/meta` — endpoint + walidacja + persystencja ✅
- Pola: `planCompliance`, `rpe`, `fatigueFlag`, `note`

**Workstream 2 — feedback aggregation**
- `GET /training-feedback?days=28` ✅
- `TrainingFeedbackService` z parity logiką Node ✅
- Agregacje: counts, complianceRate, RPE stats, fatigue, notes ✅

**Workstream 3 — compliance parity**
- Kluczowe reguły compliance z Node → PHP ✅
- Testy feature porównawcze ✅

**Definition of Done D1:**
- PHP obsługuje pełny flow bez fallbacku do Node ✅ lokalnie
- Frontend może przełączyć endpoint bazowy na PHP ✅ lokalnie
- Smoke test produkcji IQHost ⏳

---

### M3/M4 — logika trenerska ✅ LOKALNIE / ⏳ HARDENING

Stan lokalny jest dalej niż pierwotny opis: fundamenty M3/M4 beyond zostały zaimplementowane i pokryte testami. Następny etap to walidacja scenariuszy, ekspozycja UX i smoke produkcyjny.

**Co działa:**
- `WeeklyPlanService` — podstawowy dobór sesji (easy/long/quality/rest)
- `TrainingAdjustmentsService` — reaktywne adjustmenty na sygnały
- `TrainingAlertsV1Service` — alerty per-workout
- `BlockPeriodizationService` — blok, rola tygodnia, kierunek obciążenia
- `PlanMemoryService` — pamięć tygodniowa w `training_weeks`
- Struktury sesji: `threshold`, `intervals`, `fartlek`, `tempo`
- Adjustmenty z `adaptationType`, `confidence`, `decisionBasis`
- Alerty z rodziną, confidence, `explanation_code` i alertami trendowymi

**Co pozostaje do hardeningu:**
- Smoke produkcyjny i migracje na IQHost
- Scenariusze ręczne: powrót po przerwie, load spike, taper, chroniczne niedowykonanie
- UX dla `blockContext`, alertów i decision trace
- Uporządkowanie dokumentacji po wdrożeniu produkcyjnym

**Szczegółowy plan pakietu:** `m3_m4_beyond_current_scope.plan.md`

**Definition of Done M3/M4:**
- Plan tygodnia zna blok i rolę tygodnia w bloku ✅ lokalnie
- Adaptacja korzysta z historii min. kilku tygodni ✅ lokalnie
- Korekta zmienia strukturę bodźca, nie tylko objętość ✅ lokalnie
- Alerty klasyfikowane rodziną, severity i confidence ✅ lokalnie
- Istnieje ślad decyzyjny ✅ lokalnie

---

### Onboarding — PRZEPROJEKTOWANIE ✅ MVP LOKALNIE / ⏳ PROD

Stara ankieta (`Ankieta użytkownika`) była 11-sekcyjna i została zastąpiona lokalnie przez data-first onboarding MVP.

#### Nowe podejście (zgodnie z planem z 2026-04-26):

**Ścieżka A — użytkownik ma dane treningowe**

Ekran 1: źródło danych
```
[ Strava ]  [ Garmin ]  [ Pliki TCX ]  [ Bez danych ]
[ Polar ]   [ Suunto ]  ← widoczne jako źródła przyszłe
```

Backend sam wyciąga z danych:
- liczba treningów tygodniowo, kilometraż, najdłuższy bieg
- tempa easy / szybkich treningów
- HR avg/max, drift HR, powtarzalność
- aktualna forma, tolerancja objętości

Pytamy użytkownika tylko o to, czego backend nie wie:
1. Jaki masz cel? (pole otwarte, przykłady)
2. Czy masz datę startu?
3. Czy coś Cię teraz boli?
4. Ile dni w tygodniu realnie możesz trenować?
5. Czy są dni, w które nie możesz biegać?

**Ścieżka B — brak danych**

Krótka ankieta diagnostyczna (behawioralna, nie samoocena):
1. Kiedy ostatnio biegałeś?
2. Ile razy biegałeś w ostatnich 2 tygodniach?
3. Jaki był najdłuższy bieg w ostatnim miesiącu?
4. Czy jesteś w stanie przebiec 30 minut bez przerwy?
5. Czy coś boli podczas albo po bieganiu?
+ pytania uzupełniające jak w Ścieżce A (cel, data, dni)

**Cel — pole otwarte (nie lista rozwijana)**
```
Napisz swój cel jednym zdaniem.
Przykłady: "przebiec 10 km poniżej 50 minut" / "wrócić do biegania po przerwie"
```
Backend klasyfikuje cel do struktury: `distance`, `targetTime`, `goalType`, `priority`

Status implementacji:
- Frontend: MVP w `src/components/Onboarding.tsx` ✅
- Payload profilu: `goals` jako tekst + `availability` + `health` + `equipment` ✅
- Test backendowy minimalnego data-first payloadu ✅
- TCX upload działa jako ścieżka plików ✅
- FIT/GPX oraz pełna backendowa klasyfikacja celu ⏳

#### Integracje — status techniczny:
- **Strava** — oficjalne OAuth2 API ✅ na start
- **Garmin** — `python-garminconnect` (nieoficjalne, aktywnie utrzymywane) ✅ na start
- **Polar** — oficjalne AccessLink API 🔜
- **Suunto** — oficjalne Cloud API (wymaga rejestracji partnerskiej) 🔜
- **Apple Watch** — brak chmurowego API, trafia do importu plików ❌ bezpośrednio
- Szczegóły: `integrations/integracje_wnioski.md`

---

## Kolejność prac

```
D1 smoke / deploy sanity
    ↓
Onboarding — deploy MVP + ręczny test flow
    ↓
M3/M4 — hardening scenariuszy i UX ekspozycja
    ↓
M2 deeper data (FIT/GPX, cadence, power, pace-zones)
```

### Uwaga do kolejności
D1 i onboarding są lokalnie rozpoczęte/domknięte technicznie, ale wymagają smoke po deployu.
M3/M4 wchodzi w hardening po D1, bo wymaga stabilnego backendu PHP.
M2 deeper data — dopiero po M3/M4, bo nowe sygnały mają sens tylko gdy logika planowania umie je konsumować.

---

## Pliki referencyjne

- `docs/laravel-mvp-implementation-backlog.md` — szczegóły D1
- `m3_m4_beyond_current_scope.plan.md` — szczegóły M3/M4
- `integrations/integracje_wnioski.md` — integracje Garmin/Strava/Polar/Suunto
- `Ankieta użytkownika (pod plan treni.txt` — stara ankieta (do zastąpienia)
- `CLAUDE.md` — zasady deployu
