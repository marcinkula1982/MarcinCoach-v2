# Backend (NestJS + Prisma + SQLite)

## Wymagania
- Node.js (ta sama wersja co frontend)
- npm

## Konfiguracja
```bash
cd backend
npm install
```

Plik `.env` jest już ustawiony na:
```
DATABASE_URL="file:./dev.db"
```

## Prisma
- Generowanie klienta:
```bash
npm run prisma:generate
```
- Migracje (tworzy `dev.db`):
```bash
npm run prisma:migrate -- --name init
```

## Uruchomienie API
Tryb deweloperski:
```bash
npm run start:dev
```
Build + start:
```bash
npm run build
npm start
```

API nasłuchuje na porcie 3000. CORS włączony dla `http://localhost:5173`.

## Endpointy
- `POST /workouts` – body: SaveWorkoutPayload + `tcxRaw`; użytkownik z nagłówka `x-user-id` (lub `userId` w body). Tworzy użytkownika, zapisuje workout + raw TCX.
- `GET /workouts` – zwraca listę workoutów użytkownika z `x-user-id`.

## Struktura
- `src/main.ts` – bootstrap Nest + CORS.
- `src/app.module.ts` – moduł główny.
- `src/prisma.service.ts` – klient Prisma w DI.
- `src/types/workout.types.ts` – typy payloadów.
- `src/workouts/*` – moduł, serwis, kontroler.
- `prisma/schema.prisma` – modele User, Workout, WorkoutRawTcx (SQLite).



