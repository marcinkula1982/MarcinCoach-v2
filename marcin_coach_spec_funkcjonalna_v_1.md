# MarcinCoach — specyfikacja funkcjonalna v1

---

## Stan realizacji — 2026-04-21

> Ten dokument opisuje wizję i wymagania produktu. Sekcja poniżej synchronizuje go z aktualnym stanem repo.

**Projekt jest greenfield — zero użytkowników produkcyjnych. Nie ma co "migrować", jest co deployować.**

| Pakiet | Status | Uwagi |
|--------|--------|-------|
| M1 beyond minimum | ✅ DONE | profil, jakość profilu, maxSessionMin, hasCurrentPain |
| M2 beyond minimum | ✅ DONE | TCX parsing, intensityBuckets, HR stats, avgPaceSecPerKm |
| M3 | ✅ DONE | WeeklyPlanService, quality density guard, adjustments, technique/surface |
| M4 | ✅ DONE | adaptation signals: missed/harder/easier/controlStart, alerty |
| C0 + C1 | ✅ DONE | contract freeze tests (18), cold start tests (5), audit M1–M4 PASS |
| php artisan test | ✅ 220 passed / 1023 assertions | 2026-04-21 |
| Frontend | ✅ podłączony pod PHP | `src/api/client.ts` → `http://localhost:8000/api` |
| Node.js backend | 🔵 AKTYWNY dla AI + integracji | zakres per ADR 0002 |

**Publiczne kontrakty zamrożone:** `/api/weekly-plan`, `/api/training-signals`, `/api/training-adjustments`, `/api/training-context`

**Następny pakiet: M3/M4 beyond** — periodyzacja blokowa, pamięć planu, granularna adaptacja, bogatsze alerty.

**Następne kamienie wdrożeniowe:**
- D0 — pierwsze wdrożenie produkcyjne (serwer, nginx, SSL, migracje)
- D1 — pierwsze uruchomienie live z prawdziwym użytkownikiem

---

## 1. Cel produktu
MarcinCoach to wirtualny trener biegowy oparty przede wszystkim na danych konkretnego użytkownika, a nie na uśrednionych tabelach i gotowych szablonach. System ma analizować realne obciążenia, tolerancję wysiłku, historię treningów, ograniczenia czasowe, teren, sprzęt, zdrowie i cele startowe, a następnie prowadzić użytkownika w sposób indywidualny.

## 2. Główna zasada architektury
- 80% logiki i obliczeń realizuje backend.
- OpenAI nie liczy podstawowych metryk ani nie zastępuje logiki systemowej.
- OpenAI uruchamia się tylko wtedy, gdy:
  - wykryto istotne odchylenie od planu,
  - pojawił się konflikt danych lub sytuacja niejednoznaczna,
  - trzeba zinterpretować złożony kontekst,
  - trzeba przygotować rekomendację treningową, której backend nie umie bezpiecznie wyprowadzić regułami.

## 3. Fundament danych użytkownika
### 3.1 Profil bazowy
- wiek
- wzrost / masa ciała
- płeć
- lokalizacja i dostępny teren
- doświadczenie biegowe
- liczba treningów tygodniowo
- średni kilometraż z ostatnich tygodni
- najdłuższe biegi
- ostatnie wyniki na 5 km / 10 km / półmaraton / maraton
- aktualna forma subiektywna
- status po przerwie
- cele startowe
- priorytet startów
- dostępność czasowa
- dni niedostępne
- maksymalny czas treningu
- sprzęt i sensory
- historia urazów i bólu
- parametry fizjologiczne
- preferencje treningowe
- sen / stres / charakter pracy
- podstawy żywienia i tolerancji żeli

### 3.2 Profil dynamiczny
- aktualna dyspozycja
- trend zmęczenia
- trend tolerancji objętości
- trend tolerancji intensywności
- trend regeneracji
- stabilność tętna
- dryf HR
- reakcja na akcenty
- reakcja na longi
- reakcja na teren i przewyższenia
- odpowiedź na starty kontrolne

