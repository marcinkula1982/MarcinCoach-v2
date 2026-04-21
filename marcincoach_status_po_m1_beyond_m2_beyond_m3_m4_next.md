# MarcinCoach v2 — status po M1 beyond minimum, M2 beyond minimum, M3, M4 + dalszy kierunek

## 1. Punkt startowy

Na tym etapie jako **domknięte bazowo** traktujemy:
- **M1 beyond minimum**
- **M2 beyond minimum**
- **M3**
- **M4**

To oznacza, że projekt ma już:
- rozszerzony profil użytkownika z realnym wpływem downstream,
- działający fundament importu i analizy treningów,
- backendowy generator tygodnia,
- podstawową adaptację planu i alerty.

To jest wystarczający poziom, żeby przestać myśleć kategorią „czy MVP działa”, a przejść do pytania:
**co najbardziej zwiększy jakość docelowego coacha bez rozwalania architektury.**

---

## 2. Główna decyzja na teraz

**Nie wracamy do cutover readiness.**

Dalszy rozwój powinien iść w tej kolejności:

### Pakiet A — M3/M4 beyond current scope
Najpierw pogłębić warstwę decyzyjną coacha:
- głębsza periodyzacja,
- wielotygodniowa pamięć planu,
- bardziej granularna adaptacja,
- bogatsze alerty.

### Pakiet B — M2 deeper data
Dopiero potem rozszerzyć wejście danych o:
- FIT / GPX,
- cleaning artefaktów,
- moving time,
- cadence / power / elevation,
- pace-zones per user.

### Dlaczego taka kolejność
Bo przewaga produktu ma wynikać z **logiki planowania i adaptacji**, a nie z samego faktu, że czyta więcej pól.
Jeśli najpierw dołożysz głębsze dane M2, a M3/M4 nadal będą zbyt płytkie, to tylko zwiększysz złożoność wejścia bez proporcjonalnego wzrostu jakości decyzji.

---

## 3. Ocena obecnego stanu

## Co już jest wystarczająco sensowne

### M1 beyond minimum
Profil przestał być martwym JSON-em i zaczął wpływać na plan/adaptację. To był właściwy ruch.

### M2 beyond minimum
Masz już fundament analizy sesji lepszy niż „goły TCX parser”. To wystarcza jako baza pod dalsze decyzje systemu.

### M3
Masz generator mikrocyklu/tygodnia. To ważne, ale obecnie najpewniej nadal działa głównie w logice krótkiego horyzontu.

### M4
Masz podstawową adaptację i alerty. To jest potrzebne, ale jeszcze nie jest to poziom „coach pamięta blok, kontekst i trend”.

---

## 4. Największe obecne ograniczenia jakości coacha

To są teraz realne bottlenecki:

### 4.1 Brak prawdziwej pamięci planu wielotygodniowego
System potrafi ułożyć tydzień, ale jeszcze nie działa jak trener, który pamięta:
- poprzedni blok,
- ostatnie 2–4 tygodnie decyzji planistycznych,
- ile akcentów już było,
- czy dany bodziec był ostatnio rozwijany, podtrzymywany czy odpuszczany.

### 4.2 Adaptacja jest jeszcze zbyt binarna
Jeśli logika korekt nadal operuje głównie na „reduce / keep / shift”, to to jest za mało na docelową jakość.
Brakuje poziomu:
- które zdolności mają być chronione,
- które można odpuścić,
- jak zmienić nie tylko objętość, ale też strukturę bodźca.

### 4.3 Alerty są zbyt mało kontekstowe
Sam alert przeciążenia to za mało.
Potrzebne są alerty typu:
- konflikt między planem a trendem wykonania,
- jakość danych za słaba do pewnej decyzji,
- rosnąca kumulacja ryzyka mimo pozornie poprawnych sesji,
- brak realizacji konkretnego celu treningowego w bloku,
- rozjazd między profilem użytkownika a realnym wykonaniem.

### 4.4 M2 nadal nie daje pełnego obrazu jakości sesji
Bez moving time, lepszego cleaningu, elevation, cadence, power i pace-zones per user analiza pozostaje częściowo spłaszczona.
To nie blokuje rozwoju M3/M4 beyond, ale później będzie ograniczać precyzję.

---

## 5. Rekomendowany następny pakiet: M3/M4 beyond current scope

To powinien być **jeden spójny pakiet**, nie pięć drobnych eksperymentów.

## 5.1 Zakres must-have

### A. Głębsza periodyzacja
System planowania ma przejść z poziomu „dobór tygodnia” na poziom:
- faza / blok,
- cel bloku,
- dominujący bodziec,
- ograniczenia bloku,
- tydzień budujący / podtrzymujący / lżejszy.

Minimalnie backend musi umieć oznaczyć dla bieżącego tygodnia:
- block_type,
- block_goal,
- load_direction,
- key_capability_focus,
- week_role_in_block.

### B. Wielotygodniowa pamięć planu
Dodać pamięć planistyczną minimum 4–8 tygodni:
- jakie jednostki były planowane,
- jakie były wykonane,
- jakie decyzje adaptacyjne zapadły,
- czy cel bloku został osiągnięty.

To nie ma być pamięć „AI”, tylko backendowa pamięć domenowa.

### C. Bardziej granularna adaptacja
Rozszerzyć adaptację z prostych korekt na bardziej precyzyjne decyzje, np.:
- skróć objętość, ale zachowaj akcent jakościowy,
- usuń intensywność, ale utrzymaj strukturę long run,
- zamień próg na steady,
- zamień interwał na fartlek tlenowy,
- obniż gęstość akcentów na poziomie całego mikrocyklu,
- chroń kluczowy bodziec tygodnia kosztem wtórnego.

