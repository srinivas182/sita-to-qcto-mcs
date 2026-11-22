/**
 * Design tokens for the Skills Ecosystem (locked theme C: light, indigo primary).
 * Shared by the web app (Tailwind) and the mobile app (NativeWind) so both always match.
 */
export const colors = {
  primary: { 50: '#eef0fb', 100: '#e0e7ff', 200: '#c7d2fe', 300: '#a5b4fc', 400: '#818cf8', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca', 800: '#3730a3', 900: '#312e81', 950: '#1e1b4b' },
  canvas: '#f6f7fb',
  surface: '#ffffff',
  surfaceMuted: '#f9fafc',
  line: '#e6e8f0',
  lineSoft: '#eff1f6',
  text: '#14182b',
  textMuted: '#5f6579',
  textFaint: '#6b7186',
  success: { fg: '#166c52', bg: '#e6f4ee' },
  warning: { fg: '#8a5a0b', bg: '#fbf1dc' },
  danger: { fg: '#b5433e', bg: '#f9e7e5' },
  info: { fg: '#2d68a8', bg: '#e7eef8' },
  accent: { teal: '#0d9488', amber: '#d97706', violet: '#7c3aed', rose: '#e11d48' },
  site: { yellow: '#ffc61a', ink: '#1e1b4b', canvas: '#f4f2ec' },
} as const;

export const typography = {
  fontFamily: { sans: 'Inter', display: 'Fraunces', mobile: 'Sora' },
  /** SaaS-standard sizes in px: page title, card title, body, small, caption. */
  web: { pageTitle: 22, cardTitle: 14, kpi: 24, body: 13.5, small: 12.5, caption: 11 },
  /** iOS/Android-standard sizes in px on real phone widths. */
  mobile: { screenTitle: 16, sectionTitle: 13, body: 13, small: 12, caption: 11, button: 13 },
} as const;

export const radius = { sm: 8, md: 10, lg: 12, xl: 16, '2xl': 20, full: 9999 } as const;
export const spacing = { 1: 4, 2: 8, 3: 12, 4: 16, 5: 20, 6: 24, 8: 32, 10: 40 } as const;

/** WCAG relative luminance contrast ratio between two hex colours. */
export function contrastRatio(a: string, b: string): number {
  const lum = (hex: string): number => {
    const n = parseInt(hex.replace('#', ''), 16);
    const ch = [(n >> 16) & 255, (n >> 8) & 255, n & 255].map((v) => {
      const s = v / 255;
      return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * ch[0]! + 0.7152 * ch[1]! + 0.0722 * ch[2]!;
  };
  const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x) as [number, number];
  return (hi + 0.05) / (lo + 0.05);
}