### 3.3 Dane treningowe
- import TCX/FIT/GPX
- integracja Strava
- integracja Garmin przez wtyczkę / synchronizację
- ręczny wpis treningu, jeśli brak pliku
- oznaczanie typu treningu
- oznaczanie zgodności z planem
- wykrywanie przerw, zatrzymań, artefaktów GPS, anomalii tętna

## 4. Moduły funkcjonalne systemu

### 4.1 Onboarding i diagnostyka początkowa
System musi:
- zebrać komplet danych wejściowych,
- ocenić jakość danych,
- oznaczyć braki krytyczne,
- wyliczyć poziom pewności profilu,
- sklasyfikować użytkownika do jednego z poziomów dojrzałości treningowej.

Efekt:
- profil startowy,
- pierwsze strefy robocze,
- ograniczenia bezpieczeństwa,
- plan wejściowy tylko na podstawie danych wystarczających jakościowo.

### 4.2 Silnik analizy treningu
Backend powinien liczyć:
- dystans, czas, tempo, GAP jeśli teren pozwala,
- HR avg / max / drift,
- rozkład stref tempa i HR,
- obciążenie sesji,
- monotonię obciążeń,
- nagłe skoki objętości,
- świeżość po ciężkich sesjach,
- zgodność z planem,
- jakość wykonania jednostki,
- odchylenia od celu jednostki.

Powinien też wykrywać:
- zbyt szybkie easy,
- zbyt wolne lub niestabilne akcenty,
- przeciążenie narastające,
- oznaki niewyspania / słabej regeneracji widoczne w danych,
- niespójność między deklaracją użytkownika a plikiem.

### 4.3 Silnik planowania
Planowanie nie może być generatorem "szablonu tygodnia". Musi uwzględniać:
- główny cel i datę startu,
- priorytety startów,
- aktualną tolerancję obciążeń,
- historię 4–8 tygodni,
- ograniczenia kalendarzowe użytkownika,
- teren dostępny w konkretne dni,
- poziom zmęczenia,
- ryzyko kontuzji,
- konieczność budowania lub podtrzymania konkretnych zdolności.

Plan musi mieć 3 poziomy:
- makro: cykl do startu,
- mezo: blok 2–4 tygodni,
- mikro: tydzień i jednostki dzienne.

### 4.4 Adaptacja planu
To jest rdzeń produktu.

System ma automatycznie reagować na:
- opuszczony trening,
- wykonanie mocniej niż plan,
- wykonanie słabiej niż plan,
- oznaki przeciążenia,
- start kontrolny,
- chorobę / ból / uraz zgłoszony przez użytkownika,
- nagłą zmianę dostępności czasu,
- zmianę celu głównego.

Nie każda zmiana ma odpalać OpenAI.
Najpierw reguły backendowe:
- drobne przesunięcie,
- korekta objętości,
- korekta intensywności,
- zamiana jednostki,
- skrócenie lub odpuszczenie.

OpenAI dopiero wtedy, gdy trzeba zinterpretować złożony kontekst i ułożyć sensowną decyzję trenerską.

### 4.5 Moduł bezpieczeństwa treningowego
To musi być osobny moduł, nie detal.

Powinien blokować lub oznaczać wysokie ryzyko, gdy:
- objętość rośnie za szybko,
- intensywność kumuluje się zbyt gęsto,
- HR / pace sugerują spadek dyspozycji,
- użytkownik zgłasza ból,
- plan przeczy dostępności lub regeneracji,
- dane wejściowe są zbyt słabe, by rekomendować mocny akcent,
- po starcie lub chorobie system próbuje wrócić za szybko.

### 4.6 Moduł komunikacji z użytkownikiem
MarcinCoach nie może być tylko dashboardem.
Musi umieć:
- powiedzieć co dziś zrobić,
- powiedzieć dlaczego właśnie to,
- ostrzec przed ryzykiem,
- wyjaśnić zmianę planu,
- poprosić o brakujące dane,
- odróżniać komunikat informacyjny od alarmowego.

Rodzaje komunikatów:
- codzienny trening,
- podsumowanie po treningu,
- tygodniowy przegląd,
- alert przeciążenia,
- alert niespójności danych,
- alert braków w synchronizacji,
- rekomendacja korekty celu.

### 4.7 Panel trenera / administracyjny
Potrzebny, nawet jeśli początkowo uproszczony.

