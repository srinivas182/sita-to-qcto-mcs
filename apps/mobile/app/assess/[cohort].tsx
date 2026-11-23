import Feather from '@expo/vector-icons/Feather';
import { useLocalSearchParams, useRouter } from 'expo-router';
import { Pressable, ScrollView, Text, View } from 'react-native';
import { BackHeader } from '@/components/BackHeader';
import { Body, Card, Screen, Title } from '@/components/ui';
import { useCohortGrid } from '@/lib/assess';
import { useApp } from '@/lib/app';

/** The cohort's learners with how many modules each is competent in. */
export default function CohortAssessScreen() {
  const { cohort, name } = useLocalSearchParams<{ cohort: string; name?: string }>();
  const { t, online } = useApp();
  const router = useRouter();
  const q = useCohortGrid(cohort, online);
  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-3 pb-10 pt-4">
        <BackHeader />
        <Title>{name ?? t('assess.title')}</Title>
        {!q.data ? <Body>{online ? t('common.loading') : t('home.offline')}</Body> : null}
        {q.data?.learners.map((l) => (
          <Pressable key={l.enrolment_id} accessibilityRole="button" onPress={() => router.push({ pathname: '/assess/learner', params: { cohort, enrolment: l.enrolment_id } } as never)}>
            <Card className="flex-row items-center gap-3">
              <View className="flex-1">
                <Text className="font-semibold text-body text-ink dark:text-night-ink">{l.name}</Text>
                <Text className="font-sans text-small text-muted dark:text-night-muted">
                  {t('assess.competentOf', { done: l.modules.filter((m) => m.state === 'competent').length, all: l.modules.length })}
                </Text>
              </View>
              <Feather name="chevron-right" size={18} color="#64748b" />
            </Card>
          </Pressable>
        ))}
      </ScrollView>
    </Screen>
  );
}
