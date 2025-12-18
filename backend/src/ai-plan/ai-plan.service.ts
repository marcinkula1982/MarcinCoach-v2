import { Injectable, InternalServerErrorException } from '@nestjs/common'
import type { TrainingContext } from '../training-context/training-context.types'
import type { TrainingAdjustments } from '../training-adjustments/training-adjustments.types'
import type { WeeklyPlan } from '../weekly-plan/weekly-plan.types'
import type { AiPlanExplanation, AiPlanResponse } from './ai-plan.types'
import { AiCacheService } from '../ai-cache/ai-cache.service'
import type { FeedbackSignals } from '../training-feedback-v2/feedback-signals.types'
import { TrainingFeedbackV2Service } from '../training-feedback-v2/training-feedback-v2.service'

const OpenAI = require('openai')

@Injectable()
export class AiPlanService {
  constructor(
    private readonly aiCacheService: AiCacheService,
    private readonly trainingFeedbackV2Service: TrainingFeedbackV2Service,
  ) {}

  getCachedResponse(userId: number, days: number): AiPlanResponse | null {
    const cached = this.aiCacheService.get<AiPlanResponse>('plan', userId, days)
    return cached ? cached.payload : null
  }

  setCachedResponse(userId: number, days: number, response: AiPlanResponse): void {
    this.aiCacheService.set('plan', userId, days, response)
  }

  private stripMarkdownFences(raw: string): string {
    const trimmed = raw.trim()
    if (!trimmed.startsWith('```')) return trimmed
    return trimmed.replace(/^```[a-zA-Z]*\n?/, '').replace(/```$/, '').trim()
  }

  private extractResponseOutputText(response: any): string | undefined {
    const direct = response?.output_text
    if (typeof direct === 'string' && direct.trim().length > 0) return direct

    const out = response?.output
    if (!Array.isArray(out)) return undefined

    for (const item of out) {
      const content = item?.content
      if (!Array.isArray(content)) continue

      for (const c of content) {
        if (c?.type === 'output_text' || c?.type === 'text') {
          if (typeof c?.text === 'string' && c.text.trim().length > 0) return c.text
          if (typeof c?.text?.value === 'string' && c.text.value.trim().length > 0) return c.text.value
        }

        // Fallback: even without type, accept plain string text
        if (typeof c?.text === 'string' && c.text.trim().length > 0) return c.text
      }
    }

    return undefined
  }

  private isValidExplanation(candidate: any): candidate is AiPlanExplanation {
    if (!candidate || typeof candidate !== 'object') return false

    if (typeof candidate.titlePl !== 'string' || candidate.titlePl.trim().length === 0) return false

    if (!Array.isArray(candidate.summaryPl) || !candidate.summaryPl.every((s: any) => typeof s === 'string'))
      return false

    if (
      !Array.isArray(candidate.sessionNotesPl) ||
      !candidate.sessionNotesPl.every(
        (n: any) =>
          n &&
          typeof n === 'object' &&
          typeof n.day === 'string' &&
          typeof n.text === 'string',
      )
    )
      return false

    if (!Array.isArray(candidate.warningsPl) || !candidate.warningsPl.every((s: any) => typeof s === 'string'))
      return false

    if (typeof candidate.confidence !== 'number' || !Number.isFinite(candidate.confidence)) return false
    if (candidate.confidence < 0 || candidate.confidence > 1) return false

    return true
  }

  private async buildOpenAiExplanation(
    context: TrainingContext,
    adjustments: TrainingAdjustments,
    plan: WeeklyPlan & { appliedAdjustmentsCodes?: string[] },
    feedbackSignals?: FeedbackSignals,
  ): Promise<AiPlanExplanation> {
    const client = new OpenAI({ apiKey: process.env.OPENAI_API_KEY })
    const model = process.env.AI_PLAN_MODEL ?? 'gpt-5'
    const maxOut = Number(process.env.AI_PLAN_MAX_OUTPUT_TOKENS ?? '2000')

    let instructions =
      'Napisz po polsku krótkie objaśnienie planu tygodniowego. ' +
      'Nie zmieniaj planu. Nie dodawaj ani nie usuwaj treningów. Opieraj się wyłącznie na podanym JSON. ' +
      'Nie używaj oznaczeń Z1/Z2. Jeśli mówisz o intensywności, używaj sformułowania „strefa tętna". '
    
    if (feedbackSignals) {
      instructions +=
        'Jeśli podano feedbackSignals, to deterministyczne sygnały z ostatniego treningu — traktuj je jako fakty, nie do negocjacji. '
    }
    
    instructions +=
      'appliedAdjustmentsCodes są decyzjami backendu i nie wolno ich podważać ani proponować planu sprzecznego z nimi. '
    
    instructions +=
      'Zwróć WYŁĄCZNIE poprawny JSON (bez markdown, bez tekstu dookoła) dokładnie w tym kształcie: ' +
      '{"titlePl":string,"summaryPl":string[],"sessionNotesPl":{"day":string,"text":string}[],"warningsPl":string[],"confidence":number}.'

    const input = JSON.stringify({ context, adjustments, plan, ...(feedbackSignals ? { feedbackSignals } : {}) })

    const response: any = await client.responses.create({
      model,
      instructions,
      input,
      max_output_tokens: maxOut,
    })

    const outputText = this.extractResponseOutputText(response)

    if (typeof outputText !== 'string' || outputText.trim().length === 0) {
      throw new Error('OpenAI response missing text')
    }

    const jsonText = this.stripMarkdownFences(outputText)

    let parsed: any
    try {
      parsed = JSON.parse(jsonText)
    } catch {
      throw new Error('OpenAI returned non-JSON content')
    }

    if (!this.isValidExplanation(parsed)) {
      throw new Error('OpenAI returned JSON with invalid shape')
    }

    return parsed
  }

