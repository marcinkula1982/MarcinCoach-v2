# MarcinCoach — status po domknięciu P1–P5

## Cel tego pliku
Krótki status projektu po domknięciu pierwszego pakietu blockerów technicznych i operacyjnych dla cutoveru Node → PHP.

## Status ogólny
- **P1–P5: zamknięte**
- **Production switched: nie**
- **Cutover ready całościowo: jeszcze nie**
- **Wariant migracji: cutover od zera**
  - bez migracji danych z Node
  - bez migracji sesji z Node
  - rejestracja / logowanie od nowa po stronie PHP
  - ponowny onboarding profilu użytkownika po przełączeniu

---

## P1 — decyzja migracyjna
**Status: ZAMKNIĘTE**

### Ustalona decyzja
- przejście na **PHP-only**
- brak migracji danych historycznych z Node
- brak mostu sesji Node ↔ PHP
- po cutoverze użytkownicy zakładają konto / logują się ponownie w PHP

### Efekt
- upraszcza cutover
- eliminuje potrzebę migracji `User/AuthUser/Session`
- eliminuje potrzebę migracji historycznych treningów na tym etapie

---

## P2 — summary shape drift
**Status: ZAMKNIĘTE**

### Co naprawiono
- dodano wspólny builder summary dla workoutów w PHP
- ujednolicono zapis `summary` dla:
  - `/workouts/import`
  - `/workouts/upload`
  - external sync

### Co potwierdzono
- nowy rekord z `POST /workouts/import` trafia do analytics
- nowy rekord z `POST /workouts/upload` trafia do analytics
- `GET /workouts/{id}/signals` działa
- `POST /training-feedback-v2/{id}/generate` działa

### Efekt
- nowe workouty zapisane w PHP są poprawnie widoczne dla analytics i dalszej logiki

---

## P3 — source + dedupeKey
**Status: ZAMKNIĘTE**

### Co naprawiono
- wprowadzono wspólny kontrakt source/dedupe w PHP
- storage został ujednolicony do kanonicznych wartości:
  - `MANUAL_UPLOAD`
  - `GARMIN`
  - `STRAVA`
- `manual` i `tcx` mapują się do `MANUAL_UPLOAD`
- dedupe key w PHP został dostosowany do kontraktu zgodnego z Node dla nowych zapisów

### Co potwierdzono
- dwa identyczne importy Garmin z tym samym `sourceActivityId` nie tworzą duplikatu
- upload TCX zapisuje rekord jako `MANUAL_UPLOAD`, a nie `tcx`

### Efekt
- nowe workouty w PHP nie rozjeżdżają się już kontraktowo z logiką identyfikacji aktywności

---

## P4 — auth/session
**Status: ZAMKNIĘTE FUNKCJONALNIE**

### Co naprawiono
- usunięto bypass auth po samym `x-username`
- chronione endpointy wymagają prawidłowego:
  - `x-username`
  - `x-session-token`
- dodano `POST /auth/logout`
- dodano revoke tokena
- dodano testy negatywne auth/session
- uzupełniono checklistę cutoveru o komunikację dla użytkownika i smoke auth/session

### Co potwierdzono ręcznie
- poprawny token + username → `200`
- brak tokena → `401`
- zły token → `401`
- logout → `ok: true`
- stary token po logout → `401`

### Uwaga
- testy automatyczne uruchamiały się z warningami środowiskowymi związanymi z `.env`
- manual smoke auth/session został zaliczony

---

## P5 — operacyjny cutover pack
**Status: ZAMKNIĘTE DOKUMENTACYJNIE**

### Co przygotowano
- rollback plan
- ownership decyzji na dzień cutoveru
- smoke checklistę po przełączeniu
- monitoring pierwszych 24 godzin
- plan staged decommission dla Node

### Założenia
- dokumenty są infra-agnostic
- bez komend zależnych od konkretnego stacka deployowego, jeśli repo ich nie definiuje
- bez zmian w kodzie aplikacji
- bez zmian w logice M1–M4

### Efekt
- istnieje minimalny pakiet operacyjny potrzebny do wejścia w finalne przygotowanie przełączenia ruchu

---

## Co jest domknięte po P1–P5
Po stronie pierwszego pakietu blockerów zostały domknięte:
- decyzja migracyjna
- widoczność nowych workoutów w analytics
- spójność source i dedupe dla nowych zapisów
- minimalnie bezpieczny model auth/session po cutoverze od zera
- podstawowy pakiet operacyjny do przełączenia ruchu

---

## Co nadal NIE jest domknięte

### 1. M1 — onboarding / profil użytkownika
Nadal brak pełnego profilu wejściowego zgodnego ze spec:
- cele
- starty
- dostępność
- zdrowie
- sprzęt
- scoring jakości danych
- poziom pewności profilu

### 2. M2 — pełniejsza analiza treningu w PHP
P2 naprawiło widoczność danych, ale nie dało pełnej parity z Node:
- brak pełnego pipeline trackpointów
- brak pełnego czyszczenia artefaktów
- brak pełnych intensity buckets parity
- import FIT / GPX nadal poza zakresem

### 3. M3 — plan backendowy w PHP jest uproszczony
Weekly plan działa, ale nadal ma uproszczoną logikę względem Node:
- mniej adjustment codes
- mniej hints / ograniczeń
- brak pełnego safety-aware planowania

### 4. M4 / 4.5 — safety engine minimum
Nadal brak pełnego modułu bezpieczeństwa:
- limity wzrostu objętości
- gęstość akcentów
- reguły powrotu po chorobie / starcie
- sensowne fatigue / injury risk

### 5. TrainingSignals contract freeze
Nadal trzeba zamrozić kontrakt odpowiedzi PHP, jeśli ma być źródłem prawdy po cutoverze.

### 6. Finalna decyzja wykonawcza o przełączeniu
P5 przygotowało dokumenty, ale nadal trzeba:
- wykonać właściwy cutover
- przejść checklistę
- otworzyć i zamknąć rollback window
- formalnie wyłączyć ruch do Node

---

## Czego ten plik NIE oznacza
Ten plik **nie oznacza**, że MarcinCoach jest już gotowy do pełnego production switch.

Oznacza tylko, że pierwszy pakiet blockerów **P1–P5** został domknięty.

---

## Najbliższy sensowny krok
Następny krok to wskazanie, które z pozostałych tematów są:
- **must-have przed cutoverem**
- **możliwe do zrobienia po cutoverze**

Dopiero potem wybór kolejnego pakietu prac.
