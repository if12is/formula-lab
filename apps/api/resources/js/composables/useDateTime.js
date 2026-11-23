import { useI18n } from './useI18n';

/**
 * The one way a timestamp is printed: the locale's medium date and short time, Latin digits.
 *
 *   const { when, day } = useDateTime();
 *   when('2026-11-21T10:30:00+00:00')   // "21 Nov 2026, 10:30"
 */
export function useDateTime() {
    const { locale } = useI18n();

    const format = (options) => (iso) => new Intl.DateTimeFormat(locale.value, { ...options, numberingSystem: 'latn' }).format(new Date(iso));

    return {
        when: format({ dateStyle: 'medium', timeStyle: 'short' }),
        day: format({ dateStyle: 'medium' }),
        time: format({ timeStyle: 'short' }),
    };
}