Minimum:
- podgląd użytkownika 360,
- historia planów i zmian,
- historia alertów,
- historia plików i synchronizacji,
- możliwość ręcznego nadpisania planu,
- możliwość blokady określonych typów treningu,
- notatki trenerskie,
- ślad decyzyjny: kto i dlaczego zmienił plan.

### 4.8 Raporty i ocena progresu
System powinien pokazywać:
- progres formy,
- progres tolerancji obciążeń,
- progres wytrzymałości tlenowej,
- progres tempa progowego / startowego,
- skuteczność planu,
- zgodność realizacji,
- ryzyko przetrenowania,
- gotowość startową.

## 5. Czego ten system nie może robić
- nie może opierać stref na samym wieku lub wzorach z tabel,
- nie może planować ignorując dostępność czasu,
- nie może traktować każdego opuszczenia treningu jako problemu,
- nie może generować planu bez poziomu pewności danych,
- nie może rekomendować mocnych jednostek mimo czerwonych flag,
- nie może udawać pewności tam, gdzie danych nie ma.

## 6. Minimalny model decyzyjny backendu
### 6.1 Warstwa reguł twardych
- walidacja danych
- walidacja bezpieczeństwa
- limity wzrostu obciążeń
- limity gęstości akcentów
- reguły powrotu po przerwie / chorobie / starcie
- reguły obsługi bólu i urazu

### 6.2 Warstwa scoringu
- scoring jakości profilu użytkownika
- scoring jakości wykonania treningu
- scoring ryzyka przeciążenia
- scoring gotowości do akcentu
- scoring zgodności z planem
- scoring pewności rekomendacji

### 6.3 Warstwa eskalacji do OpenAI
Eskalacja tylko gdy:
- scoring pewności spada poniżej progu,
- kilka sygnałów daje sprzeczny obraz,
- potrzebna jest interpretacja z opisu użytkownika,
- potrzebna jest korekta planu z wieloma ograniczeniami jednocześnie.

## 7. Najważniejsze encje danych
- User
- UserProfile
- Goal
- Race
- Availability
- HealthStatus
- Device
- WorkoutImport
- WorkoutSession
- WorkoutAnalysis
- TrainingPlan
- TrainingWeek
- TrainingDay
- PlannedWorkout
- CompletedWorkout
- DeviationEvent
- Alert
- Recommendation
- AIInvocation
- CoachNote
- SyncLog

## 8. Zdarzenia krytyczne, które system musi obsłużyć
- brak synchronizacji danych
- duplikat treningu
- uszkodzony plik
- błędny HR
- nietypowe tempo przez GPS drift
- użytkownik zrobił inny trening niż w planie
- użytkownik nie zrobił nic przez kilka dni
- użytkownik zrobił start zamiast easy
- użytkownik zgłasza ból
- użytkownik zmienia cel startowy
- użytkownik traci kilka dni przez chorobę / podróż / pracę

## 8A. Architektura dwubackendowa i droga do produkcji

### 8A.0 Kontekst — projekt greenfield
MarcinCoach nie migruje istniejącej aplikacji z użytkownikami. To nowy projekt bez ruchu produkcyjnego. Dlatego właściwe etapy to:

- **D0 — first production-like deploy**: pierwsze wdrożenie na serwer (nginx, SSL, `.env` produkcyjny, migracje DB). Brak realnych użytkowników. Cel: potwierdzić, że system działa poza localhostem.
- **D1 — first live launch**: system dostępny dla pierwszego prawdziwego użytkownika.

Sekcja 8A.1–8A.7 opisuje zasady, które obowiązują na etapie D0 i D1 oraz przy każdej późniejszej zmianie architektury.

### 8A.1 Zasada podziału backendów (ADR 0002)
PHP przejmuje: auth, profil, workouty, signals, context, adjustments, weekly plan, compliance, alerts.
Node.js pozostaje dla: `/ai/plan`, `/ai/insights`, `/integrations/strava/*`, `/integrations/garmin/*`.

To nie jest stan tymczasowy bez decyzji — jest udokumentowany w `docs/adr/0002-cutover-scope-php-core-node-ai.md`.

