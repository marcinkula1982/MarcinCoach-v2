# 02 — Import plików i jakość danych

Plik obejmuje: upload TCX/GPX/FIT, multi-upload, ZIP, walidację dyscypliny (sport), sanity check tempa, duplikaty, malformed XML, brak HR, brak dystansu, trening z dzisiaj vs historyczny.

Realny stan 28.04.2026:
- UI dashboard: upload jednego pliku TCX.
- UI onboarding: multi-upload TCX.
- Backend: parsuje TCX, GPX, FIT.
- UI GPX/FIT: missing/partial — parser jest po stronie backendu, ale frontend nadal eksponuje głównie TCX.
- ZIP: brak obsługi.
- Wrong sport / sanity: spec, **nie wdrożone w pełni**. Backend obecnie przyjmuje też cross-training i zapisuje jako bike/swim/walk_hike/strength/other.

---

## US-IMPORT-001 — Multi-upload TCX w onboardingu

**Typ:** happy path
**Persona:** P-GARMIN, P-MULTI, P-COROS
**Status:** implemented (TCX only)
**Priorytet:** P0

### Stan wejściowy
User w fazie 1 wizardu wybrał "Upload pliku TCX".

### Preconditions
- User zalogowany.
- Wizard otwarty.

### Kroki użytkownika
1. Klika obszar drag&drop lub "Wybierz pliki".
2. Wybiera kilka plików TCX (np. 8–15).
3. Klika "Wgraj".

### Oczekiwane zachowanie UI
- Lista wybranych plików widoczna przed wysłaniem.
- Progress per plik (uploading / parsing / saved / error).
- Po zakończeniu: licznik "Wgrano X z Y plików, Z błędów".
- User może kliknąć "Dalej" żeby przejść do fazy 2.

### Oczekiwane API
- `POST /api/workouts/upload` per plik (sekwencyjnie lub równolegle).
- Body: multipart/form-data z plikiem.

### Oczekiwane zmiany danych
- Per sukces: nowy wpis w `workouts` (z `source = MANUAL_UPLOAD`), nowy wpis w `workout_raw_tcx`.
- Per duplikat (`dedupe_key` collision): brak nowego wpisu, response 409 lub 200 z flagą `duplicate: true`.

### Kryteria akceptacji (P0)
- 10 plików TCX uploaduje się w czasie < 30 sekund (na produkcji).
- Każdy plik jest niezależny — błąd 1 nie wywala innych.
- Progres jest widoczny per plik.
- Po sukcesie: response zawiera workout id i workout summary.
- Pliki o tym samym `dedupe_key` (user_id + source + start_time + duration) nie tworzą duplikatów.
- Error response zawiera czytelny komunikat: `INVALID_XML`, `MISSING_DATA`, `DUPLICATE`; `WRONG_SPORT` zostaje tylko jako legacy/spec note albo przypadek absolutnie nierozpoznawalnego sportu.

### Testy / smoke
- Test backend: upload 5 plików, weryfikacja 5 nowych wpisów.
- Test backend: upload duplicate, weryfikacja brak nowego wpisu.
- Test e2e: full flow w UI.

### Uwagi produktowe
**Format pliku:** dziś tylko `.tcx`. Backend obsługuje też `.gpx` i `.fit`, ale UI ich nie udostępnia. To jest US-IMPORT-002.

---

## US-IMPORT-002 — Upload GPX i FIT z UI

**Typ:** happy path (przyszłościowy)
**Persona:** P-COROS, P-MULTI
**Status:** partial (backend tak, UI nie eksponuje)
**Priorytet:** P1

### Stan wejściowy
User chce wgrać plik GPX (np. ze Stravy export) lub FIT (np. z Coros).

### Preconditions
- User zalogowany.
- Backend przyjmuje GPX i FIT (potwierdzone).
- UI: `accept` na input file ograniczone do `.tcx`.

### Kroki użytkownika
1. Próbuje wgrać plik `.gpx` lub `.fit` przez UI dashboard lub onboarding.
2. Plik nie pojawia się w wyborze (filter blokuje) lub pojawia się ale upload failuje.

### Oczekiwane zachowanie UI (po naprawie)
- File input akceptuje `.tcx, .gpx, .fit`.
- UI rozpoznaje typ i wysyła do tego samego endpointu (backend rozpoznaje po MIME/treści).
- Per FIT: dodatkowy info-banner "FIT parser jest w fazie MVP, prosimy o weryfikację danych po imporcie".

