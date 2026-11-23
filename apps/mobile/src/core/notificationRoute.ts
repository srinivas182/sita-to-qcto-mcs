/**
 * Where a tapped notification leads. Only known types with a well-formed id: a
 * notification's data must never steer the app to an arbitrary screen.
 */
export function routeFor(data: Record<string, unknown> | undefined): string | null {
  if (data?.type === 'agreement' && typeof data.signer_id === 'string' && /^[0-9A-Za-z]{26}$/.test(data.signer_id)) {
    return `/agreements/${data.signer_id}`;
  }
  if (data?.type === 'offer' && typeof data.offer_id === 'string' && /^[0-9A-Za-z]{26}$/.test(data.offer_id)) {
    return `/offers/${data.offer_id}`;
  }
  return null;
}
