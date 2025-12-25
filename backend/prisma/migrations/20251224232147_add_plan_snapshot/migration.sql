-- CreateTable
CREATE TABLE "PlanSnapshot" (
    "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    "userId" INTEGER NOT NULL,
    "snapshotJson" TEXT NOT NULL,
    "windowStartIso" TEXT NOT NULL,
    "windowEndIso" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT "PlanSnapshot_userId_fkey" FOREIGN KEY ("userId") REFERENCES "User" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

-- CreateIndex
CREATE INDEX "PlanSnapshot_userId_windowStartIso_idx" ON "PlanSnapshot"("userId", "windowStartIso");

-- CreateIndex
CREATE INDEX "PlanSnapshot_userId_idx" ON "PlanSnapshot"("userId");
