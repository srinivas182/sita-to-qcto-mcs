import Feather from '@expo/vector-icons/Feather';
import { useQueryClient } from '@tanstack/react-query';
import { useRouter } from 'expo-router';
import { useState } from 'react';
import { Pressable, RefreshControl, ScrollView, Text, View } from 'react-native';
import { Pill } from '@/components/BackHeader';
import { Body, Button, Card, Field, Notice, Screen, Title } from '@/components/ui';
import { ApiError, errorMessage } from '@/lib/api';
import { useApp } from '@/lib/app';
import { type MentorLearner, signOffModule, useMentorWeeks } from '@/lib/logbook';
import { useMentoring } from '@/lib/mentoring';

/** A workplace mentor's view: weeks to sign, their learners' hours and module sign-off, and the workplaces. */
export default function MentoringScreen() {
  const { t, online } = useApp();
  const router = useRouter();
  const q = useMentoring(online);
  const weeks = useMentorWeeks(online);
  const [signing, setSigning] = useState<{ learner: MentorLearner; module: MentorLearner['modules'][number] } | null>(null);
  const d = (iso: string) => new Date(iso).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  const toSign = (weeks.data?.weeks ?? []).filter((w) => w.status !== 'signed');

  if (signing) return <SignOff statement={weeks.data?.statement ?? ''} {...signing} onClose={() => setSigning(null)} />;

  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-10 pt-4" refreshControl={<RefreshControl refreshing={q.isRefetching || weeks.isRefetching} onRefresh={() => void Promise.all([q.refetch(), weeks.refetch()])} />}>
        <View className="items-start">
          <Button title={t('common.back')} variant="ghost" icon={<Feather name="chevron-left" size={18} color="#4338ca" />} onPress={() => router.back()} />
        </View>
        <Title>{t('mentor.title')}</Title>
        {q.data && !q.data.is_mentor ? <Body>{t('mentor.none')}</Body> : null}

        {weeks.data?.is_mentor ? (
          <Card className="gap-2">
            <Text className="font-semibold text-section text-ink dark:text-night-ink">{t('mentor.weeks')}</Text>
            {toSign.length === 0 ? <Body>{t('mentor.noWeeks')}</Body> : null}
            {toSign.map((w) => (
              <Pressable key={w.id} accessibilityRole="button" onPress={() => router.push({ pathname: '/logbook-week/[id]', params: { id: w.id } } as never)}
                className="min-h-11 flex-row items-center justify-between gap-2 rounded-xl bg-line-soft p-3 dark:bg-night-canvas">
                <View className="flex-1">
                  <Text className="font-medium text-body text-ink dark:text-night-ink">{t('mentor.week', { learner: w.learner ?? '', date: d(w.week_start) })}</Text>
                  <Text className="font-sans text-small text-muted dark:text-night-muted">{t('mentor.weekSummary', { n: w.entries, hours: Math.round((w.minutes / 60) * 10) / 10 })}</Text>
                </View>
                {w.status === 'returned' ? <Pill text={t('mentor.sendBack')} tone="danger" /> : <Feather name="chevron-right" size={18} color="#64748b" />}
              </Pressable>
            ))}
          </Card>
        ) : null}

        {weeks.data && weeks.data.learners.length > 0 ? (
          <Card className="gap-3">
            <Text className="font-semibold text-section text-ink dark:text-night-ink">{t('mentor.learners')}</Text>
            {weeks.data.learners.map((l) => (
              <View key={l.placement_id} className="gap-2 border-b border-line pb-3">
                <Text className="font-medium text-body text-ink dark:text-night-ink">{l.name}</Text>
                <Text className="font-sans text-small text-muted dark:text-night-muted">{l.site}</Text>
                {l.modules.map((m) => {
                  const ready = m.hours_needed !== null && m.signed_hours >= m.hours_needed;
                  return (
                    <View key={m.module_id} className="gap-2 rounded-xl bg-line-soft p-3 dark:bg-night-canvas">
                      <Text className="font-sans text-small text-ink dark:text-night-ink">{t('mentor.moduleHours', { code: m.code, signed: m.signed_hours, needed: m.hours_needed ?? '—' })}</Text>
                      {m.signoff && m.signoff.status !== 'queried' ? (
                        <Pill text={t(`mentor.signoff.${m.signoff.status}` as never)} tone={m.signoff.status === 'checked' ? 'success' : 'info'} />
                      ) : (
                        <>
                          {m.signoff?.status === 'queried' ? <Pill text={t('mentor.signoff.queried', { comment: m.signoff.check_comment ?? '' })} tone="danger" /> : null}
                          {ready ? <Button title={t('mentor.signOff')} onPress={() => setSigning({ learner: l, module: m })} /> : null}
                        </>
                      )}
                    </View>
                  );
                })}
              </View>
            ))}
          </Card>
        ) : null}

        {q.data?.workplaces.map((w) => (
          <Card key={w.id} className="gap-3">
            <View>
              <Text className="font-semibold text-section text-ink dark:text-night-ink">{w.name}</Text>
              <Text className="font-sans text-small text-muted dark:text-night-muted">{[w.employer, w.city].filter(Boolean).join(' · ')}</Text>
            </View>
            {w.cohorts.length ? (
              w.cohorts.map((c) => (
                <View key={c.name} className="rounded-xl bg-line-soft p-3 dark:bg-night-canvas">
                  <Text className="font-medium text-body text-ink dark:text-night-ink">{c.name}</Text>
                  <Text className="font-sans text-small text-muted dark:text-night-muted">{c.qualification}</Text>
                  <Text className="font-sans text-small text-muted dark:text-night-muted">{t('mentor.cohorts', { n: c.learners, from: d(c.intake_on), to: d(c.planned_end_on) })}</Text>
                </View>
              ))
            ) : (
              <Body>{t('mentor.noCohorts')}</Body>
            )}
          </Card>
        ))}
        {weeks.data?.is_mentor && weeks.data.learners.length === 0 ? <Notice tone="info">{t('mentor.soon')}</Notice> : null}
      </ScrollView>
    </Screen>
  );
}

