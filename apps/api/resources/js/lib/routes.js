import { route } from 'ziggy-js';

/**
 * A named Laravel route as a path ("/materials/3/prices"), not a full address. The kit treats a full address as
 * internal only when it starts with APP_URL, so links and visits written as paths keep working whatever address
 * the app is served from (a dev server on another port, a preview URL).
 *
 *   path('materials.show', { material: id, tab: 'prices' })   ->  /materials/<id>?tab=prices
 */
export const path = (name, params) => route(name, params, false);
