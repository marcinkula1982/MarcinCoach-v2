export type TrainingAdjustment = {
  code:
    | 'reduce_load'
    | 'increase_load'
    | 'add_long_run'
    | 'reduce_intensity'
    | 'increase_intensity'
    | 'add_rest_day'
    | 'swap_quality_day'
    | 'surface_constraint'
    | 'shoe_constraint'
    | 'recovery_focus'
    | 'technique_focus';
  severity: 'low' | 'medium' | 'high';
  rationale: string;
  evidence: Array<{ key: string; value: string | number | boolean }>;
  params?: Record<string, any>;
};

export type TrainingAdjustments = {
  generatedAtIso: string;
  windowDays: number;
  adjustments: TrainingAdjustment[];
};


