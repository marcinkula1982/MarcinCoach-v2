# MarcinCoach v2 - integracje sportowe

Status dokumentu: aktywne zrodlo prawdy dla integracji danych treningowych.

Ten plik jest jedynym miejscem w `docs/`, gdzie aktualizujemy decyzje i status integracji sportowych. Starsze dokumenty integracyjne zostaly przeniesione do `docs/archive/`.

## Zasada produktu

MarcinCoach buduje profil uzytkownika najpierw z realnych danych treningowych, a ankieta uzupelnia tylko informacje, ktorych backend nie moze wiarygodnie odczytac z aktywnosci.

Onboarding data-first:
- Strava,
- Garmin,
- pliki TCX/FIT/GPX,
- sciezka reczna, gdy uzytkownik nie ma danych,
- Polar i oficjalne Suunto API Zone jako kolejne zrodla po stabilizacji Strava/Garmin,
- Suunto Sports Tracker jako tymczasowy importer testowy przed partner access,
- Coros jako fallback plikowy teraz i przyszla integracja tylko przez oficjalny partner/API access.

## Aktualny status

| Zrodlo | Status | Integracja | Uwagi |
|---|---|---|---|
| Pliki TCX/GPX/FIT | Wdrozony fallback backendowy, wymaga hardeningu FIT | `POST /api/workouts/upload`; parsery TCX, GPX i FIT w Laravel | TCX i GPX sa pokryte testami. FIT ma parser MVP, ale wymaga testu na realnym pliku `.fit`. Raw TCX jest zapisywany, raw GPX/FIT na razie tylko parsowany do `workouts.summary`. |
| Strava | Backend gotowy lokalnie, produkcyjny smoke zablokowany credentials | OAuth2, token exchange, callback bez sesyjnych headerow, refresh token, sync aktywnosci | Oficjalne API. 2026-04-30: produkcyjne `.env` na IQHost nie ma jeszcze `STRAVA_CLIENT_ID`, `STRAVA_CLIENT_SECRET`, `STRAVA_REDIRECT_URI`, `STRAVA_SCOPES`, wiec live smoke czeka na konfiguracje aplikacji Strava. |
| Garmin | Wdrozony jako MVP wysokiego ryzyka | zewnetrzny connector `GARMIN_CONNECTOR_BASE_URL`, `python-garminconnect`, tryb `stub/live` | Nieoficjalna sciezka przez Garmin Connect. Sync aktywnosci i wysylka zaplanowanych workoutow do kalendarza Garmina sa obslugiwane przez connector; TCX upload zostaje fallbackiem. |
| Garmin Event Dashboard | Spike / research | `https://connect.garmin.com/app/event-dashboard` | Do sprawdzenia: "Moje wydarzenia", wyszukiwanie eventow po nazwie/lokalizacji/dacie, import eventu jako race A/B/C. Nie blokuje MVP, bo fallbackiem jest reczne wpisanie startu w profilu. |
| Polar | Planowane | Polar AccessLink API | Oficjalna integracja po stabilizacji Strava/Garmin. |
| Suunto | Tymczasowy importer testowy wdrozony, oficjalne API planowane | `POST /api/integrations/suunto/sports-tracker/sync` przez Sports Tracker browser session token; docelowo Suunto API Zone / partner program | Most testowy jest nieoficjalny, wymaga recznie przekazanego tokena sesji z przegladarki, nie zapisuje tokena w bazie i jest domyslnie wylaczony przez `SUUNTO_SPORTS_TRACKER_ENABLED=false`. |
| Coros | Planowane jako przyszla integracja, teraz fallback plikowy | FIT/TCX/GPX export z Coros; przyszly partner/API access | Nie reverse-engineerowac. User Coros ma sciezke przez upload i formularz "Powiadom nas". |
| Apple Watch / Health | Poza backendowym MVP | HealthKit tylko przez aplikacje iOS/watchOS lub eksport plikow | Brak prostego backendowego logowania do Apple Health. |

## Garmin - status live

2026-04-26 potwierdzono end-to-end live smoke wysylki zaplanowanego treningu do Garmin Connect:

- endpoint connectora: `POST /v1/garmin/workouts`,
- endpoint Laravel: `POST /api/integrations/garmin/workouts/send`,
- testowy workout: `MarcinCoach TEST 2026-04-26`,
- Garmin `workoutId`: `1548384239`,
- status: `scheduled`,
- data w kalendarzu Garmin: `2026-04-26`,
- potwierdzenie uzytkownika: trening widoczny/dziala na koncie Garmin.

To potwierdza, ze `python-garminconnect` w obecnej integracji obsluguje nie tylko sync aktywnosci do MarcinCoach, ale tez upload zaplanowanego treningu do Garmin Connect i przypisanie go do kalendarza. Nadal jest to nieoficjalna sciezka Garmin Connect, wiec utrzymujemy jawne ryzyka auth/MFA/rate limit/regulaminu.

## Strava - produkcyjny setup

Stan 2026-04-30:
- backendowy flow OAuth jest przygotowany pod prawdziwy redirect ze Stravy: `state` jest jednorazowym ticketem powiazanym z userem, a callback nie wymaga headerow sesji z frontendu,
- po sukcesie callback wraca na `FRONTEND_URL` z `?integration=strava&status=connected`,
- sync pobiera domyslnie ostatnie 30 dni, a frontend wysyla `fromIso` przy kliknieciu synchronizacji,
- testy lokalne pokrywaja connect/callback/sync, browser redirect bez headerow sesji i refresh wygaslego access tokena,
- live smoke na produkcji nie zostal wykonany, bo credentials Stravy nie sa ustawione na IQHost.

