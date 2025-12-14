-- RedefineTables
PRAGMA defer_foreign_keys=ON;
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_Workout" (
    "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    "userId" INTEGER NOT NULL,
    "action" TEXT NOT NULL,
    "kind" TEXT NOT NULL,
    "summary" TEXT NOT NULL,
    "raceMeta" TEXT,
    "workoutMeta" TEXT,
    "tcxRaw" TEXT,
    "source" TEXT NOT NULL DEFAULT 'MANUAL_UPLOAD',
    "sourceActivityId" TEXT,
    "sourceUserId" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Workout_userId_fkey" FOREIGN KEY ("userId") REFERENCES "User" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);
INSERT INTO "new_Workout" ("action", "createdAt", "id", "kind", "raceMeta", "summary", "tcxRaw", "updatedAt", "userId", "workoutMeta") SELECT "action", "createdAt", "id", "kind", "raceMeta", "summary", "tcxRaw", "updatedAt", "userId", "workoutMeta" FROM "Workout";
DROP TABLE "Workout";
ALTER TABLE "new_Workout" RENAME TO "Workout";
CREATE INDEX "Workout_userId_idx" ON "Workout"("userId");
CREATE INDEX "Workout_userId_createdAt_idx" ON "Workout"("userId", "createdAt");
CREATE INDEX "Workout_userId_source_sourceActivityId_idx" ON "Workout"("userId", "source", "sourceActivityId");
PRAGMA foreign_keys=ON;
PRAGMA defer_foreign_keys=OFF;
