import type { TrainingFeedbackV2, TrainingFeedbackV2Response, CoachSignals } from './training-feedback-v2.types'

export function presentFeedback(feedback: TrainingFeedbackV2, createdAt?: Date | string): TrainingFeedbackV2Response {
  const coachConclusion = generateCoachConclusion(feedback.coachSignals, feedback.hrStability, feedback.economy, feedback.loadImpact)
  
  // Generate generatedAtIso from createdAt if available, otherwise use current time
  let generatedAtIso: string
  if (createdAt) {
    const date = typeof createdAt === 'string' ? new Date(createdAt) : createdAt
    generatedAtIso = date.toISOString()
  } else {
    generatedAtIso = new Date().toISOString()
  }

  return {
    ...feedback,
    generatedAtIso,
    coachConclusion,
  }
}

function generateCoachConclusion(
  coachSignals: CoachSignals,
  hrStability: { drift: number | null; artefacts: boolean },
  economy: { paceEquality: number },
  loadImpact: { weeklyLoadContribution: number },
): string {
  // Deterministyczny template string z warunkami
  // Zero losowości, zero heurystyk językowych
  const parts: string[] = []

  // Character
  if (coachSignals.character === 'easy') {
    parts.push('Trening łatwy')
  } else if (coachSignals.character === 'tempo') {
    parts.push('Trening tempowy')
  } else if (coachSignals.character === 'interwał') {
    parts.push('Trening interwałowy')
  } else if (coachSignals.character === 'regeneracja') {
    parts.push('Trening regeneracyjny')
  }

  // HR Stability
  if (hrStability.artefacts) {
    parts.push('wykryto artefakty tętna')
  } else if (hrStability.drift != null) {
    if (Math.abs(hrStability.drift) > 2) {
      if (hrStability.drift > 0) {
        parts.push('wzrost tętna w czasie')
      } else {
        parts.push('spadek tętna w czasie')
      }
    } else {
      parts.push('stabilne tętno')
    }
  }

  // Economy
  if (economy.paceEquality > 0.8) {
    parts.push('stabilne tempo')
  } else if (economy.paceEquality < 0.5) {
    parts.push('zmienne tempo')
  }

  // Load Impact
  if (loadImpact.weeklyLoadContribution > 50) {
    parts.push('wysoki wkład w obciążenie tygodniowe')
  } else if (loadImpact.weeklyLoadContribution < 10) {
    parts.push('niski wkład w obciążenie tygodniowe')
  }

  if (parts.length === 0) {
    return 'Trening zakończony.'
  }

  return parts.join('. ') + '.'
}

