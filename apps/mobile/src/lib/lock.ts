import * as LocalAuthentication from 'expo-local-authentication';
import { hashPin, PIN_ATTEMPTS, verifyPin } from '@/core/lock';
import { randomHex } from './random';
import { secure } from './secure';

export async function biometricKind(): Promise<string | null> {
  if (!(await LocalAuthentication.hasHardwareAsync()) || !(await LocalAuthentication.isEnrolledAsync())) return null;
  const types = await LocalAuthentication.supportedAuthenticationTypesAsync();
  if (types.includes(LocalAuthentication.AuthenticationType.FACIAL_RECOGNITION)) return 'Face ID';
  if (types.includes(LocalAuthentication.AuthenticationType.FINGERPRINT)) return 'fingerprint';
  return 'biometrics';
}

export async function promptBiometric(reason: string): Promise<boolean> {
  const result = await LocalAuthentication.authenticateAsync({ promptMessage: reason, cancelLabel: 'Cancel', disableDeviceFallback: false });
  return result.success;
}

export async function setPin(pin: string): Promise<void> {
  const salt = randomHex(16);
  // Hashing is deliberately slow (PBKDF2); run it after the UI has updated.
  await new Promise((r) => setTimeout(r, 0));
  await secure.set('pin_salt', salt);
  await secure.set('pin_hash', hashPin(pin, salt));
  await secure.set('pin_attempts', '0');
}

/** @returns remaining attempts after a failure, or 'ok'. 0 remaining means sign out. */
export async function checkPin(pin: string): Promise<'ok' | number> {
  const [salt, hash, attempts] = await Promise.all([secure.get('pin_salt'), secure.get('pin_hash'), secure.get('pin_attempts')]);
  if (!salt || !hash) return 0;
  await new Promise((r) => setTimeout(r, 0));

  if (verifyPin(pin, salt, hash)) {
    await secure.set('pin_attempts', '0');
    return 'ok';
  }

  const used = Number(attempts ?? '0') + 1;
  await secure.set('pin_attempts', String(used));
  return Math.max(0, PIN_ATTEMPTS - used);
}
