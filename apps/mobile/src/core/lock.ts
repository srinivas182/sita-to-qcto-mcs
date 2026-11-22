/**
 * App lock: biometrics when available, otherwise (or as fallback) a 6-digit app PIN.
 * The PIN is stored only as a salted PBKDF2-SHA256 hash; 5 wrong PINs sign the person
 * out (the server session ends and local data is wiped).
 */
import { pbkdf2 } from '@noble/hashes/pbkdf2.js';
import { sha256 } from '@noble/hashes/sha2.js';
import { bytesToHex, utf8ToBytes } from '@noble/hashes/utils.js';

export const PIN_ATTEMPTS = 5;
export const PIN_ITERATIONS = 60_000;

/** Lock after this long in the background. */
export const RELOCK_AFTER_MS = 5 * 60_000;

export function hashPin(pin: string, salt: string, iterations = PIN_ITERATIONS): string {
  return bytesToHex(pbkdf2(sha256, utf8ToBytes(pin), utf8ToBytes(salt), { c: iterations, dkLen: 32 }));
}

export function verifyPin(pin: string, salt: string, expected: string, iterations = PIN_ITERATIONS): boolean {
  const actual = hashPin(pin, salt, iterations);
  let diff = actual.length ^ expected.length;
  for (let i = 0; i < Math.min(actual.length, expected.length); i++) diff |= actual.charCodeAt(i) ^ expected.charCodeAt(i);
  return diff === 0;
}

/** Six digits, not all the same, not an obvious sequence. */
export function isValidPin(pin: string): boolean {
  if (!/^\d{6}$/.test(pin) || /^(\d)\1{5}$/.test(pin)) return false;
  const asc = '01234567890';
  const desc = '09876543210';
  return !asc.includes(pin) && !desc.includes(pin);
}