### Oczekiwane API
- `POST /api/workouts/upload` — bez zmian, backend już to obsługuje.

### Kryteria akceptacji (P1)
- GPX uploaduje się i parsuje, dane trafiają do `workouts.summary`.
- FIT uploaduje się; jeśli parser zawiedzie — error response z kodem `FIT_PARSE_FAILED`.
- Error nie wywala UI.

### Testy / smoke
- Test backend: 1 realny plik FIT (do dostarczenia przez P-COROS lub Garmin export).
- Test backend: GPX z Strava export.
- Manual smoke produkcyjny.

### Uwagi produktowe
Roadmap punkt 3 wspomina o teście na realnym `.fit`. Bez realnego pliku FIT z urządzenia nie zamkniemy tego scenariusza.

**Decyzja architektoniczna do podjęcia:** czy zapisywać raw GPX i FIT analogicznie do `workout_raw_tcx`. Dziś tylko TCX ma raw storage.

---

## US-IMPORT-003 — Upload pliku w dashboardzie (single)

**Typ:** happy path
**Persona:** każda po onboardingu
**Status:** implemented (TCX only)
**Priorytet:** P0

### Stan wejściowy
User na dashboardzie. Dziś po treningu chce wgrać plik TCX.

### Preconditions
- User zalogowany, na dashboardzie.

### Kroki użytkownika
1. Klika file picker.
2. Wybiera pojedynczy plik TCX.
3. Czeka.

### Oczekiwane zachowanie UI
- Spinner/progress.
- Po sukcesie: trening pojawia się na liście, plan może się odświeżyć (po refreshu manualnym).
- Po błędzie: toast/komunikat z kodem.

### Oczekiwane API
- `POST /api/workouts/upload`.

### Oczekiwane zmiany danych
- Nowy `workout`, nowy `workout_raw_tcx`.
- Backend uruchamia: `TrainingSignalsService`, `PlanComplianceService`, `TrainingAlertsV1Service`.

### Kryteria akceptacji (P0)
- Upload pojedynczego pliku < 5 sekund na produkcji.
- Trening widoczny w `WorkoutsList` po reloadzie/refreshu.
- Sygnały przeliczone (`/api/training-signals` zwraca aktualne dane).

### Testy / smoke
- Test e2e: upload → workout w liście.
- Test backend: trigger na sync sygnałów.

### Uwagi produktowe
Auto-refresh planu po uploadzie jest domknięty w EP-009 — patrz US-PLAN-004.

---

## US-IMPORT-004 — Upload duplikatu

**Typ:** edge case
**Persona:** P-MULTI (importuje to samo z 2 źródeł)
**Status:** implemented (dedupe key)
**Priorytet:** P0

### Stan wejściowy
User wgrał już ten sam plik TCX kiedyś.

### Preconditions
- W `workouts` istnieje wpis z tym samym `dedupe_key`.

### Kroki użytkownika
1. Próbuje ponownie wgrać ten sam plik.

### Oczekiwane zachowanie UI
- UI pokazuje komunikat "Ten trening już istnieje" lub "Zaktualizowano" (zależy od decyzji produktowej).
- Plik nie tworzy duplikatu w bazie.

### Oczekiwane API
- `POST /api/workouts/upload` zwraca 200 z flagą `duplicate: true` i istniejącym `workout_id`, lub 409.

### Kryteria akceptacji (P0)
- Brak nowego wpisu w `workouts`.
- Response zwraca informację że to duplikat.
- UI rozróżnia "nowy import" od "duplikat".

### Testy / smoke
- Test backend: 2× upload tego samego pliku → 1 wpis (już pokryty?).

### Uwagi produktowe
Decyzja: jak zachować się gdy duplikat ma inne metadane (np. user dodał race meta). Sugestia: backend nadpisuje metadane jeśli nowy upload je zawiera, ale nie tworzy duplikatu data.

---

## US-IMPORT-005 — Upload pliku z innego sportu (Wrong Sport)

**Typ:** edge case
**Persona:** P-MULTI (przypadkowo wgrywa rower)
**Status:** partial (backend zapisuje jako cross-training, NIE odrzuca)
**Priorytet:** P0

### Stan wejściowy
User wgrywa TCX z roweru, pływania, walking.

### Preconditions
- Plik ma `<Activity Sport="Biking">` lub equivalent.

