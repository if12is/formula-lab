/**
 * The icon of each calculator's card, by its key in config/calculators.php. Written out here (not in the PHP config)
 * because the kit's Vite plugin bundles the Phosphor icons it finds in the frontend source, so none is fetched at
 * runtime. A test checks every calculator of the config has one.
 */
export const calculatorIcons = {
    saponification: 'ph:drop-half',
    neutralization: 'ph:test-tube',
    dilution: 'ph:drop',
    hlb: 'ph:scales',
    'litre-kilo': 'ph:arrows-left-right',
    'percent-from-weight': 'ph:percent',
    fragrance: 'ph:flower-lotus',
};

export const fallbackCalculatorIcon = 'ph:calculator';
