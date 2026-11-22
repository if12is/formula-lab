import type { Confidence } from '../contract';

/** The authority that licenses the product family's factory (plan/00-research/05). */
export type Regulator = 'EOS' | 'EDA';

/** `pending` until a person has checked the row against its source; a verified row needs a source better than an estimate. */
export type Verification = 'pending' | 'verified';

/** How far a template has been proven. Only the data can say; no code path promotes a template. */
export type TemplateVerification = 'theoretical' | 'lab_tested' | 'chemist_approved';

export interface LocalizedName {
  ar: string;
  en: string;
}

export interface CalculationEntry {
  id: string;
  /** `planned` = named by the research but no engine function yet; the family still lists it so the gap is visible. */
  status: 'available' | 'planned';
  /** The key of the standalone calculator page (apps/api/config/calculators.php), or null. */
  calculatorKey: string | null;
}

export interface MeasuredPropertyEntry {
  id: string;
  /** The lab loop's property (App\Enums\LabProperty value), or null while the lab cannot record it yet. */
  labProperty: string | null;
}

export interface FamilyVocabulary {
  regulators: Regulator[];
  groups: string[];
  calculations: CalculationEntry[];
  measuredProperties: MeasuredPropertyEntry[];
  /** What a template line can be: a role, never a specific material. */
  roles: string[];
}

export interface RegistrySource {
  id: string;
  title: string;
  confidence: Confidence;
}

export interface ProductFamily {
  code: string;
  group: string;
  name: LocalizedName;
  /** null = not stated by the sources (HUMAN-TODO), never guessed. */
  regulator: Regulator | null;
  ifraCategory: string | null;
  requiredCalculations: string[];
  measuredProperties: string[];
  /** The family's default tax regime id; null until the accountant provides it (HUMAN-TODO). */
  taxRegime: string | null;
  sourceId: string;
  confidence: Confidence;
  verification: Verification;
  /** Key of a lang note (families.notes.<key>) that qualifies a value, such as "IFRA 9 usually". */
  notes?: string;
}

export interface ProductFamilyRegistry {
  id: string;
  version: string;
  status: string;
  sources: RegistrySource[];
  vocabulary: FamilyVocabulary;
  families: ProductFamily[];
}

export interface TemplateLine {
  /** Unique inside the template; the handle a caller uses to fill the line. */
  key: string;
  role: string;
  /** % w/w as a decimal string, or null while no chemist has supplied it. Always null on a q.s. line. */
  percentWW: string | null;
  qs: boolean;
  /** An optional line is dropped when the caller gives it no material. */
  optional: boolean;
}

export interface FormulaTemplate {
  code: string;
  familyCode: string;
  name: LocalizedName;
  verification: TemplateVerification;
  /** Required for `chemist_approved`. */
  approvedBy?: string;
  approvedOn?: string;
  sourceId: string;
  confidence: Confidence;
  lines: TemplateLine[];
}

export interface FormulaTemplateLibrary {
  id: string;
  version: string;
  status: string;
  templates: FormulaTemplate[];
}
