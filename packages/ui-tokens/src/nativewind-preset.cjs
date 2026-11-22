/**
 * Tailwind 3 preset for the mobile app (NativeWind). Mirrors src/index.ts; the test
 * suite fails if the two ever drift apart.
 */
const colors = {
  primary: { 50: '#eef0fb', 100: '#e0e7ff', 200: '#c7d2fe', 300: '#a5b4fc', 400: '#818cf8', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca', 800: '#3730a3', 900: '#312e81', 950: '#1e1b4b' },
  canvas: '#f6f7fb',
  surface: '#ffffff',
  'surface-muted': '#f9fafc',
  line: '#e6e8f0',
  'line-soft': '#eff1f6',
  ink: '#14182b',
  muted: '#5f6579',
  faint: '#6b7186',
  success: '#166c52',
  'success-bg': '#e6f4ee',
  warning: '#8a5a0b',
  'warning-bg': '#fbf1dc',
  danger: '#b5433e',
  'danger-bg': '#f9e7e5',
  info: '#2d68a8',
  'info-bg': '#e7eef8',
  // Dark mode surfaces (Settings → Appearance).
  night: { canvas: '#0f1020', surface: '#181a2e', line: '#272a44', ink: '#eceefb', muted: '#a3a8c3' },
};

module.exports = {
  theme: {
    extend: {
      colors,
      fontFamily: {
        sans: ['Sora_400Regular'],
        medium: ['Sora_500Medium'],
        semibold: ['Sora_600SemiBold'],
        bold: ['Sora_700Bold'],
      },
      fontSize: {
        // Phone-standard sizes from the locked mock-ups (360 px frames).
        title: ['16px', { lineHeight: '22px' }],
        section: ['13px', { lineHeight: '18px' }],
        body: ['13px', { lineHeight: '19px' }],
        small: ['12px', { lineHeight: '17px' }],
        caption: ['11px', { lineHeight: '15px' }],
        display: ['22px', { lineHeight: '28px' }],
      },
      borderRadius: { sm: '8px', md: '10px', lg: '12px', xl: '16px', '2xl': '20px' },
    },
  },
};