### 8A.2 Trzy statusy dla każdego obszaru
- Code complete — kod, migracje, testy i integracje są gotowe w repo.
- Deploy ready — aplikacja jest technicznie przygotowana do wdrożenia na serwer.
- Production live — obszar obsługuje realny ruch użytkownika.

### 8A.3 Czego nie da się zamknąć samym commitem
D0 nie zamyka się commitem do repo. Operacyjnie trzeba wykonać:
- wdrożenie na serwer z konfiguracją nginx/proxy,
- ustawienie `.env` produkcyjnego (klucze, DB, mail),
- uruchomienie migracji na docelowej bazie,
- weryfikację healthchecków,
- obserwację logów po wdrożeniu.

### 8A.4 Kryteria gotowości do D0
Przed pierwszym wdrożeniem produkcyjnym musi być spełnione minimum:
- komplet kluczowych endpointów PHP działa i przechodzi testy,
- model danych jest zgodny z logiką domenową,
- zakres Node.js (AI + integracje) jest świadomie opisany i wyłączony ze smoke testów PHP,
- istnieje checklista smoke testów po wdrożeniu,
- istnieje monitoring logów (laravel.log + failed_jobs),
- istnieje plan rollbacku.

**Stan na 2026-04-21: wszystkie powyższe kryteria spełnione. Brakuje tylko infrastruktury serwera.**

### 8A.5 D0 deploy checklist
- freeze zmian przed wdrożeniem,
- backup / snapshot (jeśli DB ma dane),
- uruchomienie migracji na serwerze,
- smoke test T+5m → T+30m → T+2h → T+24h,
- monitoring logów i błędów,
- potwierdzenie działania kluczowych ścieżek użytkownika,
- decyzja: kontynuacja lub rollback.

### 8A.6 Rollback
Rollback musi być zdefiniowany przed cutoverem. Specyfikacja powinna wskazywać:
- kto podejmuje decyzję o rollbacku,
- jakie warunki uruchamiają rollback,
- ile czasu trwa powrót,
- jakie dane mogą zostać utracone lub wymagać resynchronizacji,
- jak wygląda ponowna próba przełączenia.

### 8A.7 Ryzyka operacyjne
- fałszywe poczucie, że „testy przechodzą” oznacza „gotowe do uruchomienia”,
- brak `.env` produkcyjnego przed D0,
- Node.js aktywny obok PHP bez zdefiniowanych ścieżek routingu,
- brak smoke testów po pierwszym wdrożeniu,
- brak monitoringu logów integracji,
- próba wdrożenia AI eskalacji zanim silnik deterministyczny jest ustabilizowany w produkcji.

## 9. Ryzyka projektowe
### 9.1 Ryzyka produktowe
- za szeroki zakres v1,
- zbyt wczesne użycie AI zamiast reguł,
- brak rozróżnienia między danymi pewnymi i niepewnymi,
- brak prostego UX dla zgłaszania bólu, zmęczenia i dostępności.

### 9.2 Ryzyka treningowe
- fałszywe wnioski z małej próbki,
- błędna interpretacja tętna,
- niedoszacowanie zmęczenia pozatreningowego,
- zbyt agresywna adaptacja planu,
- nieuwzględnienie terenu i przewyższeń.

### 9.3 Ryzyka techniczne
- niespójność danych z różnych źródeł,
- problemy z autoryzacją API,
- rozjazd modelu danych między PHP a wcześniejszym Node,
- zbyt kosztowne i zbyt częste wywołania OpenAI,
- brak audytowalności decyzji systemu.

### 9.4 Ryzyka prawne i operacyjne
- dane zdrowotne i treningowe wymagają ostrożnego modelu zgód i polityki prywatności,
- potrzeba jasnego rozdzielenia: informacja treningowa vs. porada medyczna,
- potrzeba logowania zgód na integracje zewnętrzne,
- potrzeba możliwości usunięcia danych użytkownika.

## 10. MVP — co naprawdę musi wejść
### Musi być
- onboarding użytkownika,
- import i analiza plików treningowych,
- synchronizacja minimum z jednym źródłem,
- backendowy silnik analizy,
- backendowy generator tygodnia,
- podstawowa adaptacja planu regułami,
- alerty przeciążenia i braków danych,
- wywołanie OpenAI tylko dla przypadków eskalacyjnych,
- widok planu tygodniowego,
- podsumowanie po treningu.

