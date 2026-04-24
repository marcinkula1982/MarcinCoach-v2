# TCX Editor

Lekka, frontendowa aplikacja React + Vite do lokalnej obrobki plikow TCX. Pozwala wczytac trening w przegladarce, zobaczyc metryki, przyciac zakres trackpointow i pobrac oczyszczony plik - bez wysylania czegokolwiek na serwer.

## Uruchomienie

```bash
npm install
npm run dev
```

Aplikacja startuje domyslnie pod adresem wyswietlanym przez Vite (np. http://localhost:5173).

## Funkcje

- Wczytanie pliku TCX (przetwarzanie wylacznie lokalnie w przegladarce).
- Parsowanie trackpointow (czas, dystans, tetno, wysokosc) z wykorzystaniem `fast-xml-parser`.
- Wyliczanie metryk: czas trwania, dystans, tempo, srednie i maksymalne HR, liczba punktow.
- Podglad pierwszych i ostatnich trackpointow.
- Przycinanie zakresu `[startIndex, endIndex]` z podgladem nowych metryk.
- Eksport przycietego pliku jako `originalFilename_trimmed.tcx`.

## Technologie

- React + TypeScript + Vite
- Tailwind CSS
- fast-xml-parser

## Struktura

- `src/App.tsx` - UI i logika stanu.
- `src/utils/tcxParser.ts` - parsowanie TCX do wewnetrznej struktury.
- `src/utils/metrics.ts` - obliczenia metryk.
- `src/utils/tcxExporter.ts` - generowanie nowego TCX z przycietymi trackpointami.
- `src/types.ts` - wspolne typy.

## Dodatkowe informacje

- Przetwarzanie plikow odbywa sie w calosci po stronie klienta; zadne dane nie sa wysylane do sieci.
- W razie bledu parsowania aplikacja pokazuje czerwony alert z komunikatem.

## Przydatne skrypty

- `npm run dev` - tryb deweloperski.
- `npm run build` - build produkcyjny.
- `npm run preview` - podglad buildu.

## Build produkcyjny a API

Przed `npm run build` ustaw produkcyjny adres backendu w `/.env.production`:

```bash
VITE_API_BASE_URL=https://api.coach.host89998.iqhs.pl/api
```

Bez tego Vite moze zbudowac paczke z lokalnym adresem API (np. `localhost`), jesli taki jest w `/.env`.

Pelny standard deploya subdomeny (hook, diagnostyka, rollback): `docs/deploy/frontend-subdomain.md`.
