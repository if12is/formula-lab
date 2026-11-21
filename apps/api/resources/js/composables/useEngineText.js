import { useI18n } from './useI18n';
import { useProperties } from './useProperties';

/**
 * The engine's `missing[]` gaps and `warnings[]` as words (lang group `engine`), the one place that does it.
 * A gap is { materialId?, property, reason, scope? }; a warning is { key, details[], raw }.
 */
export function useEngineText() {
    const { t, has } = useI18n();
    const { label: propertyLabel } = useProperties();

    /** What the gap is about: the material and the property (or calculator subject) it lacks. */
    const subjectOf = (gap) => {
        const [head, ...detail] = gap.property.split(':');
        const known = has(`calculators.subjects.${head}`) ? t(`calculators.subjects.${head}`) : has(`properties.${head}`) ? propertyLabel(head) : head;

        return [gap.materialId, detail.length ? `${known} (${detail.join(':')})` : known].filter(Boolean).join(' · ');
    };

    /** The reason in words: the engine's key (engine.<key>), or a scoped bare one (engine.<scope>.<head>), or the engine's own text. */
    function reasonOf(gap) {
        const head = gap.reason.split(':')[0].trim();
        const candidates = [`engine.${head}`, gap.scope ? `engine.${gap.scope}.${head}` : null].filter(Boolean);
        const found = candidates.find((key) => has(key));

        return found ? t(found) : t('engine.unknown_reason', { reason: gap.reason });
    }

    function warningText(warning) {
        if (!has(`engine.${warning.key}`)) {
            return warning.raw;
        }

        const [material, property, confidence] = warning.details;

        return t(`engine.${warning.key}`, {
            material: material ?? '',
            property: property ?? '',
            confidence: confidence && has(`enums.confidence.${confidence}`) ? t(`enums.confidence.${confidence}`) : (confidence ?? ''),
            detail: warning.details.join(', '),
        });
    }

    return { subjectOf, reasonOf, warningText };
}