### Kroki użytkownika
1. Wgrywa plik.
2. Czeka na response.

### Aktualne zachowanie (28.04.2026)
- Backend rozpoznaje sport, normalizuje (`bike`, `swim`, `walk_hike`, `strength`, `other`).
- Zapisuje jako workout z `sport != run`.
- Liczy wpływ na fatigue/load przez `ActivityImpactService`.
- Plan tygodniowy może uwzględnić tę aktywność jako cross-training.

### Stara spec (`03-workout-domain.md`) — zachowanie nieaktualne
- Historycznie zakładała odrzucanie wszystkiego poza `Running`, `Trail Running`, `Treadmill Running` z kodem `WRONG_SPORT`.
- Ten zapis nie powinien być traktowany jako aktualne wymaganie P0.

### Konflikt
Dwa źródła prawdy są niespójne:
- Spec mówi: odrzucaj.
- Aktywne docs (`integrations.md`) mówi: cross-training jest pełnoprawny obywatel.
- Implementacja: przyjmuje cross-training.

### Decyzja produktowa rekomendowana
**Zatrzymać cross-training jako pełnoprawny.** Spec `03-workout-domain.md` jest nieaktualna w tej sekcji i wymaga update. Cross-training daje wartość (fatigue tracking) i nie ma sensu odrzucać.

### Oczekiwane zachowanie UI (po decyzji)
- Plik zapisuje się.
- W liście treningów ma wyraźny tag (np. `Rower`, `Pływanie`).
- W planie tygodniowym widoczny jako cross-training z innym kolorem/ikonką.
- User może edytować klasyfikację jeśli backend źle zgadł (US-IMPORT-006).

### Kryteria akceptacji (P0)
- Plik z `Sport="Biking"` zapisuje się jako workout z `sport = bike`.
- Wpływ na fatigue jest liczony.
- W rolling planie aktywność jest widoczna ale nie liczona jako bieg do statystyk dystansu.

### Testy / smoke
- Test backend: upload 1× rower, 1× bieg → 2 workouty z różnym sport.
- Test backend: upload tylko cross-training → plan generuje się ale `weeklyLoad.distanceKm = 0`.

### Uwagi produktowe
**Akcja:** Zaktualizować `03-workout-domain.md` żeby odzwierciedlał rzeczywistość (cross-training jest pełnoprawny). Usunąć kod `WRONG_SPORT` ze spec albo zostawić jako kod dla "absolutnie nierozpoznawalny sport" (np. `Sport="GolfCart"`).

---

## US-IMPORT-006 — Korekta klasyfikacji aktywności

**Typ:** edge case
**Persona:** P-COROS, P-MULTI
**Status:** missing
**Priorytet:** P1

### Stan wejściowy
Backend zaimportował aktywność jako `other` (np. nie umiał rozpoznać sportu z FIT). User wie że to bieg.

### Preconditions
- Workout istnieje, `sport = other`.

### Kroki użytkownika
1. Otwiera szczegóły treningu.
2. Widzi sport "Inne".
3. Klika "Zmień klasyfikację".
4. Wybiera z listy: bieg, rower, pływanie, siła, spacer/turystyka, inne.
5. Zapisuje.

### Oczekiwane zachowanie UI
- Dropdown lub modal z opcjami.
- Po submit: workout aktualizuje się, plan się przeliczy.

### Oczekiwane API
- `PATCH /api/workouts/{id}` z body `{ sport: 'run' }`.

### Oczekiwane zmiany danych
- `workouts.sport` zaktualizowane.
- Sygnały i compliance przeliczone.

### Kryteria akceptacji (P1)
- User może zmienić klasyfikację dowolnego workoutu.
- Plan się przelicza.
- Historia zmian (audit) — opcjonalnie P2.

### Testy / smoke
- Test e2e: zmień klasyfikację → plan się odświeży.

### Uwagi produktowe
Roadmap punkt 2 to wymienia jako konkretne zadanie. Bez tego user FIT-based (Coros) jest bezsilny gdy parser się pomyli.

---

## US-IMPORT-007 — Upload z malformed XML

**Typ:** error
**Persona:** każda
**Status:** implemented (validacja parserów)
**Priorytet:** P0

### Stan wejściowy
User wgrywa plik, który jest uszkodzony / nie jest poprawnym TCX.

### Preconditions
- Plik istnieje na dysku użytkownika ale jest np. obciętym TCX.

### Kroki użytkownika
1. Wgrywa.
2. Backend nie potrafi sparsować.

