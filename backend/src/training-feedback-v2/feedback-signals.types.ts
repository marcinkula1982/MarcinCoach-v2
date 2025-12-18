export type FeedbackSignals = {
  intensityClass: 'easy' | 'moderate' | 'hard'
  hrStable: boolean
  economyFlag: 'good' | 'ok' | 'poor'
  loadImpact: 'low' | 'medium' | 'high'
  warnings: {
    economyDrop?: boolean
    hrInstability?: boolean
    overloadRisk?: boolean
  }
}

