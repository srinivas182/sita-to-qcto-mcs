import Feather from '@expo/vector-icons/Feather';
import { Tabs } from 'expo-router';
import { useColorScheme } from 'nativewind';
import { useApp } from '@/lib/app';

export default function TabsLayout() {
  const { t } = useApp();
  const { colorScheme } = useColorScheme();
  const dark = colorScheme === 'dark';

  return (
    <Tabs
      screenOptions={{
        headerShown: false,
        tabBarActiveTintColor: dark ? '#a5b4fc' : '#4338ca',
        tabBarInactiveTintColor: dark ? '#a3a8c3' : '#8a90a3',
        tabBarStyle: { backgroundColor: dark ? '#181a2e' : '#ffffff', borderTopColor: dark ? '#272a44' : '#e6e8f0', height: 64, paddingTop: 6 },
        tabBarLabelStyle: { fontFamily: 'Sora_500Medium', fontSize: 11, marginBottom: 6 },
      }}
    >
      <Tabs.Screen name="index" options={{ title: t('tabs.home'), tabBarIcon: ({ color }) => <Feather name="home" size={21} color={color as string} /> }} />
      <Tabs.Screen name="learn" options={{ title: t('tabs.learn'), tabBarIcon: ({ color }) => <Feather name="book-open" size={21} color={color as string} /> }} />
      <Tabs.Screen name="settings" options={{ title: t('tabs.settings'), tabBarIcon: ({ color }) => <Feather name="settings" size={21} color={color as string} /> }} />
    </Tabs>
  );
}
