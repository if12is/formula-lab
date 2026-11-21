/**
 * Soap-property INDICATORS from a fatty-acid profile (F-07).
 *
 * Which acids count toward hardness / cleansing / conditioning / bubbly / creamy / iodine / INS is a
 * published CONVENTION, not physics, so the mapping is DATA passed in (`PropertyMap` with its own
 * sourceId + confidence). The engine ships no default map. Every output is tagged
 * `kind: 'indicator'` (a rule-of-thumb score, never a measured property).
 *
 *   blended acid % = sum(m_i x acid_i%) / sum(m_i)           (mass-weighted over oils)
 *   indicator      = sum(coef_k x blended acid_k%)           (terms from the PropertyMap)
 */
import type { Confidence, MissingData } from '../contract';
import { D, type Dec } from '../core/decimal';
import { CONFIDENCE_RANK, parseDec, type SourcedScalar } from '../core/parse';
import { missing, ok, type Result } from '../core/result';

export interface SoapOil {
  oilId: string;
  /** Decimal string, any consistent mass unit. */
  mass: string;
  /** acid key (e.g. "lauric") -> percent of this oil's fatty acids, sourced. */
  fattyAcids: Readonly<Record<string, SourcedScalar>>;
}

export interface PropertyTerm {
  acid: string;
  /** Decimal string; 1 for a plain "sum of these acids" property. */
  coef: string;
}

export interface PropertyMap {
  sourceId: string;
  confidence: Confidence;
  /** indicator name -> acids (with coefficients) that contribute to it. */
  properties: Readonly<Record<string, { terms: readonly PropertyTerm[] }>>;
}

export interface Indicator {
  kind: 'indicator';
  value: string;
  /** Source of the mapping convention. */
  mapSourceId: string;
  /** Sorted, de-duplicated sourceIds of every oil acid value used, plus the map's. */
  sourceIds: string[];
  /** Worst confidence among everything used. */
  confidence: Confidence;
}

export interface SoapIndicators {
  indicators: Record<string, Indicator>;
  /** Blended acid % for every acid the map references. */
  blendedProfile: Record<string, string>;
}

const HUNDRED = new D(100);

function gap(property: string, reason: string, materialId?: string): MissingData {
  return materialId === undefined ? { property, reason } : { materialId, property, reason };
}

function validateMap(map: PropertyMap | undefined): MissingData[] {
  if (map === undefined) {
    return [gap('propertyMap', 'no_property_map_provided (source required, see HUMAN-TODO F-07)')];
  }
  const gaps: MissingData[] = [];
  if (!map.sourceId) {
    gaps.push(gap('propertyMap', 'missing_sourceId'));
  }
  const names = Object.keys(map.properties);
  if (names.length === 0) {
    gaps.push(gap('propertyMap', 'no_properties_defined'));
  }
  for (const name of names) {
    const terms = map.properties[name]!.terms;
    if (terms.length === 0) {
      gaps.push(gap(`propertyMap:${name}`, 'no_terms'));
    }
    for (const t of terms) {
      if (!t.acid || parseDec(t.coef) === null) {
        gaps.push(gap(`propertyMap:${name}`, `invalid_term: acid=${String(t.acid)} coef=${String(t.coef)}`));
      }
    }
  }
  return gaps;
}

export function soapIndicators(oils: readonly SoapOil[], map: PropertyMap | undefined): Result<SoapIndicators> {
  const mapGaps = validateMap(map);
  if (mapGaps.length > 0 || map === undefined) {
    return missing(mapGaps);
  }
  if (oils.length === 0) {
    return missing([gap('oils', 'no_oils')]);
  }
  const names = Object.keys(map.properties).sort();
  const acids = [...new Set(names.flatMap((n) => map.properties[n]!.terms.map((t) => t.acid)))].sort();

  const gaps: MissingData[] = [];
  const masses: Dec[] = [];
  let total: Dec = new D(0);
  let worst: Confidence = map.confidence;
  const sources = new Set<string>([map.sourceId]);
  const sums: Record<string, Dec> = Object.fromEntries(acids.map((a) => [a, new D(0)]));

  for (const oil of oils) {
    const m = parseDec(oil.mass);
    if (m === null || m.lt(0)) {
      gaps.push(gap('mass', 'invalid_mass', oil.oilId));
      masses.push(new D(0));
      continue;
    }
    masses.push(m);
    total = total.plus(m);
    let profileSum: Dec = new D(0);
    for (const [, v] of Object.entries(oil.fattyAcids)) {
      const x = parseDec(v.value);
      if (x !== null) {
        profileSum = profileSum.plus(x);
      }
    }
    if (profileSum.gt(HUNDRED)) {
      gaps.push(gap('fattyAcids', `profile_sums_above_100: ${profileSum.toFixed()}`, oil.oilId));
      continue;
    }
    for (const acid of acids) {
      const v = oil.fattyAcids[acid];
      const x = v === undefined ? null : parseDec(v.value);
      if (v === undefined) {
        gaps.push(gap(`fattyAcid:${acid}`, 'missing_fatty_acid_percent', oil.oilId));
      } else if (x === null || x.lt(0) || x.gt(HUNDRED)) {
        gaps.push(gap(`fattyAcid:${acid}`, 'invalid_fatty_acid_percent', oil.oilId));
      } else {
        sums[acid] = sums[acid]!.plus(m.mul(x));
        sources.add(v.sourceId);
        if (CONFIDENCE_RANK[v.confidence] > CONFIDENCE_RANK[worst]) {
          worst = v.confidence;
        }
      }
    }
  }
  if (gaps.length > 0) {
    return missing(gaps);
  }
  if (total.isZero()) {
    return missing([gap('mass', 'total_oil_mass_zero')]);
  }

  const blended: Record<string, Dec> = Object.fromEntries(acids.map((a) => [a, sums[a]!.div(total)]));
  const indicators: Record<string, Indicator> = {};
  for (const name of names) {
    const value = map.properties[name]!.terms.reduce((acc, t) => acc.plus(blended[t.acid]!.mul(new D(t.coef))), new D(0));
    indicators[name] = {
      kind: 'indicator',
      value: value.toFixed(),
      mapSourceId: map.sourceId,
      sourceIds: [...sources].sort(),
      confidence: worst,
    };
  }
  return ok({
    indicators,
    blendedProfile: Object.fromEntries(acids.map((a) => [a, blended[a]!.toFixed()])),
  });
}
