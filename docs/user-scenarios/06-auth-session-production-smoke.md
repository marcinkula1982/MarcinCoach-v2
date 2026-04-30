# 06 — Auth, sesja i smoke produkcyjny

Plik obejmuje: sesja użytkownika, wygasły token, invalid session, logout, ponowny login, retry po loginie, smoke produkcyjny po deploy.

Realny stan 28.04.2026:
- Custom session token: `x-session-token` + `x-username`, token w cache.
- TTL: 30 dni.
- Brak idle timeout, brak sliding refresh, brak refresh tokena.
- Frontend: część 401/403 czyści sesję i dispatchuje logout, część (autoload endpointy) tylko łapie błąd.
- Brak modala "session expired".
- Sesja wygasła w trakcie uploadu: plik nie zapisuje się, brak retry po loginie.

---

## US-AUTH-001 — Login z poprawnymi danymi

**Typ:** happy path
**Persona:** każda
**Status:** implemented
**Priorytet:** P0

Patrz US-ONBOARD-002.

---

## US-AUTH-002 — Login z niepoprawnymi danymi

**Typ:** error
**Persona:** każda
**Status:** implemented
**Priorytet:** P0

### Stan wejściowy
User wpisuje błędny email lub hasło.

### Kroki użytkownika
1. Wpisuje email, błędne hasło.
2. Klika "Zaloguj".

### Oczekiwane zachowanie
- Backend zwraca 401.
- UI pokazuje komunikat **identyczny** dla "zły email" i "złe hasło" (security best practice).
- "Niepoprawne dane logowania. Sprawdź email i hasło."

### Kryteria akceptacji (P0)
- Brak ujawnienia czy email istnieje w systemie.
- Brak crashu UI.
- User może powtórzyć próbę (brak natychmiastowego lockoutu).

### Testy / smoke
- Test backend: 401 dla błędnego hasła.
- Test backend: 401 dla nieistniejącego email.
- Test e2e: błędny login → komunikat → ponowna próba.

### Uwagi produktowe
Brute force protection: po N nieudanych próbach (np. 5) blokada na 15 min — to **TODO P1**.

---

## US-AUTH-003 — Logout

**Typ:** happy path
**Persona:** każda
**Status:** implemented
**Priorytet:** P0

### Stan wejściowy
User zalogowany.

### Kroki użytkownika
1. Klika "Wyloguj" w menu/header.

### Oczekiwane zachowanie
- Backend revokuje token (`POST /api/auth/logout`).
- Frontend czyści sesję lokalną.
- Redirect do strony loginu.

### Oczekiwane API
- `POST /api/auth/logout` zwraca `{ ok: true }`.

### Kryteria akceptacji (P0)
- Token revoked — kolejny request z tym tokenem zwraca 401.
- UI nie wisi w międzystanie.
- User może natychmiast się zalogować ponownie.

### Testy / smoke
- Test backend: logout → token usunięty → kolejny request 401.
- Test e2e: logout → login → dashboard.

---

## US-AUTH-004 — Sesja wygasa po 30 dniach

**Typ:** edge case
**Persona:** każda (kto się nie zalogował 30+ dni)
**Status:** implemented (TTL działa)
**Priorytet:** P0

### Stan wejściowy
User logował się 31 dni temu, nie wszedł od tego czasu, teraz wraca.

### Kroki użytkownika
1. Otwiera aplikację.
2. Frontend próbuje pobrać `/api/me` z zapamiętanym tokenem.
3. Backend zwraca 401 (token expired).
4. Frontend czyści sesję, redirect do login.

### Oczekiwane zachowanie
- User ląduje na ekranie loginu.
- Komunikat (opcjonalny): "Twoja sesja wygasła. Zaloguj się ponownie."
- Po loginie: dashboard z aktualnymi danymi.

### Kryteria akceptacji (P0)
- Brak wyciekających błędów do UI.
- User wraca do swojego konta po loginie (dane nieutracone).
- Frontend nie zapętla się próbując odświeżyć.

### Testy / smoke
- Test backend: token TTL → 401 po wygaśnięciu.
- Manual smoke: `localStorage.setItem('token', 'expired_token')` → reload → redirect login.

### Uwagi produktowe
30 dni to długi TTL. Brak idle timeout to świadoma decyzja (zgodnie z notatkami: idle timeout jest stary plan Node).

---

## US-AUTH-005 — Ręczne usunięcie tokena (devtools / inny tab)