Wymagana konfiguracja aplikacji Strava:
- w Strava API Settings ustawic `Authorization Callback Domain`: `api.coach.host89998.iqhs.pl`,
- w produkcyjnym `.env` Laravel ustawic:
  - `FRONTEND_URL=https://coach.host89998.iqhs.pl`,
  - `STRAVA_CLIENT_ID=<client id ze Stravy>`,
  - `STRAVA_CLIENT_SECRET=<client secret ze Stravy>`,
  - `STRAVA_REDIRECT_URI=https://api.coach.host89998.iqhs.pl/api/integrations/strava/callback`,
  - `STRAVA_SCOPES=activity:read_all,profile:read_all`.

Uwaga do logowania: jesli Strava prosi o kod przy logowaniu, to jest kod logowania do konta Strava. Po tym kroku OAuth nadal zwraca standardowy `code` w callbacku, ktory backend wymienia na tokeny.

## Suunto - tymczasowy most testowy

Decyzja 2026-04-30: do czasu posiadania produkcyjnej wersji MarcinCoach i aplikacji partnerskiej do Suunto uzywamy kontrolowanego, nieoficjalnego mostu przez Sports Tracker/Suunto App.

Zakres wdrozony:
- backendowy endpoint `POST /api/integrations/suunto/sports-tracker/sync`,
- body: `sessionToken`, opcjonalnie `format=fit|gpx` (domyslnie `gpx`), `limit`, `fromIso`, `toIso`,
- domyslny base URL: `https://api.sports-tracker.com/apiserver/v1`,
- lista aktywnosci: `/workouts?limited=true&limit=...&token=...`,
- eksport aktywnosci: `/workout/exportFit/{workoutKey}` albo `/workout/exportGpx/{workoutKey}`,
- import do `workouts` z `source=SUUNTO`, deduplikacja po `source_activity_id`,
- log w `integration_sync_runs.provider=suunto_sports_tracker`,
- wpis w `integration_accounts.provider=suunto_sports_tracker` bez zapisu access/refresh tokena.

Zasady bezpieczenstwa:
- endpoint jest domyslnie wylaczony (`SUUNTO_SPORTS_TRACKER_ENABLED=false`),
- token sesji Sports Tracker jest transient: przychodzi w request body i nie jest zapisywany w `integration_accounts`, `integration_sync_runs.meta` ani w workoutach,
- importer jest do controlled beta/testow, nie jako oficjalna publiczna integracja,
- po uzyskaniu partner access docelowa integracja pozostaje `Suunto API Zone`.

Referencje techniczne:
- oficjalne Sports Tracker help potwierdza reczny export GPX po zalogowaniu na sports-tracker.com,
- nieoficjalne skrypty/gisty uzywaja endpointow `api.sports-tracker.com/apiserver/v1/workouts`, `exportFit` i `exportGpx` z tokenem sesji przegladarki,
- Suunto API Zone potwierdza oficjalna sciezke partnerska/OAuth i FIT jako docelowy format danych.

## Minimalna probka danych

Dla pierwszego sensownego profilu:
- minimum: 6 treningow,
- lepiej: 10+ treningow,
- optymalnie: ostatnie 60-90 dni z limitem liczby aktywnosci.

Jesli danych jest za malo, backend nie udaje pewnosci. Profil i plan powinny dostac nizszy confidence i ostrozniejsze zalozenia.

## Dane, ktore backend powinien wyciagac

- czestotliwosc treningow,
- sredni kilometraz,
- najdluzszy trening,
- tempa easy i szybkich treningow,
- HR avg / max,
- drift HR,
- rozklad intensywnosci,
- tolerancje obciazenia,
- wstepne strefy robocze.

## Sync log

Kazda integracja zewnetrzna powinna zostawiac slady w `integration_sync_runs`:
- zrodlo danych,
- status synchronizacji,
- liczba pobranych aktywnosci,
- zakres dat,
- bledy autoryzacji,
- bledy parsowania,
- data ostatniej synchronizacji.

Dla Garmina od poczatku traktujemy ryzyko jako jawne: `unofficial_connector`.
Dla Suunto do czasu partner access traktujemy most Sports Tracker jako jawne ryzyko: `unofficial_sports_tracker`, domyslnie wylaczone i bez trwalego zapisu tokena.

## Linki referencyjne

- Strava API: https://developers.strava.com/docs/
- Garmin Activity API: https://developer.garmin.com/gc-developer-program/activity-api/
- python-garminconnect: https://github.com/cyberjunky/python-garminconnect
- Polar AccessLink: https://www.polar.com/accesslink-api/
- Suunto API Zone: https://apizone.suunto.com/
- Suunto / Sports Tracker FIT export bookmarklet: https://gist.github.com/marguslt/b0ee7e88960b2d03de2da62a44233893
- Sports Tracker GPX export help: https://sports-tracker.helpshift.com/hc/en/3-sports-tracker/faq/30-how-to-export-import-workouts/
- Apple HealthKit: https://developer.apple.com/documentation/healthkit
