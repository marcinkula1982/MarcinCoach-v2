import type { TrainingFeedbackV2 } from './training-feedback-v2.types'
import { trainingFeedbackV2Schema } from './training-feedback-v2.schema'

/**
 * Normalizes legacy feedback snapshots to current TrainingFeedbackV2 format.
 * Handles old snapshot formats that may lack new fields or have old structures.
 *
 * TODO: Remove after V1 → V2 data migration
 * This normalization handles old snapshot formats
 */
export function normalizeLegacyFeedback(feedbackRaw: any): TrainingFeedbackV2 | null {
  // Normalizacja starych rekordów: jeśli coachSignals ma starą strukturę lub brak boolean -> wylicz
  if (feedbackRaw.coachSignals) {
    const signals = feedbackRaw.coachSignals
    // Sprawdź czy to stara struktura (fatigueRisk/readiness/trainingRole) lub brak boolean
    if (
      signals.fatigueRisk !== undefined ||
      signals.readiness !== undefined ||
      signals.trainingRole !== undefined ||
      signals.hrStable === undefined ||
      signals.economyGood === undefined ||
      signals.loadHeavy === undefined
    ) {
      // Wylicz boolean z dostępnych danych
      const hrStable =
        (feedbackRaw.hrStability?.drift == null || Math.abs(feedbackRaw.hrStability.drift) <= 2) &&
        !feedbackRaw.hrStability?.artefacts
      const economyGood = (feedbackRaw.economy?.paceEquality ?? 0) > 0.8
      const loadHeavy = (feedbackRaw.loadImpact?.weeklyLoadContribution ?? 0) > 50

      feedbackRaw.coachSignals = {
        character: feedbackRaw.character || 'easy',
        hrStable: hrStable ?? false,
        economyGood: economyGood ?? false,
        loadHeavy: loadHeavy ?? false,
      }
    }
  } else {
    // Brak coachSignals - wylicz z dostępnych danych
    const hrStable =
      (feedbackRaw.hrStability?.drift == null || Math.abs(feedbackRaw.hrStability.drift) <= 2) &&
      !feedbackRaw.hrStability?.artefacts
    const economyGood = (feedbackRaw.economy?.paceEquality ?? 0) > 0.8
    const loadHeavy = (feedbackRaw.loadImpact?.weeklyLoadContribution ?? 0) > 50

    feedbackRaw.coachSignals = {
      character: feedbackRaw.character || 'easy',
      hrStable: hrStable ?? false,
      economyGood: economyGood ?? false,
      loadHeavy: loadHeavy ?? false,
    }
  }

  // Normalizacja metrics: jeśli brak -> wylicz z dostępnych danych
  if (!feedbackRaw.metrics) {
    feedbackRaw.metrics = {
      hrDrift: feedbackRaw.hrStability?.drift ?? null,
      paceEquality: feedbackRaw.economy?.paceEquality ?? 0,
      weeklyLoadContribution: feedbackRaw.loadImpact?.weeklyLoadContribution ?? 0,
    }
  }

  // Usuń coachConclusion i generatedAtIso jeśli istnieją (nie zapisujemy w DB)
  delete (feedbackRaw as any).coachConclusion
  delete (feedbackRaw as any).generatedAtIso

  return feedbackRaw as TrainingFeedbackV2
}

/**
 * Parses and normalizes a feedback record from the database.
 * Handles JSON parsing, legacy normalization, and validation.
 * 
 * This is a shared helper to avoid duplicating parsing/normalization logic.
 */
export function parseAndNormalizeFeedbackRecord(record: { feedback: string | any }): TrainingFeedbackV2 | null {
  // Parse JSON if needed
  const feedbackRaw = safeJsonParse<any>(record.feedback)
  if (!feedbackRaw) {
    return null
  }

  // Normalize legacy feedback
  const feedback = normalizeLegacyFeedback(feedbackRaw)
  if (!feedback) {
    return null
  }

  // Validate
  const parsed = trainingFeedbackV2Schema.safeParse(feedback)
  if (!parsed.success) {
    return null
  }

  return parsed.data
}

function safeJsonParse<T = any>(val: any): T | null {
  if (val == null) return null
  if (typeof val === 'string') {
    try {
      return JSON.parse(val) as T
    } catch {
      return null
    }
  }
  return val as T
}

