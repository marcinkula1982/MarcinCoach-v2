-- CreateTable
CREATE TABLE "TrainingFeedbackV2" (
    "id" INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
    "workoutId" INTEGER NOT NULL,
    "userId" INTEGER NOT NULL,
    "feedback" TEXT NOT NULL,
    "createdAt" DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "updatedAt" DATETIME NOT NULL,
    CONSTRAINT "TrainingFeedbackV2_workoutId_fkey" FOREIGN KEY ("workoutId") REFERENCES "Workout" ("id") ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT "TrainingFeedbackV2_userId_fkey" FOREIGN KEY ("userId") REFERENCES "User" ("id") ON DELETE CASCADE ON UPDATE CASCADE
);

-- CreateIndex
CREATE UNIQUE INDEX "TrainingFeedbackV2_workoutId_key" ON "TrainingFeedbackV2"("workoutId");

-- CreateIndex
CREATE INDEX "TrainingFeedbackV2_userId_idx" ON "TrainingFeedbackV2"("userId");

-- CreateIndex
CREATE INDEX "TrainingFeedbackV2_workoutId_idx" ON "TrainingFeedbackV2"("workoutId");
