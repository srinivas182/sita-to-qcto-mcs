import { getLocales } from "expo-localization";

export const languages = {
  en: "English",
  zu: "isiZulu",
  xh: "isiXhosa",
  st: "Sesotho",
  tn: "Setswana",
  af: "Afrikaans",
} as const;
export type Locale = keyof typeof languages;

const en = {
  "welcome.title": "Your training, in your pocket",
  "welcome.body":
    "Learn, practise and track your progress — even without data.",
  "welcome.language": "Choose your language",
  "welcome.start": "Get started",
  "tenant.title": "Find your training provider",
  "tenant.body":
    "Enter the code your training provider gave you, or scan their QR code.",
  "tenant.code": "Provider code",
  "tenant.scan": "Scan QR code",
  "tenant.continue": "Continue",
  "tenant.notFound":
    "We could not find that provider. Check the code and try again.",
  "signIn.title": "Sign in",
  "signIn.body":
    "Use your mobile number, SA ID or passport number. We will send a PIN by WhatsApp or SMS.",
  "signIn.identifier": "Mobile, SA ID or passport",
  "signIn.send": "Send PIN",
  "signIn.sent": "If your details match, your PIN is on its way.",
  "signIn.pin": "Enter the 6-digit PIN",
  "signIn.verify": "Sign in",
  "signIn.resend": "Send a new PIN",
  "signIn.paste": "Paste code from WhatsApp",
  "signIn.resendIn": "New PIN in {s}s",
  "signIn.switch": "Not your provider? Change",
  "lock.title": "Protect your app",
  "lock.body":
    "Unlock with your face or fingerprint, or set an app PIN. Your data stays encrypted on this phone.",
  "lock.biometric": "Use {kind}",
  "lock.pin": "Set an app PIN instead",
  "lock.choosePin": "Choose a 6-digit PIN",
  "lock.confirmPin": "Enter it again",
  "lock.pinHint":
    "You will use it to open the app on this phone. Avoid birthdays and repeated digits.",
  "lock.weak": "Choose a PIN that is harder to guess.",
  "lock.mismatch": "The PINs do not match.",
  "unlock.title": "Welcome back",
  "unlock.biometric": "Unlock",
  "unlock.pin": "Enter your app PIN",
  "unlock.wrong": "Wrong PIN. {n} tries left.",
  "tabs.home": "Home",
  "tabs.learn": "Learn",
  "tabs.settings": "Settings",
  "home.greeting": "Hi {name}",
  "home.synced": "Everything is up to date",
  "home.pending": "{n} change(s) waiting to sync",
  "home.offline": "Offline — your work is saved and will sync later",
  "learn.soon":
    "Your courses, simulators and mock tests appear here once you are enrolled.",
  "settings.language": "Language",
  "settings.appearance": "Appearance",
  "settings.light": "Light",
  "settings.dark": "Dark",
  "settings.system": "System",
  "settings.devices": "Signed-in phones",
  "settings.notifications": "Notifications",
  "settings.notificationsBody": "Reminders for classes and assessments, and your results.",
  "settings.notificationsOn": "Turn on notifications",
  "settings.notificationsTest": "Send a test notification",
  "settings.notificationsBlocked": "Notifications are off for this app. Turn them on in your phone's settings.",
  "settings.notificationsSent": "Sent. It should arrive in a few seconds.",
  "settings.about": "About",
  "settings.smsKey": "SMS autofill key",
  "status.readOnlyTitle": "{name} is in read-only mode",
  "status.readOnlyBody": "You can still see your work and keep working offline. New entries stay on this phone and are sent as soon as your training provider's account is active again.",
  "status.suspendedTitle": "{name} is paused",
  "status.suspendedBody": "Your work is safe on this phone. Ask your training provider to reactivate their account; everything is sent when they do.",
  "settings.thisDevice": "This phone",
  "settings.signOutOther": "Sign out",
  "settings.signOut": "Sign out of this phone",
  "settings.provider": "Training provider",
  "notice.revoked":
    "This phone was signed out by you or your training provider. Local data has been removed.",
  "notice.expired": "Please sign in again.",
  "notice.offlineExpired": "Connect to the internet to sign in again.",
  "common.cancel": "Cancel",
  "common.retry": "Try again",
} as const;

export type Key = keyof typeof en;

// Starter translations for the most visible strings (to be reviewed by first-language
// speakers before launch). Anything missing falls back to English.
const others: Record<Exclude<Locale, "en">, Partial<Record<Key, string>>> = {
  zu: {
    "welcome.start": "Qala",
    "signIn.title": "Ngena",
    "signIn.send": "Thumela i-PIN",
    "tabs.home": "Ekhaya",
    "tabs.settings": "Izilungiselelo",
  },
  xh: {
    "welcome.start": "Qalisa",
    "signIn.title": "Ngena",
    "signIn.send": "Thumela i-PIN",
    "tabs.home": "Ekhaya",
    "tabs.settings": "Iisetingi",
  },
  st: {
    "welcome.start": "Qala",
    "signIn.title": "Kena",
    "signIn.send": "Romela PIN",
    "tabs.home": "Lehae",
    "tabs.settings": "Disetting",
  },
  tn: {
    "welcome.start": "Simolola",
    "signIn.title": "Tsena",
    "signIn.send": "Romela PIN",
    "tabs.home": "Gae",
    "tabs.settings": "Dipeakanyo",
  },
  af: {
    "welcome.start": "Begin",
    "signIn.title": "Meld aan",
    "signIn.send": "Stuur PIN",
    "tabs.home": "Tuis",
    "tabs.settings": "Instellings",
  },
};

export function deviceLocale(): Locale {
  const code = getLocales()[0]?.languageCode ?? "en";
  return (code in languages ? code : "en") as Locale;
}

export function translate(
  locale: Locale,
  key: Key,
  vars?: Record<string, string | number>,
): string {
  const template =
    (locale === "en" ? undefined : others[locale][key]) ?? en[key];
  return vars
    ? template.replace(/\{(\w+)\}/g, (_, k: string) => String(vars[k] ?? ""))
    : template;
}
