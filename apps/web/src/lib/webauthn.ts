/**
 * WebAuthn helpers: the API speaks JSON (base64url), the browser speaks ArrayBuffers.
 */
type Json = Record<string, unknown>;

export function b64urlToBuffer(value: string): ArrayBuffer {
  const pad = '='.repeat((4 - (value.length % 4)) % 4);
  const bin = atob((value + pad).replace(/-/g, '+').replace(/_/g, '/'));
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return bytes.buffer;
}

export function bufferToB64url(buffer: ArrayBuffer | null): string | null {
  if (!buffer) return null;
  const bytes = new Uint8Array(buffer);
  let bin = '';
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export const passkeysSupported = (): boolean =>
  typeof window !== 'undefined' && 'PublicKeyCredential' in window && !!navigator.credentials;

export async function createPasskey(options: Json): Promise<Json> {
  const user = options.user as Json;
  const exclude = (options.excludeCredentials as Json[] | undefined) ?? [];
  const credential = (await navigator.credentials.create({
    publicKey: {
      ...(options as unknown as PublicKeyCredentialCreationOptions),
      challenge: b64urlToBuffer(options.challenge as string),
      user: { ...(user as unknown as PublicKeyCredentialUserEntity), id: b64urlToBuffer(user.id as string) },
      excludeCredentials: exclude.map((c) => ({
        ...(c as unknown as PublicKeyCredentialDescriptor),
        id: b64urlToBuffer(c.id as string),
      })),
    },
  })) as PublicKeyCredential | null;
  if (!credential) throw new Error('cancelled');

  const response = credential.response as AuthenticatorAttestationResponse;
  return {
    id: credential.id,
    rawId: bufferToB64url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: bufferToB64url(response.clientDataJSON),
      attestationObject: bufferToB64url(response.attestationObject),
      transports: response.getTransports?.() ?? [],
    },
  };
}

export async function getPasskey(options: Json, mediation?: CredentialMediationRequirement): Promise<Json> {
  const allow = (options.allowCredentials as Json[] | undefined) ?? [];
  const credential = (await navigator.credentials.get({
    mediation,
    publicKey: {
      ...(options as unknown as PublicKeyCredentialRequestOptions),
      challenge: b64urlToBuffer(options.challenge as string),
      allowCredentials: allow.map((c) => ({
        ...(c as unknown as PublicKeyCredentialDescriptor),
        id: b64urlToBuffer(c.id as string),
      })),
    },
  })) as PublicKeyCredential | null;
  if (!credential) throw new Error('cancelled');

  const response = credential.response as AuthenticatorAssertionResponse;
  return {
    id: credential.id,
    rawId: bufferToB64url(credential.rawId),
    type: credential.type,
    response: {
      clientDataJSON: bufferToB64url(response.clientDataJSON),
      authenticatorData: bufferToB64url(response.authenticatorData),
      signature: bufferToB64url(response.signature),
      userHandle: bufferToB64url(response.userHandle),
    },
  };
}
