# TCX Editor

Lekka, frontendowa aplikacja React + Vite do lokalnej obróbki plików TCX. Pozwala wczytać trening w przeglądarce, zobaczyć metryki, przyciąć zakres trackpointów i pobrać oczyszczony plik – bez wysyłania czegokolwiek na serwer.

## Uruchomienie

```bash
npm install
npm run dev
```

Aplikacja startuje domyślnie pod adresem wyświetlanym przez Vite (np. http://localhost:5173).

## Funkcje

- Wczytanie pliku TCX (przetwarzanie wyłącznie lokalnie w przeglądarce).
- Parsowanie trackpointów (czas, dystans, tętno, wysokość) z wykorzystaniem `fast-xml-parser`.
- Wyliczanie metryk: czas trwania, dystans, tempo, średnie i maksymalne HR, liczba punktów.
- Podgląd pierwszych i ostatnich trackpointów.
- Przycinanie zakresu `[startIndex, endIndex]` z podglądem nowych metryk.
- Eksport przyciętego pliku jako `originalFilename_trimmed.tcx`.

## Technologie

- React + TypeScript + Vite
- Tailwind CSS
- fast-xml-parser

## Struktura

- `src/App.tsx` – UI i logika stanu.
- `src/utils/tcxParser.ts` – parsowanie TCX do wewnętrznej struktury.
- `src/utils/metrics.ts` – obliczenia metryk.
- `src/utils/tcxExporter.ts` – generowanie nowego TCX z przyciętymi trackpointami.
- `src/types.ts` – wspólne typy.

## Dodatkowe informacje

- Przetwarzanie plików odbywa się w całości po stronie klienta; żadne dane nie są wysyłane do sieci.
- W razie błędu parsowania aplikacja pokazuje czerwony alert z komunikatem.

## Przydatne skrypty

- `npm run dev` – tryb deweloperski.
- `npm run build` – build produkcyjny.
- `npm run preview` – podgląd buildu.