### Nie musi być w MVP
- rozbudowany czat AI 24/7,
- rozbudowana gamifikacja,
- social features,
- wielosport,
- predykcje oparte na modelach ML własnej produkcji,
- zaawansowany panel trenerski z rolami.

## 11. Kamienie milowe

### ✅ M1 — fundament danych (DONE)
- model danych
- profil użytkownika z jakością (ProfileQualityScoreService)
- cele, starty, dostępność, zdrowie, sprzęt
- maxSessionMin cap, hasCurrentPain guard

### ✅ M2 — import i analiza treningów (DONE)
- import TCX / parser (TcxParsingService)
- intensityBuckets, HR stats, avgPaceSecPerKm
- ExternalWorkoutImportService → signals → compliance → alerts
- sport detection, artefakty GPS

### ✅ M3 — silnik planu backendowego (DONE)
- WeeklyPlanService: generator mikrocyklu, loadScale, quality density guard
- TrainingAdjustmentsService: normalizacja, deduplicacja
- adjustment codes: reduce_load, add_long_run, technique_focus, surface_constraint
- contract freeze: /api/weekly-plan, /api/training-adjustments

### ✅ M4 — adaptacja i alerty (DONE)
- adaptation signals: missedKeyWorkout, harderThanPlanned, easierThanPlannedStreak, controlStartRecent
- adjustment codes: missed_workout_rebalance, harder_than_planned_guard, easier_than_planned_progression, control_start_followup
- alerty: MISSED_KEY_WORKOUT, EASIER_THAN_PLANNED_STREAK
- contract freeze: /api/training-signals, /api/training-context

### 🔜 M3/M4 beyond — periodyzacja i głębsza adaptacja (następny)
- periodyzacja blokowa (base → build → peak → taper)
- pamięć planu między tygodniami
- granularna adaptacja: tempo stref, długość serii
- bogatsze alerty: HR drift, pace regression, brak snu
- głębsza analiza TCX: GAP, przewyższenia, cadence

### ⬜ M5 — integracje
- Strava (OAuth, pobieranie aktywności, mapowanie pól)
- Garmin (nieoficjalne API / narzędzia z GitHub — bez oficjalnego wsparcia)
- logi synchronizacji i obsługa błędów

### ⬜ M6 — AI escalation layer
- progi eskalacji (scoring pewności)
- prompt orchestration dla przypadków niejednoznacznych
- logowanie wejście / wyjście / koszt / powód wywołania
- mechanizm zatwierdzania lub odrzucania rekomendacji AI
- zabezpieczenie przed zbyt częstym odpalaniem

### ⬜ M7 — panel użytkownika
- dashboard tygodnia
- historia treningów
- alerty
- formularz samopoczucia / bólu / snu / stresu
- historia zmian planu

### ⬜ M8 — panel administracyjny
- podgląd użytkownika 360
- ręczne korekty
- historia planów i alertów
- audyt decyzji (log backendu + AI)

### ⬜ D0 — pierwsze wdrożenie produkcyjne
- konfiguracja serwera: nginx, SSL, .env produkcyjny
- uruchomienie migracji na docelowej bazie
- smoke tests (T+5m, T+30m, T+2h, T+24h)
- monitoring: laravel.log, failed_jobs
- Node.js: routing AI + integracje oddzielony od PHP core

### ⬜ D1 — pierwsze uruchomienie live
- pierwszy prawdziwy użytkownik
- onboarding flow end-to-end
- weryfikacja importu treningów w warunkach produkcyjnych
- decyzja o zakresie M5/M6 na podstawie feedbacku

## 12. Checklist wdrożeniowy
### M1 — fundament danych
- model danych
- profil użytkownika
- ankieta wejściowa
- cele, starty, dostępność, zdrowie, sprzęt

### M2 — import i analiza treningów
- import TCX/FIT/GPX
- parser
- czyszczenie danych
- analiza sesji
- klasyfikacja typu treningu