  private buildStubExplanation(
    context: TrainingContext,
    adjustments: TrainingAdjustments,
    plan: WeeklyPlan & { appliedAdjustmentsCodes?: string[] },
  ): AiPlanExplanation {
    const sessionsPlanned = plan.sessions.filter((s) => s.type !== 'rest').length
    const hasFatigue = context.signals.flags.fatigue === true
    const totalSessions = context.signals.volume.sessions

    const reduceLoadApplied = adjustments.adjustments.some((a) => a.code === 'reduce_load')
    const addLongRunApplied = adjustments.adjustments.some((a) => a.code === 'add_long_run')
    const surfaceConstraintApplied = adjustments.adjustments.some((a) => a.code === 'surface_constraint')

    const summaryPl: string[] = []
    summaryPl.push(`Okno danych: ${context.windowDays} dni.`)
    summaryPl.push(`Dni treningowe w tygodniu: ${sessionsPlanned}.`)

    const longRun = plan.sessions.find((s) => s.type === 'long')
    if (longRun) {
      summaryPl.push(`Długie wybieganie: ${longRun.day} (${longRun.durationMin} min).`)
    }

    const quality = plan.sessions.find((s) => s.type === 'quality')
    if (quality) {
      summaryPl.push(`Akcent: ${quality.day} (${quality.durationMin} min).`)
    } else if (hasFatigue) {
      summaryPl.push('Brak akcentu z powodu oznak zmęczenia.')
    }

    if (reduceLoadApplied) {
      summaryPl.push('Zastosowano redukcję obciążenia (−20%).')
    }

    // Ensure 3..6 points
    const finalSummary = summaryPl.slice(0, 6)
    while (finalSummary.length < 3) finalSummary.push('Plan ma charakter orientacyjny i jest deterministyczny.')

    const sessionNotesPl: Array<{ day: string; text: string }> = []
    if (quality) {
      sessionNotesPl.push({ day: quality.day, text: 'Akcent: zacznij kontrolnie, bez szarpania tempa.' })
    }
    if (longRun) {
      sessionNotesPl.push({ day: longRun.day, text: 'Długie wybieganie: spokojnie, w komforcie oddechowym.' })
    }

    const warningsPl: string[] = []
    if (reduceLoadApplied) warningsPl.push('Zredukowano obciążenie z powodu oznak zmęczenia.')
    if (addLongRunApplied) warningsPl.push('Dodano długie wybieganie — zadbaj o regenerację po nim.')
    if (surfaceConstraintApplied) warningsPl.push('Uwzględniono preferencje nawierzchni (unikaj asfaltu).')

    let confidence = 0.65
    if (totalSessions === 0) confidence = 0.2
    else if (hasFatigue) confidence = 0.5
    else if (totalSessions >= 4) confidence = 0.8
    confidence = Math.min(0.9, Math.max(0.2, confidence))

    return {
      titlePl: 'Plan tygodniowy',
      summaryPl: finalSummary,
      sessionNotesPl,
      warningsPl,
      confidence,
    }
  }


  async buildResponse(
    userId: number,
    context: TrainingContext,
    adjustments: TrainingAdjustments,
    plan: WeeklyPlan & { appliedAdjustmentsCodes?: string[] },
  ): Promise<AiPlanResponse> {
    // Pobierz najnowszy feedbackSignals (opcjonalnie) - deterministycznie po workout.startTimeIso
    const feedbackSignals = await this.trainingFeedbackV2Service.getLatestFeedbackSignalsForUser(userId)

    // Generate response
    const shouldUseOpenAi = process.env.AI_PLAN_PROVIDER === 'openai'
    const apiKeyOk = Boolean(process.env.OPENAI_API_KEY && process.env.OPENAI_API_KEY.trim().length > 0)

    if (shouldUseOpenAi && !apiKeyOk) {
      throw new InternalServerErrorException('OPENAI_API_KEY missing')
    }

    let explanation: AiPlanExplanation
    let usedProvider: 'stub' | 'openai' = 'stub'
    if (shouldUseOpenAi) {
      try {
        explanation = await this.buildOpenAiExplanation(context, adjustments, plan, feedbackSignals)
        usedProvider = 'openai'
      } catch (err) {
        explanation = this.buildStubExplanation(context, adjustments, plan)
        usedProvider = 'stub'
      }
    } else {
      explanation = this.buildStubExplanation(context, adjustments, plan)
      usedProvider = 'stub'
    }

    return {
      provider: usedProvider,
      generatedAtIso: context.generatedAtIso,
      windowDays: context.windowDays,
      plan,
      adjustments,
      explanation,
    }
  }

}


