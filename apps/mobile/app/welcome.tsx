import { Pressable, Text, View } from 'react-native';
import { Body, Button, Screen, Title } from '@/components/ui';
import { useApp } from '@/lib/app';
import { languages, type Locale } from '@/lib/i18n';

export default function Welcome() {
  const { t, locale, setLocale, continueFromWelcome } = useApp();

  return (
    <Screen>
      <View className="flex-1 justify-center gap-3">
        <View className="mb-4 h-16 w-16 items-center justify-center rounded-3xl bg-primary-700">
          <Text className="font-bold text-[28px] text-white">S</Text>
        </View>
        <Title>{t('welcome.title')}</Title>
        <Body>{t('welcome.body')}</Body>
      </View>

      <Text className="mb-3 font-semibold text-section text-ink dark:text-night-ink">{t('welcome.language')}</Text>
      <View className="mb-6 flex-row flex-wrap gap-2">
        {(Object.entries(languages) as Array<[Locale, string]>).map(([code, label]) => (
          <Pressable
            key={code}
            onPress={() => void setLocale(code)}
            accessibilityRole="radio"
            accessibilityState={{ selected: locale === code }}
            className={`rounded-full border px-4 py-2.5 ${locale === code ? 'border-primary-700 bg-primary-50 dark:border-primary-400 dark:bg-primary-900' : 'border-line bg-surface dark:border-night-line dark:bg-night-surface'}`}
          >
            <Text className={`font-medium text-small ${locale === code ? 'text-primary-800 dark:text-primary-100' : 'text-ink dark:text-night-ink'}`}>{label}</Text>
          </Pressable>
        ))}
      </View>
      <Button title={t('welcome.start')} onPress={continueFromWelcome} />
    </Screen>
  );
}