### M3 — silnik planu backendowego
- generator mikrocyklu
- logika doboru jednostek
- ograniczenia bezpieczeństwa
- plan tygodniowy

### M4 — adaptacja i alerty
- wykrywanie odchyleń
- korekty regułowe
- alerty zmęczenia / przeciążenia / braków

### M5 — integracje
- Strava
- Garmin / wtyczka
- logi synchronizacji

### M6 — AI escalation layer
- progi eskalacji
- prompt orchestration
- logowanie odpowiedzi AI
- mechanizm zatwierdzania lub odrzucania rekomendacji

### M7 — panel użytkownika
- dashboard tygodnia
- historia treningów
- alerty
- komentarze po treningu

### M8 — panel administracyjny
- podgląd użytkownika
- ręczne korekty
- historia zmian
- audyt decyzji

## 12. Checklist wdrożeniowy
### Analityka i produkt
- opisać personę użytkownika głównego
- spisać pełny zakres v1
- spisać zakres poza v1
- opisać krytyczne user stories
- zdefiniować Definition of Done dla każdego modułu

### Dane
- rozpisać schemat encji i relacji
- zdefiniować wymagane pola i pola opcjonalne
- zdefiniować poziom pewności danych
- zdefiniować słownik typów treningu
- zdefiniować słownik alertów

### Backend
- parser plików
- walidacja danych
- analiza treningu
- scoring ryzyka
- generator planu
- adaptacja planu
- system alertów
- logika eskalacji do OpenAI

### Integracje
- autoryzacja Strava
- pobieranie aktywności
- mapowanie pól Strava -> model wewnętrzny
- integracja Garmin
- obsługa błędów synchronizacji

### AI
- przygotować prompty systemowe
- zdefiniować kiedy AI ma być wywoływane
- zdefiniować kiedy odpowiedź AI ma być ignorowana
- logować wejście / wyjście / koszt / powód wywołania
- zabezpieczyć przed zbyt częstym odpalaniem

### Frontend
- onboarding
- widok planu
- widok treningu
- formularz samopoczucia / bólu / snu
- alert center
- historia zmian planu

### Bezpieczeństwo i prawo
- zgody użytkownika
- polityka prywatności
- usuwanie konta i danych
- rozdzielenie treści treningowych od medycznych
- logi zgód i integracji

### QA
- testy parsera
- testy reguł planowania
- testy odchyleń
- testy integracji
- testy edge case’ów
- testy bezpieczeństwa danych

### DevOps / migracja
- definicja statusów: code complete / cutover ready / production switched
- checklista gotowości do przełączenia
- checklista smoke testów
- plan rollbacku
- monitoring po cutoverze
- potwierdzenie wyłączenia starego środowiska Node

## 13. Kluczowa decyzja architektoniczna
Największy błąd tego projektu to byłoby zrobienie "AI coacha", który sprawia wrażenie mądrego, ale nie ma twardego silnika decyzyjnego.

Właściwy kierunek:
- backend = silnik trenerski,
- AI = warstwa interpretacji i eskalacji,
- dane użytkownika = źródło prawdy,
- każda decyzja powinna mieć ślad: z czego wynikła.

## 14. Co warto dopisać w kolejnej wersji specyfikacji

### Pilne (przed D0 / D1)
- konkretne reguły periodyzacji blokowej (M3/M4 beyond),
- tabela alertów i progów (progi numeryczne, nie tylko opisy),
- schemat bazy danych aktualny po M1-M4 (migracje już istnieją, brakuje diagramu),
- kontrakty API v1 — zamrożone endpointy + pola,
- definicja smoke test T+5m/T+30m dla D0.

### Ważne (przed M5/M6)
- dokładne user stories dla onboardingu i dashboardu,
- logika promptów OpenAI i progi eskalacji (scoring pewności),
- architektura integracji Garmin — wybór narzędzi z GitHub,
- definicja dashboardów i ekranów (wireframes lub opis funkcjonalny).

### Długoterminowe
- predykcje gotowości startowej,
- algorytm stref z danych TCX/FIT (nie z tabel wiekowych),
- model przewyższeń i terenu w planowaniu,
- wielosport (etap po ustabilizowaniu biegania).