function SignOff({ statement, learner, module, onClose }: { statement: string; learner: MentorLearner; module: MentorLearner['modules'][number]; onClose: () => void }) {
  const { t } = useApp();
  const qc = useQueryClient();
  const [authentic, setAuthentic] = useState(false);
  const [comment, setComment] = useState('');
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<{ tone: 'danger' | 'success'; text: string } | null>(null);
  const send = async () => {
    setBusy(true);
    try {
      await signOffModule(learner.enrolment_id, module.module_id, comment);
      void qc.invalidateQueries({ queryKey: ['mentoring-weeks'] });
      setMessage({ tone: 'success', text: t('mentor.signOffDone') });
    } catch (e) {
      setMessage({ tone: 'danger', text: e instanceof ApiError ? (Object.values(e.fields)[0]?.[0] ?? e.message) : errorMessage(e) });
    } finally {
      setBusy(false);
    }
  };
  return (
    <Screen scroll>
      <ScrollView contentContainerClassName="gap-4 pb-12 pt-4" keyboardShouldPersistTaps="handled">
        <Title>{t('mentor.signOffTitle', { code: module.code, learner: learner.name ?? '' })}</Title>
        <Body>{t('mentor.moduleHours', { code: module.code, signed: module.signed_hours, needed: module.hours_needed ?? '—' })}</Body>
        {message ? <Notice tone={message.tone}>{message.text}</Notice> : null}
        {message?.tone === 'success' ? (
          <Button title={t('common.done')} onPress={onClose} />
        ) : (
          <Card className="gap-3">
            <Pressable accessibilityRole="checkbox" accessibilityState={{ checked: authentic }} onPress={() => setAuthentic(!authentic)}
              className={`min-h-11 flex-row items-center gap-3 rounded-xl border px-4 py-3 ${authentic ? 'border-2 border-primary-600 bg-primary-50' : 'border-line'}`}>
              <Text className="text-section">{authentic ? '☑' : '☐'}</Text>
              <Text className="flex-1 font-sans text-body text-ink">{statement}</Text>
            </Pressable>
            <Field label={t('mentor.comment')} multiline value={comment} onChangeText={setComment} />
            <Button title={t('mentor.signOff')} loading={busy} disabled={!authentic} onPress={() => void send()} />
            <Button title={t('common.cancel')} variant="secondary" onPress={onClose} />
          </Card>
        )}
      </ScrollView>
    </Screen>
  );
}