### Oczekiwane zachowanie UI
- Toast z komunikatem "Plik X.tcx jest uszkodzony lub w niewspieranym formacie".
- Inne pliki w batchu (jeśli multi-upload) idą dalej.

### Oczekiwane API
- `POST /api/workouts/upload` zwraca 400 z kodem `INVALID_XML` lub `MALFORMED_TCX`.

### Kryteria akceptacji (P0)
- Backend nie crashuje.
- Inne pliki w batchu są niezależnie procesowane.
- User dostaje czytelny komunikat.
- Brak częściowo zapisanego workout w bazie (transakcja).

### Testy / smoke
- Test backend: upload uciętego TCX → 400.
- Test backend: upload pustego pliku → 400.
- Test backend: upload pliku JPG przemianowanego na .tcx → 400.

---

## US-IMPORT-008 — Sanity check: zbyt wysokie tempo (rower przemianowany na bieg)

**Typ:** error / edge case
**Persona:** P-MULTI (zły tag w pliku)
**Status:** missing (kody w spec, nie wdrożone)
**Priorytet:** P1

### Stan wejściowy
Plik ma `Sport="Running"` ale realnie to jazda na rowerze (np. ktoś przemianował). Średnie tempo < 3:00/km czyli > 20 km/h.

### Preconditions
- Sanity check w backendzie nie działa dziś.

### Kroki użytkownika
1. Wgrywa plik.
2. Aplikacja przyjmuje go jako bieg.
3. Plan się rozjeżdża.

### Oczekiwane zachowanie UI (po wdrożeniu)
- Backend odrzuca z kodem `IMPORT_SANITY_SPEED_TOO_HIGH`.
- UI: "Plik wygląda na rower (średnie tempo > 20 km/h). Czy chcesz wgrać go jako rower?".
- User wybiera: jako rower / odrzuć.

### Oczekiwane API
- `POST /api/workouts/upload` zwraca 400 z `IMPORT_SANITY_SPEED_TOO_HIGH` i sugestią `suggestedSport: 'bike'`.

### Kryteria akceptacji (P1)
- Średnie tempo < 3:00/km dla biegu → odrzuć lub re-klasyfikuj.
- User ma 2 opcje: zaakceptować jako rower lub anulować.

### Testy / smoke
- Test backend: TCX z fake fast running → flag.

### Uwagi produktowe
Jest też próg odwrotny (chodzenie > 15:00/km), ale to scenariusz P2 — chodzenie jest realnym treningiem regeneracyjnym dla wielu biegaczy.

---

## US-IMPORT-009 — Plik bez danych dystansu

**Typ:** error / edge case
**Persona:** P-NOVICE (bieżnia bez kalibracji)
**Status:** unknown (do potwierdzenia)
**Priorytet:** P1

### Stan wejściowy
Plik ma czas trwania > 0 ale dystans = 0 lub brak.

### Kroki użytkownika
1. Wgrywa plik.

### Oczekiwane zachowanie
- Backend przyjmuje plik z notatką "brak dystansu, nie liczono tempa".
- Workout zapisany, ale bez `distanceKm` i bez wpływu na weekly load (lub z conservatywnym fallbackiem `distance = duration × pace_estimate`).

### Oczekiwane API
- 200 OK z workout, ale `summary.original.distanceM = 0` i flaga `lowDataWarning: true`.

### Kryteria akceptacji (P1)
- Backend nie crashuje.
- Workout istnieje w liście.
- Plan/sygnały radzą sobie z `distance = 0` (fallback na duration).

### Testy / smoke
- Test backend: TCX z TotalTimeSeconds > 0 i bez DistanceMeters.

### Uwagi produktowe
Spec mówi o kodzie `IMPORT_SANITY_MISSING_DISTANCE` jako odrzucenie. Sugeruję **nie odrzucać** — bieżnia bez kalibracji to realny przypadek użytkownika. Zamiast tego: zapisz, oznacz, mów ostrożnie.

---

## US-IMPORT-010 — Plik bez danych HR

**Typ:** edge case
**Persona:** P-NOVICE (bez pasa HR)
**Status:** implemented
**Priorytet:** P0

### Stan wejściowy
Plik nie zawiera trackpoints z HeartRateBpm.

### Kroki użytkownika
1. Wgrywa plik bez HR.

