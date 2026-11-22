import Constants from 'expo-constants';

/** Platform API, e.g. https://api.skillsecosystem.co.za/v1 (set per EAS build profile). */
export const API_BASE_URL: string = (Constants.expoConfig?.extra?.apiBaseUrl as string | undefined) ?? 'https://api.platform.test/v1';