### D. Bogatsze alerty
Dodać osobne typy alertów dla:
- under-recovery trend,
- execution drift,
- stale missed capability,
- weak-data confidence,
- plan-history inconsistency,
- excessive density trend,
- post-race return risk,
- pain-with-load conflict.

## 5.2 Co powinno powstać technicznie

### Nowe lub rozszerzone encje / pola
- `training_plans`: meta dla bloku i tygodnia
- `training_weeks`: rola tygodnia w bloku, focus tygodnia
- `planned_workouts`: capability target / priority / protected flag
- `deviation_events`: bogatsza klasyfikacja odchyleń
- `alerts`: severity, family, confidence, explanation_code
- nowa warstwa typu `PlanHistoryService` lub `PlanMemoryService`
- nowa warstwa typu `BlockContextService`

### Nowe usługi backendowe
- `BlockPeriodizationService`
- `PlanMemoryService`
- `AdaptiveDecisionService` albo głębsza wersja istniejącej adaptacji
- `AlertClassificationService`

### Minimalne kontrakty logiczne
System musi umieć odpowiedzieć backendowo:
- jaki jest aktualny blok,
- jaki był cel poprzednich 2 tygodni,
- który bodziec jest chroniony,
- czy korekta dotyczy objętości, intensywności, gęstości, czy struktury,
- czy alert jest informacyjny, ostrzegawczy czy blokujący.

---

## 6. Czego nie ruszać w tym pakiecie

Poza zakresem M3/M4 beyond teraz:
- AI escalation layer,
- UI/dashboard polish,
- panel admina,
- Strava/Garmin,
- final cutover/ops,
- wielosport,
- predykcje ML,
- rozbudowany chat coacha.

Kluczowe: **nie mieszać teraz M3/M4 beyond z M5, M6, M7, M8.**

---

## 7. Pakiet po tym: M2 deeper data

Po domknięciu pakietu M3/M4 beyond kolejny logiczny ruch to dopiero:

### 7.1 Zakres must-have
- parser/import **FIT**,
- parser/import **GPX**,
- porządniejsze cleaning rules dla GPS/HR,
- moving time,
- elevation gain/loss,
- cadence,
- power jeśli plik zawiera,
- pace-zones per user liczone z realnych danych użytkownika, nie z tabel.

### 7.2 Dlaczego to jest drugi krok, nie pierwszy
Bo te dane są wartościowe dopiero wtedy, gdy planowanie i adaptacja umieją je konsumować.
Inaczej powstanie „bogatsza analiza sesji”, ale nadal nie przełoży się ona wystarczająco mocno na decyzje coachingowe.

### 7.3 Najważniejsze ryzyko
Największym błędem byłoby wrzucić cadence/power/elevation do systemu bez zdefiniowania:
- które z tych pól wpływają na scoring,
- które wpływają na alerty,
- które wpływają na klasyfikację wykonania,
- które są tylko metadanymi raportowymi.

---

## 8. Proponowana kolejność wdrożenia

### Etap 1
**M3/M4 beyond — analiza i projekt pakietu**

Output:
- current M3/M4 state,
- bottlenecks,
- jeden spójny pakiet wdrożeniowy,
- lista plików do zmiany,
- ryzyka regresji,
- out-of-scope.

### Etap 2
**M3/M4 beyond — wdrożenie**

### Etap 3
**M3/M4 beyond — audit wdrożenia**

Dopiero po tym:

### Etap 4
**M2 deeper data — analiza i projekt pakietu**

### Etap 5
**M2 deeper data — wdrożenie**

### Etap 6
**M2 deeper data — audit wdrożenia**

---

## 9. Definition of Done dla M3/M4 beyond

Pakiet uznajemy za sensownie domknięty dopiero gdy:
- plan tygodnia zna swój blok i rolę w bloku,
- adaptacja korzysta z pamięci min. kilku poprzednich tygodni,
- korekta potrafi zmieniać strukturę bodźca, nie tylko objętość,
- alerty są klasyfikowane rodziną, severity i confidence,
- istnieje ślad decyzyjny, dlaczego plan został zmieniony,
- testy pokrywają regresję kontraktów i logiki adaptacyjnej.

---

## 10. Definition of Done dla M2 deeper data

Pakiet uznajemy za sensownie domknięty dopiero gdy:
- FIT i GPX są importowane bez psucia obecnego TCX,
- cleaning artefaktów jest deterministyczny i testowalny,
- moving time jest rozdzielone od elapsed time,
- elevation/cadence/power są zapisywane typowanie,
- pace-zones per user wynikają z danych użytkownika,
- nowe pola realnie wpływają na analizę lub scoring, a nie tylko „są w bazie”.

---

## 11. Finalny werdykt

Najbardziej sensowny następny krok to:

# **M3/M4 beyond current scope**

Nie M2 deeper data jako pierwsze.

Powód jest prosty:
na obecnym etapie największy wzrost jakości coacha da **lepsza logika decyzji wielotygodniowej i adaptacyjnej**, a nie samo dołożenie kolejnych sygnałów wejściowych.

M2 deeper data ma wejść **zaraz po tym**, jako pakiet zwiększający precyzję analizy i zasilający już dojrzalszy silnik planowania/adaptacji.

---

## 12. Następny roboczy krok

Przygotować osobny dokument:

**`m3_m4_beyond_current_scope.plan.md`**

zawierający:
- current state,
- biggest bottlenecks,
- recommended package,
- must-have / later,
- minimal implementation plan,
- files to change,
- regression risks.

Dopiero po jego zatwierdzeniu wchodzić we wdrożenie.