**Typ:** edge case
**Persona:** każda (rzadkie, debug)
**Status:** partial (global handler FE gotowy; brak manual smoke/cancel inflight)
**Priorytet:** P1

### Stan wejściowy
User w DevTools usunął localStorage lub w innej karcie zrobił logout.

### Oczekiwane zachowanie
- Następny request z usuniętym tokenem zwraca 401.
- Frontend czyści sesję, redirect do login.

### Kryteria akceptacji (P1)
- UI nie crashuje.
- Po loginie wraca do dashboardu.

### Testy / smoke
- Manual smoke: wyczyść localStorage → kliknij coś → redirect.

---

## US-AUTH-006 — 401 podczas autoload endpointów (silent fail)

**Typ:** error / regression
**Persona:** każda po wygaśnięciu sesji
**Status:** partial
**Priorytet:** P1

### Stan wejściowy
User wraca po długiej przerwie, frontend ładuje wiele endpointów równocześnie (`/api/me`, `/api/me/profile`, `/api/workouts`, `/api/rolling-plan`).

### Aktualne zachowanie (29.04.2026)
- Po EP-004 frontendowy axios interceptor obsługuje 401/403 globalnie, bez wyjątków dla autoload endpointów.
- Interceptor czyści token, emituje event logout i pokazuje komunikat "Sesja wygasla. Zaloguj sie ponownie." przy formularzu logowania.
- Brakuje jeszcze manual smoke 401 oraz anulowania inflight requestów.

### Oczekiwane zachowanie po naprawie
- **Globalny axios/fetch interceptor** dla 401: zawsze czyści sesję, redirect do login.
- Wszystkie inflight requesty są anulowane.
- UI nie pokazuje połowicznego stanu.

### Kryteria akceptacji (P1)
- Pojedynczy 401 z dowolnego endpointu kończy sesję spójnie.
- User nie widzi mieszanki załadowanych i zepsutych komponentów.

### Testy / smoke
- `npm run build` -> OK po EP-004.
- Manual smoke: token expired → otwórz dashboard → tylko ekran login (bez wycieku) — nieuruchomiony.

### Uwagi produktowe
**To jest istotna luka UX.** Bez globalnego interceptora user widzi "dziwne błędy" zamiast jasnego "zaloguj się ponownie".

---

## US-AUTH-007 — Sesja wygasła w trakcie uploadu pliku

**Typ:** error
**Persona:** P-GARMIN, P-MULTI
**Status:** partial / missing retry
**Priorytet:** P1

### Stan wejściowy
User zaczyna upload pliku TCX. W trakcie sesja wygasa.

### Aktualne zachowanie (29.04.2026)
- Backend zwraca 401.
- Plik **nie zapisuje się**.
- Po EP-004 frontend czyści sesję globalnie i pokazuje komunikat o wygaśnięciu sesji przy logowaniu.
- User musi zalogować się ponownie i powtórzyć upload.
- Plik **może** zostać w pamięci komponentu, ale to nie jest gwarantowane/testowane.
- Brak modal "session expired, retry?".

### Oczekiwane zachowanie po wdrożeniu
- UI wykrywa 401.
- Pokazuje modal: "Twoja sesja wygasła. Zaloguj się ponownie żeby dokończyć upload."
- Po loginie automatic retry (jeśli plik jest w pamięci) lub prośba o ponowny wybór pliku.

### Kryteria akceptacji (P1)
- User nie traci pracy bez ostrzeżenia.
- Modal jest informacyjny, nie myli z normalnym logoutem.

### Testy / smoke
- Manual smoke: zaloguj, wymuś expiry tokena, upload pliku → modal.

### Uwagi produktowe
Implementacja: trzymać pending upload w stanie React, po loginie zaproponować retry. Może być scoped tylko do uploadu — innym requestom retry nie jest tak ważne.

---

## US-AUTH-008 — Login na 2. urządzeniu i logout na 1.

**Typ:** edge case
**Persona:** P-MULTI
**Status:** implemented (multi-session) / unknown (logout-everywhere)
**Priorytet:** P1

### Stan wejściowy
User zalogowany na laptopie i telefonie.

### Kroki użytkownika
1. Na telefonie klika "Wyloguj".

### Oczekiwane zachowanie
- Token telefonu revoked.
- Sesja na laptopie **nadal aktywna** (multi-session).

### Kryteria akceptacji (P1)
- Logout per device, nie globalny.
- Opcja "Wyloguj wszędzie" — P2.

### Testy / smoke
- Test backend: 2 tokeny → revoke 1 → drugi nadal valid.

---

