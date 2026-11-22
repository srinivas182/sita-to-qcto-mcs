import '../global.css';
import { Sora_400Regular, Sora_500Medium, Sora_600SemiBold, Sora_700Bold, useFonts } from '@expo-google-fonts/sora';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Stack, useRouter, useSegments } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useColorScheme } from 'nativewind';
import { useEffect } from 'react';
import { GestureHandlerRootView } from 'react-native-gesture-handler';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { AppProvider, useApp, type Phase } from '@/lib/app';

void SplashScreen.preventAutoHideAsync();

const queryClient = new QueryClient({
  defaultOptions: { queries: { staleTime: 60_000, retry: 1, networkMode: 'offlineFirst' } },
});

/** Where each phase lives; the gate keeps the person on the right screen. */
const home: Record<Exclude<Phase, 'loading'>, { route: string; allowed: string[] }> = {
  welcome: { route: '/welcome', allowed: ['welcome'] },
  tenant: { route: '/tenant', allowed: ['tenant', 'scan'] },
  'signed-out': { route: '/sign-in', allowed: ['sign-in'] },
  'setup-lock': { route: '/setup-lock', allowed: ['setup-lock'] },
  locked: { route: '/unlock', allowed: ['unlock'] },
  ready: { route: '/', allowed: ['(tabs)'] },
};

function Gate() {
  const { phase } = useApp();
  const segments = useSegments();
  const router = useRouter();
  const { colorScheme } = useColorScheme();

  useEffect(() => {
    if (phase === 'loading') return;
    void SplashScreen.hideAsync();
    const target = home[phase];
    if (!target.allowed.includes(segments[0] ?? '')) router.replace(target.route as never);
  }, [phase, segments, router]);

  return (
    <>
      <StatusBar style={colorScheme === 'dark' ? 'light' : 'dark'} />
      <Stack screenOptions={{ headerShown: false, animation: 'fade', contentStyle: { backgroundColor: colorScheme === 'dark' ? '#0f1020' : '#f6f7fb' } }}>
        <Stack.Screen name="scan" options={{ presentation: 'modal', animation: 'slide_from_bottom' }} />
      </Stack>
    </>
  );
}

export default function RootLayout() {
  const [fontsLoaded] = useFonts({ Sora_400Regular, Sora_500Medium, Sora_600SemiBold, Sora_700Bold });
  if (!fontsLoaded) return null;

  return (
    <GestureHandlerRootView style={{ flex: 1 }}>
      <SafeAreaProvider>
        <QueryClientProvider client={queryClient}>
          <AppProvider>
            <Gate />
          </AppProvider>
        </QueryClientProvider>
      </SafeAreaProvider>
    </GestureHandlerRootView>
  );
}
