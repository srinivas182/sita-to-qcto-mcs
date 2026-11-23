import Feather from '@expo/vector-icons/Feather';
import { useRouter } from 'expo-router';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { BackHeader, Pill } from '@/components/BackHeader';
import { Body, Card, Notice, Screen, Title } from '@/components/ui';
import { useApp } from '@/lib/app';
import { useMyDocuments, type Requirement } from '@/lib/enrolment';

const STATE: Record<Requirement['state'], { label: string; tone: 'neutral' | 'success' | 'warning' | 'danger' | 'info' }> = {
  missing: { label: 'Needed', tone: 'warning' },
  pending: { label: 'Being checked', tone: 'info' },
  accepted: { label: 'Accepted', tone: 'success' },
  rejected: { label: 'Rejected', tone: 'danger' },
  expired: { label: 'Too old', tone: 'danger' },
};

/** The documents each application needs, with their state; add one by photographing it. */
export default function Documents() {
  const { t, online } = useApp();
  const router = useRouter();
  const q = useMyDocuments(online);

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" refreshControl={<RefreshControl refreshing={q.isRefetching} onRefresh={() => void q.refetch()} />}>
        <BackHeader />
        <Title>{t('docs.title')}</Title>
        <Body>{t('docs.body')}</Body>
        {!online ? <Notice tone="warning">{t('docs.offline')}</Notice> : null}
        {q.data && !q.data.needed.length ? <Body>{t('docs.none')}</Body> : null}
        {(q.data?.needed ?? []).map((n) => (
          <View key={n.application_id} className="gap-2">
            <Text className="font-semibold text-body text-ink dark:text-night-ink">{n.intake}</Text>
            {n.complete ? <Notice tone="success">{t('docs.complete')}</Notice> : null}
            {n.items.map((i) => {
              const canAdd = ['missing', 'rejected', 'expired'].includes(i.state);
              return (
                <Pressable key={i.type} accessibilityRole="button" accessibilityLabel={`${i.label}: ${STATE[i.state].label}`} disabled={!canAdd || !online}
                  onPress={() => router.push({ pathname: '/documents/capture', params: { type: i.type, label: i.label } } as never)}>
                  <Card className="flex-row items-center gap-3">
                    <View className="flex-1 gap-1">
                      <Text className="font-medium text-body text-ink dark:text-night-ink">{i.label}</Text>
                      <Pill text={STATE[i.state].label} tone={STATE[i.state].tone} />
                      {i.problem ? <Text className="font-sans text-small text-danger">{i.problem}</Text> : null}
                    </View>
                    {canAdd ? <Feather name="camera" size={20} color="#4338ca" /> : null}
                  </Card>
                </Pressable>
              );
            })}
          </View>
        ))}
      </ScrollView>
    </Screen>
  );
}
