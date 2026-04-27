export type OnboardingSummaryTone = 'good' | 'warn' | 'neutral'

export type OnboardingSummaryHighlight = {
  code: string
  label: string
  value: string
  detail: string
  tone: OnboardingSummaryTone
}

export type OnboardingSummaryBadge = {
  code: string
  label: string
  tone: OnboardingSummaryTone
}

export type OnboardingSummaryNextStep = {
  code: string
  label: string
  reason: string
}

export type OnboardingSummary = {
  generatedAtIso: string
  source: 'training_analysis'
  analysisComputedAt: string
  windowDays: number
  confidence: 'none' | 'low' | 'medium' | 'high'
  headline: string
  lead: string
  highlights: OnboardingSummaryHighlight[]
  badges: OnboardingSummaryBadge[]
  nextSteps: OnboardingSummaryNextStep[]
  analysisCache: 'hit' | 'miss'
}
