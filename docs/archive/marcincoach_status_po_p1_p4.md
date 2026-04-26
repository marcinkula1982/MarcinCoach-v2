# MarcinCoach — status po domknięciu P1–P4

## Cel tego pliku
Krótki status projektu po domknięciu pierwszego pakietu blockerów cutoveru Node → PHP.

## Status ogólny
- **P1–P4: zamknięte**
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

### Konsekwencja
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

### Co zostało potwierdzone
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

### Co zostało potwierdzone
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

### Co zostało potwierdzone ręcznie
- poprawny token + username → `200`
- brak tokena → `401`
- zły token → `401`
- logout → `ok: true`
- stary token po logout → `401`

### Uwaga
- testy automatyczne uruchamiały się z warningami środowiskowymi związanymi z `.env`
- manual smoke auth/session został zaliczony

---

## Co jest domknięte po P1–P4
Po stronie krytycznych blockerów pierwszego pakietu zostały domknięte:
- decyzja migracyjna
- widoczność nowych workoutów w analytics
- spójność source i dedupe dla nowych zapisów
- minimalnie bezpieczny model auth/session po cutoverze od zera

---

## Czego ten plik NIE oznacza
Ten plik **nie oznacza**, że cały projekt jest już gotowy do production cutover.

Oznacza tylko, że pierwszy pakiet blockerów **P1–P4** został domknięty.

---

## Następny etap
Następny krok to osobne rozpisanie:
- co jeszcze blokuje pełny cutover po zamknięciu P1–P4
- w jakiej kolejności trzeba to domknąć
- co jest operacyjne, a co stricte backendowe