## US-AUTH-009 — "Zapomniałem hasła" / reset hasła

**Typ:** happy path
**Persona:** każda
**Status:** partial (API/mail/UI gotowe; brak SMTP smoke i revoke starych sesji)
**Priorytet:** P0

### Stan wejściowy
User nie pamięta hasła.

### Kroki użytkownika
1. Klika "Nie pamiętam hasła" pod formularzem login.
2. Wpisuje email.
3. Submituje.
4. Otrzymuje email z linkiem.
5. Klika link.
6. Ustawia nowe hasło.

### Aktualne API po EP-005
- `POST /api/auth/forgot-password` z `identifier` albo `email`.
- `POST /api/auth/reset-password` z `identifier`, `token`, `password`.
- Token jest hashowany w `password_reset_tokens` i wygasa po 1h.
- Link resetu prowadzi na frontend z `resetToken` i `email` w query string.

### Kryteria akceptacji (P0)
- Email z linkiem dostarczony w ciągu 5 min.
- Link wygasa po 1h.
- Po reset old session tokeny są revoked (security).

### Testy / smoke
- `php artisan test tests\Feature\Api\AuthAndProfileTest.php` -> 28 passed po EP-005.
- `npm run build` -> OK po EP-005.
- Manual smoke SMTP: pełny flow — nieuruchomiony.

### Uwagi produktowe
Wymaga jeszcze konfiguracji i smoke realnego SMTP na środowisku docelowym. Po resecie hasła stare custom session tokeny nie są jeszcze globalnie revokowane, bo `SessionTokenService` nie ma enumeracji tokenów per user.

---

## US-AUTH-010 — Zmiana hasła z poziomu profilu

**Typ:** happy path
**Persona:** każda
**Status:** unknown / missing
**Priorytet:** P1

### Stan wejściowy
User chce zmienić hasło.

### Kroki użytkownika
1. Profil → Bezpieczeństwo → Zmień hasło.
2. Wpisuje stare i nowe (2× nowe).
3. Submit.

### Oczekiwane API
- `PUT /api/auth/password` z `{ oldPassword, newPassword }`.

### Kryteria akceptacji (P1)
- Walidacja starego hasła.
- Nowe hasło min. 8 znaków.
- Po zmianie inne sesje pozostają aktywne (lub revoked — decyzja produktowa).

### Testy / smoke
- Test backend: poprawna zmiana, błędne stare hasło → 401.

---

## US-AUTH-011 — Smoke produkcyjny: register/login/profile

**Typ:** smoke regression
**Persona:** test account
**Status:** partial (local API smoke)
**Priorytet:** P0

### Cel
Po każdym deploy backendu lub frontendu smoke test podstawowych flowów auth.

### Kroki manual
1. Otwórz `https://coach.host89998.iqhs.pl`.
2. Zaloguj się testowym kontem.
3. Sprawdź że dashboard się ładuje.
4. Wyloguj.
5. Zaloguj ponownie.

### Kryteria akceptacji (P0)
- Wszystko < 5s na produkcji.
- Brak błędów w console.
- Wszystkie endpointy zwracają 200.

### Testy / smoke
- **Skrypt automatyczny** (cron / GitHub Action raz dziennie):
  - GET `https://coach.host89998.iqhs.pl/` → 200
  - GET `https://api.coach.host89998.iqhs.pl/api/health` → 200 z timestamp
  - POST `/api/auth/login` z test creds → 200 + token
  - GET `/api/me` z tokenem → 200
  - POST `/api/auth/logout` → 200
- Lokalny smoke API po EP-010: `php artisan test tests\Feature\Api\MvpSmokeTest.php` -> 1 passed, 51 assertions; obejmuje register, login, `/api/me` i profil. Produkcyjny cron nadal nieuruchomiony.

### Uwagi produktowe
**Bez tego smoke nikt nie wie że produkcja działa**, dopóki user nie zgłosi błędu. To powinno być w GitHub Actions lub uptime monitor.

---

## US-AUTH-012 — Smoke produkcyjny: pełny flow MVP

**Typ:** smoke regression
**Persona:** test account
**Status:** partial (local API smoke)
**Priorytet:** P0

### Cel
End-to-end smoke przez kluczowe ścieżki MVP.

### Kroki manual (po deploy)
1. Login.
2. Sprawdź dashboard: weekly plan, analytics, lista treningów.
3. Upload TCX z fixture albo manual check-in bez pliku.
4. Sprawdź że workout pojawił się.
5. Generate feedback.
6. Refresh plan.
7. Wyloguj.

