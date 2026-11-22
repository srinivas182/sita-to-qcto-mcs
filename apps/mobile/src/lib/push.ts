import Constants from 'expo-constants';
import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';
import { api, json } from './api';

// Show notifications while the app is open too.
Notifications.setNotificationHandler({
  handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: false }),
});

export type PushState = 'on' | 'off' | 'blocked' | 'unavailable';

/** Current permission, without asking. */
export async function pushState(): Promise<PushState> {
  if (Platform.OS === 'web') return 'unavailable';
  const { status, canAskAgain } = await Notifications.getPermissionsAsync();
  if (status === 'granted') return 'on';
  return canAskAgain ? 'off' : 'blocked';
}

/**
 * Ask (only when the person chooses to turn notifications on), then register this
 * phone's Expo push token on its device record.
 */
export async function enablePush(): Promise<PushState> {
  if (Platform.OS === 'web') return 'unavailable';
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('default', {
      name: 'Reminders and results',
      importance: Notifications.AndroidImportance.HIGH,
    });
  }
  const { status, canAskAgain } = await Notifications.requestPermissionsAsync();
  if (status !== 'granted') return canAskAgain ? 'off' : 'blocked';

  const projectId = (Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined)?.eas?.projectId;
  if (!projectId) return 'unavailable'; // development builds without an EAS project
  const { data: token } = await Notifications.getExpoPushTokenAsync({ projectId });
  await json(api.POST('/me/push-tokens' as never, { body: { token } } as never));
  return 'on';
}

export async function testPush(): Promise<void> {
  await json(api.POST('/me/push-tokens/test' as never, {} as never));
}
