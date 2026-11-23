import Feather from '@expo/vector-icons/Feather';
import { useRouter } from 'expo-router';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { BackHeader } from '@/components/BackHeader';
import { Body, Button, Card, Notice, Screen, Title } from '@/components/ui';
import { discardDecision, useAssessing, waitingDecisions } from '@/lib/assess';
import { useApp } from '@/lib/app';
import { useState } from 'react';

/** The assessor's cohorts, and decisions still waiting to be sent. */
export default function AssessScreen() {
  const { t, online, syncNow } = useApp();
  const router = useRouter();
  const q = useAssessing(online);
  const [queue, setQueue] = useState(waitingDecisions());
  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-10 pt-4" refreshControl={<RefreshControl refreshing={q.isRefetching} onRefresh={() => void syncNow().then(() => { setQueue(waitingDecisions()); return q.refetch(); })} />}>
        <BackHeader />
        <Title>{t('assess.title')}</Title>
        <Body>{t('assess.body')}</Body>
        {queue.length ? (
          <Card className="gap-2">
            <Text className="font-medium text-body text-ink dark:text-night-ink">{t('assess.waiting', { n: queue.filter((x) => x.status === 'pending').length })}</Text>
            {queue.map((w) => (
              <View key={w.clientId} className="gap-1 border-b border-line py-2">
                <Text className="font-sans text-small text-ink dark:text-night-ink">{String(w.body.learner)} · {String(w.body.title)} · {t(`outcome.${String(w.body.outcome)}` as never)}</Text>
                {w.status === 'conflict' ? (
                  <>
                    <Text className="font-sans text-small text-danger">{t('assess.refused', { reason: w.error ?? '' })}</Text>
                    <View className="items-start"><Button title={t('logbook.discard')} variant="secondary" onPress={() => { discardDecision(w.clientId); setQueue(waitingDecisions()); }} /></View>
                  </>
                ) : null}
              </View>
            ))}
          </Card>
        ) : null}
        {q.data && q.data.length === 0 ? <Notice>{t('assess.none')}</Notice> : null}
        {q.data?.map((c) => (
          <Pressable key={c.id} accessibilityRole="button" onPress={() => router.push({ pathname: '/assess/[cohort]', params: { cohort: c.id, name: c.name } } as never)}>
            <Card className="flex-row items-center gap-3">
              <View className="flex-1">
                <Text className="font-semibold text-body text-ink dark:text-night-ink">{c.name}</Text>
                <Text className="font-sans text-small text-muted dark:text-night-muted">{t('assess.learners', { n: c.learners })}</Text>
              </View>
              <Feather name="chevron-right" size={18} color="#64748b" />
            </Card>
          </Pressable>
        ))}
      </ScrollView>
    </Screen>
  );
}
