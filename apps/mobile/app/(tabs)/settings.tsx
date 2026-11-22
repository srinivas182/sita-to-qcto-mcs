import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import Constants from 'expo-constants';
import { appHash } from '../../modules/code-autofill/src';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { Button, Card, Screen, Title } from '@/components/ui';
import { api, json } from '@/lib/api';
import { useApp, type ThemeChoice } from '@/lib/app';
import { languages, type Locale } from '@/lib/i18n';
import { enablePush, pushState, testPush } from '@/lib/push';
import { errorMessage } from '@/lib/api';

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
  const push = useQuery({ queryKey: ['push-state'], queryFn: pushState });
  const turnOn = useMutation({ mutationFn: enablePush, onSuccess: (s) => qc.setQueryData(['push-state'], s) });
  const test = useMutation({ mutationFn: testPush });
  const hash = appHash();
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

        {push.data && push.data !== 'unavailable' ? (
          <Card className="gap-3">
            <Text className="font-semibold text-section text-ink dark:text-night-ink">{t('settings.notifications')}</Text>
            <Text className="font-sans text-body text-muted dark:text-night-muted">
              {push.data === 'blocked' ? t('settings.notificationsBlocked') : t('settings.notificationsBody')}
            </Text>
            {push.data === 'off' ? <Button title={t('settings.notificationsOn')} loading={turnOn.isPending} onPress={() => turnOn.mutate()} /> : null}
            {push.data === 'on' ? (
              <Button title={t('settings.notificationsTest')} variant="secondary" loading={test.isPending} disabled={!online} onPress={() => test.mutate()} />
            ) : null}
            {test.isSuccess ? <Text className="font-sans text-small text-success">{t('settings.notificationsSent')}</Text> : null}
            {test.error || turnOn.error ? <Text className="font-sans text-small text-danger">{errorMessage(test.error ?? turnOn.error)}</Text> : null}
          </Card>
        ) : null}

        <Card className="gap-3">
          <Text className="font-semibold text-section text-ink dark:text-night-ink">{t('settings.provider')}</Text>
          <Text className="font-sans text-body text-muted dark:text-night-muted">{tenant?.name}</Text>
          <Button title={t('signIn.switch')} variant="ghost" onPress={() => void changeTenant()} />
        </Card>

        <Card className="gap-1">
          <Text className="font-semibold text-section text-ink dark:text-night-ink">{t('settings.about')}</Text>
          <Text className="font-sans text-small text-muted dark:text-night-muted">v{Constants.expoConfig?.version ?? ''}</Text>
          {hash ? (
            <Text selectable className="font-sans text-small text-muted dark:text-night-muted">
              {t('settings.smsKey')}: {hash}
            </Text>
          ) : null}
        </Card>

        <Button title={t('settings.signOut')} variant="danger" onPress={() => void signOut()} />
      </ScrollView>
    </Screen>
  );
}
