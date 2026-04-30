# MarcinCoach v2 - roadmap

Status dokumentu: aktywny plan dalszych prac.

Po zrealizowaniu funkcjonalnosci nie dopisuj jej tutaj jako historii. Przenies jej status do `docs/status.md`, do sekcji `Dziennik zrealizowanych funkcjonalnosci`, a w tym pliku zostaw tylko kolejne prace do wykonania.

Operacyjna kolejnosc wykonywania taskow jest w `docs/execution-plan.md`. Ten plik trzyma kierunek produktu i obszary prac, a nie codzienna liste TODO.

## Kierunek MVP

MVP to webowy coach/planner, nie tracker GPS.

Glowna petla:
1. plan rolling 14 dni,
2. trening,
3. deterministyczny feedback bez AI,
4. korekta kolejnych dni.

Nie robimy w MVP:
- platnosci,
- social feedu,
- LiveTrack,
- lokalnej bazy biegow,
- smogu/pogody,
- HRV/snu/readiness.

## Najblizsza kolejnosc

1. Feedback po treningu w UX:
   - po zapisanym/imporcie treningu pokazac `POST /api/workouts/{id}/feedback/generate`,
   - dodac widok ponownego odczytu `GET /api/workouts/{id}/feedback`,
   - pokazac sekcje: praise, deviations, conclusions, planImpact,
   - nie udawac AI: komunikaty sa deterministyczne i oparte o fakty/training compliance.

2. Cross-training hardening:
   - dodac UI korekty klasyfikacji aktywnosci zaimportowanych jako `other`,
   - dopracowac planowane aktywnosci wielodniowe i szybkie powtarzanie tygodniowe,
   - dodac fixture providerowe dla strength/bike/swim,
   - zachowac `GET /api/weekly-plan` jako kompatybilny fallback.

3. Deeper data hardening:
   - dodac test na realnym pliku `.fit`,
   - zdecydowac, czy raw FIT/GPX zapisywac w osobnej tabeli,
   - dopracowac cleaning rules dla cadence, power, elevation,
   - wykorzystac `profile.paceZones` w planie i feedbacku.

4. Nawigacja tabelaryczna i onboarding jako zakladka:
   - wprowadzic podzial frontendu na zakładki (np. Plan / Historia / Profil / Ustawienia),
   - po zalozeniu konta new user powinien od razu trafic do first-run onboardingu jako naturalnego kolejnego kroku,
   - po kliknieciu Pomin w onboardingu nawigowac do konkretnej zakladki (obecnie przechodzi do glownego dashboardu),
   - jezeli user pominie albo przerwie onboarding, pokazac pozniej wyrazne CTA "Dokoncz onboarding" / "Uzupelnij dane" na Dashboardzie i w zakladce Profil,
   - onboarding dostepny rowniez z poziomu zakładki Profil dla zalogowanego usera, ktory chce uzupelnic dane,
   - nie blokowac dostepu do reszty aplikacji gdy onboarding nieukończony,
   - na ekranie wyboru zrodla danych dodac akcje typu "Brakuje Twojej aplikacji? Powiadom nas",
   - dodac krotki formularz zgloszenia brakujacej integracji/API: nazwa aplikacji lub urzadzenia, link do API/strony integracji, typ danych (treningi/sen/HRV/readiness), opcjonalny kontakt do usera,
   - formularz ma byc sygnalem produktowym/backlogowym i nie moze blokowac onboardingu; fallbackiem dla brakujacej aplikacji pozostaje import plikow TCX/GPX/FIT.

5. Races w profilu:
   - upewnic sie, ze frontend pozwala dodac start recznie: nazwa, data, dystans, priorytet A/B/C, cel czasowy,
   - pokazac `primaryRace` i wplyw na taper/peak/base,
   - race import nie blokuje MVP, bo fallbackiem zostaje reczne wpisanie.

5. Garmin Event Dashboard spike:
   - sprawdzic `https://connect.garmin.com/app/event-dashboard`,
   - sprawdzic odczyt "Moje wydarzenia",
   - sprawdzic wyszukiwanie eventow po nazwie/lokalizacji/dacie,
   - sprawdzic import wybranego eventu jako race A/B/C,
   - zakonczyc spike jasnym statusem: stabilne / kruche / niedostepne.

## Later

- Smog/pogoda z lokalna precyzja lepsza niz samo miasto.
- ZmierzymyCzas jako docelowe partnerstwo lub selektywne zrodlo eventow.
- HRV/sen/readiness z urzadzen.
- Platnosci/BLIK po walidacji MVP.
- Integracje API sportowe: Strava produkcyjnie (credentials, smoke, webhook), potem Polar AccessLink i oficjalne Suunto API Zone; do czasu partner access Suunto ma tylko kontrolowany Sports Tracker test bridge dla zamknietych testow. Coros jako partner/API gdy bedzie dostep, z fallbackiem FIT/TCX/GPX do tego czasu.
- Produkcyjny AI provider hardening: limity, cache, observability.

## Aktywne referencje

- `docs/status.md` - wykonane funkcjonalnosci, technologie, walidacje.
- `docs/execution-plan.md` - operacyjna kolejnosc prac i taski `NOW/NEXT/LATER/DONE`.
- `docs/integrations.md` - integracje Garmin/Strava/Polar/Suunto/Coros.
- `docs/deploy/frontend-iqhost-deploy.txt` - deploy frontu.
- `CLAUDE.md` / `AGENTS.md` - zasady pracy AI i deployu.