### Lista smoke endpoints
- GET `/api/health`
- GET `/api/me`
- GET `/api/me/profile`
- GET `/api/workouts?limit=10`
- GET `/api/rolling-plan?days=14`
- GET `/api/training-signals?days=28`
- GET `/api/training-context?days=28`
- GET `/api/training-adjustments?days=28`
- POST `/api/workouts/upload` z fixture albo POST `/api/workouts/manual-check-in`
- POST `/api/workouts/{id}/feedback/generate`
- GET `/api/workouts/{id}/feedback`

### Kryteria akceptacji (P0)
- Każdy endpoint zwraca 200 w < 3s.
- Workout flow działa end-to-end.

### Testy / smoke
- Lokalny API smoke po EP-010: `php artisan test tests\Feature\Api\MvpSmokeTest.php` -> 1 passed, 51 assertions; workflow: health -> register -> login -> `/me` -> profile -> rolling plan -> manual check-in bez pliku -> feedback generate/get -> rolling plan.
- Produkcyjny/browser smoke i cron raz dziennie z alertem na Slack/email/SMS nadal do zrobienia.

### Uwagi produktowe
**Po D0 (post-launch produkcji)** smoke jest jedyną gwarancją że nikt nie zepsuł. Bez automation polegamy na manual checklist po każdym deploy.

---

## US-AUTH-013 — Frontend deploy nie psuje produkcji

**Typ:** smoke regression
**Persona:** każda
**Status:** partial
**Priorytet:** P0

### Stan wejściowy
Deploy frontendu przez `deploy-front.ps1` (lokalny build → SCP do `public_html/`).

### Oczekiwane zachowanie
- Po deployu nowa wersja frontendu jest serwowana.
- Stara wersja nie jest serwowana (cache busting).
- API endpoint URL nadal poprawny (`https://api.coach.host89998.iqhs.pl/api`).

### Kryteria akceptacji (P0)
- Po deploy: `curl https://coach.host89998.iqhs.pl/` zwraca nową wersję (np. nowy `index.html` z nowym hashem JS).
- Stary tab w przeglądarce z poprzednią wersją nie crashuje (graceful degradation lub force reload).

### Testy / smoke
- Po deploy: open incognito → sprawdź że wszystko działa.
- Sprawdź że źródła JS/CSS mają nowe hashe.

### Uwagi produktowe
Nigdy nie buduj frontu na IQHost. Środowisko hooka Git nie ma `npm` (zgodnie z `AGENTS.md` / `CLAUDE.md`).

Zasady deployu są jasno udokumentowane w `AGENTS.md`. Przestrzegać.

---

## US-AUTH-014 — CORS / cross-origin smoke

**Typ:** smoke regression
**Persona:** każda
**Status:** implemented (zakładam)
**Priorytet:** P0

### Stan wejściowy
Frontend `coach.host89998.iqhs.pl` woła API `api.coach.host89998.iqhs.pl`. Cross-origin.

### Oczekiwane zachowanie
- Backend Laravel ma skonfigurowany CORS (`config/cors.php`).
- Allowed origins: `https://coach.host89998.iqhs.pl`.
- Preflight OPTIONS działa.

### Kryteria akceptacji (P0)
- Brak błędów CORS w console przeglądarki.
- POST/PUT/DELETE działają (nie blokowane).

### Testy / smoke
- Manual smoke: open DevTools → Network → sprawdź brak `CORS error`.

### Uwagi produktowe
Zgodnie z `AGENTS.md`: "Czego NIE diagnozować gdy front nie działa: API, CORS — te elementy były sprawdzone i działają". Realny problem zwykle: stary `dist/` w `public_html/`.

---

## US-AUTH-015 — Backend healthcheck

**Typ:** smoke regression
**Persona:** ops
**Status:** implemented
**Priorytet:** P0

### Stan wejściowy
Monitoring sprawdza co X min.

### Endpoint
- `GET /api/health` zwraca:
```json
{
  "status": "ok",
  "timestamp": "2026-04-28T10:00:07+00:00",
  "version": "..."
}
```

### Kryteria akceptacji (P0)
- 200 w < 1s.
- Response zawiera timestamp (potwierdzenie że odpowiedź jest fresh).

### Testy / smoke
- Cron / uptime monitor (UptimeRobot, BetterUptime, własny cron).
- Alert na fail.

### Uwagi produktowe
**Aktualnie potwierdzony 28.04.2026 10:00:07 UTC.** Healthcheck to powinno być pierwsze co alarmuje przy padzie produkcji.
