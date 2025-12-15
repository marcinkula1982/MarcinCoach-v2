/*
  Warnings:

  - You are about to drop the column `userId` on the `Session` table. All the data in the column will be lost.
  - Added the required column `userId` to the `AuthUser` table without a default value. This is not possible if the table is not empty.
  - Added the required column `authUserId` to the `Session` table without a default value. This is not possible if the table is not empty.
  - Added the required column `dedupeKey` to the `Workout` table without a default value. This is not possible if the table is not empty.

*/
-- CreateTable
CREATE TABLE "UserProfile" (
    "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    "userId" INTEGER NOT NULL,
    "preferredRunDays" TEXT,
    "preferredSurface" TEXT,
    "goals" TEXT,
    "constraints" TEXT,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "UserProfile_userId_fkey" FOREIGN KEY ("userId") REFERENCES "User" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

-- RedefineTables
PRAGMA defer_foreign_keys=ON;
PRAGMA foreign_keys=OFF;
CREATE TABLE "new_AuthUser" (
    "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    "username" TEXT NOT NULL,
    "passwordHash" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "userId" INTEGER NOT NULL,
    CONSTRAINT "AuthUser_userId_fkey" FOREIGN KEY ("userId") REFERENCES "User" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);
INSERT INTO "new_AuthUser" ("createdAt", "id", "passwordHash", "username") SELECT "createdAt", "id", "passwordHash", "username" FROM "AuthUser";
DROP TABLE "AuthUser";
ALTER TABLE "new_AuthUser" RENAME TO "AuthUser";
CREATE UNIQUE INDEX "AuthUser_username_key" ON "AuthUser"("username");
CREATE UNIQUE INDEX "AuthUser_userId_key" ON "AuthUser"("userId");
CREATE TABLE "new_Session" (
    "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    "token" TEXT NOT NULL,
    "authUserId" INTEGER NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "expiresAt" DATETIME,
    "lastSeenAt" DATETIME,
    CONSTRAINT "Session_authUserId_fkey" FOREIGN KEY ("authUserId") REFERENCES "AuthUser" ("id") ON DELETE RESTRICT ON UPDATE CASCADE
);
INSERT INTO "new_Session" ("createdAt", "expiresAt", "id", "lastSeenAt", "token") SELECT "createdAt", "expiresAt", "id", "lastSeenAt", "token" FROM "Session";
DROP TABLE "Session";
ALTER TABLE "new_Session" RENAME TO "Session";
CREATE UNIQUE INDEX "Session_token_key" ON "Session"("token");
CREATE TABLE "new_Workout" (
    "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    "userId" INTEGER NOT NULL,
    "action" TEXT NOT NULL,
    "kind" TEXT NOT NULL,
    "summary" TEXT NOT NULL,
    "raceMeta" TEXT,
    "workoutMeta" TEXT,
    "tcxRaw" TEXT,
    "fitRaw" TEXT,
    "source" TEXT NOT NULL DEFAULT 'MANUAL_UPLOAD',
    "sourceActivityId" TEXT,
    "sourceUserId" TEXT,
    "dedupeKey" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "Workout_userId_fkey" FOREIGN KEY ("userId") REFERENCES "User" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);
INSERT INTO "new_Workout" ("action", "createdAt", "fitRaw", "id", "kind", "raceMeta", "source", "sourceActivityId", "sourceUserId", "summary", "tcxRaw", "updatedAt", "userId", "workoutMeta") SELECT "action", "createdAt", "fitRaw", "id", "kind", "raceMeta", "source", "sourceActivityId", "sourceUserId", "summary", "tcxRaw", "updatedAt", "userId", "workoutMeta" FROM "Workout";
DROP TABLE "Workout";
ALTER TABLE "new_Workout" RENAME TO "Workout";
CREATE INDEX "Workout_userId_idx" ON "Workout"("userId");
CREATE INDEX "Workout_userId_createdAt_idx" ON "Workout"("userId", "createdAt");
CREATE INDEX "Workout_userId_source_sourceActivityId_idx" ON "Workout"("userId", "source", "sourceActivityId");
CREATE UNIQUE INDEX "Workout_userId_dedupeKey_key" ON "Workout"("userId", "dedupeKey");
PRAGMA foreign_keys=ON;
PRAGMA defer_foreign_keys=OFF;

-- CreateIndex
CREATE UNIQUE INDEX "UserProfile_userId_key" ON "UserProfile"("userId");
