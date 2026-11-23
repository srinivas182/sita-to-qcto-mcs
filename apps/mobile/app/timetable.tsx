import { RefreshControl, ScrollView, Text, View } from 'react-native';
import { BackHeader, Pill } from '@/components/BackHeader';
import { Body, Card, Screen, Title } from '@/components/ui';
import { useApp } from '@/lib/app';
import { useMyTimetable } from '@/lib/delivery';

const day = (iso: string) => new Date(`${iso.slice(0, 10)}T12:00:00Z`).toLocaleDateString('en-ZA', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' });

/** The next two weeks of classes; cancelled ones say why. */
export default function Timetable() {
  const { t, online } = useApp();
  const q = useMyTimetable(online);
  const days = [...new Set((q.data ?? []).map((s) => s.starts_at.slice(0, 10)))];
  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" refreshControl={<RefreshControl refreshing={q.isRefetching} onRefresh={() => void q.refetch()} />}>
        <BackHeader />
        <Title>{t('timetable.title')}</Title>
        {q.data && !q.data.length ? <Body>{t('timetable.none')}</Body> : null}
        {days.map((d) => (
          <View key={d} className="gap-2">
            <Text className="font-semibold text-small text-muted">{day(d)}</Text>
            {(q.data ?? []).filter((s) => s.starts_at.startsWith(d)).map((s) => (
              <Card key={s.id} className={`gap-1 ${s.status === 'cancelled' ? 'opacity-60' : ''}`}>
                <Text className="font-semibold text-body text-ink dark:text-night-ink">{s.starts_at.slice(11, 16)}–{s.ends_at.slice(11, 16)} · {s.title ?? t('timetable.class')}</Text>
                <Text className="font-sans text-small text-muted">{[s.room?.name ?? s.venue, s.facilitator?.name].filter(Boolean).join(' · ')}</Text>
                {s.status === 'cancelled' ? <Pill text={`${t('timetable.cancelled')}${s.cancel_reason ? `: ${s.cancel_reason}` : ''}`} tone="danger" /> : null}
              </Card>
            ))}
          </View>
        ))}
      </ScrollView>
    </Screen>
  );
}
