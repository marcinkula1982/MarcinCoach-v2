# MarcinCoach — specyfikacja funkcjonalna v1

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

## 8A. Migracja Node → PHP — definicja gotowości, cutover i rollback
### 8A.1 Zasada
Migracja nie jest zakończona w momencie, gdy repo ma gotowy kod i przechodzi testy. To oznacza jedynie gotowość do przełączenia. Faktyczne zakończenie migracji następuje dopiero po operacyjnym przełączeniu ruchu produkcyjnego na środowisko PHP i wyłączeniu starego deploymentu Node.

### 8A.2 Trzy statusy migracji
- Code complete — kod, migracje, testy i integracje są gotowe w repo.
- Cutover ready — aplikacja jest technicznie przygotowana do przejęcia ruchu produkcyjnego.
- Production switched — ruch produkcyjny został przełączony, środowisko PHP obsługuje realny traffic, a Node nie jest już aktywną ścieżką produkcyjną.

### 8A.3 Czego nie da się zamknąć samym commitem
Tego etapu nie zamyka sam commit ani merge do repo. Operacyjnie trzeba wykonać:
- przełączenie ruchu,
- zmianę routingu / proxy / load balancera / DNS — zależnie od architektury,
- weryfikację healthchecków,
- obserwację błędów po przełączeniu,
- wyłączenie albo odpięcie starego deploymentu Node.

### 8A.4 Kryteria gotowości do cutoveru
Przed przełączeniem musi być spełnione minimum:
- komplet kluczowych endpointów działa w PHP,
- model danych jest zgodny z docelową logiką domenową,
- integracje zewnętrzne działają albo są świadomie wyłączone i opisane,
- migracje danych zostały przetestowane,
- istnieje plan rollbacku,
- istnieje checklista smoke testów po przełączeniu,
- istnieje monitoring logów, błędów i synchronizacji.

### 8A.5 Production cutover checklist
- freeze zmian bezpośrednio przed przełączeniem,
- backup / snapshot,
- finalna walidacja danych,
- przełączenie ruchu na PHP,
- smoke test po przełączeniu,
- monitoring błędów i logów,
- potwierdzenie działania kluczowych ścieżek użytkownika,
- wyłączenie lub odpięcie Node z produkcji,
- decyzja: utrzymanie lub zamknięcie rollback window.

### 8A.6 Rollback
Rollback musi być zdefiniowany przed cutoverem. Specyfikacja powinna wskazywać:
- kto podejmuje decyzję o rollbacku,
- jakie warunki uruchamiają rollback,
- ile czasu trwa powrót,
- jakie dane mogą zostać utracone lub wymagać resynchronizacji,
- jak wygląda ponowna próba przełączenia.

### 8A.7 Ryzyka operacyjne migracji
- fałszywe poczucie, że „repo gotowe” oznacza „migracja zakończona”,
- pozostawienie aktywnej ścieżki Node równolegle bez kontroli,
- brak spójności danych po przełączeniu,
- brak testów smoke po cutoverze,
- brak jednoznacznych warunków rollbacku,
- brak monitoringu błędów integracji po przełączeniu.

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

### M9 — production cutover / DevOps
- freeze zmian
- backup / snapshot
- finalna walidacja danych
- przełączenie ruchu na PHP
- smoke test po przełączeniu
- monitoring błędów
- wyłączenie lub odpięcie Node z produkcji
- rollback window i decyzja o zamknięciu

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
- dokładne user stories,
- konkretne reguły planowania,
- konkretne reguły adaptacji,
- tabela alertów i progów,
- schemat bazy danych,
- kontrakty API,
- logika promptów OpenAI,
- definicja dashboardów i ekranów.