### Oczekiwane zachowanie
- Backend zapisuje workout z `hr.avgBpm = null, hr.maxBpm = null`.
- Strefy HR nie są liczone.
- `intensityBuckets` używa numeric intensity z tempa albo fallback.

### Kryteria akceptacji (P0)
- Workout zapisany.
- Brak HR nie crashuje sygnałów.
- W UI workout pokazuje "Brak danych HR" w miejsce wartości.

### Testy / smoke
- Test backend: TCX bez HR (już pokryte w `TcxParsingServiceTest`).

### Uwagi produktowe
To częsty przypadek dla P-NOVICE bez pasa. Aplikacja musi działać bez HR, choć z mniejszą precyzją.

---

## US-IMPORT-011 — Trening z dzisiaj vs historyczny

**Typ:** edge case
**Persona:** P-GARMIN (sync po treningu), P-NOVICE (upload TCX po biegu)
**Status:** missing
**Priorytet:** P1

### Stan wejściowy
User właśnie skończył trening i wgrywa plik (lub Garmin sync go pobiera). Lub: user wgrywa zaległe pliki sprzed miesiąca.

### Preconditions
- Workout zaimportowany.
- Backend ma logikę porównania `workoutDate` z `today` (UTC).

### Kroki użytkownika
1. (a) Trening dzisiejszy: po wgraniu user widzi feedback z odniesieniem do planowanej sesji na dziś.
2. (b) Trening historyczny: user widzi workout w liście, sygnały zaktualizowane, ale brak feedbacku kontekstowego.

### Oczekiwane zachowanie (po wdrożeniu)
- Backend porównuje datę treningu z dzisiejszą.
- Per dziś: dodaje workout do feedbacku z planem dnia ("Dzisiaj miałeś planowany easy 45 min, zrobiłeś 50 min easy — zgodnie z planem").
- Per historyczny: tylko aktualizacja sygnałów, bez feedbacku kontekstowego.

### Oczekiwane API
- `POST /api/workouts/upload` zwraca pole `isToday: bool` w response.
- `POST /api/workouts/{id}/feedback/generate` przyjmuje flagę `isToday`.

### Kryteria akceptacji (P1)
- Workout z dzisiaj generuje feedback z `planImpact` odnoszącym się do planu dnia.
- Workout historyczny generuje feedback bez tego (lub uproszczony).
- Strefa czasowa: porównanie w UTC; user timezone P2.

### Testy / smoke
- Test backend: upload workout z `startTime = today (UTC)` → `isToday = true`.
- Test backend: upload workout z `startTime = 30 days ago` → `isToday = false`.

### Uwagi produktowe
Spec `03-workout-domain.md` to opisuje jako wymagane. Realna implementacja to TODO. Bez tego feedback po treningu jest mniej trafny.

---

## US-IMPORT-012 — Upload bardzo dużego pliku

**Typ:** edge case / error
**Persona:** P-MULTI (full ultramaraton 8h)
**Status:** unknown
**Priorytet:** P1

### Stan wejściowy
Plik > 10 MB (np. ultramaraton ze sekundowym sampling).

### Kroki użytkownika
1. Próbuje wgrać.

### Oczekiwane zachowanie
- Backend ma limit (np. 25 MB) i zwraca 413 jeśli przekroczony.
- UI pokazuje komunikat "Plik za duży, max XX MB".
- Pliki < limit są procesowane.

### Kryteria akceptacji (P1)
- Limit jest jawny w docs.
- 413 z czytelnym komunikatem.
- Brak timeoutu na typowych plikach (50-100 MB tak samo jak 100 KB).

### Testy / smoke
- Test backend: plik 30 MB → 413.

### Uwagi produktowe
Konfiguracja serwera (LiteSpeed na IQHost) ma swoje limity post_max_size i upload_max_filesize. Sprawdzić aktualne wartości produkcyjne.

---

## US-IMPORT-013 — Upload przerwany przez timeout sieci

**Typ:** error
**Persona:** każda na słabym wifi
**Status:** unknown
**Priorytet:** P1

### Stan wejściowy
User wgrywa duży plik, sieć się rozłącza w trakcie.

### Kroki użytkownika
1. Wgrywa.
2. Połączenie zrywa.

### Oczekiwane zachowanie
- UI pokazuje "Błąd połączenia, spróbuj ponownie".
- Backend nie zostaje z half-uploaded plikiem.
- User może retry tym samym plikiem.

