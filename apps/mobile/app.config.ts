import type { ExpoConfig } from 'expo/config';

/**
 * One app for every training provider (white-label by TP code / QR at first launch).
 * API_BASE_URL points at the platform API host, e.g. https://api.skillsecosystem.co.za/v1
 */
const config: ExpoConfig = {
  name: 'Skills Ecosystem',
  slug: 'skills-ecosystem',
  scheme: 'skills',
  version: '0.1.0',
  orientation: 'portrait',
  userInterfaceStyle: 'automatic',
  icon: './assets/icon.png',
  ios: {
    bundleIdentifier: 'za.co.skillsecosystem.app',
    supportsTablet: true,
    infoPlist: {
      NSFaceIDUsageDescription: 'Unlock the app with Face ID.',
      NSCameraUsageDescription: 'Scan your training provider’s QR code and capture evidence.',
      ITSAppUsesNonExemptEncryption: false,
    },
  },
  android: {
    package: 'za.co.skillsecosystem.app',
    adaptiveIcon: { foregroundImage: './assets/adaptive-icon.png', backgroundColor: '#4338ca' },
    permissions: ['android.permission.CAMERA', 'android.permission.USE_BIOMETRIC'],
    blockedPermissions: ['android.permission.RECORD_AUDIO'],
  },
  plugins: [
    'expo-router',
    ['expo-splash-screen', { image: './assets/splash.png', imageWidth: 160, resizeMode: 'contain', backgroundColor: '#f6f7fb', dark: { backgroundColor: '#0f1020' } }],
    'expo-secure-store',
    'expo-font',
    ['expo-sqlite', { useSQLCipher: true }],
    ['expo-local-authentication', { faceIDPermission: 'Unlock the app with Face ID.' }],
    ['expo-camera', { cameraPermission: 'Scan your training provider’s QR code and capture evidence.' }],
    ['expo-notifications', { icon: './assets/icon.png', color: '#4338ca' }],
    ['expo-location', { locationWhenInUsePermission: 'Check you in to class: only your distance from the centre is kept.', isAndroidBackgroundLocationEnabled: false }],
    ['expo-video', { supportsBackgroundPlayback: false, supportsPictureInPicture: false }],
    ['expo-build-properties', { android: { minSdkVersion: 26 }, ios: { deploymentTarget: '16.4' } }],
  ],
  web: { bundler: 'metro', output: 'single' },
  experiments: { typedRoutes: true },
  extra: {
    apiBaseUrl: process.env.API_BASE_URL ?? 'https://api.platform.test/v1',
    eas: { projectId: process.env.EAS_PROJECT_ID },
  },
  updates: { url: process.env.EAS_UPDATE_URL, fallbackToCacheTimeout: 0 },
  runtimeVersion: { policy: 'appVersion' },
};

export default config;
