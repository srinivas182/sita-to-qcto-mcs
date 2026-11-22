import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { Button, Card, Screen, Title } from '@/components/ui';
import { api, json } from '@/lib/api';
import { useApp, type ThemeChoice } from '@/lib/app';
import { languages, type Locale } from '@/lib/i18n';

interface Device { id: string; name: string; platform: string; last_seen_at: string | null; current: boolean }

function Choice<T extends string>({ options, value, onChange }: { options: Array<[T, string]>; value: T; onChange: (v: T) => void }) {
  return (
    <View className="flex-row flex-wrap gap-2">
      {options.map(([key, label]) => (
        <Pressable key={key} onPress={() => onChange(key)} accessibilityRole="radio" accessibilityState={{ selected: value === key }}
          className={`rounded-full border px-3.5 py-2 ${value === key ? 'border-primary-700 bg-primary-50 dark:border-primary-400 dark:bg-primary-900' : 'border-line dark:border-night-line'}`}>
          <Text className={`font-medium text-small ${value === key ? 'text-primary-800 dark:text-primary-100' : 'text-ink dark:text-night-ink'}`}>{label}</Text>
        </Pressable>
      ))}
    </View>
  );
}

export default function Settings() {
  const { t, tenant, locale, setLocale, theme, setTheme, signOut, changeTenant, online } = useApp();
  const qc = useQueryClient();
  const devices = useQuery({ queryKey: ['devices'], queryFn: () => json<{ data: Device[] }>(api.GET('/auth/devices')).then((r) => r.data), enabled: online });

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-10 pt-4">
        <Title>{t('tabs.settings')}</Title>

        <Card className="gap-3">
          <Text className="font-semibold text-section text-ink dark:text-night-ink">{t('settings.language')}</Text>
          <Choice<Locale> options={Object.entries(languages) as Array<[Locale, string]>} value={locale} onChange={(l) => void setLocale(l)} />
        </Card>

        <Card className="gap-3">
          <Text className="font-semibold text-section text-ink dark:text-night-ink">{t('settings.appearance')}</Text>
          <Choice<ThemeChoice> options={[['system', t('settings.system')], ['light', t('settings.light')], ['dark', t('settings.dark')]]} value={theme} onChange={(v) => void setTheme(v)} />
        </Card>

        <Card className="gap-3">
          <Text className="font-semibold text-section text-ink dark:text-night-ink">{t('settings.devices')}</Text>
          {(devices.data ?? []).map((d) => (
            <View key={d.id} className="flex-row items-center gap-3">
              <View className="flex-1">
                <Text className="font-medium text-body text-ink dark:text-night-ink">{d.name}</Text>
                <Text className="font-sans text-caption text-muted dark:text-night-muted">{d.current ? t('settings.thisDevice') : d.last_seen_at ? new Date(d.last_seen_at).toLocaleString() : ''}</Text>
              </View>
              {!d.current ? (
                <Button title={t('settings.signOutOther')} variant="secondary" onPress={() => void api.DELETE('/auth/devices/{device}', { params: { path: { device: d.id } } }).then(() => qc.invalidateQueries({ queryKey: ['devices'] }))} />
              ) : null}
            </View>
          ))}
        </Card>

        <Card className="gap-3">
          <Text className="font-semibold text-section text-ink dark:text-night-ink">{t('settings.provider')}</Text>
          <Text className="font-sans text-body text-muted dark:text-night-muted">{tenant?.name}</Text>
          <Button title={t('signIn.switch')} variant="ghost" onPress={() => void changeTenant()} />
        </Card>

        <Button title={t('settings.signOut')} variant="danger" onPress={() => void signOut()} />
      </ScrollView>
    </Screen>
  );
}
