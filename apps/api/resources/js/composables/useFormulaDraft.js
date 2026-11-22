import { computed, reactive, ref } from 'vue';
import { balanceLines, costLines, phaseCheck, summarizeLines } from '../lib/engine';

/**
 * The editable state of a formula in the editor: its lines, the materials they use (with the price quotes the
 * engine costs with), and everything the engine says about them: sum and deviation (draftSummary), cost per kg
 * (calculate) and q.s. balancing (balanceQs). Nothing here computes a number; it only calls the engine.
 *
 *   const draft = useFormulaDraft({ lines: props.lines, materials: props.materials, datasetVersion: props.datasetVersion });
 *   draft.summary.value.sum        draft.cost.value.perKg        draft.balance()
 */
export function useFormulaDraft({ lines: initialLines, materials: initialMaterials, datasetVersion }) {
    let sequence = 0;
    const blankLine = () => ({ key: `line-${(sequence += 1)}`, material_id: null, percent: '', is_qs: false, phase: '', addition_temp_c: '' });
    const fromServer = (serverLines) => {
        const loaded = serverLines.map((line) => ({ ...blankLine(), material_id: line.trade_material_id, percent: line.percent_ww, is_qs: line.is_qs, phase: line.phase ?? '', addition_temp_c: line.addition_temp_c ?? '' }));

        return loaded.length ? loaded : [blankLine()];
    };

    const lines = ref(fromServer(initialLines));

    /** material id -> { label, description, status, prices[] } as the server sent them. */
    const materials = reactive({ ...initialMaterials });
    /** Balancing done since the editor opened: [{ material_id, before, after }]. */
    const balanceEvents = ref([]);

    /** Starts over from what the server sent (another version of the formula opened in the same page). */
    function reset(serverLines, serverMaterials) {
        lines.value = fromServer(serverLines);
        Object.keys(materials).forEach((id) => delete materials[id]);
        Object.assign(materials, serverMaterials);
        balanceEvents.value = [];
    }

    const summary = computed(() => summarizeLines(lines.value));
    const phases = computed(() => phaseCheck(lines.value));
    const cost = computed(() => costLines(lines.value, materials, datasetVersion));
    const qsLine = computed(() => lines.value.find((line) => line.is_qs) ?? null);

    const labelOf = (materialId) => materials[materialId]?.label ?? materialId;

    function addLine() {
        const line = blankLine();
        lines.value.push(line);

        return line;
    }

    function removeLine(key) {
        lines.value = lines.value.filter((line) => line.key !== key);

        if (lines.value.length === 0) {
            lines.value.push(blankLine());
        }
    }

    /** Remembers what the server sent about a material (name, prices) once a line uses it. */
    function remember(option) {
        materials[option.value] = option;
    }

    /** Puts a material on a line, or on the first empty one (or a new one) when no line is named. */
    function useMaterial(option, key = null) {
        remember(option);
        const line = lines.value.find((candidate) => candidate.key === key)
            ?? lines.value.find((candidate) => candidate.material_id === null)
            ?? addLine();
        line.material_id = option.value;
    }

    /** The one q.s. line (a radio: choosing one clears the others), or none. */
    function setQs(key) {
        lines.value.forEach((line) => { line.is_qs = line.key === key; });
    }

    /** Balances the q.s. line through the engine and records the event. Returns the engine's answer. */
    function balance() {
        const result = balanceLines(lines.value);

        if (result.ok && qsLine.value) {
            const line = qsLine.value;

            if (line.material_id) {
                balanceEvents.value.push({ material_id: line.material_id, before: line.percent === '' ? null : line.percent, after: result.percent });
            }

            line.percent = result.percent;
        }

        return result;
    }

    /** Only the events whose result is still what the q.s. line carries. */
    const currentBalanceEvents = () => balanceEvents.value.filter((event) => lines.value.some((line) => line.is_qs && line.material_id === event.material_id && line.percent === event.after));

    return { lines, materials, summary, phases, cost, qsLine, labelOf, addLine, removeLine, remember, useMaterial, setQs, balance, currentBalanceEvents, reset };
}
