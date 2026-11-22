import * as Crypto from 'expo-crypto';

export const randomBytes = (n: number): Uint8Array => Crypto.getRandomBytes(n);

export function randomId(): string {
  return Crypto.randomUUID();
}

export function randomHex(n: number): string {
  return Array.from(randomBytes(n), (b) => b.toString(16).padStart(2, '0')).join('');
}
