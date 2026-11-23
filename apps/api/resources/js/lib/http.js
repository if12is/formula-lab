/**
 * JSON calls from a page (inline create of a substance, a source or a draft material). Inertia handles the
 * redirecting forms; these endpoints answer with the created option instead, so a modal can stay on its page.
 */
const FIRST_ERROR = (messages) => (Array.isArray(messages) ? messages[0] : messages);

/** Laravel's 422 body { errors: { field: [message] } } as { field: message }. */
export function firstErrors(errors) {
    return Object.fromEntries(Object.entries(errors ?? {}).map(([field, messages]) => [field, FIRST_ERROR(messages)]));
}

/**
 * POSTs JSON and returns { ok: true, data } or, for a validation failure, { ok: false, errors }.
 * Any other failure (network, 500) throws so the caller shows its generic error.
 */
export async function postJson(url, payload) {
    try {
        const response = await window.axios.post(url, payload, { headers: { Accept: 'application/json' } });

        return { ok: true, data: response.data };
    } catch (error) {
        if (error.response?.status === 422) {
            return { ok: false, errors: firstErrors(error.response.data.errors) };
        }

        throw error;
    }
}

/** GETs JSON and returns its body. A failure (network, 503 from the engine) throws so the caller shows its generic error. */
export async function getJson(url) {
    const response = await window.axios.get(url, { headers: { Accept: 'application/json' } });

    return response.data;
}
