import { useFocusEffect, useRouter } from 'expo-router';
import { useCallback, useState } from 'react';
import { RefreshControl, ScrollView, Text, View } from 'react-native';
import { BackHeader, Pill } from '@/components/BackHeader';
import { Body, Button, Card, Notice, Screen, Title } from '@/components/ui';
import { useApp } from '@/lib/app';
import { discardWaiting, useMyLogbook, waiting } from '@/lib/logbook';

const tone = { submitted: 'warning', signed: 'success', returned: 'danger', corrected: 'neutral' } as const;

/** The learner's logbook: where they are placed, hours per module, waiting and recent entries. */
export default function LogbookScreen() {
  const { t, online, syncNow } = useApp();
  const router = useRouter();
  const q = useMyLogbook(online);
  const [queue, setQueue] = useState(waiting());
  useFocusEffect(useCallback(() => setQueue(waiting()), []));
  const d = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
  const lb = q.data;
  const placement = lb?.placements[0];
  const activity = (moduleId: string, key: string) => lb?.modules.find((m) => m.id === moduleId)?.activities.find((a) => a.key === key)?.title ?? key;
  const refresh = async () => {
    await syncNow();
    setQueue(waiting());
    await q.refetch();
  };

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-10 pt-4" refreshControl={<RefreshControl refreshing={q.isRefetching} onRefresh={() => void refresh()} />}>
        <BackHeader />
        <Title>{t('logbook.title')}</Title>
        <Body>{t('logbook.body')}</Body>
        {q.isError && !lb ? <Notice tone="danger">{t('home.offline')}</Notice> : null}
        {lb && !placement ? <Notice>{t('logbook.none')}</Notice> : null}
        {lb && placement && lb.modules.length === 0 ? <Notice>{t('logbook.notSetUp')}</Notice> : null}
        {placement ? (
          <Card className="gap-1">
            <Text className="font-semibold text-body text-ink dark:text-night-ink">{placement.cohort}</Text>
            <Text className="font-sans text-small text-muted dark:text-night-muted">
              {t('logbook.placement', { site: placement.site ?? '', mentor: placement.mentor ?? '—' })}
            </Text>
          </Card>
        ) : null}
        {lb?.hours.map((h) => {
          const pct = h.hours_needed ? Math.min(100, Math.round((h.hours / h.hours_needed) * 100)) : 0;
          return (
            <Card key={h.module_id} className="gap-2">
              <Text className="font-medium text-body text-ink dark:text-night-ink">{h.code} {h.title}</Text>
              <View className="h-2 overflow-hidden rounded-full bg-line-soft" accessibilityRole="progressbar" accessibilityValue={{ min: 0, max: 100, now: pct }}>
                <View className="h-2 rounded-full bg-primary-600" style={{ width: `${pct}%` }} />
              </View>
              <Text className="font-sans text-small text-muted dark:text-night-muted">
                {t('logbook.hours', { hours: h.hours, needed: h.hours_needed ?? '—', signed: h.signed_hours })}
              </Text>
            </Card>
          );
        })}
        {placement && (lb?.modules.length ?? 0) > 0 ? <Button title={t('logbook.new')} onPress={() => router.push('/logbook/new' as never)} /> : null}

        {queue.length > 0 ? (
          <Card className="gap-2">
            <Text className="font-medium text-body text-ink dark:text-night-ink">{t('logbook.waiting', { n: queue.filter((x) => x.status === 'pending').length })}</Text>
            {queue.map((w) => (
              <View key={w.clientId} className="gap-1 border-b border-line py-2">
                <Text className="font-sans text-small text-ink dark:text-night-ink">
                  {d(String(w.body.work_date))} · {activity(String(w.body.module_id), String(w.body.activity_key))} · {Math.round((Number(w.body.minutes) / 60) * 10) / 10} h
                </Text>
                {w.status === 'conflict' ? (
                  <>
                    <Text className="font-sans text-small text-danger">{t('logbook.refused', { reason: w.error ?? '' })}</Text>
                    <View className="items-start">
                      <Button title={t('logbook.discard')} variant="secondary" onPress={() => { discardWaiting(w.clientId); setQueue(waiting()); }} />
                    </View>
                  </>
                ) : null}
              </View>
            ))}
          </Card>
        ) : null}

        {lb && lb.entries.length > 0 ? (
          <Card className="gap-2">
            <Text className="font-medium text-body text-ink dark:text-night-ink">{t('logbook.recent')}</Text>
            {lb.entries.filter((e) => e.status !== 'corrected').slice(0, 30).map((e) => (
              <View key={e.id} className="gap-1 border-b border-line py-2">
                <View className="flex-row items-center justify-between gap-2">
                  <Text className="flex-1 font-sans text-small text-ink dark:text-night-ink">
                    {d(e.work_date)} · {activity(e.module_id, e.activity_key)} · {Math.round((e.minutes / 60) * 10) / 10} h
                  </Text>
                  <Pill text={t(`logbook.status.${e.status}` as never)} tone={tone[e.status]} />
                </View>
                {e.note ? <Text className="font-sans text-small text-muted dark:text-night-muted">{e.note}</Text> : null}
                {e.status === 'returned' ? (
                  <>
                    {e.comment ? <Text className="font-sans text-small text-danger">{e.comment}</Text> : null}
                    <View className="items-start">
                      <Button title={t('logbook.fix')} variant="secondary" onPress={() => router.push({ pathname: '/logbook/new', params: { replaces: e.id } } as never)} />
                    </View>
                  </>
                ) : null}
              </View>
            ))}
          </Card>
        ) : null}
      </ScrollView>
    </Screen>
  );
}
