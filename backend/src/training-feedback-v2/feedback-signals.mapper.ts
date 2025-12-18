import type { TrainingFeedbackV2 } from './training-feedback-v2.types'
import type { FeedbackSignals } from './feedback-signals.types'

export function mapFeedbackToSignals(feedback: TrainingFeedbackV2): FeedbackSignals {
  // Map character to intensityClass
  let intensityClass: 'easy' | 'moderate' | 'hard'
  switch (feedback.character) {
    case 'easy':
    case 'regeneracja':
      intensityClass = 'easy'
      break
    case 'tempo':
      intensityClass = 'moderate'
      break
    case 'interwał':
      intensityClass = 'hard'
      break
    default:
      intensityClass = 'easy' // fallback
  }

  // Map hrStable directly
  const hrStable = feedback.coachSignals.hrStable

  // Map paceEquality to economyFlag
  const paceEquality = feedback.metrics.paceEquality
  let economyFlag: 'good' | 'ok' | 'poor'
  if (paceEquality > 0.8) {
    economyFlag = 'good'
  } else if (paceEquality > 0.6) {
    economyFlag = 'ok'
  } else {
    economyFlag = 'poor'
  }

  // Map weeklyLoadContribution to loadImpact
  const weeklyLoadContribution = feedback.metrics.weeklyLoadContribution
  let loadImpact: 'low' | 'medium' | 'high'
  if (weeklyLoadContribution > 50) {
    loadImpact = 'high'
  } else if (weeklyLoadContribution > 25) {
    loadImpact = 'medium'
  } else {
    loadImpact = 'low'
  }

  // Calculate warnings
  const warnings: FeedbackSignals['warnings'] = {}
  
  // overloadRisk: loadImpact === 'high' or weeklyLoadContribution > 50
  if (loadImpact === 'high' || weeklyLoadContribution > 50) {
    warnings.overloadRisk = true
  }
  
  // hrInstability: !hrStable and character === 'easy'
  if (!hrStable && feedback.character === 'easy') {
    warnings.hrInstability = true
  }
  
  // economyDrop: economyFlag === 'poor' and character === 'easy'
  if (economyFlag === 'poor' && feedback.character === 'easy') {
    warnings.economyDrop = true
  }

  return {
    intensityClass,
    hrStable,
    economyFlag,
    loadImpact,
    warnings,
  }
}