### Kryteria akceptacji (P1)
- Brak orphan rekordów w bazie.
- Retry działa.
- User nie traci danych formularza/innych plików.

### Testy / smoke
- Manual smoke: wgraj plik, ubij sieć w trakcie, sprawdź stan backendu.

---

## US-IMPORT-014 — Multi-upload z mieszanką poprawnych i błędnych plików

**Typ:** edge case
**Persona:** P-MULTI
**Status:** implemented (zakładam)
**Priorytet:** P0

### Stan wejściowy
User wgrywa 10 plików: 7 poprawnych TCX, 1 malformed, 1 duplicate, 1 cross-training.

### Kroki użytkownika
1. Wgrywa wszystkie razem.

### Oczekiwane zachowanie
- 7 zapisuje się jako biegi.
- 1 cross-training: zapisuje się z odpowiednim tagiem (lub odrzucany — zależnie od decyzji US-IMPORT-005).
- 1 duplicate: pomijany z notką.
- 1 malformed: error w UI, inne idą dalej.

### Kryteria akceptacji (P0)
- Każdy plik jest niezależny.
- Sumaryczna informacja: "Wgrano 8/10, pominięto 1 duplikat, 1 błąd".
- Lista treningów odświeża się.

### Testy / smoke
- Test e2e: 10 plików → 8 nowych workoutów.

---

## US-IMPORT-015 — Upload ZIP z wieloma plikami

**Typ:** happy path (przyszłościowy)
**Persona:** P-MULTI (export historyczny)
**Status:** missing
**Priorytet:** P2

### Stan wejściowy
User chce zaimportować całą historię z eksportu Garmin/Strava (ZIP z setkami plików).

### Kroki użytkownika
1. Pobrałby plik ZIP z eksportu.
2. Wgrałby na MarcinCoach.

### Oczekiwane zachowanie (po wdrożeniu)
- Backend rozpakowuje ZIP w sandbox.
- Iteruje po plikach, każdy procesuje jak single upload.
- Async job — user widzi progress.
- Po skończeniu: notification w UI / email.

### Oczekiwane API
- `POST /api/workouts/upload-batch` (nowy endpoint).
- Body: ZIP file.
- Response: job id.
- `GET /api/workouts/upload-batch/{job_id}` — status.

### Kryteria akceptacji (P2)
- Limit pliku ZIP (np. 100 MB).
- Progress widoczny w UI.
- Po zakończeniu lista workoutów odświeżona.

### Testy / smoke
- Test backend: ZIP z 50 TCX.

### Uwagi produktowe
**Decyzja:** P2, nie blockuje MVP. Multi-upload TCX w onboardingu (US-IMPORT-001) częściowo rozwiązuje problem dla zwykłego użytkownika. ZIP to feature dla power-userów importujących setki plików.

---

## US-IMPORT-016 — Obsługa błędów technicznych (zbiorczy)

**Typ:** error / regression
**Persona:** każda
**Status:** partial
**Priorytet:** P1

### Podscenariusze (skrócone)

#### 016a — Backend zwraca 500
**Oczekiwane:** UI pokazuje "Coś poszło nie tak, spróbuj ponownie", error logowany.

#### 016b — Timeout requestu (>30s)
**Oczekiwane:** UI anuluje, pokazuje "Połączenie trwa zbyt długo".

#### 016c — Backend offline (502/503)
**Oczekiwane:** UI pokazuje status "Aplikacja chwilowo niedostępna".

#### 016d — Frontend offline (brak internetu)
**Oczekiwane:** UI wykrywa offline (`navigator.onLine`), pokazuje banner.

#### 016e — 401 w trakcie uploadu
**Oczekiwane:** redirect do login po zakończeniu requestu, plik nie jest zapisany. Plik **może** zostać w pamięci komponentu, ale to nie jest gwarantowane (US-AUTH-007).

#### 016f — 429 rate limit
**Oczekiwane:** UI pokazuje "Za dużo requestów, poczekaj minutę".

### Kryteria akceptacji (P1)
- Każdy z błędów wyżej ma czytelny komunikat dla użytkownika.
- Brak gołych "TypeError" lub "undefined" wyciekających do UI.
- Sentry / error logging zbiera błędy frontendu.

### Testy / smoke
- Manual smoke: każdy z podpunktów.
- Integration test: backend mock zwracający każdy status.

### Uwagi produktowe
Sugeruję wdrożenie jednego globalnego error boundary + interceptora axios dla wszystkich requestów.
