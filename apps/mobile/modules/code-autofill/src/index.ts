import { Platform } from 'react-native';
import { requireOptionalNativeModule } from 'expo';

export type CodeSource = 'whatsapp' | 'sms';

interface Subscription {
  remove(): void;
}

interface NativeAutofill {
  requestWhatsAppCode(): boolean;
  startSmsRetriever(): Promise<boolean>;
  stop(): void;
  appHash(): string | null;
  addListener(event: 'onCode', listener: (e: { code: string; source: CodeSource }) => void): Subscription;
}

// Android only; absent in Expo Go, on iOS (the keyboard offers the code) and on the web.
const native = Platform.OS === 'android' ? requireOptionalNativeModule<NativeAutofill>('CodeAutofill') : null;

export const autofillAvailable = native !== null;

/**
 * Start listening for the sign-in code before asking for it. Calls `onCode` once with a
 * 6-digit code from WhatsApp one-tap or the SMS Retriever. Returns a stop function.
 */
export function listenForCode(onCode: (code: string, source: CodeSource) => void): () => void {
  if (!native) return () => undefined;
  let done = false;
  const sub = native.addListener('onCode', ({ code, source }) => {
    if (done || !/^\d{6}$/.test(code)) return;
    done = true;
    onCode(code, source);
  });
  try {
    native.requestWhatsAppCode();
  } catch {
    // WhatsApp not installed: SMS still works
  }
  void native.startSmsRetriever().catch(() => false);
  return () => {
    done = true;
    sub.remove();
    native.stop();
  };
}

/** The hash an administrator sets as ANDROID_SMS_HASH (shown in Settings → About). */
export function appHash(): string | null {
  return native?.appHash() ?? null;
}
