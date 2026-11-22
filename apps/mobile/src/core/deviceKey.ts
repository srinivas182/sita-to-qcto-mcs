/**
 * Device key (Ed25519). The private key never leaves the phone: it is generated here,
 * stored in the Keychain / Keystore, and used to sign token refreshes.
 */
import * as ed from '@noble/ed25519';
import { sha256, sha512 } from '@noble/hashes/sha2.js';
import { bytesToHex, utf8ToBytes } from '@noble/hashes/utils.js';

ed.hashes.sha512 = sha512;

export interface DeviceKeyPair {
  secretKey: Uint8Array;
  publicKey: Uint8Array;
}

export function generateDeviceKey(randomBytes: (n: number) => Uint8Array): DeviceKeyPair {
  const secretKey = randomBytes(32);
  return { secretKey, publicKey: ed.getPublicKey(secretKey) };
}

export function publicKeyFor(secretKey: Uint8Array): Uint8Array {
  return ed.getPublicKey(secretKey);
}

export function signMessage(secretKey: Uint8Array, message: string): Uint8Array {
  return ed.sign(utf8ToBytes(message), secretKey);
}

export function sha256Hex(value: string): string {
  return bytesToHex(sha256(utf8ToBytes(value)));
}

export function toBase64(bytes: Uint8Array): string {
  let bin = '';
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin);
}

export function fromBase64(value: string): Uint8Array {
  const bin = atob(value);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}
